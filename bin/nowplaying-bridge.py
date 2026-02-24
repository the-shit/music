#!/usr/bin/env python3
"""
nowplaying-bridge.py
Bridges Spotify playback state into the macOS NowPlaying system
(Control Center widget + media keys).

Usage:
    spotify watch --json --interval=3 | python3 nowplaying-bridge.py
"""

import os
import shutil
import sys
import json
import subprocess
import threading
import signal

import objc
from Foundation import NSObject, NSRunLoop, NSDate
from AppKit import NSApplication
import MediaPlayer

# Derive paths dynamically so the bridge works on any machine.
# SCRIPT_DIR = the directory this script lives in (i.e. <project>/bin/)
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(SCRIPT_DIR)

# The 'spotify' Laravel Zero binary lives at the project root.
SPOTIFY_CLI = os.path.join(PROJECT_DIR, "spotify")

# Find PHP on the system PATH — works with Herd, Homebrew, or any other install.
PHP_BIN = shutil.which("php") or "php"


class NowPlayingBridge(NSObject):

    def init(self):
        self = objc.super(NowPlayingBridge, self).init()
        if self is None:
            return None
        self._current = {}
        self._setup_now_playing()
        self._setup_remote_commands()
        return self

    def _setup_now_playing(self):
        self._info_center = MediaPlayer.MPNowPlayingInfoCenter.defaultCenter()
        self._info_center.setPlaybackState_(
            MediaPlayer.MPNowPlayingPlaybackStatePlaying)

    def _setup_remote_commands(self):
        rc = MediaPlayer.MPRemoteCommandCenter.sharedCommandCenter()
        rc.playCommand().addTargetWithHandler_(self._handle_play)
        rc.pauseCommand().addTargetWithHandler_(self._handle_pause)
        rc.togglePlayPauseCommand().addTargetWithHandler_(self._handle_toggle)
        rc.nextTrackCommand().addTargetWithHandler_(self._handle_next)
        rc.previousTrackCommand().addTargetWithHandler_(self._handle_previous)

    def _run_spotify(self, *args):
        try:
            subprocess.Popen(
                [PHP_BIN, SPOTIFY_CLI] + list(args),
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        except Exception as e:
            print(f"[bridge] error: {e}", file=sys.stderr)

    def _handle_play(self, event):
        self._run_spotify("resume")
        return MediaPlayer.MPRemoteCommandHandlerStatusSuccess

    def _handle_pause(self, event):
        self._run_spotify("pause")
        return MediaPlayer.MPRemoteCommandHandlerStatusSuccess

    def _handle_toggle(self, event):
        if self._current.get("is_playing"):
            self._run_spotify("pause")
        else:
            self._run_spotify("resume")
        return MediaPlayer.MPRemoteCommandHandlerStatusSuccess

    def _handle_next(self, event):
        self._run_spotify("skip")
        return MediaPlayer.MPRemoteCommandHandlerStatusSuccess

    def _handle_previous(self, event):
        self._run_spotify("skip", "--previous")
        return MediaPlayer.MPRemoteCommandHandlerStatusSuccess

    def update_from_event(self, event):
        event_type = event.get("type")

        if event_type == "track_changed":
            self._current = dict(event)
            self._current["is_playing"] = event.get("is_playing", True)
            self._push_now_playing()

        elif event_type == "playback_state_changed":
            self._current["is_playing"] = event.get("is_playing", False)
            self._push_playback_state()

        elif event_type == "playback_stopped":
            self._current = {}
            self._info_center.setPlaybackState_(
                MediaPlayer.MPNowPlayingPlaybackStateStopped)

    def _push_now_playing(self):
        track  = self._current.get("track", "")
        artist = self._current.get("artist", "")
        album  = self._current.get("album", "")

        info = {
            MediaPlayer.MPMediaItemPropertyTitle:      track,
            MediaPlayer.MPMediaItemPropertyArtist:     artist,
            MediaPlayer.MPMediaItemPropertyAlbumTitle: album,
            MediaPlayer.MPNowPlayingInfoPropertyMediaType:
                MediaPlayer.MPNowPlayingInfoMediaTypeAudio,
        }

        self._info_center.setNowPlayingInfo_(info)
        self._push_playback_state()
        print(f"[bridge] Now Playing: {track} — {artist}", flush=True)

    def _push_playback_state(self):
        state = (
            MediaPlayer.MPNowPlayingPlaybackStatePlaying
            if self._current.get("is_playing")
            else MediaPlayer.MPNowPlayingPlaybackStatePaused
        )
        self._info_center.setPlaybackState_(state)


def main():
    NSApplication.sharedApplication()

    bridge = NowPlayingBridge.alloc().init()

    def reader_thread():
        for line in sys.stdin:
            line = line.strip()
            if not line:
                continue
            try:
                event = json.loads(line)
                bridge.update_from_event(event)
            except json.JSONDecodeError:
                pass

    t = threading.Thread(target=reader_thread, daemon=True)
    t.start()

    signal.signal(signal.SIGINT,  lambda s, f: sys.exit(0))
    signal.signal(signal.SIGTERM, lambda s, f: sys.exit(0))

    print("[bridge] NowPlaying bridge started. Media keys and Control Center active.", flush=True)

    run_loop = NSRunLoop.currentRunLoop()
    while t.is_alive():
        run_loop.runUntilDate_(NSDate.dateWithTimeIntervalSinceNow_(0.1))


if __name__ == "__main__":
    main()
