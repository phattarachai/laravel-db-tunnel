<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Phattarachai\DbTunnel\Exceptions\TunnelException;
use Phattarachai\DbTunnel\Support\Tunnels;

beforeEach(function () {
    Sleep::fake();
    touch("{$this->sandbox}/.ssh/google_compute_engine");
    config([
        'db-tunnel.gcp_iap.identity_file' => "{$this->sandbox}/.ssh/google_compute_engine",
        'db-tunnel.tunnels.claude-qas' => [
            'remote_port' => 5432,
            'gcp_iap' => ['instance' => 'qas-backend-db', 'project' => 'acme', 'zone' => 'asia-southeast1-b'],
        ],
    ]);
});

function iapProxyCommand(): string
{
    return 'ProxyCommand=gcloud compute start-iap-tunnel qas-backend-db 22 --listen-on-stdin --project=acme --zone=asia-southeast1-b --verbosity=error';
}

it('defaults the alias to the instance and dials through an IAP ProxyCommand with the gcloud key', function () {
    Process::fake(function () {
        $this->inspector->ssh(15441, 'qas-backend-db');

        return Process::result();
    });

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('Open: claude-qas — 127.0.0.1:15441 → qas-backend-db → 127.0.0.1:5432')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => array_slice($process->command, -9) === [
        '-o', iapProxyCommand(),
        '-o', "IdentityFile={$this->sandbox}/.ssh/google_compute_engine",
        '-o', 'StrictHostKeyChecking=accept-new',
        '-L', '127.0.0.1:15441:127.0.0.1:5432',
        'qas-backend-db',
    ]);
});

it('keeps an explicit alias as the ssh target', function () {
    config(['db-tunnel.tunnels.claude-qas.alias' => 'f11dr-qas-db']);

    expect(app(Tunnels::class)->find('claude-qas')->alias)->toBe('f11dr-qas-db');
});

it('rejects gcp_iap without an instance', function () {
    config(['db-tunnel.tunnels.claude-qas.gcp_iap' => ['project' => 'acme']]);

    expect(fn () => app(Tunnels::class)->find('claude-qas'))->toThrow(TunnelException::class, 'without an `instance`');
});

it('passes arbitrary ssh_options through as -o flags', function () {
    config(['db-tunnel.tunnels.claude-uat' => ['alias' => 'uat', 'remote_port' => 5432, 'ssh_options' => ['ProxyJump' => 'bastion']]]);

    expect(app(Tunnels::class)->find('claude-uat')->sshOptions())->toBe(['-o', 'ProxyJump=bastion']);
});

it('points at gcloud auth login when the gcloud token has expired', function () {
    Process::fake(fn () => Process::result(errorOutput: 'ERROR: (gcloud.compute.start-iap-tunnel) There was a problem refreshing your current auth tokens: Reauthentication failed.', exitCode: 255));

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('run `gcloud auth login`')
        ->doesntExpectOutputToContain('HostName')
        ->assertFailed();
});

it('points at the one-time gcloud login when the key is not on the instance', function () {
    Process::fake(fn () => Process::result(errorOutput: 'phatchai@qas-backend-db: Permission denied (publickey).', exitCode: 255));

    $this->artisan('db:tunnel open claude-qas')
        ->expectsOutputToContain('run `gcloud compute ssh qas-backend-db --project=acme --zone=asia-southeast1-b --tunnel-through-iap` once')
        ->assertFailed();
});

it('needs no Host block and checks the gcloud key and credentials instead', function () {
    Process::fake(['*' => Process::result('ya29.token')]);
    $this->artisan('db:tunnel install claude-qas')
        ->expectsOutputToContain('needs no Host block')
        ->expectsOutputToContain('ProxyCommand gcloud compute start-iap-tunnel qas-backend-db 22');

    $this->artisan('db:tunnel doctor')
        ->doesntExpectOutputToContain('no `Host')
        ->expectsOutputToContain('127.0.0.1:15441 → qas-backend-db → 127.0.0.1:5432')
        ->assertSuccessful();
});

it('fails the doctor without a gcloud key or credentials', function () {
    unlink("{$this->sandbox}/.ssh/google_compute_engine");
    Process::fake(['*' => Process::result(errorOutput: 'ERROR: Reauthentication failed.', exitCode: 1)]);
    $this->artisan('db:tunnel install claude-qas');

    $this->artisan('db:tunnel doctor')
        ->expectsOutputToContain('no gcloud SSH key')
        ->expectsOutputToContain('gcloud has no usable credentials (ERROR: Reauthentication failed.)')
        ->assertFailed();
});
