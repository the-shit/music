# Sibling Grok insight (2026-08-26)

Do not treat `context is not available` as a reason to heal, and do not expect `play` to resurrect a killed pid.

Evidence: `spotify daemon health --json` → `status: healthy`, `errors: {"context is not available": 3}`, pid up, 21-line log. Those three are spotifyd WARNs while a single track URI loads; the track still loads. `diagnose()` only flips `degraded` at `totalErrors > 10` (or cache > 500MB). `--heal` is gated on `status !== healthy`, so this JSON is noise and heal no-ops.

`play` / `resume` heal **once** when `getDevices()` does not list the `spotifyd.conf` `device_name`. Killing the process is not that. Connect can still list a stale device, so play will not heal. Crash-respawn is the user unit (`Restart=always`, distro `RestartSec=12`). Graph-dead (playing, no `pactl` sink-input) is #156, not play-heal.

Do not lower the `> 10` bar. Do not count `context is not available` toward degraded. Every single-track play would restart the daemon.

Comment on https://github.com/the-shit/music/issues/156#issuecomment-5433844084
Locked bullets also in `specs/daemon-systemd/spec.md`.
