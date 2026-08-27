# Changelog

All notable changes to this project are documented here.

## v1.2.0 — 2026-08-26

### Fixed

- `spotify play` / `spotify resume` restart a dead local spotifyd even when Connect still lists the speaker (kill the process, then play — it comes back)

## v1.1.0 — 2026-06-11

### Highlights

- **Premium player** — `spotify player:premium`, a full php-tui terminal player: album art rendered as ANSI, mood-aware theming with a manual theme picker (`t`), lyrics overlay (`y`) via lrclib, a search palette with play-now and queue actions, playlist browsing, and a native queue editor (#136, #141, #143, #144)
- **AI listening sessions** — `spotify session` plans mood-aware listening phases from a natural-language description, powered by intent-parser, curator, and adapt agents with configurable models in `config/ai.php` (#89, #90, #91, #92, #96, #99)
- **`wrapped`** — Spotify-Wrapped for your commit soundtrack (#146)
- **Autopilot, grown up** — event-driven queue refill with 10 mood presets, smart discovery, and `--adapt` AI mood adaptation with `--json` decision events (#33, #40, #45, #47, #142)
- **Listening history** — Qdrant event sink plus `history:backfill` for vector-searchable listening history (#129, #130)

### Added

- `playlist:create` command (#105)
- `find` and `pulse` commands, Solo cockpit integration (#135)
- `--limit` flag on `chill`, `flow`, and `hype` (#77)
- Webhook support for forwarding playback events with HMAC signing (#25)
- Swift media bridge: macOS Control Center and media-key integration (#42, #46)
- Unified setup flow with LaunchAgent auto-start (#38)
- Daemon health monitoring with cache self-healing (#115) and local daemon device preference (#112)
- MCP tools for sessions: `session_start`, `session_status`, `session_adjust` (#99)
- Portability smoke test across PHP versions in CI (#106, #108, #109)

### Fixed

- Respect Spotify 429s with a circuit breaker; sharper daemon health detection (#147)
- Daemon hardening: zeroconf disabled to stop mDNS crashes (#116), LaunchAgent guarded against WebSocket crash loops (#119), OAuth directory preserved during cache heal (#123), spotifyd `credentials_cache` configured (#125)
- `queue:fill` works with the deprecated Spotify recommendations API (#36)
- Playback auto-transfers to the daemon device after start (#32)
- Deterministic test suite: Prompts forced non-interactive so `spin()` never forks (#137)

### Changed

- `the-shit/vector` is now required as `^0.2.1` from Packagist instead of `dev-main`, fixing `composer global require the-shit/music` for consumers (#148)
- `SpotifyService` god class split into focused services (#121)
- Unified config, PHPStan clean, legacy fallbacks removed (#22)
- AI scaffolding commands (`make:agent`, `make:tool`) hidden from the command list (#148)
- Vibes page deploys to GitHub Pages instead of opening PRs (#145); redesigned vibes page experience (#65)
- README rewritten to lead with the soundtrack-your-codebase hook (#138, #140)

### Security

- `league/commonmark` bumped to 2.8.1 for CVE-2026-30838 (#100)
- Credentials no longer embedded in the temporary handler script (#94)

## v1.0.1 — 2026-02-23

Initial public release on Packagist. See the [release notes](https://github.com/the-shit/music/releases/tag/v1.0.1).
