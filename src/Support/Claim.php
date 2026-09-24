<?php

namespace Phattarachai\DbTunnel\Support;

final readonly class Claim
{
    public const string REGISTRY = 'registry';

    public const string SSH_CONFIG = 'ssh config';

    public const string LISTENER = 'listener';

    public function __construct(
        public int $port,
        public string $source,
        public string $owner,
        public ?string $alias = null,
        public ?string $project = null,
        public ?string $connection = null,
    ) {}

    public function belongsTo(Tunnel $tunnel, string $project): bool
    {
        return match ($this->source) {
            self::REGISTRY => $this->project === $project && $this->connection === $tunnel->connection,
            default => $this->alias !== null && $this->alias === $tunnel->alias,
        };
    }

    public function describe(): string
    {
        return "{$this->owner} ({$this->source})";
    }
}
