# Launch Copy

The highlight is the Omarchy bar plugin. The CI soundtrack is the joke that makes it ours. Drafts to post. Not a product spec.

Plugin: https://github.com/the-shit/omarchy-music
Player: https://github.com/the-shit/music
Vibes: https://the-shit.github.io/music/vibes.html

```bash
omarchy plugin add https://github.com/the-shit/omarchy-music.git
omarchy plugin enable the-shit.music --section right
```

Requires `composer global require the-shit/music` and `spotify login`. It shells out to the `spotify` binary on `PATH`. It does not call the Spotify Web API. It does not replace stock `omarchy.media`. Mood buttons are one-shot queue fill (`chill` / `flow` / `hype`). They do not start `spotify autopilot`.

---

## Omarchy / tweet (post this)

### Single post

> I put my Spotify CLI on the Omarchy bar.
>
> One line. Now-playing chip, skip, chill / flow / hype. It shells out to the `spotify` binary you already have — not another client, not Electron, not a Rust rewrite.
>
> `omarchy plugin add https://github.com/the-shit/omarchy-music.git`

### Thread

**1/**
I put my Spotify CLI on the Omarchy bar. One install line. Now-playing, skip, chill / flow / hype. Not a second Spotify client.

`omarchy plugin add https://github.com/the-shit/omarchy-music.git`

**2/**
The widget talks to the `spotify` binary on PATH. Same CLI as the terminal player, the MCP server, and the git hook. Stock `omarchy.media` stays. You can run both.

**3/**
The same CLI stamps the track you were listening to into every commit. CI rejects musicless PRs. No music, no merge. The repo grows a soundtrack: https://the-shit.github.io/music/vibes.html

**4/**
PHP, single binary, no Electron. Plugin is QML. Player stays `the-shit/music`.

https://github.com/the-shit/omarchy-music
https://github.com/the-shit/music

---

## Show HN (the joke, still true)

### Title

> Show HN: My CI rejects commits unless they include the song I was listening to

Alternates:

> Show HN: Every commit in this repo carries the Spotify track playing when it was written
>
> Show HN: No music, no merge — a CI gate that gives your codebase a soundtrack

### Body

I built a Spotify CLI in PHP, and then it grew a rule: every commit must include the track that was playing when the code was written.

How it works:

- A pre-commit hook asks the CLI what's playing on Spotify and appends the track URL to the commit message.
- A CI gate ("Vibe Check") scans every commit on every PR for a Spotify URL. No music, no merge. It sits next to PHPStan and test coverage — same pipeline, same blocking status.
- On every push to master, a vibes page regenerates: an auto-generated soundtrack of the entire codebase.

The thing I actually want people to install, if they run Omarchy: a bar widget that shells out to that same CLI. Now-playing chip, skip, chill / flow / hype. One line:

```
omarchy plugin add https://github.com/the-shit/omarchy-music.git
```

Vibes: https://the-shit.github.io/music/vibes.html
Plugin: https://github.com/the-shit/omarchy-music
CLI: https://github.com/the-shit/music

It started as a joke and then turned out to be useful. The track on a commit is a timestamp for mental state — which commits were 2am death-metal debugging and which were Sunday-morning refactors. It's `git blame` for mood.

Under the joke: 30+ commands, a php-tui player with album art (`spotify player:premium`), an MCP server so an agent can DJ while you work, mood-based queue fill. Laravel Zero, single binary, no Electron.

Happy to answer questions about the hook, Spotify API deprecations (RIP audio-features), TUIs in PHP, or driving a bar widget from a CLI instead of cloning a media player.
