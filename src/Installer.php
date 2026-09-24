<?php

namespace Phattarachai\DbTunnel;

use Phattarachai\DbTunnel\Support\EnvFile;
use Phattarachai\DbTunnel\Support\InstallResult;
use Phattarachai\DbTunnel\Support\PortAllocator;
use Phattarachai\DbTunnel\Support\PortRegistry;
use Phattarachai\DbTunnel\Support\SshConfig;
use Phattarachai\DbTunnel\Support\Tunnel;

class Installer
{
    public function __construct(
        private PortAllocator $allocator,
        private PortRegistry $registry,
        private SshConfig $sshConfig,
    ) {}

    public function install(Tunnel $tunnel, string $project, ?int $port = null): InstallResult
    {
        $allocated = $this->allocator->allocate($tunnel, $project, $port);

        $this->registry->claim($allocated, $project, $tunnel->connection, $tunnel->alias);
        (new EnvFile(base_path('.env')))->set($tunnel->portEnv, (string) $allocated);
        config(["database.connections.{$tunnel->connection}.port" => $allocated]);

        return new InstallResult(
            port: $allocated,
            previous: $tunnel->localPort,
            exampleAdded: (new EnvFile(base_path('.env.example')))->ensure($tunnel->portEnv),
            aliasDefined: $this->sshConfig->defines($tunnel->alias),
        );
    }
}
