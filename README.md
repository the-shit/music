# Music CLI - Spotify Controller

A beautiful command-line interface for controlling Spotify, built with Laravel Zero.

## Features

- **OAuth 2.0 Authentication** - Secure authentication with automatic token refresh
- **Playback Control** - Play, pause, resume, skip, and control volume
- **Device Management** - List and switch between Spotify devices
- **Search & Play** - Search for tracks, artists, albums, and playlists
- **Queue Management** - Add tracks to your queue
- **Interactive Player** - Full-featured interactive player mode
- **JSON Output** - Machine-readable output for scripting

## Installation

```bash
# Clone the repository
git clone https://github.com/your-user/music.git
cd music

# Install dependencies
composer install

# Set up Spotify credentials
./music setup
```

## Quick Start

```bash
# First-time setup (opens Spotify Developer Dashboard)
./music setup

# Authenticate with Spotify
./music login

# Play a song
./music play "Never Gonna Give You Up"

# See what's playing
./music current

# Control playback
./music pause
./music resume
./music skip
./music skip prev
```

## Commands

### Setup & Authentication

| Command | Description |
|---------|-------------|
| `music setup` | Set up Spotify API credentials with guided wizard |
| `music setup --reset` | Reset stored credentials |
| `music login` | Authenticate with Spotify via OAuth |

### Playback Control

| Command | Description |
|---------|-------------|
| `music play "query"` | Search and play a track |
| `music play "query" --queue` | Add to queue instead of playing |
| `music pause` | Pause playback |
| `music resume` | Resume playback |
| `music skip` | Skip to next track |
| `music skip prev` | Skip to previous track |
| `music volume` | Show current volume |
| `music volume 50` | Set volume to 50% |
| `music volume +10` | Increase volume by 10% |
| `music shuffle` | Toggle shuffle |
| `music shuffle on` | Enable shuffle |
| `music repeat` | Cycle repeat mode |
| `music repeat track` | Repeat current track |

### Information & Queue

| Command | Description |
|---------|-------------|
| `music current` | Show currently playing track |
| `music devices` | List available devices |
| `music devices --switch` | Interactive device switching |
| `music queue "query"` | Add a track to the queue |
| `music player` | Launch interactive player |

### JSON Output

All playback commands support `--json` for machine-readable output:

```bash
./music current --json
./music play "song" --json
./music volume --json
```

## Environment Variables

```bash
SPOTIFY_CLIENT_ID=your_client_id
SPOTIFY_CLIENT_SECRET=your_client_secret
SPOTIFY_REDIRECT_URI=http://127.0.0.1:8888/callback
```

## Configuration

Credentials are stored in `.env` and tokens are stored securely in `storage/spotify_token.json` with restricted permissions (0600).

### Required Spotify Scopes

- `user-read-playback-state`
- `user-modify-playback-state`
- `user-read-currently-playing`
- `streaming`
- `playlist-read-private`
- `playlist-read-collaborative`

## Requirements

- PHP 8.2+
- Composer
- A Spotify Premium account
- A Spotify Developer application

## Creating a Spotify App

1. Go to [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)
2. Create a new application
3. Add `http://127.0.0.1:8888/callback` as a redirect URI
4. Copy your Client ID and Client Secret
5. Run `./music setup` to configure

## License

MIT License
