<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Phattarachai\DbTunnel\Supervisor;
use Phattarachai\DbTunnel\Support\Tunnels;

beforeEach(fn () => Sleep::fake());

function supervise(int $cycles): array
{
    $log = [];
    app(Supervisor::class)->run(app(Tunnels::class)->find('claude-qas'), function (string $line) use (&$log) {
        $log[] = $line;
    }, $cycles);

    return $log;
}

it('dials in the foreground, holds the tunnel, and re-dials when it drops', function () {
    Process::fake(function () {
        $this->inspector->ssh(15441, 'qas');

        return Process::describe()->runsFor(iterations: 3);
    });

    $log = supervise(cycles: 1);

    expect($log[0])->toContain('dialing qas for 127.0.0.1:15441')
        ->and($log[1])->toContain('up — 127.0.0.1:15441 → qas → 127.0.0.1:5432')
        ->and($log[2])->toContain('tunnel dropped');
    Process::assertRan(fn (PendingProcess $process) => ! in_array('-f', $process->command, true)
        && in_array('ControlMaster=no', $process->command, true)
        && in_array('ControlPath=none', $process->command, true)
        && end($process->command) === 'qas');
});

it('kills a dial whose handshake stalls past the establish timeout', function () {
    config(['db-tunnel.watch.establish_timeout' => 4]);
    Process::fake(fn () => Process::describe()->runsFor(iterations: 50));

    expect(supervise(cycles: 1)[1])->toContain('handshake with qas stalled');
});

it('reports why ssh exited before forwarding', function () {
    Process::fake(fn () => Process::describe()->errorOutput('Connection refused')->exitCode(255));

    expect(supervise(cycles: 1)[1])->toContain('ssh qas exited: Connection refused');
});

it('idles while another tunnel already serves the port', function () {
    Process::fake();
    $this->inspector->ssh(15441, 'qas');

    expect(supervise(cycles: 2))->toBeEmpty();
    Process::assertNothingRan();
});
