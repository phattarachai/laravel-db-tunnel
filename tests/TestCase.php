<?php

namespace Phattarachai\DbTunnel\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\DbTunnel\DbTunnelServiceProvider;
use Phattarachai\DbTunnel\Support\PortInspector;
use Phattarachai\DbTunnel\Tests\Fakes\FakePortInspector;

abstract class TestCase extends BaseTestCase
{
    protected string $sandbox;

    protected FakePortInspector $inspector;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/db-tunnel-'.bin2hex(random_bytes(4));
        mkdir("{$this->sandbox}/project/storage", 0777, true);
        mkdir("{$this->sandbox}/.ssh", 0777, true);
        touch("{$this->sandbox}/.ssh/config");
        touch("{$this->sandbox}/project/.env");

        parent::setUp();

        $this->inspector = new FakePortInspector;
        $this->app->instance(PortInspector::class, $this->inspector);
        $this->app->setBasePath("{$this->sandbox}/project");
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory($this->sandbox);
    }

    protected function getPackageProviders($app): array
    {
        return [DbTunnelServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('db-tunnel.ssh_config', "{$this->sandbox}/.ssh/config");
        $app['config']->set('db-tunnel.registry', "{$this->sandbox}/registry/ports.json");
        $app['config']->set('db-tunnel.tunnels', [
            'claude-qas' => ['alias' => 'qas', 'remote_port' => 5432],
        ]);
        $app['config']->set('database.connections.claude-qas', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 15441,
            'database' => 'dr_qas',
            'username' => 'claude',
            'password' => '',
        ]);
    }

    protected function sshConfig(string $contents): void
    {
        file_put_contents("{$this->sandbox}/.ssh/config", $contents);
    }

    protected function project(): string
    {
        return realpath("{$this->sandbox}/project");
    }
}
