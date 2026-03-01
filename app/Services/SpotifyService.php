<?php

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
        $this->auth = new SpotifyAuthManager();
        $this->player = new SpotifyPlayerService($this->auth);
        $this->discovery = new SpotifyDiscoveryService($this->auth);
    }

    public function isConfigured(): bool
    {
        return $this->auth->isConfigured();
    }

    public function search(string $query, string $type = 'track'): ?array
    {
        return $this->discovery->search($query, $type);
    }

    public function play(string $uri, ?string $deviceId = null): void
    {
        $this->player->play($uri, $deviceId);
    }

    public function resume(?string $deviceId = null): void
    {
        $this->player->resume($deviceId);
    }

    public function pause(): void
    {
        $this->player->pause();
    }

    public function setVolume(int $volumePercent): bool
    {
        return $this->player->setVolume($volumePercent);
    }

    public function next(): void
    {
        $this->player->next();
    }

    public function previous(): void
    {
        $this->player->previous();
    }

    public function getDevices(): array
    {
        return $this->player->getDevices();
    }

    public function transferPlayback(string $deviceId, bool $play = true): void
    {
        $this->player->transferPlayback($deviceId, $play);
    }

    public function getActiveDevice(): ?array
    {
        return $this->player->getActiveDevice();
    }

    public function addToQueue(string $uri): void
    {
        $this->player->addToQueue($uri);
    }

    public function getQueue(): array
    {
        return $this->player->getQueue();
    }

    public function searchMultiple(string $query, string $type = 'track', int $limit = 10): array
    {
        return $this->discovery->searchMultiple($query, $type, $limit);
    }

    public function getTopTracks(string $timeRange = 'medium_term', int $limit = 20): array
    {
        return $this->discovery->getTopTracks($timeRange, $limit);
    }

    public function getTopArtists(string $timeRange = 'medium_term', int $limit = 20): array
    {
        return $this->discovery->getTopArtists($timeRange, $limit);
    }

    public function getRecentlyPlayed(int $limit = 20): array
    {
        return $this->discovery->getRecentlyPlayed($limit);
    }

    public function searchByGenre(string $genre, string $mood = '', int $limit = 10): array
    {
        return $this->discovery->searchByGenre($genre, $mood, $limit);
    }

    public function getRecommendations(array $seedTrackIds = [], array $seedArtistIds = [], int $limit = 10, array $audioFeatures = [], ?string $currentArtist = null): array
    {
        return $this->discovery->getRecommendations($seedTrackIds, $seedArtistIds, $limit, $audioFeatures, $currentArtist);
    }

    public function getSmartRecommendations(int $limit = 10, ?string $currentArtist = null, array $audioFeatures = []): array
    {
        return $this->discovery->getSmartRecommendations($limit, $currentArtist, $audioFeatures);
    }

    public function getRelatedTracks(string $artistName, string $trackName, int $limit = 10): array
    {
        return $this->discovery->getRelatedTracks($artistName, $trackName, $limit);
    }

    public function seek(int $positionMs): void
    {
        $this->player->seek($positionMs);
    }

    public function setShuffle(bool $state): bool
    {
        return $this->player->setShuffle($state);
    }

    public function setRepeat(string $state): bool
    {
        return $this->player->setRepeat($state);
    }

    public function getCurrentPlayback(): ?array
    {
        return $this->player->getCurrentPlayback();
    }

    public function getTracks(array $trackIds): array
    {
        return $this->discovery->getTracks($trackIds);
    }

    public function getTracksViaOEmbed(array $trackIds): array
    {
        return $this->discovery->getTracksViaOEmbed($trackIds);
    }

    public function getPlaylists(int $limit = 20): array
    {
        return $this->discovery->getPlaylists($limit);
    }

    public function getPlaylistTracks(string $playlistId): array
    {
        return $this->discovery->getPlaylistTracks($playlistId);
    }

    public function playPlaylist(string $playlistId, ?string $deviceId = null): bool
    {
        return $this->player->playPlaylist($playlistId, $deviceId);
    }

    public function createPlaylist(string $name, string $description = '', bool $public = true): ?array
    {
        return $this->discovery->createPlaylist($name, $description, $public);
    }

    public function replacePlaylistTracks(string $playlistId, array $trackUris): bool
    {
        return $this->discovery->replacePlaylistTracks($playlistId, $trackUris);
    }

    public function findPlaylistByName(string $name): ?array
    {
        return $this->discovery->findPlaylistByName($name);
    }

    public function updatePlaylistDetails(string $playlistId, array $details): bool
    {
        return $this->discovery->updatePlaylistDetails($playlistId, $details);
    }

    public function getUserProfile(): ?array
    {
        return $this->discovery->getUserProfile();
    }
}
