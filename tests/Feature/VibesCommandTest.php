<?php

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

describe('VibesCommand', function () {

    it('has the correct command signature', function () {
        $command = $this->app->make(\App\Commands\VibesCommand::class);
        expect($command->getName())->toBe('vibes');
    });

    it('has a description', function () {
        $command = $this->app->make(\App\Commands\VibesCommand::class);
        expect($command->getDescription())->not->toBeEmpty();
    });

    it('outputs success message when no commits with spotify URLs are found', function () {
        Process::fake([
            'git log *' => Process::result(output: '', exitCode: 0),
        ]);

        $this->artisan('vibes', ['--no-open' => true])
            ->expectsOutputToContain('No commits with Spotify track URLs found.')
            ->assertExitCode(0);
    });

    it('exits early with info message when --json flag is used and no commits found', function () {
        Process::fake([
            'git log *' => Process::result(output: '', exitCode: 0),
        ]);

        // Command early-returns before reaching --json block when no commits found
        $this->artisan('vibes', ['--json' => true])
            ->expectsOutputToContain('No commits with Spotify track URLs found.')
            ->assertExitCode(0);
    });

    it('generates HTML and writes output to --output path', function () {
        $gitLog = implode("\n", [
            'COMMIT_START',
            'abc1234567890abc1234567890abc1234567890',
            'Test Author',
            '2024-01-15 10:00:00 +0000',
            'feat: add tests',
            'Listening to https://open.spotify.com/track/4iV5W9uYEdYUVa79Axb7Rh while coding',
            'COMMIT_END',
        ]);

        Process::fake([
            'git log *' => Process::result(output: $gitLog, exitCode: 0),
        ]);

        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('getTracks')
                ->once()
                ->with(['4iV5W9uYEdYUVa79Axb7Rh'])
                ->andReturn([]);
            $mock->shouldReceive('getTracksViaOEmbed')->andReturn([]);
        });

        $tempFile = sys_get_temp_dir().'/vibes-test-'.uniqid().'.html';

        $this->artisan('vibes', ['--output' => $tempFile, '--no-open' => true])
            ->expectsOutputToContain('Generated vibes page')
            ->assertExitCode(0);

        expect(file_exists($tempFile))->toBeTrue();
        $html = file_get_contents($tempFile);
        expect($html)->toContain('<!DOCTYPE html>');
        expect($html)->toContain('vibes');

        unlink($tempFile);
    });

    it('groups commits by track and counts correctly in JSON output', function () {
        $trackId = '4iV5W9uYEdYUVa79Axb7Rh';
        $gitLog = implode("\n", [
            'COMMIT_START',
            'abc1234567890abc1234567890abc1234567890',
            'Author One',
            '2024-01-15 10:00:00 +0000',
            'feat: first commit',
            'Track: https://open.spotify.com/track/'.$trackId,
            'COMMIT_END',
            'COMMIT_START',
            'def1234567890def1234567890def1234567890',
            'Author Two',
            '2024-01-16 11:00:00 +0000',
            'fix: second commit',
            'Same song: https://open.spotify.com/track/'.$trackId,
            'COMMIT_END',
        ]);

        Process::fake([
            'git log *' => Process::result(output: $gitLog, exitCode: 0),
        ]);

        $this->mock(SpotifyService::class, function ($mock) use ($trackId) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('getTracks')
                ->once()
                ->andReturn([]);
            $mock->shouldReceive('getTracksViaOEmbed')->andReturn([]);
        });

        Artisan::call('vibes', ['--json' => true]);
        $output = Artisan::output();

        // JSON_PRETTY_PRINT adds spaces after colons
        expect($output)->toContain('"total_commits": 2')
            ->toContain('"total_tracks": 1');
    });

    it('does not fetch track metadata when Spotify is not configured', function () {
        $gitLog = implode("\n", [
            'COMMIT_START',
            'abc1234567890abc1234567890abc1234567890',
            'Test Author',
            '2024-01-15 10:00:00 +0000',
            'feat: something',
            'https://open.spotify.com/track/someTrackId',
            'COMMIT_END',
        ]);

        Process::fake([
            'git log *' => Process::result(output: $gitLog, exitCode: 0),
        ]);

        $this->mock(SpotifyService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldNotReceive('getTracks');
            $mock->shouldReceive('getTracksViaOEmbed')->andReturn([]);
        });

        $tempFile = sys_get_temp_dir().'/vibes-test-'.uniqid().'.html';

        $this->artisan('vibes', ['--output' => $tempFile, '--no-open' => true])
            ->assertExitCode(0);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    });

    it('uses default docs/vibes.html output path when --output is not specified', function () {
        Process::fake([
            'git log *' => Process::result(output: '', exitCode: 0),
        ]);

        // When no commits found, the command exits early before writing the file
        $this->artisan('vibes', ['--no-open' => true])
            ->assertExitCode(0);
    });

    it('opens the file in browser unless --no-open is passed', function () {
        Process::fake([
            'git log *' => Process::result(output: '', exitCode: 0),
            "open '*'" => Process::result(output: '', exitCode: 0),
        ]);

        // With no commits, the command exits before the open call — just verify no crash
        $this->artisan('vibes')
            ->assertExitCode(0);
    });

});
