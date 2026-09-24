<?php

use Phattarachai\DbTunnel\Exceptions\TunnelException;
use Phattarachai\DbTunnel\Support\Tunnels;

it('builds a tunnel from its definition and the connection port', function () {
    $tunnel = app(Tunnels::class)->find('claude-qas');

    expect($tunnel->alias)->toBe('qas')
        ->and($tunnel->localPort)->toBe(15441)
        ->and($tunnel->forward())->toBe('127.0.0.1:15441:127.0.0.1:5432')
        ->and($tunnel->portEnv)->toBe('QAS_DB_PORT')
        ->and($tunnel->autoOpen)->toBeTrue();
});

it('derives the port env from the connection name', function (string $connection, string $expected) {
    config(["db-tunnel.tunnels.{$connection}" => ['alias' => 'x', 'remote_port' => 5432]]);

    expect(app(Tunnels::class)->find($connection)->portEnv)->toBe($expected);
})->with([
    ['claude-prod', 'PROD_DB_PORT'],
    ['claude-prod-logs', 'PROD_LOGS_DB_PORT'],
    ['reporting', 'REPORTING_DB_PORT'],
    ['testing', 'DB_PORT'],
]);

it('honours an explicit port_env', function () {
    config(['db-tunnel.tunnels.claude-logs' => ['alias' => 'f11dr-logs', 'remote_port' => 5433, 'port_env' => 'PROD_LOGS_DB_PORT']]);

    expect(app(Tunnels::class)->find('claude-logs')->portEnv)->toBe('PROD_LOGS_DB_PORT');
});

it('rejects unknown and incomplete tunnels', function () {
    config(['db-tunnel.tunnels.broken' => ['alias' => 'x']]);

    expect(fn () => app(Tunnels::class)->find('nope'))->toThrow(TunnelException::class, 'No tunnel is defined for [nope]')
        ->and(fn () => app(Tunnels::class)->find('broken'))->toThrow(TunnelException::class, 'needs at least');
});
