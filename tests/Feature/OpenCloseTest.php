<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

function sshArguments(): array
{
    return ['ssh', '-f', '-N', '-o', 'BatchMode=yes', '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=8'];
}

it('opens a detached tunnel with -L over the login alias', function () {
    Process::fake(function () {
        $this->inspector->ssh(15441, 'qas');

        return Process::result();
    });

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('Open: claude-qas — 127.0.0.1:15441 → qas → 127.0.0.1:5432')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [...sshArguments(), '-L', '127.0.0.1:15441:127.0.0.1:5432', 'qas']);
});

it('leaves out -L when the alias already forwards that port', function () {
    $this->sshConfig("Host qas\n    LocalForward 15441 127.0.0.1:5432\n");
    Process::fake(function () {
        $this->inspector->ssh(15441, 'qas');

        return Process::result();
    });

    $this->artisan('db:tunnel open claude-qas')->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === [...sshArguments(), 'qas']);
});

it('does nothing when the tunnel is already open', function () {
    Process::fake();
    $this->inspector->ssh(15441, 'qas');

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('Already open')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('refuses to dial when another process holds the port', function () {
    Process::fake();
    $this->inspector->process(15441, 'postgres');

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('held by postgres (pid 777)')
        ->assertFailed();

    Process::assertNothingRan();
});

it('reports the ssh error and suggests a Host block for an unknown alias', function () {
    config(['db-tunnel.tunnels.claude-qas.host' => '203.0.113.5', 'db-tunnel.tunnels.claude-qas.user' => 'deploy']);
    Process::fake(fn () => Process::result(errorOutput: 'ssh: Could not resolve hostname qas', exitCode: 255));

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('Could not resolve hostname qas')
        ->expectsOutputToContain('HostName 203.0.113.5')
        ->assertFailed();
});

it('fails when ssh exits cleanly but nothing ever listens', function () {
    Process::fake();

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('nothing listened on 127.0.0.1:15441 within 10s')
        ->assertFailed();
});

it('needs a connection or --all to open', function () {
    $this->artisan('db:tunnel open')
        ->expectsOutputToContain('Pass a connection (claude-qas) or --all.')
        ->assertFailed();
});

it('opens every tunnel with --all and reports each failure', function () {
    config(['db-tunnel.tunnels.claude-uat' => ['alias' => 'uat', 'remote_port' => 5432]]);
    config(['database.connections.claude-uat' => ['driver' => 'pgsql', 'port' => 15442]]);
    $this->inspector->ssh(15441, 'qas');
    Process::fake(fn () => Process::result(errorOutput: 'Permission denied (publickey)', exitCode: 255));

    $this->artisan('db:tunnel open --all')
        ->expectsOutputToContain('Already open: claude-qas')
        ->expectsOutputToContain('Permission denied (publickey)')
        ->assertFailed();
});

it('closes by killing the ssh process that listens on the port', function () {
    Process::fake();
    $this->inspector->ssh(15441, 'qas', pid: 5150);

    $this->artisan('db:tunnel close claude-qas')
        ->expectsOutputToContain('Closed claude-qas')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['kill', '5150']);
});

it('never kills a listener that is not our tunnel', function () {
    Process::fake();
    $this->inspector->ssh(15441, 'someone-else');

    $this->artisan('db:tunnel close claude-qas')
        ->expectsOutputToContain('was not open')
        ->assertSuccessful();

    Process::assertNothingRan();
});

it('shows a status table and fails for a single closed tunnel', function () {
    $this->artisan('db:tunnel status')
        ->expectsTable(['Connection', 'Local', 'Remote', 'State'], [['claude-qas', '127.0.0.1:15441', 'qas → 127.0.0.1:5432', 'closed']])
        ->assertSuccessful();

    $this->artisan('db:tunnel status claude-qas')->assertFailed();

    $this->inspector->ssh(15441, 'qas');
    $this->artisan('db:tunnel status claude-qas')->assertSuccessful();
});

it('waits for the tunnel to listen', function () {
    $this->artisan('db:tunnel wait claude-qas --timeout=1')
        ->expectsOutputToContain('did not open on 127.0.0.1:15441 within 1s')
        ->assertFailed();

    $this->inspector->ssh(15441, 'qas');
    $this->artisan('db:tunnel wait claude-qas --timeout=1')->assertSuccessful();
});
