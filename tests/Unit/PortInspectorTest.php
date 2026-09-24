<?php

use Illuminate\Support\Facades\Process;
use Phattarachai\DbTunnel\Support\PortInspector;

it('parses lsof field output into listeners keyed by port', function () {
    config(['db-tunnel.probe' => 'lsof']);
    Process::fake([
        "'lsof' *" => Process::result("p191\ncphp-fpm\nf10\nn127.0.0.1:9000\np45334\ncssh\nf4\nn127.0.0.1:15441\nf5\nn[::1]:15441\n"),
    ]);

    $listeners = (new PortInspector)->listeners();

    expect($listeners->keys()->all())->toBe([9000, 15441])
        ->and($listeners[15441]->pid)->toBe(45334)
        ->and($listeners[15441]->isSsh())->toBeTrue()
        ->and($listeners[9000]->command)->toBe('php-fpm');
});

it('parses ss output, including sockets owned by other users', function () {
    config(['db-tunnel.probe' => 'ss']);
    Process::fake([
        "'ss' *" => Process::result(<<<'SS'
            LISTEN 0      128        127.0.0.1:15441      0.0.0.0:*    users:(("ssh",pid=1234,fd=5))
            LISTEN 0      4096               *:5432             *:*
            SS),
    ]);

    $listeners = (new PortInspector)->listeners();

    expect($listeners[15441]->command)->toBe('ssh')
        ->and($listeners[15441]->pid)->toBe(1234)
        ->and($listeners[5432]->command)->toBe('?');
});

it('attaches the process arguments to a single listener', function () {
    config(['db-tunnel.probe' => 'lsof']);
    Process::fake([
        "'lsof' *" => Process::result("p4242\ncssh\nn127.0.0.1:15441\n"),
        "'ps' *" => Process::result("ssh -f -N -L 127.0.0.1:15441:127.0.0.1:5432 qas\n"),
    ]);

    $listener = (new PortInspector)->listener(15441);

    expect($listener->isSshTo('qas'))->toBeTrue()
        ->and($listener->isSshTo('prod'))->toBeFalse();
});

it('returns null when nothing listens', function () {
    config(['db-tunnel.probe' => 'lsof']);
    Process::fake(["'lsof' *" => Process::result('', exitCode: 1)]);

    expect((new PortInspector)->listener(15441))->toBeNull();
});
