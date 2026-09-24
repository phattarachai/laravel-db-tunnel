<?php

namespace Phattarachai\DbTunnel\Support;

final class Paths
{
    public static function home(): string
    {
        return rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME')), '/');
    }

    public static function sshConfig(): string
    {
        return config('db-tunnel.ssh_config') ?: self::home().'/.ssh/config';
    }

    public static function registry(): string
    {
        return config('db-tunnel.registry') ?: self::configHome().'/db-tunnel/ports.json';
    }

    public static function project(): string
    {
        return realpath(base_path()) ?: base_path();
    }

    private static function configHome(): string
    {
        return getenv('XDG_CONFIG_HOME') ?: self::home().'/.config';
    }
}
