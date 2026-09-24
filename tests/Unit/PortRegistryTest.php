<?php

use Phattarachai\DbTunnel\Support\PortRegistry;

it('creates the registry and keeps one port per project connection', function () {
    $registry = new PortRegistry("{$this->sandbox}/registry/ports.json");

    $registry->claim(15441, '/work/f11', 'claude-qas', 'qas');
    $registry->claim(15442, '/work/f11', 'claude-uat', 'uat');
    $registry->claim(15450, '/work/f11', 'claude-qas', 'qas');

    expect(array_keys($registry->all()))->toBe([15442, 15450])
        ->and($registry->portFor('/work/f11', 'claude-qas'))->toBe(15450)
        ->and($registry->portFor('/work/other', 'claude-qas'))->toBeNull()
        ->and(json_decode(file_get_contents($registry->path), true)['ports'])->toHaveKey('15450');
});

it('prunes entries whose project directory is gone', function () {
    $registry = new PortRegistry("{$this->sandbox}/registry/ports.json");
    $registry->claim(15441, $this->project(), 'claude-qas', 'qas');
    $registry->claim(15442, "{$this->sandbox}/deleted", 'claude-uat', 'uat');

    expect($registry->prune())->toBe([15442])
        ->and(array_keys($registry->all()))->toBe([15441]);
});
