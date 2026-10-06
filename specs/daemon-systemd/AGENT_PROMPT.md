Implement SPEC `specs/daemon-systemd/spec.md` exactly. GitHub issue #156.

You are a local Ollama coding agent. Pest first. Allowlist only.

Do:
1. Read `specs/daemon-systemd/spec.md` and existing `app/Commands/DaemonCommand.php`, `app/Commands/DaemonSetupCommand.php`, `app/Services/Daemon/*`, `tests/Feature/DaemonCommandTest.php`, `tests/Feature/DaemonSetupCommandTest.php`.
2. Write failing Pest tests for every numbered case in the SPEC Tests section (Linux user unit including start limit; playing + no spotifyd sink-input → degraded; `--heal` restarts the user unit; setup is pacman/omarchy not the Debian installer; Linux pulseaudio conf writer; pulseaudio not rewritten to rodio; drop an existing ALSA hardware device pin; `DeviceNotAvailable` heal rewrites backend then restarts the unit).
3. Implement until this is green: `php -d disable_functions=pcntl_fork vendor/bin/pest --filter='Daemon'`
4. Stop. Do not commit unless that filter is green. Do not push.

Locked:
- `PartOf=` pipewire, not a bind that dies with the bus.
- `Restart=always` / `RestartSec=2` / `StartLimitBurst=5` / `StartLimitIntervalSec=60`.
- Unit `~/.config/systemd/user/spotifyd.service`.
- ExecStart uses `Provision::findSpotifyd()` plus `--config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery`.
- Linux conf: `backend = "pulseaudio"`. Never write an ALSA hardware device pin. start/heal must not clobber pulseaudio back to rodio. `DeviceNotAvailable` → rewrite pulseaudio, then restart the user unit.
- Never bounce PipeWire in tests. Never the Debian installer.

Do not: a second player, catalog, HTTP API, USB hardware ids, macOS LaunchAgent rewrite, README, CHANGELOG, docs/launch.md.
