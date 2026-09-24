<?php

use Phattarachai\DbTunnel\Support\PortRegistry;

function registry(): PortRegistry
{
    return app(PortRegistry::class);
}

it('adopts the current port when it is in range and free', function () {
    file_put_contents(base_path('.env.example'), "APP_NAME=x\n");

    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('claude-qas: 127.0.0.1:15441')
        ->expectsOutputToContain('Added an empty QAS_DB_PORT= to .env.example')
        ->assertSuccessful();

    expect(file_get_contents(base_path('.env')))->toBe("QAS_DB_PORT=15441\n")
        ->and(file_get_contents(base_path('.env.example')))->toBe("APP_NAME=x\nQAS_DB_PORT=\n")
        ->and(registry()->portFor($this->project(), 'claude-qas'))->toBe(15441);
});

it('moves a legacy out-of-range port to the first free port', function () {
    config(['database.connections.claude-qas.port' => 15432]);
    registry()->claim(15440, '/work/other', 'claude-prod', 'prod');
    $this->sshConfig("Host f11dr-logs\n    LocalForward 15441 127.0.0.1:5433\n");
    $this->inspector->process(15442, 'postgres');

    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('claude-qas: 127.0.0.1:15443')
        ->expectsOutputToContain('Restart Laravel Boost')
        ->assertSuccessful();

    expect(config('database.connections.claude-qas.port'))->toBe(15443);
});

it('keeps a registered port stable across installs', function () {
    config(['database.connections.claude-qas.port' => 0]);
    registry()->claim(15460, $this->project(), 'claude-qas', 'qas');

    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('127.0.0.1:15460')
        ->assertSuccessful();
});

it('re-allocates a registered port that someone else has since taken', function () {
    registry()->claim(15441, $this->project(), 'claude-qas', 'qas');
    $this->inspector->process(15441, 'postgres');

    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('127.0.0.1:15440')
        ->assertSuccessful();

    expect(registry()->portFor($this->project(), 'claude-qas'))->toBe(15440)
        ->and(registry()->all())->not->toHaveKey(15441);
});

it('does not count our own open tunnel as a collision', function () {
    $this->inspector->ssh(15441, 'qas');

    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('127.0.0.1:15441')
        ->doesntExpectOutputToContain('Restart Laravel Boost')
        ->assertSuccessful();
});

it('claims an explicit --port, or explains who holds it', function () {
    registry()->claim(15450, '/work/other', 'claude-prod', 'prod');

    $this->artisan('db:tunnel install claude-qas --port=15450')
        ->expectsOutputToContain('Port 15450 is already taken: other:claude-prod (registry)')
        ->assertFailed();

    $this->artisan('db:tunnel install claude-qas --port=15444')->assertSuccessful();

    expect(registry()->portFor($this->project(), 'claude-qas'))->toBe(15444);
});

it('prints a login Host block when the alias is missing', function () {
    config(['db-tunnel.tunnels.claude-qas.host' => '203.0.113.5']);

    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('`Host qas` is not in')
        ->expectsOutputToContain('HostName 203.0.113.5')
        ->assertSuccessful();

    $this->sshConfig("Host qas\n    HostName 203.0.113.5\n");

    $this->artisan('db:tunnel install claude-qas')
        ->doesntExpectOutputToContain('is not in')
        ->assertSuccessful();
});
