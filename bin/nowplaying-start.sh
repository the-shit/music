#!/bin/bash
# nowplaying-start.sh
# Starts the Spotify → macOS NowPlaying bridge

PHP="/Users/jordanpartridge/Library/Application Support/Herd/bin/php"
SPOTIFY="/Users/jordanpartridge/packages/the-shit/spotify-cli/spotify"
PYTHON="/opt/homebrew/Caskroom/miniconda/base/bin/python3"
BRIDGE="/Users/jordanpartridge/packages/the-shit/spotify-cli/bin/nowplaying-bridge.py"

exec "$PHP" "$SPOTIFY" watch --json --interval=3 | exec "$PYTHON" "$BRIDGE"
