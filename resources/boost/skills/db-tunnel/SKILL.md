---
name: db-tunnel
description: 'Use this skill whenever you need to look at data on a remote environment (production, UAT, QAS, staging, logs) in a project using `phattarachai/laravel-db-tunnel` — the `claude-*` database connections reach those databases only through an SSH tunnel, because their ports are closed to the internet. Covers querying them with the Boost `database-query` / `database-schema` tools (`database: claude-<env>`), what to do when a query fails with "connection refused" / "Connection timed out" / "no local port", opening and closing tunnels (`php artisan db:tunnel open|close|status`), fixing port collisions (`db:tunnel doctor`), and adding a new remote connection (`db:tunnel install`). Triggers on: "check production data", "query UAT", "investigate on QAS", "what does prod have for", "claude-prod", "claude-uat", "claude-qas", "db:tunnel", "tunnel is down", "SQLSTATE[08006]", "Connection refused on 127.0.0.1:154", or any read-only investigation against a non-local database.'
version: 2026.09.24.1
---

# Remote database access through SSH tunnels

In this project the `claude-*` connections in `config/database.php` point at `127.0.0.1:<port>`. Each port is
the local end of an SSH tunnel to a remote database. The tunnels are defined in `config/db-tunnel.php`,
keyed by connection name. Every `claude-*` role is **read-only** (SELECT only).

## Querying a remote environment

1. List what is available: `php artisan db:tunnel status`, or Boost's `database-connections`. `status` prints
   each connection, its local port, the SSH alias → remote port, and whether the tunnel is open.
2. Query with Boost's database tools, passing the connection name, e.g. `database-query` with
   `database: claude-prod`. In `APP_ENV=local` the tunnel **opens automatically** the first time the
   connection is used, so there is no need to open it first.
3. Treat it as production data:
   - SELECT only. The role cannot write, and you should never try to.
   - Always bound result sets (`LIMIT`, a narrow `WHERE`). Prefer aggregates to row dumps.
   - Avoid unindexed full scans on large tables. Check `database-schema` first.
   - Do not copy personal data into files, commits or chat beyond what the investigation needs.

## When a query fails

Run `php artisan db:tunnel open claude-<env>`. It prints the underlying cause.

| Symptom | Cause → fix |
|---|---|
| `Permission denied (publickey)` | Your SSH key is not on the box. Ask the user; access is granted outside this project. |
| `Could not resolve hostname <alias>` | The `Host <alias>` block is missing from `~/.ssh/config`. The command prints one to add; the user adds it. |
| `port … is held by <process>` | Another process owns the local port. Run `php artisan db:tunnel doctor`, then `db:tunnel install <conn>` to move to a free port. |
| `has no local port (…_DB_PORT is empty)` | Run `php artisan db:tunnel install <conn>`. |
| Timeout on a VPN-only host | The VPN is down. Ask the user to connect it. |

After `db:tunnel install` changes a port, **Boost must be restarted**: it read `config/database.php` at boot and
keeps using the old port. Ask the user to reconnect the Laravel Boost MCP server.

Tunnels stay open in the background after use. `php artisan db:tunnel close --all` closes them.

## Adding a remote connection

1. Add a `claude-<env>` connection to `config/database.php`:
   - host `127.0.0.1`
   - port from `<ENV>_DB_PORT` with **no default port and no default password**
   - username/password/database from `<ENV>_DB_*`
2. Add the tunnel to `config/db-tunnel.php`: `'claude-<env>' => ['alias' => '<ssh alias>', 'remote_port' => 5432]`.
3. Run `php artisan db:tunnel install claude-<env>`. It allocates a machine-wide free port, writes
   `<ENV>_DB_PORT` to `.env`, and adds an empty key to `.env.example`.
4. The read-only role on the server and the password in `.env` come from the user (the `remote-db-access`
   skill covers creating the role). Never invent credentials.

## The app's own database behind a tunnel

When the default connection itself is tunneled, `composer dev` runs `php artisan db:tunnel watch <conn>`, a
foreground supervisor that re-dials when the tunnel drops. The other panes gate on
`php artisan db:tunnel wait <conn>`. Such tunnels usually set `'auto_open' => false`. Do not open them by hand
while `composer dev` is running.
