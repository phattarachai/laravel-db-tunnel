<?php

namespace Phattarachai\DbTunnel\Support;

final readonly class Tunnel
{
    public function __construct(
        public string $connection,
        public string $alias,
        public int $localPort,
        public string $remoteHost,
        public int $remotePort,
        public string $portEnv,
        public bool $autoOpen,
        public ?string $host = null,
        public ?string $user = null,
    ) {}

    public function hasPort(): bool
    {
        return $this->localPort > 0;
    }

    public function forward(): string
    {
        return "127.0.0.1:{$this->localPort}:{$this->remoteHost}:{$this->remotePort}";
    }

    public function remote(): string
    {
        return "{$this->alias} → {$this->remoteHost}:{$this->remotePort}";
    }
}
