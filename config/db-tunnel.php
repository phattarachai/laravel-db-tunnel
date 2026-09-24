<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tunnels
    |--------------------------------------------------------------------------
    |
    | One entry per database connection that is reached through SSH, keyed by
    | the connection name in config/database.php. The local end of the tunnel
    | is that connection's `port` — machine-specific, so it lives in .env and
    | `php artisan db:tunnel install` picks it for you.
    |
    | alias        The ~/.ssh/config Host you already log in with.
    | remote_port  The database port as seen from the SSH box.
    | remote_host  Where the database listens from the box (default 127.0.0.1).
    | port_env     The .env key holding the local port. Defaults to DB_PORT for
    |              the default connection, otherwise <NAME>_DB_PORT with any
    |              `claude-` prefix dropped (claude-qas → QAS_DB_PORT).
    | auto_open    Open the tunnel the first time the connection is used.
    | host, user   Only used to print a Host block when `alias` is missing.
    |
    */

    'tunnels' => [
        // 'claude-prod' => [
        //     'alias' => 'prod',
        //     'remote_port' => 5432,
        // ],
    ],

    /*
    | Open a tunnel automatically when its connection is first used. null
    | means "only when APP_ENV=local".
    */
    'auto_open' => env('DB_TUNNEL_AUTO_OPEN'),

    /*
    | `install` hands out the first port in this range that no other project,
    | ~/.ssh/config LocalForward or listening process already holds.
    */
    'port_range' => [15440, 15999],

    /*
    | The machine-wide port registry shared by every project on this machine.
    | null → $XDG_CONFIG_HOME/db-tunnel/ports.json (~/.config/db-tunnel/ports.json).
    */
    'registry' => env('DB_TUNNEL_REGISTRY'),

    /*
    | null → ~/.ssh/config
    */
    'ssh_config' => env('DB_TUNNEL_SSH_CONFIG'),

    /*
    | How listening ports are found: lsof (macOS) or ss (Linux, WSL2). `auto`
    | picks by OS. A plain connect probe (nc -z) is not used — on WSL2 it
    | reports ports as open that nothing is listening on.
    */
    'probe' => env('DB_TUNNEL_PROBE', 'auto'),

    'connect_timeout' => 30,

    'wait' => 10,

    'keepalive' => [
        'interval' => 15,
        'count_max' => 8,
    ],

    /*
    | `db:tunnel watch` — the foreground supervisor for a tunnel that carries
    | the app's own database. A dial that has not forwarded within
    | establish_timeout seconds is killed and re-dialed.
    */
    'watch' => [
        'establish_timeout' => 90,
        'redial_delay' => 3,
    ],

];
