<?php

namespace Phattarachai\DbTunnel\Support;

use Illuminate\Support\Str;

final readonly class Listener
{
    public function __construct(
        public int $port,
        public int $pid,
        public string $command,
        public string $arguments = '',
    ) {}

    public function isSsh(): bool
    {
        return $this->command === 'ssh';
    }

    public function isSshTo(string $alias): bool
    {
        return $this->isSsh() && $this->sshTarget() === $alias;
    }

    public function sshTarget(): ?string
    {
        return $this->isSsh() ? Str::afterLast(trim($this->arguments), ' ') : null;
    }

    public function withArguments(string $arguments): self
    {
        return new self($this->port, $this->pid, $this->command, $arguments);
    }

    public function describe(): string
    {
        $target = $this->isSsh() ? " to {$this->sshTarget()}" : '';

        return "{$this->command}{$target} (pid {$this->pid})";
    }
}
