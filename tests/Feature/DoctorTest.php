<?php

use Phattarachai\DbTunnel\Support\PortRegistry;

it('is green for an installed tunnel', function () {
    $this->sshConfig("Host qas\n    HostName 203.0.113.5\n");
    $this->artisan('db:tunnel install claude-qas');

    $this->artisan('db:tunnel doctor')
        ->expectsOutputToContain('127.0.0.1:15441 → qas → 127.0.0.1:5432')
        ->expectsOutputToContain('No port collisions')
        ->assertSuccessful();
});

it('reports ports claimed by two ssh aliases on this machine', function () {
    $this->sshConfig(<<<'SSH'
        Host qas
        Host f11dr-logs
            LocalForward 15433 127.0.0.1:5433
        Host nectapharma-dbtunnel
            LocalForward 15433 127.0.0.1:5433
        SSH);
    $this->artisan('db:tunnel install claude-qas');

    $this->artisan('db:tunnel doctor')
        ->expectsOutputToContain('port 15433 claimed by Host f11dr-logs (ssh config), Host nectapharma-dbtunnel (ssh config)')
        ->assertFailed();
});

it('flags an unregistered port, a missing alias and a foreign listener', function () {
    $this->inspector->process(15441, 'postgres');

    $this->artisan('db:tunnel doctor')
        ->expectsOutputToContain('not in the machine registry')
        ->expectsOutputToContain('postgres (pid 777) (listener)')
        ->expectsOutputToContain('no `Host qas`')
        ->assertFailed();
});

it('flags a tunnel with no port and a login alias with its own LocalForward', function () {
    config(['db-tunnel.tunnels.mj' => ['alias' => 'mjreport', 'remote_port' => 3306]]);
    config(['database.connections.mj' => ['driver' => 'mysql', 'port' => 15445]]);
    config(['database.connections.claude-qas.port' => null]);
    $this->sshConfig("Host mjreport\n    LocalForward 3307 127.0.0.1:3306\n");

    $this->artisan('db:tunnel doctor')
        ->expectsOutputToContain('QAS_DB_PORT is not set')
        ->expectsOutputToContain('`Host mjreport` also forwards 3307')
        ->assertFailed();
});

it('prunes registry entries of deleted projects', function () {
    app(PortRegistry::class)->claim(15470, "{$this->sandbox}/gone", 'claude-prod', 'prod');

    $this->artisan('db:tunnel doctor')->expectsOutputToContain('no longer exists');
    $this->artisan('db:tunnel doctor --prune')->expectsOutputToContain('Pruned stale registry ports: 15470');

    expect(app(PortRegistry::class)->all())->toBeEmpty();
});

it('accepts a dedicated alias that forwards several of the project tunnels', function () {
    config(['db-tunnel.tunnels' => [
        'claude-prod' => ['alias' => 'f11dr-prod-db', 'remote_port' => 5432],
        'claude-logs' => ['alias' => 'f11dr-prod-db', 'remote_port' => 5433, 'port_env' => 'PROD_LOGS_DB_PORT'],
    ]]);
    config(['database.connections.claude-prod' => ['driver' => 'pgsql', 'port' => 15441]]);
    config(['database.connections.claude-logs' => ['driver' => 'pgsql', 'port' => 15442]]);
    $this->sshConfig("Host f11dr-prod-db\n    LocalForward 15441 127.0.0.1:5432\n    LocalForward 15442 127.0.0.1:5433\n");
    $this->artisan('db:tunnel install');

    $this->artisan('db:tunnel doctor')
        ->doesntExpectOutputToContain('also forwards')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.env')))->toBe("PROD_DB_PORT=15441\nPROD_LOGS_DB_PORT=15442\n");
});
