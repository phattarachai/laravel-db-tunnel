# Laravel DB Tunnel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/laravel-db-tunnel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-db-tunnel)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-db-tunnel/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/laravel-db-tunnel/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-db-tunnel/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/laravel-db-tunnel/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/laravel-db-tunnel/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/laravel-db-tunnel)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/laravel-db-tunnel.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-db-tunnel)

Reach remote databases (production, UAT, QAS) from your local Laravel app **through SSH**, so their ports never
have to be open to the internet.

The main use case is giving [Laravel Boost](https://github.com/laravel/boost) read-only `claude-*` connections, so
an AI agent can investigate real data on remote environments. It also handles an app whose own database lives
behind a tunnel.

- **Machine-wide port allocation.** Every project on your machine gets its own stable local port.
  `db:tunnel install` picks one that no other project, `~/.ssh/config` `LocalForward` or running process holds.
- **Opens on first use.** In `local`, resolving a tunneled connection opens its tunnel automatically. Boost's
  `database-query` just works.
- **Uses the SSH login you already have.** The tunnel is `ssh -f -N -L 127.0.0.1:<port>:127.0.0.1:<remote> <alias>`
  over your existing `Host` alias. You don't need a per-tunnel alias.
- **Supervised mode** for an app's primary database: `db:tunnel watch` re-dials dropped and stalled connections
  inside `composer dev`.
- **Ships a Laravel Boost skill**, so the agent in every project that installs this knows how to use it.

| Command | Purpose |
|---|---|
| `db:tunnel status [conn]` | A table of every tunnel: local port, SSH alias → remote, open / closed / port held by another process. |
| `db:tunnel open <conn>` · `open --all` | Open detached tunnels and wait until they listen. |
| `db:tunnel close <conn>` · `close --all` | Kill the ssh process listening on the tunnel's port, and nothing else. |
| `db:tunnel install [conn]` | Allocate a machine-wide free port and write `<ENV>_DB_PORT` to `.env`. |
| `db:tunnel doctor [--prune]` | Report port collisions across projects, `~/.ssh/config` and listening sockets, plus per-tunnel problems. |
| `db:tunnel watch <conn>` | Foreground supervisor: dial, watch, re-dial. For `composer dev`. |
| `db:tunnel wait <conn> [--timeout=60]` | Block until the tunnel listens. Use it to gate `serve` / `queue` panes. |

## Requirements

- PHP 8.3+, Laravel 12 or 13
- macOS, Linux or WSL2 with OpenSSH
- `lsof` (macOS) or `ss` (Linux/WSL2), to see which process listens on a port
- SSH key access to the database host. Tunnels run with `BatchMode=yes`, so they never prompt.

## Installation

```bash
composer require --dev phattarachai/laravel-db-tunnel
php artisan vendor:publish --tag=db-tunnel-config
```

With Laravel Boost installed, run `php artisan boost:update` (or `boost:install`) and select this package to get its
agent skill.

## Quick start: a read-only production connection for Boost

**1. Add a connection** in `config/database.php`. The port is machine-specific, so it has no default. Neither
does the password.

```php
'claude-prod' => [
    'driver' => 'pgsql',
    'host' => '127.0.0.1',
    'port' => env('PROD_DB_PORT'),
    'database' => env('PROD_DB_DATABASE'),
    'username' => env('PROD_DB_USERNAME', 'claude-prod'),
    'password' => env('PROD_DB_PASSWORD'),
    'sslmode' => 'prefer',
],
```

**2. Describe the tunnel** in `config/db-tunnel.php`, keyed by the same connection name:

```php
'tunnels' => [
    'claude-prod' => [
        'alias' => 'prod',          // the ~/.ssh/config Host you already log in with
        'remote_port' => 5432,      // the database port on that box
    ],
],
```

**3. Allocate a port:**

```bash
php artisan db:tunnel install claude-prod
```

```
INFO  claude-prod: 127.0.0.1:15441 → prod → 127.0.0.1:5432 (PROD_DB_PORT=15441 in .env).
```

**4. Query.** `DB::connection('claude-prod')`, `php artisan db:show --database=claude-prod`, or Boost's
`database-query` with `database: claude-prod` all open the tunnel on first use. Check with
`php artisan db:tunnel status`.

## How it works

### Ports

The local end of each tunnel is the connection's own `port`. That keeps `config/database.php` as the single source
of truth, which matters because tools such as the Boost MCP server read it once at boot.

`db:tunnel install` picks the port once and keeps it stable. It considers a port taken when any of these hold it:

- another project in the machine registry, `~/.config/db-tunnel/ports.json` (or `$XDG_CONFIG_HOME/db-tunnel`)
- a `LocalForward` in `~/.ssh/config`, including `Include`d files, under a different alias
- a process listening on it right now

Install then decides in this order:
1. It reuses the port already registered for this project and connection.
2. Otherwise it adopts the current `.env` port, if that port is inside `port_range` (default `15440–15999`) and free.
3. Otherwise it takes the first free port in the range.

If a registered port has since been taken by someone else, install moves the connection to a fresh port. Pin a
port with `--port=15444`.

`.env.example` gets an empty `<ENV>_DB_PORT=`, because each machine allocates its own.

### Opening and closing

`open` runs:

```
ssh -f -N -o BatchMode=yes -o ExitOnForwardFailure=yes -o ControlMaster=no -o ControlPath=none \
    -o ServerAliveInterval=15 -o ServerAliveCountMax=8 -L 127.0.0.1:<port>:<remote_host>:<remote_port> <alias>
```

It then waits until the port is actually listening. If the alias already declares a `LocalForward` for that port,
`-L` is left out and plain `ssh -f -N <alias>` is used, so dedicated tunnel aliases keep working.

`ControlMaster=no` and `ControlPath=none` keep the tunnel out of SSH connection multiplexing. Without them, an alias
with `ControlMaster auto` and a live master connection would hand the `-L` forward to that master process and exit, so
the port would be held by `ssh: … [mux]` instead of a tunnel `db:tunnel` recognises. The tunnel always gets its own
connection, whatever the alias sets; your interactive `ssh <alias>` sessions keep multiplexing as before.

Tunnel state comes from the process that **listens** on the port (`lsof`/`ss`), not from a connect probe. A connect
probe such as `nc -z` reports ports open on WSL2 that nothing is listening on. The listener check tells three cases
apart:
- the tunnel is open: an ssh process to the right alias listens on the port
- the tunnel is closed: nothing listens
- the port is held by something else, e.g. a local Postgres

`close` kills only an ssh listener to the right alias.

### Auto-open

When `db-tunnel.auto_open` is `null` (the default), tunnels open automatically in `APP_ENV=local`. The package
registers a connection resolver for each tunneled connection. That resolver opens the tunnel, if needed, the moment
Laravel resolves the connection. A failure throws a `TunnelException` that carries ssh's own error message.

Set `'auto_open' => false` on a tunnel, or `DB_TUNNEL_AUTO_OPEN=false`, to turn this off.

## The app's own database behind a tunnel

When the default connection itself goes through SSH, supervise the tunnel inside `composer dev` rather than
detaching it. Also set `'auto_open' => false` on that tunnel.

```json
"dev": [
    "Composer\\Config::disableProcessTimeout",
    "npx concurrently -c \"#fdba74,#93c5fd,#c4b5fd\" \"php artisan db:tunnel watch mysql\" \"php artisan db:tunnel wait mysql && php artisan serve\" \"php artisan db:tunnel wait mysql && php artisan queue:listen --tries=1\" --names=tunnel,server,queue --kill-others"
]
```

`watch` does the following:
- dials in the foreground
- kills a dial that hasn't forwarded within `watch.establish_timeout` (90s), which covers networks whose SSH
  handshake stalls
- re-dials after the connection drops
- idles when another tunnel already serves the port
- takes its ssh down on SIGINT/SIGTERM

Anything network-specific, such as bringing a VPN up first, stays in the project's own dev script.

## A read-only role on the server

The `claude-*` role should only ever SELECT. Tunnelled connections arrive from the server's own loopback.

**PostgreSQL:**

```sql
CREATE ROLE "claude-prod" LOGIN PASSWORD '<generated>';
GRANT CONNECT ON DATABASE app TO "claude-prod";
GRANT USAGE ON SCHEMA public TO "claude-prod";
GRANT SELECT ON ALL TABLES IN SCHEMA public TO "claude-prod";
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO "claude-prod";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO "claude-prod";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON SEQUENCES TO "claude-prod";
```

**MySQL / MariaDB:**

```sql
CREATE USER 'claude-prod'@'127.0.0.1' IDENTIFIED BY '<generated>';
GRANT SELECT ON app.* TO 'claude-prod'@'127.0.0.1';
```

Keep the password in `.env` only. Never put it in an `env()` default in committed config.

## Configuration

```php
return [
    'tunnels' => [
        'claude-qas' => [
            'alias' => 'qas',             // required: the ~/.ssh/config Host
            'remote_port' => 5432,        // required
            'remote_host' => '127.0.0.1', // where the DB listens, as seen from the box
            'port_env' => 'QAS_DB_PORT',  // default: DB_PORT for the default connection, else <NAME>_DB_PORT
                                          //          with a `claude-` prefix dropped
            'auto_open' => true,
            'host' => '203.0.113.5',      // only used to print a Host block when the alias is missing
            'user' => 'deploy',
        ],
    ],
    'auto_open' => env('DB_TUNNEL_AUTO_OPEN'),    // null → local only
    'port_range' => [15440, 15999],
    'registry' => env('DB_TUNNEL_REGISTRY'),      // null → ~/.config/db-tunnel/ports.json
    'ssh_config' => env('DB_TUNNEL_SSH_CONFIG'),  // null → ~/.ssh/config
    'probe' => env('DB_TUNNEL_PROBE', 'auto'),    // auto | lsof | ss
    'connect_timeout' => 30,                      // seconds before a detached dial is abandoned
    'wait' => 10,                                 // seconds `open` waits for the port to listen
    'keepalive' => ['interval' => 15, 'count_max' => 8],
    'watch' => ['establish_timeout' => 90, 'redial_delay' => 3],
];
```

If the alias is missing from `~/.ssh/config`, `open` and `install` print a login block to add:

```
Host qas
    HostName 203.0.113.5
    User deploy
    IdentityFile ~/.ssh/id_ed25519
    IdentitiesOnly yes
    StrictHostKeyChecking accept-new
    ServerAliveInterval 15
    ServerAliveCountMax 8
```

## Testing

```bash
composer test
```

## Changelog

See the [Releases page](https://github.com/phattarachai/laravel-db-tunnel/releases).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
