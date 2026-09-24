<?php

namespace Phattarachai\DbTunnel;

use Illuminate\Database\DatabaseManager;
use Phattarachai\DbTunnel\Commands\DbTunnelCommand;
use Phattarachai\DbTunnel\Support\Paths;
use Phattarachai\DbTunnel\Support\PortRegistry;
use Phattarachai\DbTunnel\Support\SshConfig;
use Phattarachai\DbTunnel\Support\Tunnel;
use Phattarachai\DbTunnel\Support\Tunnels;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DbTunnelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('db-tunnel')
            ->hasConfigFile()
            ->hasCommand(DbTunnelCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(SshConfig::class, fn () => new SshConfig(Paths::sshConfig()));
        $this->app->bind(PortRegistry::class, fn () => new PortRegistry(Paths::registry()));
    }

    public function packageBooted(): void
    {
        $this->callAfterResolving('db', $this->registerAutoOpen(...));
    }

    private function registerAutoOpen(DatabaseManager $db): void
    {
        if (! $this->autoOpenEnabled()) {
            return;
        }

        $this->app->make(Tunnels::class)->all()
            ->filter(fn (Tunnel $tunnel) => $tunnel->autoOpen)
            ->each(fn (Tunnel $tunnel) => $db->extend($tunnel->connection, $this->connectThroughTunnel(...)));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connectThroughTunnel(array $config, string $name): mixed
    {
        $this->app->make(TunnelManager::class)->open($this->app->make(Tunnels::class)->find($name));

        return $this->app->make('db.factory')->make($config, $name);
    }

    private function autoOpenEnabled(): bool
    {
        return (bool) (config('db-tunnel.auto_open') ?? $this->app->isLocal());
    }
}
