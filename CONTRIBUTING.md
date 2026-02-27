# Contributing to THE SHIT / music

## The One Rule

**Every commit must include the Spotify track you were listening to when you wrote it.**

This is enforced by CI. PRs with commits missing a track URL will be rejected.

---

## Why

This is a Spotify CLI. You should be listening to music while you work on it. If you're not, that's the real bug.

---

## Setup (2 minutes)

The `prepare-commit-msg` hook appends the now-playing track automatically. You just commit normally.

### Install the hooks

```bash
bash .git-hooks/install
```

This installs:
- `prepare-commit-msg` (adds now-playing track metadata)
- `post-commit` (regenerates `docs/vibes.html` in background)

The hook reads your Spotify token from `~/.config/spotify-cli/token.json` (written by `./spotify login`) and appends the currently playing track as a trailer. It also avoids repeating the same track too often by preferring a recent different track when the current song already appears in recent commit history. If nothing is playing, it skips silently — but your commit will fail CI.

### What a valid commit looks like

```
fix: handle empty device list gracefully

🎵 Now playing: Kendrick Lamar - Not Like Us
💿 Album: Not Like Us
🔗 Track: https://open.spotify.com/track/6AI3ezQ4o3HUoP6Dhudph3
🕒 Captured: 2026-02-27 02:05:03 PST
```

### If nothing is playing

Put something on. Then commit.

```bash
./spotify play "something good"
git commit
```

---

## Running the test suite

```bash
php vendor/bin/pest
```

Coverage must stay above 50% (enforced by Sentinel Gate).

---

## The vibe check

CI runs two gates on every PR:

| Gate | What it checks |
|---|---|
| **Sentinel Gate** | Test coverage ≥ 50% |
| **Vibe Check** | Every commit has a Spotify track URL |

Both must pass. No exceptions.
