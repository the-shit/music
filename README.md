# Your codebase has a soundtrack

Every commit in this repo carries the Spotify track that was playing when the code was written. A git hook injects it. CI enforces it. **No music, no merge.**

The byproduct is the [vibes page](https://the-shit.github.io/music/vibes.html) — an auto-generated, living soundtrack of the entire codebase. Every song that was playing when every line was written, regenerated on every push to master.

**[Docs](https://the-shit.github.io/music/)** · **[Vibes](https://the-shit.github.io/music/vibes.html)** · **[Commands](https://the-shit.github.io/music/commands.html)** · **[MCP](https://the-shit.github.io/music/mcp.html)**

## How the soundtrack happens

1. **You listen** — Spotify plays while you code, like it already does.
2. **The hook captures** — a pre-commit hook asks the CLI what's playing and appends the track URL to your commit message.
3. **CI enforces** — the Vibe Check gate scans every commit for a Spotify URL. No music, no merge.
4. **The vibes page regenerates** — every push to master rebuilds the soundtrack of the whole repo.

It's enforced by the same pipeline as PHPStan and test coverage. Music is a CI gate here, not a gimmick.

## Quick Start

```bash
composer global require the-shit/music

spotify setup      # Configure Spotify API credentials
spotify login      # Authenticate via OAuth
spotify play "Killing In the Name"
spotify current    # See what's playing
```

That's it. The hook and CI gate are wired up per-repo — see [CONTRIBUTING.md](CONTRIBUTING.md).

## The Premium Player

`spotify player:premium` — a full php-tui player in your terminal. Album art rendered as ANSI, mood-aware theming, a search palette with play-now and queue actions, playlist browsing, and a queue peek. It's the player a vibes-enforced codebase deserves.

![Premium player demo — mood theming, lyrics, queue peek, and the search palette](docs/demo/premium-player.gif)

```bash
spotify player:premium
```

(The classic `spotify player` TUI is still there if you want something lighter.)

## And it's a full Spotify CLI

The soundtrack is enforced, but the day-to-day tooling carries its weight too: 30+ commands, 12 MCP tools, mood-aware autopilot, event-driven integrations. Every feature is a layer you opt into — nothing assumes anything else is running.

| Layer | What it does | Required? |
|---|---|---|
| **CLI commands** | Play, pause, skip, queue, search, volume, shuffle, repeat | Just this |
| **Premium player** | php-tui player with art, mood theming, search palette | `spotify player:premium` when you want it |
| **MCP server** | 12 tools for AI assistants (Claude, etc.) to control Spotify | Configure if you use AI tools |
| **Daemon** | Headless Spotify Connect speaker via spotifyd | Install if you want background playback |
| **Media bridge** | macOS Control Center + media keys via native Swift | Install if you want media key control |
| **Autopilot** | Background queue auto-refill with mood presets | Install if you want hands-off listening |
| **Slack integration** | Share now-playing to Slack channels | Set up if you use Slack |
| **Webhooks** | Forward playback events to any URL with HMAC signing | Configure if you want event-driven automation |
| **Event streaming** | JSON Lines event bus for track changes, state transitions | Enable if you're building on top of this |

See the full [command reference](https://the-shit.github.io/music/commands.html) for all 32 commands.

## Why This Instead Of...

**spotify_player / ncspot / spotatui** — Great TUI players. But they only play music. No commit soundtrack, no MCP server, no queue intelligence, no integrations. If you just want a terminal UI, those are solid. If you want Spotify as composable infrastructure — and your git history to have a soundtrack — this is it.

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
