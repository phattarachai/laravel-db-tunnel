<?php

namespace Phattarachai\DbTunnel\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Phattarachai\DbTunnel\Exceptions\TunnelException;

class Tunnels
{
    /**
     * @return Collection<string, Tunnel>
     */
    public function all(): Collection
    {
        return collect((array) config('db-tunnel.tunnels', []))
            ->map(fn (array $definition, string $connection) => $this->make($connection, $definition));
    }

    public function find(string $connection): Tunnel
    {
        $definition = config('db-tunnel.tunnels')[$connection] ?? null;

        throw_unless(is_array($definition), TunnelException::unknown($connection));

        return $this->make($connection, $definition);
    }

    public function connectionExists(Tunnel $tunnel): bool
    {
        return is_array(config('database.connections')[$tunnel->connection] ?? null);
    }

    /**
     * @param  array{alias?: string, remote_port?: int, remote_host?: string, port_env?: string, auto_open?: bool, host?: string, user?: string, gcp_iap?: array{instance?: string, project?: string, zone?: string}, ssh_options?: array<string, string|int>}  $definition
     */
    private function make(string $connection, array $definition): Tunnel
    {
        $gcpIap = $this->gcpIap($connection, $definition['gcp_iap'] ?? null);
        $alias = $definition['alias'] ?? $gcpIap?->instance;

        throw_unless($alias !== null && isset($definition['remote_port']), TunnelException::incomplete($connection));

        return new Tunnel(
            connection: $connection,
            alias: $alias,
            localPort: (int) (config('database.connections')[$connection]['port'] ?? 0),
            remoteHost: $definition['remote_host'] ?? '127.0.0.1',
            remotePort: (int) $definition['remote_port'],
            portEnv: $definition['port_env'] ?? $this->defaultPortEnv($connection),
            autoOpen: $definition['auto_open'] ?? true,
            host: $definition['host'] ?? null,
            user: $definition['user'] ?? null,
            gcpIap: $gcpIap,
            extraSshOptions: $definition['ssh_options'] ?? [],
        );
    }

    /**
     * @param  array{instance?: string, project?: string, zone?: string}|null  $definition
     */
    private function gcpIap(string $connection, ?array $definition): ?GcpIap
    {
        if ($definition === null) {
            return null;
        }

        throw_unless(isset($definition['instance']), TunnelException::incompleteGcpIap($connection));

        return new GcpIap($definition['instance'], $definition['project'] ?? null, $definition['zone'] ?? null);
    }

    private function defaultPortEnv(string $connection): string
    {
        if ($connection === config('database.default')) {
            return 'DB_PORT';
        }

        return Str::of($connection)->chopStart('claude-')->upper()->replace(['-', '.'], '_')->append('_DB_PORT')->toString();
    }
}
