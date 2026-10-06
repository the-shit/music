# SPEC: Arch systemd user unit so spotifyd survives PipeWire bounce

Status: **LOCKED**
Issue: https://github.com/the-shit/music/issues/156
Repo: `the-shit/music`

**Story:** On Arch (Thor / Omarchy) you should not need the Spotify desktop app. `spotify daemon install` must write a **user** unit against `~/.config/spotify-cli/spotifyd.conf` that respawns when PipeWire restarts. Connect "playing" is not a live sink-input. After a PipeWire bounce the process can still be up while speakers are dead.

## Already shipped (do not rebuild)

- Hostname as Connect `device_name` (`DeviceResolution`, leftover `Work Mac` ignored)
- macOS LaunchAgent install/uninstall (`com.theshit.spotifyd`)
- Daemon health: pid + recent log errors + cache size, `--heal` restarts the process
- `daemon:setup` finds/installs a spotifyd binary (Linux currently talks `apt` — that is the gap)

## Locked decisions

- Linux `spotify daemon install` writes `%h/.config/systemd/user/spotifyd.service` and runs `systemctl --user daemon-reload` then `systemctl --user enable --now spotifyd.service`.
- Unit name is `spotifyd.service` (user unit shadows the distro unit at `/usr/bin` / `/usr/lib/systemd/user/spotifyd.service`, which has no `--config-path`).
- **PartOf=** not **BindsTo=**. `systemctl --user restart pipewire` must restart this unit. BindsTo stops us when PipeWire dies and does not bring us back.
- `Restart=always` and `RestartSec=2`.
- `After=pipewire.service pipewire-pulse.service`
- `PartOf=pipewire.service pipewire-pulse.service`
- `WantedBy=default.target`
- `ExecStart=<spotifyd> --config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery`
  - `<spotifyd>` is `Provision::findSpotifyd()` (preferred wrapper binary, then `which spotifyd`). Do not hardcode a USB id. Do not write an ALSA hardware device pin.
- Linux conf writer (`writeSpotifydConfig` / `Config`): `backend = "pulseaudio"`. Never write a `device = "hw:…"` line. If the existing conf has that pin, drop it on write. start/heal must not rewrite a working pulseaudio conf back to rodio. Darwin keeps today's rodio/portaudio detection from `--help`.
- User unit start limit (in `[Unit]`, with `Restart=always` / `RestartSec=2` already locked): `StartLimitBurst=5` and `StartLimitIntervalSec=60`. Distro `RestartSec=12` plus a 10s interval never trips; this pair must be able to stop a panic loop.
- Log `DeviceNotAvailable` (recent tail already used by health): rewrite backend to pulseaudio, then restart the user unit. Do not respawn the same panic. Default PipeWire/Pulse sink is enough.
- Linux `uninstall` stops/disables the user unit and removes the file we wrote. Do not touch the distro unit under `/usr/lib`.
- Health: this Connect device is **playing** AND there is **no** `pactl list short sink-inputs` row whose name matches `/spotifyd/i` → `degraded`. Pid-up + empty graph is not healthy.
- `context is not available` in `spotifyd.log` is a WARN on single-track play without an album/playlist context. The track still loads. It is **not** degraded. Remove it from `scanLogErrors()` patterns (or never let it count toward `totalErrors`). Do not lower `totalErrors > 10` so three of these WARNs become a restart.
- `--heal` stays gated on `status !== healthy`. Log-error `degraded` is `spotify daemon health --heal`, never a side effect of `play` / `resume`. Play heals only when the Connect device is **missing** (`specs/play-daemon-heal/`, already in `ResolvesDevice`).
- `--heal` on Linux: `systemctl --user restart spotifyd.service` (the user unit), not only kill-pid + spawn.
- `daemon:setup` on Linux: if `omarchy` is on PATH → `omarchy pkg add spotifyd`; else if `pacman` is on PATH → `pacman -S --needed spotifyd`; else tell the user to put `spotifyd` on PATH. **Never `apt` / `apt-get`.**
- Pest does **not** bounce PipeWire. Live "restart pipewire → sink-input back within 10s" is a manual Done-when, not a test.
- macOS LaunchAgent path: do not rewrite. Darwin tests stay green.

## Unit file (must match)

Generated contents must include these keys. Extra comments ok.

```
[Unit]
Description=spotifyd (spotify-cli)
After=pipewire.service pipewire-pulse.service
PartOf=pipewire.service pipewire-pulse.service
StartLimitBurst=5
StartLimitIntervalSec=60

[Service]
ExecStart=… --config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery
Restart=always
RestartSec=2

[Install]
WantedBy=default.target
```

## Tests (Pest first, then code)

Filter: `php artisan test --filter='Daemon'`

Must add (names can vary):

1. Linux install writes the user unit with `After=`, `PartOf=pipewire.service`, `Restart=always`, `RestartSec=2`, `StartLimitBurst=5`, `StartLimitIntervalSec=60`, `--config-path`, `--no-daemon`, `--disable-discovery`.
2. Playing on this device + empty sink-inputs → health `degraded`.
3. `--heal` in that state invokes `systemctl --user restart spotifyd.service` (mock the shell; do not talk to real systemd).
4. `daemon:setup` Linux instructions mention `pacman` or `omarchy pkg`, and do not mention the Debian installer.
5. Existing Darwin LaunchAgent tests still pass.
6. Linux `writeSpotifydConfig` writes `backend = "pulseaudio"` and does not write a `device =` ALSA hardware pin.
7. Existing pulseaudio conf stays pulseaudio after start/heal write (not rewritten to rodio).
8. Conf that already has an ALSA hardware `device =` pin: Linux write drops that line and sets pulseaudio.
9. Heal with `DeviceNotAvailable` in the recent log rewrites backend to pulseaudio, then restarts the user unit (mock the shell).

Use a temp `HOME` (existing daemon tests already do). Mock `pactl` / `systemctl`. Do not `systemctl restart pipewire` in Pest.

## Do not

- New player / Electron / catalog / HTTP API / package split
- Scarlett mixer, Direct Monitor, USB hardware ids
- macOS LaunchAgent rewrite
- `apt` as the Linux install path
- Bounce PipeWire from tests
- Edit README, CHANGELOG, docs/launch.md
- Commit unless the Daemon filter is green. Do not push.

## Done when (Pest)

- Filter `Daemon` green
- Allowlist only (see AGENT_PROMPT)
- Forbidden strings absent
