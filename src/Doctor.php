<?php

namespace Phattarachai\DbTunnel;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Phattarachai\DbTunnel\Support\Claim;
use Phattarachai\DbTunnel\Support\EnvFile;
use Phattarachai\DbTunnel\Support\Finding;
use Phattarachai\DbTunnel\Support\GcpIap;
use Phattarachai\DbTunnel\Support\PortClaims;
use Phattarachai\DbTunnel\Support\PortRegistry;
use Phattarachai\DbTunnel\Support\SshConfig;
use Phattarachai\DbTunnel\Support\Tunnel;
use Phattarachai\DbTunnel\Support\Tunnels;

class Doctor
{
    /** @var Collection<int, Claim>|null */
    private ?Collection $claimed = null;

    private ?string $gcloudAuthError = null;

    private bool $gcloudAuthChecked = false;

    public function __construct(
        private Tunnels $tunnels,
        private PortRegistry $registry,
        private PortClaims $claims,
        private SshConfig $sshConfig,
    ) {}

    /**
     * @param  Collection<string, Tunnel>  $tunnels
     * @return Collection<int, Finding>
     */
    public function project(Collection $tunnels, string $project): Collection
    {
        return $tunnels->flatMap(fn (Tunnel $tunnel) => $this->checkTunnel($tunnel, $project, $tunnels))->values();
    }

    /**
     * @return Collection<int, Finding>
     */
    public function machine(): Collection
    {
        return $this->collisions()->concat($this->staleEntries())->values();
    }

    /**
     * @param  Collection<string, Tunnel>  $siblings
     * @return Collection<int, Finding>
     */
    private function checkTunnel(Tunnel $tunnel, string $project, Collection $siblings): Collection
    {
        if (! $this->tunnels->connectionExists($tunnel)) {
            return collect([Finding::fail($tunnel->connection, 'connection is not defined in config/database.php')]);
        }

        if (! $tunnel->hasPort()) {
            return collect([Finding::fail($tunnel->connection, "{$tunnel->portEnv} is not set — run `php artisan db:tunnel install {$tunnel->connection}`")]);
        }

        $findings = collect([
            $this->registration($tunnel, $project),
            $this->foreignClaims($tunnel, $project),
            $this->alias($tunnel),
            $this->gcpKey($tunnel),
            $this->gcloudAuth($tunnel),
            $this->aliasForwards($tunnel, $siblings),
            $this->envExample($tunnel),
        ])->filter();

        return $findings->isEmpty()
            ? collect([Finding::ok($tunnel->connection, "127.0.0.1:{$tunnel->localPort} → {$tunnel->remote()}")])
            : $findings;
    }

    private function registration(Tunnel $tunnel, string $project): ?Finding
    {
        $registered = $this->registry->portFor($project, $tunnel->connection);

        return match (true) {
            $registered === null => Finding::warn($tunnel->connection, "port {$tunnel->localPort} is not in the machine registry — run `php artisan db:tunnel install {$tunnel->connection}`"),
            $registered !== $tunnel->localPort => Finding::fail($tunnel->connection, "registry holds {$registered} but {$tunnel->portEnv}={$tunnel->localPort} — run `php artisan db:tunnel install {$tunnel->connection}`"),
            default => null,
        };
    }

    private function foreignClaims(Tunnel $tunnel, string $project): ?Finding
    {
        $foreign = $this->claims->foreign($this->claimed(), $tunnel->localPort, $tunnel, $project);

        return $foreign->isEmpty()
            ? null
            : Finding::fail($tunnel->connection, "port {$tunnel->localPort} is also claimed by ".$foreign->map->describe()->implode(', '));
    }

    private function alias(Tunnel $tunnel): ?Finding
    {
        return $tunnel->isSelfContained() || $this->sshConfig->defines($tunnel->alias)
            ? null
            : Finding::warn($tunnel->connection, "no `Host {$tunnel->alias}` in {$this->sshConfig->path} — ssh will treat it as a hostname");
    }

    private function gcpKey(Tunnel $tunnel): ?Finding
    {
        return ! $tunnel->gcpIap || is_file(GcpIap::identityFile())
            ? null
            : Finding::fail($tunnel->connection, 'no gcloud SSH key at '.GcpIap::identityFile()." — run `{$tunnel->gcpIap->loginCommand()}` once");
    }

    private function gcloudAuth(Tunnel $tunnel): ?Finding
    {
        if (! $tunnel->gcpIap) {
            return null;
        }

        if (! $this->gcloudAuthChecked) {
            $result = Process::timeout(30)->run(['gcloud', 'auth', 'print-access-token', '--quiet']);
            $this->gcloudAuthError = $result->successful() ? null : (Str::of($result->errorOutput())->trim()->explode("\n")->first() ?: 'gcloud failed');
            $this->gcloudAuthChecked = true;
        }

        return $this->gcloudAuthError === null
            ? null
            : Finding::fail($tunnel->connection, "gcloud has no usable credentials ({$this->gcloudAuthError}) — run `gcloud auth login`");
    }

    /**
     * @param  Collection<string, Tunnel>  $siblings
     */
    private function aliasForwards(Tunnel $tunnel, Collection $siblings): ?Finding
    {
        $ours = $siblings->filter(fn (Tunnel $sibling) => $sibling->alias === $tunnel->alias)->map->localPort;
        $stray = collect($this->sshConfig->forwardsOf($tunnel->alias))->diff($ours);

        return $stray->isEmpty()
            ? null
            : Finding::warn($tunnel->connection, "`Host {$tunnel->alias}` also forwards {$stray->implode(', ')}, which ssh binds on every open — point the tunnel at a login alias without LocalForward");
    }

    private function envExample(Tunnel $tunnel): ?Finding
    {
        $example = new EnvFile(base_path('.env.example'));

        return ! $example->exists() || $example->has($tunnel->portEnv)
            ? null
            : Finding::warn($tunnel->connection, "{$tunnel->portEnv} is missing from .env.example");
    }

    /**
     * @return Collection<int, Finding>
     */
    private function collisions(): Collection
    {
        return $this->claims->collisions($this->claimed())
            ->map(fn (Collection $group, int $port) => Finding::fail("port {$port}", 'claimed by '.$group->map->describe()->unique()->implode(', ')))
            ->values();
    }

    /**
     * @return Collection<int, Finding>
     */
    private function staleEntries(): Collection
    {
        return collect($this->registry->all())
            ->reject(fn (array $entry) => is_dir($entry['project']))
            ->map(fn (array $entry, int $port) => Finding::warn("port {$port}", "registered to {$entry['project']}, which no longer exists — `db:tunnel doctor --prune` frees it"))
            ->values();
    }

    /**
     * @return Collection<int, Claim>
     */
    private function claimed(): Collection
    {
        return $this->claimed ??= $this->claims->capture();
    }
}
