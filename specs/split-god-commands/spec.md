# SPEC: Split DaemonCommand and PremiumPlayerCommand

Status: **DRAFT**
Repo: `the-shit/music`

**Story:** `SpotifyService` was already split into focused services (`SpotifyAuthManager`, `SpotifyPlayerService`, `SpotifyDiscoveryService`, …). Two command gods remain: `DaemonCommand` (~1.2k LOC) and `PremiumPlayerCommand` (~1k LOC). Split them the same way so behavior stays testable without opening a thousand-line file for every fix.

## Already shipped (do not rebuild)

- Service-layer split of `SpotifyService` (#121)
- `ResolvesDevice` heal-once path for play/resume
- Daemon health / heal / Arch systemd user unit paths

## Locked decisions (when status → LOCKED)

### DaemonCommand

- Keep the public CLI surface: `spotify daemon {setup,start,stop,status,health,install,uninstall}` with existing flags.
- Extract collaborators under `App\Services\Daemon\` (extend what already exists: `Process`, config, health):
  - Install / LaunchAgent / systemd unit writers
  - Health check + heal orchestration
  - Start/stop lifecycle
- `DaemonCommand` becomes a thin dispatcher that maps `action` → service method and renders output.
- Do not change JSON shapes used by Omarchy / bar callers.

### PremiumPlayerCommand

- Keep `spotify player:premium` signature and keybindings.
- Extract under `App\Player\` (renderer/themes already live there):
  - Input/keymap loop controller
  - Search palette actions
  - Queue editor / lyrics overlay coordinators
- Command class owns bootstrapping + the main tick loop only.

## Acceptance Criteria

- [ ] `DaemonCommand.php` and `PremiumPlayerCommand.php` each drop substantially (target under 400 LOC) without behavior change
- [ ] Existing Feature tests for daemon + premium player stay green without rewriting assertions
- [ ] New Unit tests cover extracted collaborators where logic moved
- [ ] PHPStan level 6 clean on new code; Pint clean

## Do not

- Change CLI signatures or `--json` contracts
- Merge Autopilot into this SPEC (optional follow-up)
- Drive-by refactors of mood commands / MCP tools
- CHANGELOG / Packagist until LOCKED and green
