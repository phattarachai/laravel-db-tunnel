<?php

namespace Phattarachai\DbTunnel\Support;

final readonly class TunnelStatus
{
    public function __construct(
        public TunnelState $state,
        public ?Listener $listener = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->state === TunnelState::Open;
    }

    public function describe(): string
    {
        return match ($this->state) {
            TunnelState::Open => "open (ssh pid {$this->listener?->pid})",
            TunnelState::Conflict => "port held by {$this->listener?->describe()}",
            TunnelState::Closed => 'closed',
            TunnelState::Unconfigured => 'no local port — run db:tunnel install',
        };
    }
}
