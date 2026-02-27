# Spotify CLI

Spotify as terminal infrastructure. 30+ commands, 12 MCP tools, mood-aware autopilot, event-driven integrations — use what you need, ignore what you don't.

**[Docs](https://the-shit.github.io/music/)** · **[Commands](https://the-shit.github.io/music/commands.html)** · **[MCP](https://the-shit.github.io/music/mcp.html)** · **[Vibes](https://the-shit.github.io/music/vibes.html)**

## Quick Start

```bash
composer global require the-shit/music

spotify setup      # Configure Spotify API credentials
spotify login      # Authenticate via OAuth
spotify play "Killing In the Name"
spotify current    # See what's playing
```

That's it. Everything else is optional.

## Use What You Need

Every feature is a layer you opt into. Nothing assumes anything else is running.

| Layer | What it does | Required? |
|---|---|---|
| **CLI commands** | Play, pause, skip, queue, search, volume, shuffle, repeat | Just this |
| **Interactive player** | TUI with progress bar, controls, playlist browsing | `spotify player` when you want it |
| **MCP server** | 12 tools for AI assistants (Claude, etc.) to control Spotify | Configure if you use AI tools |
| **Daemon** | Headless Spotify Connect speaker via spotifyd | Install if you want background playback |
| **Media bridge** | macOS Control Center + media keys via native Swift | Install if you want media key control |
| **Autopilot** | Background queue auto-refill with mood presets | Install if you want hands-off listening |
| **Slack integration** | Share now-playing to Slack channels | Set up if you use Slack |
| **Webhooks** | Forward playback events to any URL with HMAC signing | Configure if you want event-driven automation |
| **Event streaming** | JSON Lines event bus for track changes, state transitions | Enable if you're building on top of this |

See the full [command reference](https://the-shit.github.io/music/commands.html) for all 32 commands.

## Why This Instead Of...

**spotify_player / ncspot / spotatui** — Great TUI players. But they only play music. No MCP server, no queue intelligence, no integrations, no service management. If you just want a terminal UI, those are solid. If you want Spotify as composable infrastructure, this is it.

**Standalone MCP servers** — There are a handful on Smithery/GitHub. Most are weekend scripts with 3-5 tools and no maintenance. This is a real application with 12 MCP tools, 2 resources, battle-tested against Spotify's API deprecations, and actively maintained.

**The Spotify desktop app** — 800MB Electron app. This is 30 commands in a single binary.

## MCP Server

This CLI doubles as an [MCP server](https://the-shit.github.io/music/mcp.html) — AI assistants like Claude can control your Spotify directly. 12 tools for playback, queue, search, and discovery.

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

## Mood-Aware Queue

Built-in mood presets that queue tracks matching an energy profile. Each uses Spotify's audio feature targeting with smart deduplication against your queue and recent history.

```bash
spotify chill                              # Lofi, acoustic, ambient
spotify flow --duration=60                 # Deep work focus
spotify hype                               # High energy, workout
spotify autopilot --install --mood=hype    # Set it and forget it
```

Autopilot watches for track changes and auto-refills the queue when it drops below a threshold. Supports moods: `chill`, `flow`, `hype`, `focus`, `party`, `upbeat`, `melancholy`, `ambient`, `workout`, `sleep`.

## macOS Services

Three optional background services, each managed via `launchd` with auto-restart and logging.

**spotifyd** — headless Spotify Connect speaker:
```bash
spotify daemon:setup && spotify daemon install
```

**Swift Media Bridge** — Control Center + media keys:
```bash
spotify setup:media-bridge
```

**Autopilot** — background queue auto-refill:
```bash
spotify autopilot --install --mood=flow
```

| Service | LaunchAgent | RunAtLoad | KeepAlive | Logs |
|---|---|---|---|---|
| spotifyd | `com.spotify-cli.spotifyd` | Yes | Yes | `~/.config/spotify-cli/spotifyd.log` |
| media-bridge | `com.theshit.media-bridge` | Yes | Yes | `~/.config/spotify-cli/media-bridge.log` |
| autopilot | `com.theshit.autopilot` | No (on-demand) | Yes | `~/.config/spotify-cli/autopilot.log` |

## Integrations

**Slack** — share now-playing to a channel:
```bash
spotify slack setup && spotify slack now
```

**Webhooks** — forward events with HMAC signing:
```bash
spotify webhook:configure --url=https://your.endpoint/hook --secret=your-secret
```

**Event streaming** — the `watch` command emits JSON Lines events (`track.changed`, `playback.paused`, `playback.resumed`, etc.) that you can pipe anywhere:
```bash
spotify watch --json | your-consumer
```

## Vibe Check

Every commit must include the Spotify track playing when the code was written. A pre-commit hook injects the track metadata, and CI rejects any push without one.

The result is the [vibes page](https://the-shit.github.io/music/vibes.html) — a living soundtrack of the entire codebase.

## Contributing

PRs welcome. Pick an [issue](https://github.com/the-shit/music/issues), ship it, CI handles the rest.

CI runs three automated gates on every PR — no manual review bottleneck:

| Gate | What it checks |
|---|---|
| **PHPStan** | Static analysis at level 5 (type safety, param mismatches) |
| **Sentinel Gate** | Test coverage ≥ 50% |
| **Vibe Check** | Every commit has a Spotify track URL |

All must pass. See [CONTRIBUTING.md](CONTRIBUTING.md) for setup.

## Requirements

- PHP 8.2+
- Composer
- Spotify Premium account
- A [Spotify Developer](https://developer.spotify.com/dashboard) application

## License

MIT
