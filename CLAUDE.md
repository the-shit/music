# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Spotify CLI - A Laravel Zero command-line interface for controlling Spotify playback. Currently part of "THE SHIT" component system but transitioning to standalone.

## Common Commands

```bash
# Run the CLI
php spotify <command>
./spotify <command>

# Testing (Pest)
./vendor/bin/pest
./vendor/bin/pest --filter "test name"
./vendor/bin/pest tests/Feature/ExampleTest.php

# Code formatting (Pint)
./vendor/bin/pint
./vendor/bin/pint --test    # Check without fixing

# Install dependencies
composer install
```

## Architecture

### Service Layer (`app/Services/SpotifyService.php`)
Central service handling all Spotify Web API interactions:
- OAuth 2.0 token management with auto-refresh (60s before expiry)
- Token storage in `~/.config/spotify-cli/token.json` with 0600 permissions
- Device management, playback control, search, playlists, queue

### Command Layer (`app/Commands/`)
Laravel Zero commands providing CLI interface. Each command uses `SpotifyService` for API calls and Laravel Prompts for interactive UI elements.

Key commands:
- `PlayerCommand` - Interactive visual player with search, queue browser, playlist browser
- `PlayCommand` - Search and play tracks/artists/playlists
- `SetupCommand` / `LoginCommand` - OAuth setup and authentication

### Configuration (`config/`)
- `spotify.php` - API credentials and OAuth scopes from environment
- `commands.php` - Command discovery configuration

### Entry Point
`spotify` file - Executable that bootstraps Laravel Zero and runs console kernel.

## Environment Setup

Required in `.env`:
```
SPOTIFY_CLIENT_ID=<app-id>
SPOTIFY_CLIENT_SECRET=<app-secret>
SPOTIFY_REDIRECT_URI=http://127.0.0.1:8888/callback
```

## OAuth Flow

The `login` command starts a local HTTP server on port 8888 to receive OAuth callbacks. Token refresh happens automatically when making API requests.

## Component Manifest

`💩.json` defines this as a "THE SHIT" component with command mappings like `spotify:play`, `spotify:current`, etc. This will be removed as part of Issue #2.

## Planned Architectural Changes (Open Issues)

### Issue #2: Standalone CLI
Remove "THE SHIT" component dependency. Changes required:
- Delete `💩.json` manifest file
- Rename commands from `spotify:play` to just `play` (update `$signature` in each command)
- Update README to remove component system references
- Consider renaming the project/executable if desired

**Recommendation:** Use Laravel Zero's command namespacing or a config flag to support both modes during transition. Commands could check for component context and adapt signatures.

### Issue #3: Librespot Integration (Terminal Audio Player)
Add native audio playback via librespot (Rust Spotify client library). Major architectural addition:

**New components needed:**
- `LibrespotService` - Manages librespot process lifecycle (start/stop/status)
- `AudioPlayerCommand` - New command for local playback mode
- Process management for the librespot daemon
- Audio device selection/configuration

**Architecture considerations:**
- Librespot runs as a separate process - need process supervision
- Must handle graceful shutdown and crash recovery
- Consider using Symfony Process component (already available via Laravel)
- Token sharing between SpotifyService (API) and librespot (streaming)
- New OAuth scope needed: `streaming` (already present in config)

**Recommendation:** Create a `PlaybackManager` abstraction that can delegate to either:
1. Remote playback (current SpotifyService - controls other Spotify clients)
2. Local playback (new LibrespotService - plays audio locally)

This allows commands to be agnostic about playback mode.

### Issue #4: PHAR Compatibility (Mostly Complete)
Config already uses `~/.config/spotify-cli/` for tokens and credentials. Remaining work:
- Verify `SetupCommand` writes credentials to user config directory
- Test PHAR build with `box compile`
- Ensure no other paths reference `storage/` or `base_path()`

**Recommendation:** Add a `ConfigManager` service that centralizes all path resolution, making it easier to test and ensuring consistency.

### Issue #5: Gate Integration + Test Suite
Add authorization layer and comprehensive tests.

**Gate integration:**
- Laravel's Gate facade for authorization policies
- Could gate features based on OAuth scopes obtained
- Example: block queue commands if `user-modify-playback-state` scope missing

**Test suite (currently no tests exist):**
- Unit tests for `SpotifyService` (mock HTTP responses)
- Feature tests for commands (mock SpotifyService)
- Integration tests for OAuth flow

**Recommended test structure:**
```
tests/
├── Unit/
│   └── Services/
│       └── SpotifyServiceTest.php
├── Feature/
│   └── Commands/
│       ├── PlayCommandTest.php
│       ├── DevicesCommandTest.php
│       └── ...
└── Pest.php
```

**Recommendation:** Use Mockery (already in dev dependencies) to mock HTTP responses. Create a `SpotifyServiceFake` for command tests that returns predictable data.

## Implementation Priority

1. **Issue #5 (Tests)** - Add tests first to prevent regressions during other changes
2. **Issue #4 (PHAR)** - Quick win, mostly done, verify and close
3. **Issue #2 (Standalone)** - Moderate effort, enables wider adoption
4. **Issue #3 (Librespot)** - Largest effort, new feature, do last
