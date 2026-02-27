&lt;?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;

class SpotifyService
{
    private SpotifyAuthManager $auth;
    private SpotifyPlayerService $player;
    private SpotifyDiscoveryService $discovery;

    public function __construct()
    {
        $this-&gt;auth = new SpotifyAuthManager();
        $this-&gt;player = new SpotifyPlayerService($this-&gt;auth);
        $this-&gt;discovery = new SpotifyDiscoveryService($this-&gt;auth);
    }

    public function isConfigured(): bool
    {
        return $this-&gt;auth-&gt;isConfigured();
    }

    public function search(string $query, string $type = &#x27;track&#x27;): ?array
    {
        return $this-&gt;discovery-&gt;search($query, $type);
    }

    public function play(string $uri, ?string $deviceId = null): void
    {
        $this-&gt;player-&gt;play($uri, $deviceId);
    }

    public function resume(?string $deviceId = null): void
    {
        $this-&gt;player-&gt;resume($deviceId);
    }

    public function pause(): void
    {
        $this-&gt;player-&gt;pause();
    }

    public function setVolume(int $volumePercent): bool
    {
        return $this-&gt;player-&gt;setVolume($volumePercent);
    }

    public function next(): void
    {
        $this-&gt;player-&gt;next();
    }

    public function previous(): void
    {
        $this-&gt;player-&gt;previous();
    }

    public function getDevices(): array
    {
        return $this-&gt;player-&gt;getDevices();
    }

    public function transferPlayback(string $deviceId, bool $play = true): void
    {
        $this-&gt;player-&gt;transferPlayback($deviceId, $play);
    }

    public function getActiveDevice(): ?array
    {
        return $this-&gt;player-&gt;getActiveDevice();
    }

    public function addToQueue(string $uri): void
    {
        $this-&gt;player-&gt;addToQueue($uri);
    }

    public function getQueue(): array
    {
        return $this-&gt;player-&gt;getQueue();
    }

    public function searchMultiple(string $query, string $type = &#x27;track&#x27;, int $limit = 10): array
    {
        return $this-&gt;discovery-&gt;searchMultiple($query, $type, $limit);
    }

    public function getTopTracks(string $timeRange = &#x27;medium_term&#x27;, int $limit = 20): array
    {
        return $this-&gt;discovery-&gt;getTopTracks($timeRange, $limit);
    }

    public function getTopArtists(string $timeRange = &#x27;medium_term&#x27;, int $limit = 20): array
    {
        return $this-&gt;discovery-&gt;getTopArtists($timeRange, $limit);
    }

    public function getRecentlyPlayed(int $limit = 20): array
    {
        return $this-&gt;discovery-&gt;getRecentlyPlayed($limit);
    }

    public function searchByGenre(string $genre, string $mood = &#x27;&#x27;, int $limit = 10): array
    {
        return $this-&gt;discovery-&gt;searchByGenre($genre, $mood, $limit);
    }

    public function getRecommendations(array $seedTrackIds = [], array $seedArtistIds = [], int $limit = 10, array $audioFeatures = [], ?string $currentArtist = null): array
    {
        return $this-&gt;discovery-&gt;getRecommendations($seedTrackIds, $seedArtistIds, $limit, $audioFeatures, $currentArtist);
    }

    public function getSmartRecommendations(int $limit = 10, ?string $currentArtist = null, array $audioFeatures = []): array
    {
        return $this-&gt;discovery-&gt;getSmartRecommendations($limit, $currentArtist, $audioFeatures);
    }

    public function getRelatedTracks(string $artistName, string $trackName, int $limit = 10): array
    {
        return $this-&gt;discovery-&gt;getRelatedTracks($artistName, $trackName, $limit);
    }

    public function seek(int $positionMs): void
    {
        $this-&gt;player-&gt;seek($positionMs);
    }

    public function setShuffle(bool $state): bool
    {
        return $this-&gt;player-&gt;setShuffle($state);
    }

    public function setRepeat(string $state): bool
    {
        return $this-&gt;player-&gt;setRepeat($state);
    }

    public function getCurrentPlayback(): ?array
    {
        return $this-&gt;player-&gt;getCurrentPlayback();
    }

    public function getTracks(array $trackIds): array
    {
        return $this-&gt;discovery-&gt;getTracks($trackIds);
    }

    public function getTracksViaOEmbed(array $trackIds): array
    {
        return $this-&gt;discovery-&gt;getTracksViaOEmbed($trackIds);
    }

    public function getPlaylists(int $limit = 20): array
    {
        return $this-&gt;discovery-&gt;getPlaylists($limit);
    }

    public function getPlaylistTracks(string $playlistId): array
    {
        return $this-&gt;discovery-&gt;getPlaylistTracks($playlistId);
    }

    public function playPlaylist(string $playlistId, ?string $deviceId = null): bool
    {
        return $this-&gt;player-&gt;playPlaylist($playlistId, $deviceId);
    }

    public function createPlaylist(string $name, string $description = &#x27;&#x27;, bool $public = true): ?array
    {
        return $this-&gt;discovery-&gt;createPlaylist($name, $description, $public);
    }

    public function replacePlaylistTracks(string $playlistId, array $trackUris): bool
    {
        return $this-&gt;discovery-&gt;replacePlaylistTracks($playlistId, $trackUris);
    }

    public function findPlaylistByName(string $name): ?array
    {
        return $this-&gt;discovery-&gt;findPlaylistByName($name);
    }

    public function updatePlaylistDetails(string $playlistId, array $details): bool
    {
        return $this-&gt;discovery-&gt;updatePlaylistDetails($playlistId, $details);
    }

    public function getUserProfile(): ?array
    {
        return $this-&gt;discovery-&gt;getUserProfile();
    }
}
