# Changelog

All notable changes to `phattarachai/laravel-db-tunnel` are documented here.

Release notes are drafted automatically from merged pull requests and published on the
[Releases page](https://github.com/phattarachai/laravel-db-tunnel/releases) — that page is the authoritative log.

This file records anything released before that automation landed.

## v1.0.1 — 2026-09-24

### Fixed
- Tunnels now pass `-o ControlMaster=no -o ControlPath=none`, so an alias with `ControlMaster auto` and a live master
  connection no longer swallows the `-L` forward into the master (`ssh: … [mux]`). Before, `open` failed with
  "never started listening" and `status` reported the port as held by the mux process. Applies to both `open` and
  `watch`.
