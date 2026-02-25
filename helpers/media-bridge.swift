import Cocoa
import MediaPlayer

/// Bridges macOS media keys and Control Center to the spotify CLI.
/// Runs as a background agent — no dock icon, no menu bar.
class MediaBridge: NSObject {
    private let spotifyCli: String
    private let phpPath: String
    private var pollTimer: Timer?
    private var cachedArt: (url: String, image: NSImage)?

    // Track state to avoid redundant pushes
    private var lastTrackUri: String = ""
    private var lastIsPlaying: Bool = false

    // Progress interpolation — timestamp when we last received progress from API
    private var lastPollTime: Date = Date()
    private var lastProgressMs: Int = 0
    private var lastDurationMs: Int = 0

    override init() {
        let home = FileManager.default.homeDirectoryForCurrentUser.path

        // Resolve spotifyCli: prefer PROJECT_DIR env, fall back to common locations
        if let projectDir = ProcessInfo.processInfo.environment["PROJECT_DIR"] {
            self.spotifyCli = projectDir + "/spotify"
        } else if FileManager.default.fileExists(atPath: home + "/Code/music/spotify") {
            self.spotifyCli = home + "/Code/music/spotify"
        } else if FileManager.default.fileExists(atPath: home + "/packages/music/spotify") {
            self.spotifyCli = home + "/packages/music/spotify"
        } else {
            // Last resort: check if 'spotify' is a global composer binary
            self.spotifyCli = home + "/.composer/vendor/bin/spotify"
        }

        // Resolve PHP: prefer PHP_BIN env, then check common paths
        if let phpBin = ProcessInfo.processInfo.environment["PHP_BIN"],
           FileManager.default.fileExists(atPath: phpBin) {
            self.phpPath = phpBin
        } else {
            let candidates = [
                home + "/Library/Application Support/Herd/bin/php",
                "/opt/homebrew/bin/php",
                "/usr/local/bin/php",
                "/usr/bin/php",
            ]
            self.phpPath = candidates.first { FileManager.default.fileExists(atPath: $0) } ?? "/usr/bin/php"
        }

        super.init()
    }

    func start() {
        setupRemoteCommands()
        startPolling()
        NSLog("MediaBridge: started — php=\(phpPath) cli=\(spotifyCli)")
    }

    // MARK: - Remote Command Center

    private func setupRemoteCommands() {
        let center = MPRemoteCommandCenter.shared()

        center.playCommand.isEnabled = true
        center.playCommand.addTarget { [weak self] _ in
            self?.runAndRefresh("resume")
            return .success
        }

        center.pauseCommand.isEnabled = true
        center.pauseCommand.addTarget { [weak self] _ in
            self?.runAndRefresh("pause")
            return .success
        }

        center.togglePlayPauseCommand.isEnabled = true
        center.togglePlayPauseCommand.addTarget { [weak self] _ in
            self?.togglePlayPause()
            return .success
        }

        center.nextTrackCommand.isEnabled = true
        center.nextTrackCommand.addTarget { [weak self] _ in
            self?.runAndRefresh("skip", delay: 1.5)
            return .success
        }

        center.previousTrackCommand.isEnabled = true
        center.previousTrackCommand.addTarget { [weak self] _ in
            self?.runAndRefresh("skip", args: ["previous"], delay: 1.5)
            return .success
        }

        center.changePlaybackPositionCommand.isEnabled = true
        center.changePlaybackPositionCommand.addTarget { [weak self] event in
            guard let self = self,
                  let posEvent = event as? MPChangePlaybackPositionCommandEvent else {
                return .commandFailed
            }
            let ms = Int(posEvent.positionTime * 1000)

            // Immediately update the progress bar so it feels instant
            self.lastProgressMs = ms
            self.lastPollTime = Date()
            self.pushProgress(positionMs: ms, durationMs: self.lastDurationMs, isPlaying: self.lastIsPlaying)

            // Then send the seek to Spotify
            self.runAndRefresh("seek", args: [String(ms)])
            return .success
        }
    }

    // MARK: - Now Playing Info

    private func startPolling() {
        pollTimer = Timer.scheduledTimer(withTimeInterval: 5.0, repeats: true) { [weak self] _ in
            self?.updateNowPlaying()
        }
        updateNowPlaying()
    }

