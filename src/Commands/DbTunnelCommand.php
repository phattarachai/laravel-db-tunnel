<?php

namespace Phattarachai\DbTunnel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Phattarachai\DbTunnel\Doctor;
use Phattarachai\DbTunnel\Exceptions\TunnelException;
use Phattarachai\DbTunnel\Installer;
use Phattarachai\DbTunnel\Supervisor;
use Phattarachai\DbTunnel\Support\Finding;
use Phattarachai\DbTunnel\Support\Paths;
use Phattarachai\DbTunnel\Support\PortRegistry;
use Phattarachai\DbTunnel\Support\SshConfig;
use Phattarachai\DbTunnel\Support\Tunnel;
use Phattarachai\DbTunnel\Support\Tunnels;
use Phattarachai\DbTunnel\TunnelManager;

class DbTunnelCommand extends Command
{
    protected $signature = 'db:tunnel
        {action=status : open, close, status, install, doctor, watch or wait}
        {connection? : A tunnel key from config/db-tunnel.php (the database connection it carries)}
        {--all : open/close — every configured tunnel}
        {--port= : install — claim this exact port instead of the next free one}
        {--timeout=60 : wait — seconds to wait for the tunnel to listen}
        {--prune : doctor — drop registry entries whose project directory is gone}';

    protected $description = 'Open, close and inspect SSH tunnels for remote database connections';

    private Tunnels $tunnels;

    private TunnelManager $manager;

