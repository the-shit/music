# Launch Copy

Drafts for Show HN and Twitter/X, built around the hook: every commit carries the Spotify track playing when the code was written, CI rejects musicless commits, and the byproduct is an auto-generated soundtrack of the whole codebase.

---

## Show HN

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
- On every push to master, a vibes page regenerates: an auto-generated soundtrack of the entire codebase. Every song that was playing when every line was written.

Vibes page: https://the-shit.github.io/music/vibes.html
Repo: https://github.com/the-shit/music

It started as a joke and then turned out to be weirdly useful. The track on a commit is a timestamp for your mental state — I can tell which commits were 2am death-metal debugging and which were calm Sunday-morning refactors. It's `git blame` for mood.

Under the joke there's a real tool: a Spotify CLI with 30+ commands (play/queue/search/devices), a php-tui terminal player with album art and a search palette (`spotify player:premium`), an MCP server with 12 tools so Claude can DJ while you work, mood-based queue autopilot, and launchd-managed services for headless playback on macOS. Laravel Zero, single binary, no Electron.

Happy to answer questions about the hook mechanics, fighting Spotify's API deprecations (RIP audio-features endpoint), or building TUIs in PHP of all things.

---

## Tweet / Thread

### Single tweet

> My CI rejects commits unless they include the song I was listening to.
>
> A git hook grabs the current Spotify track, CI enforces it on every PR — no music, no merge — and the repo auto-generates a soundtrack of every line ever written.
>
> https://github.com/the-shit/music

### Thread version

**1/**
My CI rejects commits unless they include the song I was listening to. Not a joke — it's a blocking gate next to PHPStan and test coverage. No music, no merge.

**2/**
The mechanics: a pre-commit hook asks my Spotify CLI what's playing and stamps the track URL into the commit message. CI scans every commit on every PR. Miss one and the build is red.

**3/**
The byproduct is the best part: a vibes page that regenerates on every push — an auto-generated soundtrack of the entire codebase. Every song that was playing when every line was written.
https://the-shit.github.io/music/vibes.html

**4/**
It's `git blame` for mood. I can tell which commits were 2am death-metal debugging and which were calm Sunday-morning refactors.

**5/**
Under the joke: a full Spotify CLI — 30+ commands, a php-tui terminal player with album art (`spotify player:premium`), an MCP server so Claude can DJ while you code, mood-based queue autopilot. PHP, single binary, no Electron.
https://github.com/the-shit/music
