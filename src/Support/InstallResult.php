<?php

namespace Phattarachai\DbTunnel\Support;

final readonly class InstallResult
{
    public function __construct(
        public int $port,
        public int $previous,
        public bool $exampleAdded,
        public bool $aliasDefined,
    ) {}

    public function changed(): bool
    {
        return $this->port !== $this->previous;
    }
}