    public function handle(Tunnels $tunnels, TunnelManager $manager): int
    {
        $this->tunnels = $tunnels;
        $this->manager = $manager;

        try {
            return match ($this->argument('action')) {
                'open' => $this->open(),
                'close' => $this->close(),
                'status' => $this->status(),
                'install' => $this->install(),
                'doctor' => $this->doctor(),
                'watch' => $this->watch(),
                'wait' => $this->wait(),
                default => $this->unknownAction(),
            };
        } catch (TunnelException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function open(): int
    {
        $failures = $this->targets()->reject(fn (Tunnel $tunnel) => $this->openOne($tunnel));

        return $failures->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function openOne(Tunnel $tunnel): bool
    {
        try {
            $wasOpen = $this->manager->status($tunnel)->isOpen();
            $this->manager->open($tunnel);
            $this->components->info(($wasOpen ? 'Already open' : 'Open').": {$tunnel->connection} — 127.0.0.1:{$tunnel->localPort} → {$tunnel->remote()}");

            return true;
        } catch (TunnelException $exception) {
            $this->components->error($exception->getMessage());
            $this->suggestHostBlock($tunnel);

            return false;
        }
    }

    private function close(): int
    {
        $this->targets()->each(fn (Tunnel $tunnel) => $this->manager->close($tunnel)
            ? $this->components->info("Closed {$tunnel->connection} (127.0.0.1:{$tunnel->localPort}).")
            : $this->components->warn("{$tunnel->connection} was not open — nothing to close."));

        return self::SUCCESS;
    }

    private function status(): int
    {
        $tunnels = $this->targets(defaultAll: true);
        $statuses = $tunnels->map(fn (Tunnel $tunnel) => $this->manager->status($tunnel));

        $this->table(
            ['Connection', 'Local', 'Remote', 'State'],
            $tunnels->map(fn (Tunnel $tunnel, string $connection) => [
                $connection,
                $tunnel->hasPort() ? "127.0.0.1:{$tunnel->localPort}" : '—',
                $tunnel->remote(),
                $statuses[$connection]->describe(),
            ])->values()->all(),
        );

        $singleClosed = $this->argument('connection') && ! $statuses->first()?->isOpen();

        return $singleClosed ? self::FAILURE : self::SUCCESS;
    }

    private function install(): int
    {
        $port = $this->option('port') ? (int) $this->option('port') : null;
        $tunnels = $this->targets(defaultAll: true);

        throw_if($port && $tunnels->count() > 1, new TunnelException('--port needs a single connection.'));

        $changed = $tunnels->filter(fn (Tunnel $tunnel) => $this->installOne($tunnel, $port))->isNotEmpty();
        $changed && $this->components->warn('Restart Laravel Boost (reconnect the MCP server in Claude Code) — it read the old port at boot.');
        $changed && $this->laravel->configurationIsCached() && $this->components->warn('Config is cached — run `php artisan config:clear` so the new port takes effect.');

        return self::SUCCESS;
    }

    private function installOne(Tunnel $tunnel, ?int $port): bool
    {
        $result = app(Installer::class)->install($tunnel, Paths::project(), $port);

        $this->components->info("{$tunnel->connection}: 127.0.0.1:{$result->port} → {$tunnel->remote()} ({$tunnel->portEnv}={$result->port} in .env)");
        $result->exampleAdded && $this->components->bulletList(["Added an empty {$tunnel->portEnv}= to .env.example — each machine allocates its own port."]);
        $result->aliasDefined || $this->suggestHostBlock($tunnel);

        return $result->changed();
    }

    private function doctor(): int
    {
        $this->option('prune') && $this->reportPruned(app(PortRegistry::class)->prune());

        $doctor = app(Doctor::class);
        $project = $doctor->project($this->tunnels->all(), Paths::project());
        $machine = $doctor->machine();

        $this->reportFindings('This project', $project, emptyMessage: 'No tunnels configured in config/db-tunnel.php.');
        $this->reportFindings('This machine', $machine, emptyMessage: 'No port collisions across the registry, ~/.ssh/config and listening sockets.');

        return $project->concat($machine)->contains('level', Finding::FAIL) ? self::FAILURE : self::SUCCESS;
    }

    private function watch(): int
    {
        $tunnel = $this->tunnels->find($this->requiredConnection());
        $supervisor = app(Supervisor::class);

        extension_loaded('pcntl') && $this->trap([SIGINT, SIGTERM], fn () => $supervisor->stop());
        $supervisor->run($tunnel, fn (string $message) => $this->line('['.now()->format('H:i:s')."] db-tunnel {$tunnel->connection}: {$message}"));

        return self::SUCCESS;
    }

    private function wait(): int
    {
        $tunnel = $this->tunnels->find($this->requiredConnection());
        $timeout = (int) $this->option('timeout');

        if ($this->manager->waitUntilOpen($tunnel, $timeout)) {
            return self::SUCCESS;
        }

        $this->components->error("{$tunnel->connection} did not open on 127.0.0.1:{$tunnel->localPort} within {$timeout}s.");

        return self::FAILURE;
    }

    /**
     * @return Collection<string, Tunnel>
     */
    private function targets(bool $defaultAll = false): Collection
    {
        $connection = $this->argument('connection');

        if ($connection) {
            return collect([$connection => $this->tunnels->find($connection)]);
        }

        throw_unless($defaultAll || $this->option('all'), new TunnelException('Pass a connection ('.$this->tunnels->all()->keys()->implode(', ').') or --all.'));

        return $this->tunnels->all();
    }

    private function requiredConnection(): string
    {
        return $this->argument('connection') ?? throw new TunnelException("`db:tunnel {$this->argument('action')}` needs a connection.");
    }

    private function suggestHostBlock(Tunnel $tunnel): void
    {
        $sshConfig = app(SshConfig::class);

        if ($sshConfig->defines($tunnel->alias)) {
            return;
        }

        $this->components->warn("`Host {$tunnel->alias}` is not in {$sshConfig->path} — add a login alias like:");
        $this->line($this->hostBlock($tunnel));
    }

    private function hostBlock(Tunnel $tunnel): string
    {
        $host = $tunnel->host ?? '<host>';
        $user = $tunnel->user ?? '<user>';

        return <<<SSH

            Host {$tunnel->alias}
                HostName {$host}
                User {$user}
                IdentityFile ~/.ssh/id_ed25519
                IdentitiesOnly yes
                StrictHostKeyChecking accept-new
                ServerAliveInterval 15
                ServerAliveCountMax 8

            SSH;
    }

    /**
     * @param  Collection<int, Finding>  $findings
     */
    private function reportFindings(string $title, Collection $findings, string $emptyMessage): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");

        if ($findings->isEmpty()) {
            $this->line("  <fg=green;options=bold>OK</>    {$emptyMessage}");

            return;
        }

        $findings->each(fn (Finding $finding) => $this->line(match ($finding->level) {
            Finding::FAIL => '  <fg=red;options=bold>FAIL</>  ',
            Finding::WARN => '  <fg=yellow;options=bold>WARN</>  ',
            default => '  <fg=green;options=bold>OK</>    ',
        }."<options=bold>{$finding->subject}</> {$finding->message}"));
    }

    /**
     * @param  list<int>  $ports
     */
    private function reportPruned(array $ports): void
    {
        $this->components->info($ports === [] ? 'Nothing to prune.' : 'Pruned stale registry ports: '.implode(', ', $ports));
    }

    private function unknownAction(): int
    {
        $this->components->error("Unknown action [{$this->argument('action')}] — use open, close, status, install, doctor, watch or wait.");

        return self::FAILURE;
    }
}
