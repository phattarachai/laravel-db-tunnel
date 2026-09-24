<?php

namespace Phattarachai\DbTunnel\Support;

use Closure;
use Illuminate\Support\Collection;
use Phattarachai\DbTunnel\Exceptions\TunnelException;

class PortAllocator
{
    public function __construct(
        private PortRegistry $registry,
        private PortClaims $claims,
    ) {}

    public function allocate(Tunnel $tunnel, string $project, ?int $requested = null): int
    {
        $claims = $this->claims->capture();
        $foreign = fn (int $port) => $this->claims->foreign($claims, $port, $tunnel, $project);

        if ($requested) {
            return $this->requested($requested, $foreign($requested));
        }

        return collect([$this->registry->portFor($project, $tunnel->connection), $this->adoptable($tunnel)])
            ->filter()
            ->first(fn (int $port) => $foreign($port)->isEmpty())
            ?? $this->firstFree($foreign);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function range(): array
    {
        [$from, $to] = config('db-tunnel.port_range', [15440, 15999]);

        return [(int) $from, (int) $to];
    }

    public function inRange(int $port): bool
    {
        [$from, $to] = $this->range();

        return $port >= $from && $port <= $to;
    }

    /**
     * @param  Collection<int, Claim>  $owners
     */
    private function requested(int $port, Collection $owners): int
    {
        throw_if($owners->isNotEmpty(), TunnelException::portTaken($port, $owners->map->describe()->implode(', ')));

        return $port;
    }

    private function adoptable(Tunnel $tunnel): ?int
    {
        return $this->inRange($tunnel->localPort) ? $tunnel->localPort : null;
    }

    /**
     * @param  Closure(int): Collection<int, Claim>  $foreign
     */
    private function firstFree(Closure $foreign): int
    {
        [$from, $to] = $this->range();

        foreach (range($from, $to) as $port) {
            if ($foreign($port)->isEmpty()) {
                return $port;
            }
        }

        throw TunnelException::rangeExhausted($from, $to);
    }
}
