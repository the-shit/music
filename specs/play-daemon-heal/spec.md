# SPEC: Heal the daemon once when play/resume cannot see it

Status: **LOCKED**
Issue: https://github.com/the-shit/music/issues/156
Repo: `the-shit/music`

**Story:** `spotify play` / `spotify resume` (the Omarchy bar play button is `resume --json`) must bring the local Connect speaker back if this machine opted into the daemon. Today they only look up the device name from `spotifyd.conf` and continue. Heal is a separate command. Autopilot heals on consecutive failures. Play does not.

PipeWire bounce with a still-listed Connect device is **not** this SPEC. That is `specs/daemon-systemd/` (sink-input + user unit). This SPEC is: conf exists, daemon device missing from Connect (or process dead) → one heal → retry lookup → then play/resume as today.

## Already shipped (do not rebuild)

- `spotify daemon health --heal` (cache/log/pid; Linux still kill-pid + spawn until systemd SPEC lands)
- Autopilot consecutive-failure heal
- `ResolvesDevice` reads `device_name` from `~/.config/spotify-cli/spotifyd.conf`
- Hostname as Connect name (`DeviceResolution`)

## Locked decisions

- Shared path: `ResolvesDevice` (used by `play` and `resume`). Do not duplicate heal in each command.
- Heal at most **once** per invocation, only when **all** of:
  1. No `--device=` was passed (explicit device is the user's target; do not restart spotifyd for a phone).
  2. `spotifyd.conf` exists and has a `device_name`.
  3. First `getDevices()` lookup does **not** find that name (or id) **OR** the local spotifyd process is dead (`App\Services\Daemon\Process::isAlive()` is false). Connect listing the speaker after a kill does **not** count as alive.
- Then: `$this->call('daemon', ['action' => 'health', '--heal' => true])` when not `--json`; `$this->callSilently(...)` when `--json`. Then **one** retry of `getDevices()` / find (the Connect id may change after restart).
- If still missing: continue as today (`play` may throw "No Spotify devices available"; `resume` same). Do not loop.
- Do **not** call heal when the daemon device **is** in the Connect list **and** the process is alive. Cache-size / log-error `degraded` is `spotify daemon health --heal`, not a side effect of play.
- Do **not** call heal when there is no `spotifyd.conf` (user never opted into the daemon). Existing Play/Resume tests stay green.
- `--json` output stays JSON-only. Heal chatter is silent.

## Tests (Pest first, then code)

Filter: `php -d disable_functions=pcntl_fork vendor/bin/pest --filter='PlayCommand|ResumeCommand'`

Must add (names can vary):

1. `play` with a `spotifyd.conf` `device_name` that is **absent** from `getDevices()` invokes daemon `health` with `--heal`, then retries `getDevices()`, then plays on the device found on retry.
2. `resume` same, then transfers/resumes on the retried device.
3. `play --json` in that state still prints only JSON (no "Healing daemon" / PID lines).
4. `play --device=Phone` with a conf present does **not** invoke daemon health.
5. `play` with **no** conf does **not** invoke daemon health (existing happy-path still passes with `play(..., null)`).
6. `play` with conf, Connect **still listing** the daemon name, process **dead** → heal once, retry `getDevices()`, play on the retried id.
7. `play` with conf, Connect listing the daemon name, process **alive** → no heal.

Isolate `HOME` / `$_SERVER['HOME']` like `DaemonCommandTest` (phpunit.xml forces `HOME=/tmp/spotify-cli-test` — do not write the fixture there). Mock the player; do not talk to Spotify or systemd.

## Do not

- Systemd user unit, `pactl`, sink-input health (other SPEC)
- Chill / flow / hype / skip / pause / autopilot
- Packagist / version bump / CHANGELOG / README
- macOS LaunchAgent rewrite
- Heal on cache-size or log-error alone
- Commit unless the PlayCommand|ResumeCommand filter is green. Do not push.

## Done when (Pest)

- Filter `PlayCommand|ResumeCommand` green
- Allowlist only
- Forbidden strings absent
