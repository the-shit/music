Implement SPEC `specs/daemon-rust/spec.md` exactly. Sibling of https://github.com/the-shit/music/issues/162

You are a factory coding agent in a disposable clone. Rust only. Allowlist only.

Do:
1. Read `specs/daemon-rust/spec.md` and `specs/daemon-systemd/spec.md`.
2. If `cargo` is missing: `omarchy pkg add rust` or tell the human to `pacman -S rust`. Never `curl | sh`. Never rustup-init from the internet. If cargo still missing, stop and write BLOCKED.
3. Create crate `crates/spotify-daemon` (add workspace member if a root Cargo.toml appears). Binary `spotify-daemon`.
4. `spotify-daemon` and `spotify-daemon daemon` with no extra args exit 0 and list setup/start/stop/status/health/install/uninstall, one line each.
5. Wrap spotifyd authenticate: subprocess, swallow stdout, success = `~/.config/spotify-cli/cache/oauth/credentials.json` non-empty.
6. Tests first. `cargo test --manifest-path crates/spotify-daemon/Cargo.toml` green.
7. Stop. Do not merge. Do not deploy. Do not edit PHP under `app/`.

Closes nothing until Jordan picks a winner. Branch already checked out.

Do not: apt, BindsTo=, LaunchAgent, README, CHANGELOG, packagist, PHP files.
