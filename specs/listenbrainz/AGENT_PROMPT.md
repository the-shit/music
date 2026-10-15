Implement SPEC `specs/listenbrainz/spec.md` exactly.

You are a local coding agent. Pest first. Allowlist only.

Do:
1. Read `specs/listenbrainz/spec.md`. Confirm Status is **LOCKED** before writing production code. If still DRAFT, stop and report.
2. Add failing Pest tests for watch + ListenBrainz (Http::fake): submits on track.changed, no-op without token/flag, swallows 5xx.
3. Implement the scrobble sink and wire it into `WatchCommand` behind `--listenbrainz`.
4. Run `php -d disable_functions=pcntl_fork vendor/bin/pest --filter=Watch`. Done only when new tests exist and pass.
5. Stop. Do not commit. Do not push.

Do not: MPRIS, Qdrant schema changes, CHANGELOG, Packagist.
