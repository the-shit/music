<?php

use App\Agents\CuratorAgent;
use Laravel\Ai\Contracts\Agent;

it('implements Agent interface', function () {
    expect(new CuratorAgent)->toBeInstanceOf(Agent::class);
});

it('returns non-empty instructions', function () {
    $agent = new CuratorAgent;

    expect($agent->instructions())->toBeString()->not->toBeEmpty();
});

it('uses the configured curator model', function () {
    config(['ai.session.curator_model' => 'test/curator-456']);

    $agent = new CuratorAgent;

    expect($agent->model())->toBe('test/curator-456');
});

it('uses default model from config', function () {
    // Default from config/ai.php is x-ai/grok-3
    $agent = new CuratorAgent;

    expect($agent->model())->toBeString()->not->toBeEmpty();
});

it('instructions include JSON output format', function () {
    $agent = new CuratorAgent;

    expect($agent->instructions())
        ->toContain('playlist_name')
        ->toContain('track_uris')
        ->toContain('dj_note')
        ->toContain('JSON');
});
