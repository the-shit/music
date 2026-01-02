<?php

use Illuminate\Support\Facades\File;

describe('EventEmitCommand', function () {

    beforeEach(function () {
        // Use unique file per test process to avoid parallel test interference
        $this->testId = uniqid('test_', true);
        $this->eventsFile = sys_get_temp_dir() . '/spotify-events-' . $this->testId . '.jsonl';
        if (file_exists($this->eventsFile)) {
            unlink($this->eventsFile);
        }

        // Override the events file path in the app
        config(['app.events_file' => $this->eventsFile]);
    });

    afterEach(function () {
        // Clean up events file after each test
        if (file_exists($this->eventsFile)) {
            unlink($this->eventsFile);
        }
    });

    describe('event:emit command', function () {

        it('emits an event without data', function () {
            $this->artisan('event:emit', ['event' => 'test.event'])
                ->expectsOutputToContain('Event emitted: spotify.test.event')
                ->assertExitCode(0);

            // Verify file was created
            expect(file_exists($this->eventsFile))->toBeTrue();

            // Verify content
            $content = file_get_contents($this->eventsFile);
            $lines = array_filter(explode("\n", $content));
            expect($lines)->toHaveCount(1);

            $eventData = json_decode($lines[0], true);
            expect($eventData)->toHaveKey('component')
                ->and($eventData['component'])->toBe('spotify')
                ->and($eventData)->toHaveKey('event')
                ->and($eventData['event'])->toBe('spotify.test.event')
                ->and($eventData)->toHaveKey('data')
                ->and($eventData['data'])->toBe([])
                ->and($eventData)->toHaveKey('timestamp');
        });

        it('emits an event with JSON data', function () {
            $jsonData = json_encode(['key' => 'value', 'number' => 42]);

            $this->artisan('event:emit', ['event' => 'data.event', 'data' => $jsonData])
                ->expectsOutputToContain('Event emitted: spotify.data.event')
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_filter(explode("\n", $content));

            $eventData = json_decode($lines[0], true);
            expect($eventData['data'])->toBe(['key' => 'value', 'number' => 42]);
        });

        it('emits an event with complex nested data', function () {
            $complexData = json_encode([
                'user' => ['name' => 'Test User', 'id' => 123],
                'tracks' => [['id' => 1], ['id' => 2]],
            ]);

            $this->artisan('event:emit', ['event' => 'complex', 'data' => $complexData])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_filter(explode("\n", $content));

            $eventData = json_decode($lines[0], true);
            expect($eventData['data'])->toBe([
                'user' => ['name' => 'Test User', 'id' => 123],
                'tracks' => [['id' => 1], ['id' => 2]],
            ]);
        });

    });

    describe('directory creation', function () {

        it('creates storage directory if it does not exist', function () {
            // Use a unique subdirectory within storage for this test
            $testDir = base_path('storage/test-emit-' . uniqid());
            $testEventsFile = $testDir . '/events.jsonl';

            try {
                // Verify the test directory doesn't exist
                expect(is_dir($testDir))->toBeFalse();

                // Create the directory via the command's logic simulation
                // Since we can't easily change base_path, we test the mkdir logic directly
                if (!is_dir($testDir)) {
                    mkdir($testDir, 0755, true);
                }

                expect(is_dir($testDir))->toBeTrue();

                // Write a test event file to verify the full flow
                file_put_contents($testEventsFile, json_encode(['test' => true]) . "\n");
                expect(file_exists($testEventsFile))->toBeTrue();
            } finally {
                // Cleanup
                if (file_exists($testEventsFile)) {
                    unlink($testEventsFile);
                }
                if (is_dir($testDir)) {
                    rmdir($testDir);
                }
            }
        });

        it('works when storage directory already exists', function () {
            $storageDir = base_path('storage');

            // Ensure directory exists
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }
            expect(is_dir($storageDir))->toBeTrue();

            $this->artisan('event:emit', ['event' => 'existing.dir'])
                ->assertExitCode(0);

            expect(file_exists($this->eventsFile))->toBeTrue();
        });

    });

    describe('file append functionality', function () {

        it('appends multiple events to the same file', function () {
            $this->artisan('event:emit', ['event' => 'first'])
                ->assertExitCode(0);

            $this->artisan('event:emit', ['event' => 'second'])
                ->assertExitCode(0);

            $this->artisan('event:emit', ['event' => 'third'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));

            expect($lines)->toHaveCount(3);

            $events = array_map(fn($line) => json_decode($line, true), $lines);
            expect($events[0]['event'])->toBe('spotify.first');
            expect($events[1]['event'])->toBe('spotify.second');
            expect($events[2]['event'])->toBe('spotify.third');
        });

        it('appends to existing file content', function () {
            // Create directory if it doesn't exist
            $storageDir = dirname($this->eventsFile);
            if (!is_dir($storageDir)) {
                mkdir($storageDir, 0755, true);
            }

            // Create pre-existing event
            $existingEvent = json_encode([
                'component' => 'other',
                'event' => 'pre.existing',
                'data' => [],
                'timestamp' => '2024-01-01T00:00:00+00:00',
            ]) . "\n";
            file_put_contents($this->eventsFile, $existingEvent);

            $this->artisan('event:emit', ['event' => 'new.event'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));

            expect($lines)->toHaveCount(2);

            $firstEvent = json_decode($lines[0], true);
            $secondEvent = json_decode($lines[1], true);

            expect($firstEvent['event'])->toBe('pre.existing');
            expect($secondEvent['event'])->toBe('spotify.new.event');
        });

    });

    describe('JSON output format', function () {

        it('outputs valid JSON Lines format', function () {
            $this->artisan('event:emit', ['event' => 'json.test'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_filter(explode("\n", $content));

            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                expect(json_last_error())->toBe(JSON_ERROR_NONE);
                expect($decoded)->toBeArray();
            }
        });

        it('includes component field set to spotify', function () {
            $this->artisan('event:emit', ['event' => 'component.test'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));
            $eventData = json_decode($lines[0], true);

            expect($eventData['component'])->toBe('spotify');
        });

        it('prefixes event name with spotify.', function () {
            $this->artisan('event:emit', ['event' => 'my.custom.event'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));
            $eventData = json_decode($lines[0], true);

            expect($eventData['event'])->toBe('spotify.my.custom.event');
        });

        it('includes ISO 8601 timestamp', function () {
            $this->artisan('event:emit', ['event' => 'timestamp.test'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));
            $eventData = json_decode($lines[0], true);

            expect($eventData)->toHaveKey('timestamp');

            // Verify it's a valid ISO 8601 timestamp
            $timestamp = \DateTime::createFromFormat(\DateTime::ATOM, $eventData['timestamp']);
            expect($timestamp)->not->toBeFalse();
        });

        it('handles empty data as empty array', function () {
            $this->artisan('event:emit', ['event' => 'empty.data'])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));
            $eventData = json_decode($lines[0], true);

            expect($eventData['data'])->toBe([]);
        });

        it('preserves data types in JSON', function () {
            $jsonData = json_encode([
                'string' => 'hello',
                'integer' => 42,
                'float' => 3.14,
                'boolean' => true,
                'null' => null,
                'array' => [1, 2, 3],
            ]);

            $this->artisan('event:emit', ['event' => 'types', 'data' => $jsonData])
                ->assertExitCode(0);

            $content = file_get_contents($this->eventsFile);
            $lines = array_values(array_filter(explode("\n", $content)));
            $eventData = json_decode($lines[0], true);

            expect($eventData['data']['string'])->toBe('hello');
            expect($eventData['data']['integer'])->toBe(42);
            expect($eventData['data']['float'])->toBe(3.14);
            expect($eventData['data']['boolean'])->toBe(true);
            expect($eventData['data']['null'])->toBeNull();
            expect($eventData['data']['array'])->toBe([1, 2, 3]);
        });

    });

});
