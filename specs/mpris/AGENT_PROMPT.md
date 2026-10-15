Implement SPEC `specs/mpris/spec.md` exactly.

You are a local coding agent. Pest first. Allowlist only.

Do:
1. Read `specs/mpris/spec.md`. Confirm Status is **LOCKED** before writing production code. If still DRAFT, stop and report.
2. Add failing Pest tests for `setup:mpris` (or the agreed command name) covering Darwin refusal, missing D-Bus deps, and status/uninstall happy paths with mocks.
3. Implement the Linux MPRIS helper + command. Prefer consuming `spotify watch --json` / `spotify current --json` over a second API client.
4. Run `php -d disable_functions=pcntl_fork vendor/bin/pest --filter=Mpris`. Done only when new tests exist and pass.
5. Stop. Do not commit. Do not push.

Do not: Swift bridge changes, Omarchy GTK rewrite, ListenBrainz, CHANGELOG, Packagist.
