<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use TheShit\Vector\Data\Point;
use TheShit\Vector\Qdrant;
use TheShit\Vector\QdrantConnector;

class QdrantEventSink
{
    private ?Qdrant $client = null;

    public function isConfigured(): bool
    {
        return config('spotify.qdrant.url') !== null
            && config('spotify.qdrant.collection') !== null;
    }

    public function sink(array $eventData): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $event = $eventData['event'] ?? '';
        $data = $eventData['data'] ?? [];

        // Only sink track-related events
        if (! str_contains($event, 'track')) {
            return;
        }

        $description = $this->describe($event, $data);
        $vector = $this->embed($description);

        if ($vector === []) {
            return;
        }

        $point = new Point(
            id: $this->generateId(),
            vector: $vector,
            payload: [
                'event' => $event,
                'track' => $data['track'] ?? 'unknown',
                'artist' => $data['artist'] ?? 'unknown',
                'album' => $data['album'] ?? 'unknown',
                'uri' => $data['uri'] ?? '',
                'is_playing' => $data['is_playing'] ?? true,
                'description' => $description,
                'timestamp' => $eventData['timestamp'] ?? now()->toIso8601String(),
                'hour' => (int) now()->format('H'),
                'day_of_week' => now()->format('l'),
            ],
        );

        try {
            $this->getClient()->upsert(
                config('spotify.qdrant.collection'),
                [$point],
            );
        } catch (\Throwable) {
            // Fire-and-forget — don't block the CLI
        }
    }

    private function describe(string $event, array $data): string
    {
        $track = $data['track'] ?? 'unknown track';
        $artist = $data['artist'] ?? 'unknown artist';
        $time = now()->format('g:ia l');

        return match (true) {
            str_contains($event, 'played') => "Jordan played {$track} by {$artist} at {$time}",
            str_contains($event, 'skipped') => "Jordan skipped {$track} by {$artist} at {$time}",
            str_contains($event, 'changed') => "Jordan listened to {$track} by {$artist} at {$time}",
            str_contains($event, 'resumed') => "Jordan resumed {$track} by {$artist} at {$time}",
            default => "Track event: {$track} by {$artist} at {$time}",
        };
    }

    /**
     * @return array<float>
     */
    private function embed(string $text): array
    {
        $server = config('spotify.qdrant.embedding_server');
        if ($server === null) {
            return [];
        }

        try {
            $response = Http::timeout(5)
                ->post("{$server}/embed", ['text' => $text]);

            if ($response->failed()) {
                return [];
            }

            $embeddings = $response->json('embeddings.0');

            return is_array($embeddings)
                ? array_map(fn ($v): float => (float) $v, $embeddings)
                : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function generateId(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    private function getClient(): Qdrant
    {
        if ($this->client === null) {
            $this->client = new Qdrant(
                new QdrantConnector(
                    baseUrl: config('spotify.qdrant.url'),
                    apiKey: config('spotify.qdrant.api_key'),
                ),
            );
        }

        return $this->client;
    }
}
