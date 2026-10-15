# SPEC: MPRIS / D-Bus media keys on Linux

Status: **DRAFT**
Issue: https://github.com/the-shit/music/issues/56 (closed help-wanted — reopen or replace when this SPEC locks)
Repo: `the-shit/music`

**Story:** On macOS, `spotify setup:media-bridge` wires Control Center and media keys. On Linux (especially Omarchy/Hyprland), media keys and `playerctl` have nothing to talk to. Register an MPRIS player on D-Bus so desktop environments and `playerctl` can control this CLI.

## Already shipped (do not rebuild)

- Swift media bridge + `setup:media-bridge` (Darwin only)
- Deprecated `spotify nowplaying` redirect to the Swift path
- `spotify watch --json` event stream (track changes, play/pause)
- Omarchy bar plugin shells out to CLI verbs (`play` / `pause` / `resume` / moods)

## Locked decisions (when status → LOCKED)

- New surface: `spotify setup:mpris` (install/uninstall/status), Linux only — mirror the media-bridge command shape.
- Implementation may be a small helper (Python/`dbus-next` or native) that polls `spotify current --json` / consumes `spotify watch --json` and exposes `org.mpris.MediaPlayer2.Player`.
- Play/pause/next/previous map to existing CLI commands (`resume`/`pause`/`skip`/`skip prev`), not a second Spotify client.
- Now-playing metadata (title, artist, album art URL, length, position) comes from `current --json`.
- Platform detection: refuse on Darwin with a pointer to `setup:media-bridge`; refuse on Windows.
- Do not require the Omarchy GTK window (`desktop/omarchy/`) — MPRIS must work headless with only the CLI + daemon.

## Acceptance Criteria

- [ ] CLI (or helper) registers as an MPRIS player on the session D-Bus
- [ ] `playerctl play|pause|next|previous` drives Spotify via this CLI
- [ ] `playerctl metadata` shows the current track
- [ ] Works alongside the macOS Swift bridge (no shared LaunchAgent / no Darwin install path)
- [ ] Pest coverage for the install/status/uninstall command guards (platform + missing deps)

## Do not

- Rewrite the Omarchy GTK app as the MPRIS provider
- Depend on Spotify desktop’s own MPRIS export
- Packagist / version bump / CHANGELOG until LOCKED and green
