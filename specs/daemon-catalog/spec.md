# SPEC: `spotify daemon` is the catalog; Linux speaker just works

Status: DRAFT
Issue: https://github.com/the-shit/music/issues/162
Repo: `the-shit/music`

**Story:** `spotify daemon` required a positional action (or you had to know `daemon:setup` vs `daemon start` vs `systemctl --user start`). Setup looked at the wrong credentials file and `start` passed `--disable-discovery` without an oauth blob, so it said re-auth while Thor was already a Connect speaker via zeroconf. Mac LaunchAgent / Swift Control Center were treated as peers.

## Locked

- Wrap spotifyd. It is a subprocess we find, install, authenticate, and supervise. The user never runs `spotifyd`, never sees `spotifyd authenticate`, never `passthru` of their CLI. `spotify daemon <verb>` is the product.
- `spotify daemon` with no args exits 0 and prints a catalog: every daemon verb and a one-line what-it-does. Not a Symfony "missing: action" error. Not a dump of the whole CLI. Not Termwind polish on a leaked second CLI.
- Verbs on that list (and as `spotify daemon <verb>`): `setup`, `start`, `stop`, `status`, `health`, `install`, `uninstall`. Fold today's `daemon:setup` into `daemon setup` (`daemon:setup` may stay as a hidden alias).
- Linux is the chair. `install` writes the user unit in `specs/daemon-systemd/spec.md` (`PartOf=` pipewire, `Restart=always`, `RestartSec=2`, `ExecStart` with `--config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery`). `daemon setup` / `install` on Linux: `omarchy pkg add spotifyd` if `omarchy` on PATH, else `pacman -S --needed spotifyd`. Never `apt`. Never brew as the Linux path.
- `--disable-discovery` is honest only after setup has written `~/.config/spotify-cli/cache/oauth/credentials.json`. Setup checks that file, not `cache/credentials.json`. Zeroconf leftover is not "already authenticated." Browser login is ours (open URL, wait for the blob) even if the binary behind it is still `spotifyd authenticate`.
- Mac: Web API commands (`play`, `devices`, `login`, …) may still run if the binary is there. No more LaunchAgent product, no Swift media bridge, no Control Center / media-key work. Do not rewrite `com.theshit.spotifyd`. Darwin tests may stay so the suite does not go red; they are not the chair.
- You own: `app/Commands/DaemonCommand.php`, `app/Commands/DaemonSetupCommand.php`, `app/Services/Daemon/*`, Daemon Pest filters.
- Do not: merge, deploy, new harness, new player, HTTP API, Scarlett/USB ids, README/CHANGELOG unless asked. Do not keep restyling `title()` / Prompts / Termwind while `passthru(spotifyd authenticate)` is still the setup. Rust crate is sibling `specs/daemon-rust/` — do not type it in this PHP track.

## Done when

- [ ] `php spotify daemon` (no args) exits 0 and lists `setup`, `start`, `stop`, `status`, `health`, `install`, `uninstall` with a one-line description each.

## Out of scope

- Keep zeroconf / drop `--disable-discovery` (mDNS crash path)
- Rust crate (`specs/daemon-rust/`)
- LaunchAgent / Swift media bridge as product
- `apt` as the Linux install path
- Bounce PipeWire from Pest

## Open decisions

- Verify "seamless always works" (not a named command yet): oauth blob exists, `systemctl --user is-active spotifyd` is `active`, `spotify devices` lists Thor, `spotify play` has a `pactl` sink-input matching `/spotifyd/i`, `spotify daemon start` does not print "no credentials found". Confirm before `/local-worker` for the speaker path.
