Implement SPEC `specs/split-god-commands/spec.md` exactly.

You are a local coding agent. Pest first. Allowlist only.

Do:
1. Read `specs/split-god-commands/spec.md`. Confirm Status is **LOCKED**. If DRAFT, stop.
2. Pick **one** god per job (Daemon **or** PremiumPlayer). Do not split both in one pass.
3. Move logic behind services/controllers; keep Feature tests green. Add Unit tests for extracted pieces.
4. Run the relevant Pest filter (`DaemonCommand` or `PremiumPlayer`). Done only when green and the command file is meaningfully smaller.
5. Stop. Do not commit. Do not push.

Do not: MPRIS, ListenBrainz, signature changes, CHANGELOG.