    private func updateNowPlaying() {
        let output = runCapture("current", args: ["--json"])

        guard !output.isEmpty,
              let data = output.data(using: .utf8),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
              let isPlaying = json["is_playing"] as? Bool else {
            NSLog("MediaBridge: no playback data")
            MPNowPlayingInfoCenter.default().playbackState = .stopped
            return
        }

        let track = json["track"] as? String ?? json["name"] as? String ?? "Unknown"
        let artist = json["artist"] as? String ?? "Unknown"
        let album = json["album"] as? String ?? ""
        let progressMs = json["progress_ms"] as? Int ?? 0
        let durationMs = json["duration_ms"] as? Int ?? 0
        let uri = json["uri"] as? String ?? ""

        let trackChanged = uri != lastTrackUri
        let stateChanged = isPlaying != lastIsPlaying

        // Store state for interpolation
        lastPollTime = Date()
        lastProgressMs = progressMs
        lastDurationMs = durationMs
        lastIsPlaying = isPlaying
        lastTrackUri = uri

        // Only log on track change or state change to reduce noise
        if trackChanged || stateChanged {
            NSLog("MediaBridge: \(track) by \(artist) — \(isPlaying ? "playing" : "paused")")
        }

        // Compensate for API latency: the progress_ms value is already slightly stale
        // by the time we receive it. macOS will extrapolate forward from here using
        // the playback rate, so this gives a more accurate starting point.
        let compensatedMs = isPlaying ? progressMs + 200 : progressMs

        var info: [String: Any] = [
            MPMediaItemPropertyTitle: track,
            MPMediaItemPropertyArtist: artist,
            MPMediaItemPropertyAlbumTitle: album,
            MPNowPlayingInfoPropertyElapsedPlaybackTime: Double(compensatedMs) / 1000.0,
            MPMediaItemPropertyPlaybackDuration: Double(durationMs) / 1000.0,
            MPNowPlayingInfoPropertyPlaybackRate: isPlaying ? 1.0 : 0.0,
        ]

        // Cache album art to avoid re-downloading every poll
        if let artUrl = json["album_art_url"] as? String {
            if let cached = cachedArt, cached.url == artUrl {
                let artwork = MPMediaItemArtwork(boundsSize: cached.image.size) { _ in cached.image }
                info[MPMediaItemPropertyArtwork] = artwork
            } else if let url = URL(string: artUrl),
                      let imageData = try? Data(contentsOf: url),
                      let image = NSImage(data: imageData) {
                cachedArt = (url: artUrl, image: image)
                let artwork = MPMediaItemArtwork(boundsSize: image.size) { _ in image }
                info[MPMediaItemPropertyArtwork] = artwork
                NSLog("MediaBridge: loaded album art")
            }
        }

        MPNowPlayingInfoCenter.default().nowPlayingInfo = info
        MPNowPlayingInfoCenter.default().playbackState = isPlaying ? .playing : .paused
    }

    /// Push only progress/rate without re-setting track metadata.
    /// Used for immediate feedback on seek without waiting for next poll.
    private func pushProgress(positionMs: Int, durationMs: Int, isPlaying: Bool) {
        if var info = MPNowPlayingInfoCenter.default().nowPlayingInfo {
            info[MPNowPlayingInfoPropertyElapsedPlaybackTime] = Double(positionMs) / 1000.0
            info[MPMediaItemPropertyPlaybackDuration] = Double(durationMs) / 1000.0
            info[MPNowPlayingInfoPropertyPlaybackRate] = isPlaying ? 1.0 : 0.0
            MPNowPlayingInfoCenter.default().nowPlayingInfo = info
        }
    }

    // MARK: - CLI execution

    private func runAndRefresh(_ command: String, args: [String] = [], delay: Double = 0.5) {
        run(command, args: args)
        DispatchQueue.main.asyncAfter(deadline: .now() + delay) { [weak self] in
            self?.updateNowPlaying()
        }
    }

    private func togglePlayPause() {
        // Use cached state instead of extra API call
        let wasPlaying = lastIsPlaying
        runAndRefresh(wasPlaying ? "pause" : "resume")

        // Immediately flip the playback state for responsive feel
        lastIsPlaying = !wasPlaying
        if var info = MPNowPlayingInfoCenter.default().nowPlayingInfo {
            // When pausing, freeze the progress at the interpolated position
            if wasPlaying {
                let elapsed = Date().timeIntervalSince(lastPollTime)
                let currentMs = lastProgressMs + Int(elapsed * 1000)
                info[MPNowPlayingInfoPropertyElapsedPlaybackTime] = Double(min(currentMs, lastDurationMs)) / 1000.0
            }
            info[MPNowPlayingInfoPropertyPlaybackRate] = wasPlaying ? 0.0 : 1.0
            MPNowPlayingInfoCenter.default().nowPlayingInfo = info
            MPNowPlayingInfoCenter.default().playbackState = wasPlaying ? .paused : .playing
        }
    }

    private func run(_ command: String, args: [String] = []) {
        DispatchQueue.global(qos: .userInitiated).async { [phpPath, spotifyCli] in
            let process = Process()
            process.executableURL = URL(fileURLWithPath: phpPath)
            process.arguments = [spotifyCli, command] + args
            process.standardOutput = FileHandle.nullDevice
            process.standardError = FileHandle.nullDevice
            try? process.run()
            process.waitUntilExit()
        }
    }

    private func runCapture(_ command: String, args: [String] = []) -> String {
        let process = Process()
        let pipe = Pipe()
        process.executableURL = URL(fileURLWithPath: phpPath)
        process.arguments = [spotifyCli, command] + args
        process.standardOutput = pipe
        process.standardError = FileHandle.nullDevice
        try? process.run()
        process.waitUntilExit()
        let data = pipe.fileHandleForReading.readDataToEndOfFile()
        return String(data: data, encoding: .utf8) ?? ""
    }
}

// MARK: - Main

let app = NSApplication.shared
app.setActivationPolicy(.accessory)

let bridge = MediaBridge()
bridge.start()

app.run()
