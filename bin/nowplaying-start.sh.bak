#!/bin/bash
# nowplaying-start.sh
# Starts the Spotify → macOS NowPlaying bridge

# Resolve the real directory of this script, even through symlinks.
SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0" 2>/dev/null || realpath "$0" 2>/dev/null || echo "$0")")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Find binaries on PATH — works with Herd, Homebrew, system PHP, etc.
PHP="$(command -v php)"
PYTHON="$(command -v python3)"

# Project-relative paths for the CLI binary and bridge script.
SPOTIFY="$PROJECT_DIR/spotify"
BRIDGE="$SCRIPT_DIR/nowplaying-bridge.py"

if [ -z "$PHP" ]; then
    echo "Error: php not found on PATH" >&2
    exit 1
fi
if [ -z "$PYTHON" ]; then
    echo "Error: python3 not found on PATH" >&2
    exit 1
fi
if [ ! -f "$SPOTIFY" ]; then
    echo "Error: spotify binary not found at $SPOTIFY" >&2
    exit 1
fi
if [ ! -f "$BRIDGE" ]; then
    echo "Error: nowplaying-bridge.py not found at $BRIDGE" >&2
    exit 1
fi

exec "$PHP" "$SPOTIFY" watch --json --interval=3 | exec "$PYTHON" "$BRIDGE"
