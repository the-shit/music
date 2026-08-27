Implement SPEC `specs/play-daemon-heal/spec.md` exactly.

You are a local Ollama coding agent. Pest first. Allowlist only.

The previous job failed: it added a resume positional `{device?}` alias and stopped when the existing 26 tests passed. That is not the SPEC. Revert any positional-arg idea. Do not change command signatures.

Do:
1. Read `specs/play-daemon-heal/spec.md` and `app/Commands/Concerns/ResolvesDevice.php`.
2. FIRST add the five failing Pest tests named in the SPEC to `tests/Feature/PlayCommandTest.php` and `tests/Feature/ResumeCommandTest.php`. Isolate HOME. Mock the player. Existing tests that do not mention heal must still pass.
3. Put heal-once-then-retry in `ResolvesDevice` only. `play` and `resume` already use that trait. Do not edit `PlayCommand` / `ResumeCommand` unless a test forces it.
4. Run `php -d disable_functions=pcntl_fork vendor/bin/pest --filter='PlayCommand|ResumeCommand'`. Done only when the NEW heal tests exist and pass. The old 26 passing alone is failure.
5. Stop. Do not commit. Do not push.

Locked: heal only when no `--device=`, conf exists with `device_name`, first lookup misses. Once. `--json` uses `callSilently`. Do not heal when the device is already listed. Do not heal when there is no conf.

Do not: systemd, pactl, chill/flow/hype, CHANGELOG, README, LaunchAgent rewrite, positional resume args, composer publish.
