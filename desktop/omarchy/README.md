# Omarchy desktop (in-repo)

This folder is the **optional GTK tiled music window** for Omarchy/Hyprland — not the bar plugin.

| Surface | Where | Install |
|---|---|---|
| Bar chip (now-playing, play/pause, mood popup) | [`the-shit/omarchy-music`](https://github.com/the-shit/omarchy-music) | `omarchy plugin add …` |
| Full-window player + Super+M summon | `desktop/omarchy/` (this tree) | Copy/link `the-shit.music` and `the-shit.music-summon` onto your PATH |

## Requirements

- This CLI on `PATH` (or set `SPOTIFY_BIN` to the absolute binary)
- `spotify login` completed
- Hyprland + GTK4 (Omarchy theme colors are read from `~/.local/state/omarchy/current/theme/colors.toml` when present)

## Binary resolution

`the-shit.music` resolves the CLI in this order:

1. `SPOTIFY_BIN` env var (must be executable)
2. `spotify` on `PATH` (`shutil.which`)
3. Common Composer / local bin locations under `$HOME`

It no longer assumes `~/.config/composer/vendor/bin/spotify` alone.
