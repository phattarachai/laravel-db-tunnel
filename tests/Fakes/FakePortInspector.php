<?php

namespace Phattarachai\DbTunnel\Tests\Fakes;

use Illuminate\Support\Collection;
use Phattarachai\DbTunnel\Support\Listener;
use Phattarachai\DbTunnel\Support\PortInspector;

class FakePortInspector extends PortInspector
{
    /** @var array<int, Listener> */
    public array $listening = [];

    public function ssh(int $port, string $alias, int $pid = 4242): self
    {
        $this->listening[$port] = new Listener($port, $pid, 'ssh', "ssh -f -N -L 127.0.0.1:{$port}:127.0.0.1:5432 {$alias}");

        return $this;
    }

    public function process(int $port, string $command, int $pid = 777): self
    {
        $this->listening[$port] = new Listener($port, $pid, $command);

        return $this;
    }

    public function release(int $port): self
    {
        unset($this->listening[$port]);

        return $this;
    }

    public function listener(int $port): ?Listener
    {
        return $this->listening[$port] ?? null;
    }

    public function listeners(?int $port = null): Collection
    {
        return collect($this->listening)->when($port, fn (Collection $listening) => $listening->only([$port]));
    }

    public function arguments(int $pid): string
    {
        return collect($this->listening)->firstWhere('pid', $pid)->arguments ?? '';
    }
}
