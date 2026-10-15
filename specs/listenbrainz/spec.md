# SPEC: ListenBrainz scrobbling via watch events

Status: **DRAFT**
Issue: https://github.com/the-shit/music/issues/57 (closed help-wanted — reopen or replace when this SPEC locks)
Repo: `the-shit/music`

**Story:** Spotify’s Web API keeps shrinking (audio-features / recommendations already soft-fail). Persist listening outside Spotify by scrobbling track-change events from `spotify watch` to ListenBrainz.

## Already shipped (do not rebuild)

- `spotify watch` / `watch --json` emitting `track.changed`, `playback.paused`, `playback.resumed`
- Webhook HMAC forwarding via `event:emit` / `webhook:configure`
- Qdrant listening history + `history:backfill`

## Locked decisions (when status → LOCKED)

- Flag: `spotify watch --listenbrainz` (and/or config `LISTENBRAINZ_TOKEN` / setup prompt). Default off — existing watch behavior unchanged.
- On `track.changed`, submit a ListenBrainz “listen” (fire-and-forget). Log failures; never block the watch loop.
- Token via env `LISTENBRAINZ_TOKEN` or `~/.config/spotify-cli/listenbrainz.json`. Optional `spotify setup` prompt is nice-to-have, not required for v1.
- Payload: artist name(s), track title, optional Spotify recording MBID / `track.extra.spotify_id` from the watch event.
- Deduplicate: do not scrobble the same track URI twice within a short window (e.g. 30s) if watch flaps.

## Acceptance Criteria

- [ ] `spotify watch --listenbrainz` submits track changes to ListenBrainz
- [ ] Token configurable via env (and optionally setup)
- [ ] Without the flag / token, watch behavior is unchanged
- [ ] Network/API errors are logged and do not exit the watch loop
- [ ] Pest tests cover submit-on-track-change, skip-without-token, and error swallow with Http::fake

## Do not

- Replace Qdrant history with ListenBrainz
- Scrobble Last.fm in the same SPEC (can share a sink interface later)
- CHANGELOG / Packagist until LOCKED and green
