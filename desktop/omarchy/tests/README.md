# the-shit.music window tests (no Spotify traffic)

Runs the window in-process against a local stub of the Web API, headless on GTK Broadway,
so nothing pops up on the desktop and the real quota is never touched.

```bash
T=$(mktemp -d); mkdir -p $T/cfg $T/state
echo '{"client_id":"stub","client_secret":"stub"}' > $T/cfg/credentials.json
echo '{"access_token":"stub","refresh_token":"r","expires_at":9999999999}' > $T/cfg/token.json
echo '{}' > $T/ctrl.json
export T MOCK_LOG=$T/mock.log MOCK_CTRL=$T/ctrl.json \
  THE_SHIT_MUSIC_CONFIG=$T/cfg THE_SHIT_MUSIC_LOG=$T/app.log SPOTIFY_CLI_STATE_DIR=$T/state \
  THE_SHIT_MUSIC_API_BASE=http://127.0.0.1:18099 THE_SHIT_MUSIC_ACCOUNTS_BASE=http://127.0.0.1:18099 \
  THE_SHIT_MUSIC_VISIBLE=1 GDK_BACKEND=broadway BROADWAY_DISPLAY=:17
gtk4-broadwayd :17 & python3 mock_spotify.py 18099 &
python3 harness.py ../the-shit.music   # expects "SUMMARY 20 / 20"
```
