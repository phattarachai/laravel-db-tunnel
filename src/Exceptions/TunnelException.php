<?php

namespace Phattarachai\DbTunnel\Exceptions;

use Phattarachai\DbTunnel\Support\Listener;
use Phattarachai\DbTunnel\Support\Tunnel;
use RuntimeException;

class TunnelException extends RuntimeException
{
    public static function unknown(string $connection): self
    {
        return new self("No tunnel is defined for [{$connection}] in config/db-tunnel.php.");
    }

    public static function incomplete(string $connection): self
    {
        return new self("Tunnel [{$connection}] needs at least `alias` and `remote_port` in config/db-tunnel.php.");
    }

    public static function noPort(Tunnel $tunnel): self
    {
        return new self("Tunnel [{$tunnel->connection}] has no local port ({$tunnel->portEnv} is empty). Run `php artisan db:tunnel install {$tunnel->connection}`.");
    }

    public static function portHeld(Tunnel $tunnel, Listener $listener): self
    {
        return new self("Port {$tunnel->localPort} for [{$tunnel->connection}] is held by {$listener->describe()}. Run `php artisan db:tunnel doctor`.");
    }

    public static function sshFailed(Tunnel $tunnel, string $error): self
    {
        $error = trim($error) ?: 'no output';

        return new self("ssh {$tunnel->alias} failed for [{$tunnel->connection}]: {$error}. Debug with `ssh -v -N {$tunnel->alias}`.");
    }

    public static function neverListened(Tunnel $tunnel, int $seconds): self
    {
        return new self("ssh {$tunnel->alias} started, but nothing listened on 127.0.0.1:{$tunnel->localPort} within {$seconds}s. Debug with `ssh -v -N {$tunnel->alias}`.");
    }

    public static function portTaken(int $port, string $owners): self
    {
        return new self("Port {$port} is already taken: {$owners}.");
    }

    public static function rangeExhausted(int $from, int $to): self
    {
        return new self("No free port left in db-tunnel.port_range {$from}–{$to}.");
    }
}
