import Cocoa
import MediaPlayer

/// Bridges macOS media keys and Control Center to the spotify CLI.
/// Runs as a background agent — no dock icon, no menu bar.
class MediaBridge: NSObject {
    private let spotifyCli: String
    private let phpPath: String
    private var pollTimer: Timer?
    private var cachedArt: (url: String, image: NSImage)?

    override init() {
        let home = FileManager.default.homeDirectoryForCurrentUser.path
        self.spotifyCli = home + "/Code/music/spotify"
        self.phpPath = home + "/Library/Application Support/Herd/bin/php"
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
            self?.runAndRefresh("skip", args: ["--previous"], delay: 1.5)
            return .success
        }

        center.changePlaybackPositionCommand.isEnabled = true
        center.changePlaybackPositionCommand.addTarget { [weak self] event in
            guard let posEvent = event as? MPChangePlaybackPositionCommandEvent else {
                return .commandFailed
            }
            let ms = Int(posEvent.positionTime * 1000)
            self?.runAndRefresh("seek", args: [String(ms)])
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

        NSLog("MediaBridge: \(track) by \(artist) — \(isPlaying ? "playing" : "paused")")

        var info: [String: Any] = [
            MPMediaItemPropertyTitle: track,
            MPMediaItemPropertyArtist: artist,
            MPMediaItemPropertyAlbumTitle: album,
            MPNowPlayingInfoPropertyElapsedPlaybackTime: Double(progressMs) / 1000.0,
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

    // MARK: - CLI execution

    private func runAndRefresh(_ command: String, args: [String] = [], delay: Double = 0.5) {
        run(command, args: args)
        DispatchQueue.main.asyncAfter(deadline: .now() + delay) { [weak self] in
            self?.updateNowPlaying()
        }
    }

    private func togglePlayPause() {
        let output = runCapture("current", args: ["--json"])
        if let data = output.data(using: .utf8),
           let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
           let isPlaying = json["is_playing"] as? Bool {
            runAndRefresh(isPlaying ? "pause" : "resume")
        } else {
            runAndRefresh("resume")
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
