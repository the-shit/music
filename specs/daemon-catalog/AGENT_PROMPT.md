Implement SPEC `specs/daemon-catalog/spec.md` exactly. GitHub issue https://github.com/the-shit/music/issues/162

You are a local Ollama coding agent. Pest first. Allowlist only.

Do:
1. Read `specs/daemon-catalog/spec.md`, `specs/daemon-systemd/spec.md`, `app/Commands/DaemonCommand.php`, `app/Commands/DaemonSetupCommand.php`, `app/Services/Daemon/*`, `tests/Feature/DaemonCommandTest.php`, `tests/Feature/DaemonSetupCommandTest.php`.
2. `spotify daemon` with no args exits 0 and prints a catalog of `setup`, `start`, `stop`, `status`, `health`, `install`, `uninstall` with one-line descriptions. Fold `daemon:setup` into `daemon setup` (hidden alias ok).
3. Wrap spotifyd: no `passthru` of `spotifyd authenticate`. Success is `~/.config/spotify-cli/cache/oauth/credentials.json` existing and non-empty, not `cache/credentials.json`.
4. Linux `install` writes the user unit from `specs/daemon-systemd/spec.md`. Linux package path is `omarchy pkg add spotifyd` / `pacman`, never `apt`.
5. Implement until `php -d disable_functions=pcntl_fork vendor/bin/pest --filter='Daemon'` is green.
6. Stop. Do not merge. Do not push unless ship.sh does. Do not rewrite in Rust.

Locked: wrap, catalog, oauth blob path, PartOf= not BindsTo=, never apt.

Do not: Electron, HTTP API, Scarlett/USB ids, macOS LaunchAgent rewrite, README, CHANGELOG, docs/launch.md, crates/, Cargo.toml.
