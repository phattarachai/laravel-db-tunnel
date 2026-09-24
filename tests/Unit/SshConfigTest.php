<?php

use Phattarachai\DbTunnel\Support\SshConfig;

it('finds hosts by exact alias token', function () {
    $this->sshConfig(<<<'SSH'
        Host qas os-qas erp-qas
            HostName 203.0.113.5

        Host qas-db-extra
            HostName 203.0.113.6
        SSH);

    $config = new SshConfig("{$this->sandbox}/.ssh/config");

    expect($config->defines('qas'))->toBeTrue()
        ->and($config->defines('erp-qas'))->toBeTrue()
        ->and($config->defines('qas-db'))->toBeFalse();
});

it('reads LocalForward ports per host, ignoring comments and case', function () {
    $this->sshConfig(<<<'SSH'
        Host f11dr-logs
            localforward 15433 127.0.0.1:5433   # logs replica
        Host nectapharma-dbtunnel
            LocalForward=127.0.0.1:15433 127.0.0.1:5433
        # Host commented
        #   LocalForward 9999 127.0.0.1:1
        Match host foo
            LocalForward 16000 127.0.0.1:1
        SSH);

    $config = new SshConfig("{$this->sandbox}/.ssh/config");

    expect($config->forwardsOf('f11dr-logs'))->toBe([15433])
        ->and($config->localForwards()->pluck('port')->all())->toBe([15433, 15433, 16000])
        ->and($config->localForwards()->pluck('alias')->all())->toBe(['f11dr-logs', 'nectapharma-dbtunnel', '(Match block)']);
});

it('follows Include relative to the config directory', function () {
    mkdir("{$this->sandbox}/.ssh/conf.d");
    file_put_contents("{$this->sandbox}/.ssh/conf.d/work.conf", "Host mjreport\n    LocalForward 3307 127.0.0.1:3306\n");
    $this->sshConfig("Include conf.d/*.conf\n\nHost prod\n    HostName 203.0.113.9\n");

    $config = new SshConfig("{$this->sandbox}/.ssh/config");

    expect($config->defines('mjreport'))->toBeTrue()
        ->and($config->defines('prod'))->toBeTrue()
        ->and($config->forwardsOf('mjreport'))->toBe([3307]);
});

it('treats a missing config as empty', function () {
    $config = new SshConfig("{$this->sandbox}/nope");

    expect($config->defines('qas'))->toBeFalse()
        ->and($config->localForwards())->toBeEmpty();
});
