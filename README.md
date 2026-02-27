# Spotify CLI

A full-featured Spotify CLI built on Laravel Zero. 30+ commands for playback control, queue management, discovery, and more — all from your terminal.

**[Docs](https://the-shit.github.io/music/)** · **[Commands](https://the-shit.github.io/music/commands.html)** · **[MCP](https://the-shit.github.io/music/mcp.html)** · **[Vibes](https://the-shit.github.io/music/vibes.html)**

## Quick Start

```bash
composer global require the-shit/music

spotify setup      # Configure Spotify API credentials
spotify login      # Authenticate via OAuth
spotify play "Killing In the Name"
spotify current    # See what's playing
spotify player     # Launch interactive TUI player
```

## Highlights

- **Playback** — play, pause, skip, volume, shuffle, repeat
- **Queue** — add tracks, view upcoming, auto-fill from recommendations
- **Discovery** — search, mood queues, smart recommendations, top tracks
- **Interactive Player** — TUI with progress bar and keyboard controls
- **Daemon** — background playback via spotifyd, macOS media key integration
- **Services** — spotifyd, Swift media bridge, autopilot — all managed via launchd
- **Integrations** — Slack sharing, webhooks, event streaming

See the full [command reference](https://the-shit.github.io/music/commands.html) for all 32 commands.

## MCP Server

This CLI doubles as an [MCP server](https://the-shit.github.io/music/mcp.html) — AI assistants like Claude can control your Spotify directly. 12 tools for playback, queue, search, and more.

```json
{
  "mcpServers": {
    "spotify": {
      "command": "spotify",
      "args": ["mcp:start", "spotify"]
    }
  }
}
```

See the [MCP setup docs](https://the-shit.github.io/music/mcp.html) for Claude Desktop, Claude Code, and OpenCode configuration.

## macOS Services

Three background services keep everything running seamlessly on macOS. Each is managed via `launchd` with auto-restart and logging.

### spotifyd (headless Spotify Connect speaker)

```bash
spotify daemon:setup              # Install dependencies + authenticate
spotify daemon install             # Install LaunchAgent (auto-starts on login)
spotify daemon status              # Check daemon status
spotify daemon stop                # Stop the daemon
```

Uses the `rodio` audio backend for reliable playback. Config lives at `~/.config/spotify-cli/spotifyd.conf`.

### Swift Media Bridge (Control Center + media keys)

```bash
spotify setup:media-bridge         # Compile Swift binary + install LaunchAgent
spotify setup:media-bridge --status    # Check status + recent logs
spotify setup:media-bridge --uninstall # Remove
```

A native Swift app that bridges your media keys and macOS Control Center to the CLI. Polls `spotify current --json` and pushes track info (title, artist, album art, progress) to `MPNowPlayingInfoCenter`. Play/pause/skip from your keyboard or Control Center just work.

### Autopilot (queue auto-refill)

```bash
spotify autopilot                          # Run interactively
spotify autopilot --install --mood=hype    # Install as background daemon
spotify autopilot --status                 # Check status
spotify autopilot --uninstall              # Remove
```

Watches for track changes and auto-refills the queue when it drops below a threshold. Supports mood presets: `chill`, `flow`, `hype`, `focus`, `party`, `upbeat`, `melancholy`, `ambient`, `workout`, `sleep`.

### Service overview

| Service | LaunchAgent | RunAtLoad | KeepAlive | Logs |
|---|---|---|---|---|
| spotifyd | `com.spotify-cli.spotifyd` | ✅ | ✅ | `~/.config/spotify-cli/spotifyd.log` |
| media-bridge | `com.theshit.media-bridge` | ✅ | ✅ | `~/.config/spotify-cli/media-bridge.log` |
| autopilot | `com.theshit.autopilot` | ❌ (on-demand) | ✅ | `~/.config/spotify-cli/autopilot.log` |

## Vibe Check

Every commit must include the Spotify track playing when the code was written. A pre-commit hook injects the track URL, and CI rejects any push without one.

The result is the [vibes page](https://the-shit.github.io/music/vibes.html) — a living soundtrack of the entire codebase.

## Requirements

- PHP 8.2+
- Composer
- Spotify Premium account
- A [Spotify Developer](https://developer.spotify.com/dashboard) application

## License

MIT
