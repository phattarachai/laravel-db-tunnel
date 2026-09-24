<?php

use Phattarachai\DbTunnel\Support\EnvFile;

it('replaces an existing key in place', function () {
    file_put_contents("{$this->sandbox}/.env", "APP_NAME=x\nQAS_DB_PORT=5432\nQAS_DB_PORT_OTHER=1\n");

    (new EnvFile("{$this->sandbox}/.env"))->set('QAS_DB_PORT', '15441');

    expect(file_get_contents("{$this->sandbox}/.env"))->toBe("APP_NAME=x\nQAS_DB_PORT=15441\nQAS_DB_PORT_OTHER=1\n");
});

it('appends a missing key, adding the trailing newline first', function () {
    file_put_contents("{$this->sandbox}/.env", 'APP_NAME=x');

    (new EnvFile("{$this->sandbox}/.env"))->set('QAS_DB_PORT', '15441');

    expect(file_get_contents("{$this->sandbox}/.env"))->toBe("APP_NAME=x\nQAS_DB_PORT=15441\n");
});

it('ensures a key only in an existing file that lacks it', function () {
    file_put_contents("{$this->sandbox}/.env.example", "QAS_DB_PORT=\n");
    $example = new EnvFile("{$this->sandbox}/.env.example");

    expect($example->ensure('QAS_DB_PORT'))->toBeFalse()
        ->and($example->ensure('UAT_DB_PORT'))->toBeTrue()
        ->and((new EnvFile("{$this->sandbox}/missing"))->ensure('X'))->toBeFalse()
        ->and(file_get_contents("{$this->sandbox}/.env.example"))->toBe("QAS_DB_PORT=\nUAT_DB_PORT=\n");
});
