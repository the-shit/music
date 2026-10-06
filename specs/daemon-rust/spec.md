# SPEC: Rust crate wraps spotifyd; `daemon` is the catalog

Status: DRAFT
Sibling: https://github.com/the-shit/music/issues/162
Repo: `the-shit/music`

**Story:** The PHP wizard still leaks spotifyd's CLI (`passthru` authenticate, `[INFO] Browse to:`). We wrap spotifyd in a Rust crate in this repo and race it against the PHP track. Same verbs. User never runs `spotifyd`.

## Locked

- Pair in `the-shit/music`. Crate path `crates/spotify-daemon/`. Binary name `spotify-daemon`. Do not replace the PHP `spotify` binary. Do not open a new GitHub repo.
- `spotify-daemon` with no args, and `spotify-daemon daemon` with no args, exit 0 and print: `setup`, `start`, `stop`, `status`, `health`, `install`, `uninstall` plus one line each.
- Wrap: find/install `spotifyd`, run `authenticate` as a subprocess with stdout swallowed, succeed only when `~/.config/spotify-cli/cache/oauth/credentials.json` is non-empty. Open the browser ourselves if we parse a `Browse to:` URL. Never print their clap dump.
- Linux `install` writes the same user unit as `specs/daemon-systemd/spec.md` (`PartOf=` pipewire, `Restart=always`, `RestartSec=2`, `--config-path %h/.config/spotify-cli/spotifyd.conf --no-daemon --disable-discovery`).
- Linux package: `omarchy pkg add spotifyd` if `omarchy` on PATH, else `pacman -S --needed spotifyd`. Never `apt`. Never `curl | sh`. Toolchain: `omarchy pkg add rust` or `pacman -S rust` if `cargo` missing. Never rustup-init via curl.
- Mac: no LaunchAgent, no Swift bridge. Web API is the PHP CLI's job; this crate is the speaker wrap.
- You own: `crates/spotify-daemon/**`, `Cargo.toml` workspace members only for this crate.
- Do not: merge, deploy, rewrite PHP commands, new player, HTTP API, README/CHANGELOG unless asked.

## Done when

- [ ] `cargo test --manifest-path crates/spotify-daemon/Cargo.toml` green
- [ ] `cargo run --manifest-path crates/spotify-daemon/Cargo.toml --quiet --` (no args) lists the seven verbs with one line each, exit 0

## Out of scope

- Replacing `./spotify` / Laravel Zero this issue
- PHP `DaemonCommand` rewrite (that's `specs/daemon-catalog/`)
- Zeroconf as login
- Bounce PipeWire from tests
