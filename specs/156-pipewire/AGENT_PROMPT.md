# Issue 156 — Linux user unit rides PipeWire, silence is degraded

Repo: this clone. Issue: https://github.com/the-shit/music/issues/156
Prove: `php -d disable_functions=pcntl_fork vendor/bin/pest --filter=Daemon`
Stay inside the allowlist. Do not add a player, a catalog, or an HTTP API.

## Safety

This machine is playing audio through the real user `spotifyd`. Tests already set `$_SERVER['HOME']` to a temp dir. Call systemd only from `systemdAllowed()`:

- false when `SPOTIFY_SYSTEMD_DRY=1`
- false when `$_SERVER['HOME']` is not the process owner's home (`posix_getpwuid(posix_geteuid())['dir']`)
- otherwise true

Never invoke systemd, `launchctl`, or a package manager from a test. Never rewrite an existing `spotifyd.conf` `device_name`.

## Behavior

### Linux `daemon install`

On `PHP_OS_FAMILY === 'Linux'`, stop refusing with the macOS-only error.

1. Resolve `spotifyd` the way `findSpotifyd()` already does. Missing binary: exit 1 and say to run `spotify daemon:setup`.
2. If `spotifyd.conf` is absent, write it. On Linux the backend value is `pulseaudio`. On Darwin keep the current help-text detection. If the file already exists, change only a non-pulseaudio `backend` line when the OS is Linux. Leave `device_name` alone.
3. Write `~/.config/systemd/user/spotifyd.service.d/override.conf` (under the HOME from the constructor) with exactly this shape:

```
[Unit]
After=pipewire.service pipewire-pulse.service
PartOf=pipewire.service pipewire-pulse.service

[Service]
ExecStart=
ExecStart=/usr/bin/spotifyd --no-daemon --config-path %h/.config/spotify-cli/spotifyd.conf --disable-discovery
Restart=always
RestartSec=5
```

`PartOf` is what restarts this unit when PipeWire restarts. Do not add a second, stronger dependency directive.

4. Write `~/.config/systemd/user/spotifyd-resume.service`:

```
[Unit]
Description=Replay Spotify when PipeWire returns and playback was intended
After=spotifyd.service pipewire-pulse.service
PartOf=pipewire-pulse.service

[Service]
Type=oneshot
ExecStart=<absolute spotify binary> daemon resume-if-intended

[Install]
WantedBy=pipewire-pulse.service
```

Resolve the spotify binary from `realpath($_SERVER['argv'][0])` when that file is executable, else `which spotify`. Tests may pass a fake path into the pure builder.

5. When `systemdAllowed()` is true, run `systemctl --user daemon-reload`, `systemctl --user enable --now spotifyd.service`, and `systemctl --user enable spotifyd-resume.service`. When it is false, skip those commands and still exit 0 after the files exist. Print `user unit installed`.

### Linux `daemon uninstall`

Remove the drop-in and the resume unit, then daemon-reload and disable both units only when `systemdAllowed()`. Missing files: exit 0. Print `user unit removed`. Leave the macOS LaunchAgent path unchanged.

### Intent file

`App\Services\Daemon\PlaybackIntent` reads and writes `{configDir}/playback-intent.json`:

```json
{"playing":true,"uri":"spotify:track:...","device_name":"Work Mac"}
```

- `markPlaying(string $uri, ?string $deviceName): void`
- `markPaused(): void` sets `playing` false and keeps `uri`
- `read(): ?array`

`configDir` is `($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp').'/.config/spotify-cli'`.

Record it only after the Spotify call succeeds:

- `PlayCommand`, immediate play branch, after `$player->play(...)`: `markPlaying($result['uri'], $deviceName)`. Do not mark a queue add.
- `ResumeCommand`, after `transferPlayback` or `resume` returns: `markPlaying` with the uri from `getCurrentPlayback()` when present, otherwise mark playing and keep the stored uri.
- `PauseCommand`, after `$player->pause()` returns: `markPaused()`.

### Silence

`App\Services\Daemon\Silence`:

- `hasSpotifydSink(string $pactlText): bool` is true only when the text contains `application.process.binary = "spotifyd"`.
- `degraded(bool $intentPlaying, bool $ourDevice, bool $hasSink): bool` is true only when all three are intent-playing, our device, and no sink.

`diagnose()` keeps dead / log-error>10 / cache>500. Also degrade when `Silence::degraded(...)` is true. Put `'no-sink-input' => 1` in the `errors` array so existing JSON shape stays. Skip the silence check when `pactl` is missing or exits non-zero (unknown graph is not a failure). `ourDevice` is true when current playback's `device.name` equals the `device_name` in `spotifyd.conf` (fallback `--name`, then `Work Mac`). No playback payload means not our device.

### Heal and replay

New action `resume-if-intended` (add it to the available-actions string):

- intent missing, `playing` false, or `uri` empty: exit 0, no output
- else wait up to 15s for that `device_name` in `getDevices()`, then `$player->play($uri, $deviceId)`
- do not delete cache or rotate logs

`health --heal` when the only problem is `no-sink-input` (process is alive, cache and other error counts are under the existing limits): do not clear cache. Restart via `systemctl --user restart spotifyd.service` when `systemdAllowed()` and the drop-in exists; otherwise keep the current kill-and-start path. Then run the same replay as `resume-if-intended`.

Other heal reasons keep today's cache-clear and log-rotate. On Linux, when the drop-in exists, restart through systemd instead of `posix_kill` plus a raw process.

### daemon:setup

Replace both Linux package hints. The only hint string is `pacman -S --needed spotifyd sox`. Do not execute a package manager. On Linux, `installDependencies()` prints that hint and returns failure. Update `DaemonSetupCommandTest` so it expects that hint and no Debian install command.

## Tests

Extend Pest. Names sit under a `describe` containing `Daemon` so `--filter=Daemon` runs them.

- Drop-in text contains `PartOf=pipewire.service`, `PartOf=pipewire-pulse.service`, both `After=` units, `Restart=always`, `--config-path %h/.config/spotify-cli/spotifyd.conf`, and `--disable-discovery`.
- Resume unit contains `PartOf=pipewire-pulse.service`, `WantedBy=pipewire-pulse.service`, and `daemon resume-if-intended`.
- `Silence::degraded` matrix: true only for intent + our device + no sink. False when intent is false (a real pause).
- `hasSpotifydSink` true for a fixture that includes `application.process.binary = "spotifyd"`, false for empty text and for `application.process.binary = "firefox"`.
- Intent round-trip: markPlaying then read; markPaused keeps uri and sets playing false.
- Linux install with `SPOTIFY_SYSTEMD_DRY=1` and a fake `spotifyd` earlier on `PATH` writes both unit files under the temp HOME and exits 0. Assert `systemctl` was not required (files exist, exit 0).
- Update the existing Linux install/uninstall routing tests. They currently expect the macOS-only error and exit 1. Point them at the new messages.
- Update the available-actions string in `invalidAction` and in `tests/Feature/DaemonCommandTest.php`.

Build the unit text in pure methods (`App\Services\Daemon\LinuxUnit`) and assert those. Do not call the network.

## Done

`php -d disable_functions=pcntl_fork vendor/bin/pest --filter=Daemon` exits 0.
