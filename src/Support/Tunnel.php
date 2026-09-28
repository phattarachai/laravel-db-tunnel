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
        public ?GcpIap $gcpIap = null,
        /** @var array<string, string|int> */
        public array $extraSshOptions = [],
    ) {}

    /**
     * `-o` options the tunnel's ssh always carries, on top of whatever ~/.ssh/config sets for the alias.
     *
     * @return list<string>
     */
    public function sshOptions(): array
    {
        return [
            ...($this->gcpIap?->sshOptions() ?? []),
            ...collect($this->extraSshOptions)->flatMap(fn (string|int $value, string $key) => ['-o', "{$key}={$value}"])->values()->all(),
        ];
    }

    /**
     * Whether the tunnel carries everything ssh needs, so the alias need not exist in ~/.ssh/config.
     */
    public function isSelfContained(): bool
    {
        return $this->gcpIap !== null;
    }

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
