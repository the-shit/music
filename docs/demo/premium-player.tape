# VHS tape for the player:premium README demo.
# Render with: vhs docs/demo/premium-player.tape
# Requires: active Spotify playback and a closed 429 breaker,
# or the player renders the empty/rate-limited panel.
#
# Keys (from PremiumPlayerCommand): t theme, y lyrics, u up-next,
# l playlists, / search, q quit.

Output docs/demo/premium-player.gif

Set FontSize 16
Set Width 1200
Set Height 460
Set Theme "Catppuccin Mocha"
Set Padding 10

Type "spotify player:premium"
Enter
# Let playback state, album art, and the mood theme render.
Sleep 6s

# Cycle the manual theme override a couple of times.
Type "t"
Sleep 2.5s
Type "t"
Sleep 2.5s

# Lyrics overlay.
Type "y"
Sleep 4s
Escape
Sleep 1.5s

# Up-next queue peek.
Type "u"
Sleep 3s
Escape
Sleep 1.5s

# Search palette.
Type "/"
Sleep 1s
Type "midnight city"
Sleep 3.5s
Escape
Sleep 2s

# Quit cleanly.
Type "q"
Sleep 1s
