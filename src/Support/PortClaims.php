<?php

namespace Phattarachai\DbTunnel\Support;

use Illuminate\Support\Collection;

class PortClaims
{
    public function __construct(
        private PortRegistry $registry,
        private SshConfig $sshConfig,
        private PortInspector $inspector,
    ) {}

    /**
     * @return Collection<int, Claim>
     */
    public function capture(): Collection
    {
        return $this->registryClaims()
            ->concat($this->sshConfigClaims())
            ->concat($this->listenerClaims())
            ->values();
    }

    /**
     * @param  Collection<int, Claim>  $claims
     * @return Collection<int, Claim>
     */
    public function foreign(Collection $claims, int $port, Tunnel $tunnel, string $project): Collection
    {
        return $claims->filter(fn (Claim $claim) => $claim->port === $port && ! $claim->belongsTo($tunnel, $project))->values();
    }

    /**
     * @param  Collection<int, Claim>  $claims
     * @return Collection<int, Collection<int, Claim>>
     */
    public function collisions(Collection $claims): Collection
    {
        return $claims->groupBy('port')
            ->filter(fn (Collection $group) => $this->owners($group)->count() > 1)
            ->sortKeys();
    }

    /**
     * @param  Collection<int, Claim>  $group
     * @return Collection<int, string>
     */
    private function owners(Collection $group): Collection
    {
        return $group->map(fn (Claim $claim) => $claim->alias ?? $claim->owner)->unique()->values();
    }

    /**
     * @return Collection<int, Claim>
     */
    private function registryClaims(): Collection
    {
        return collect($this->registry->all())->map(fn (array $entry, int $port) => new Claim(
            port: $port,
            source: Claim::REGISTRY,
            owner: basename($entry['project']).":{$entry['connection']}",
            alias: $entry['alias'],
            project: $entry['project'],
            connection: $entry['connection'],
        ))->values();
    }

    /**
     * @return Collection<int, Claim>
     */
    private function sshConfigClaims(): Collection
    {
        return $this->sshConfig->localForwards()->map(fn (array $forward) => new Claim(
            port: $forward['port'],
            source: Claim::SSH_CONFIG,
            owner: "Host {$forward['alias']}",
            alias: $forward['alias'],
        ))->values();
    }

    /**
     * @return Collection<int, Claim>
     */
    private function listenerClaims(): Collection
    {
        return $this->inspector->listeners()
            ->map(fn (Listener $listener) => $listener->isSsh() ? $listener->withArguments($this->inspector->arguments($listener->pid)) : $listener)
            ->map(fn (Listener $listener) => new Claim(
                port: $listener->port,
                source: Claim::LISTENER,
                owner: $listener->describe(),
                alias: $listener->sshTarget(),
            ))->values();
    }
}
