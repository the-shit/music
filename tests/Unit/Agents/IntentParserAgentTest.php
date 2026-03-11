<?php

use App\Agents\IntentParserAgent;
use Laravel\Ai\Contracts\Agent;

it('implements Agent interface', function (): void {
    expect(new IntentParserAgent)->toBeInstanceOf(Agent::class);
});

it('returns non-empty instructions', function (): void {
    $agent = new IntentParserAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toBeString()->not->toBeEmpty();
});

it('includes mood presets in instructions', function (): void {
    $agent = new IntentParserAgent;
    $instructions = $agent->instructions();

    expect($instructions)->toContain('chill')
        ->toContain('flow')
        ->toContain('hype')
        ->toContain('energy')
        ->toContain('valence')
        ->toContain('tempo');
});

it('uses the configured parser model', function (): void {
    config(['ai.session.parser_model' => 'test/model-123']);

    $agent = new IntentParserAgent;

    expect($agent->model())->toBe('test/model-123');
});

it('uses openrouter provider', function (): void {
    $agent = new IntentParserAgent;

    expect($agent->provider())->toBe('openrouter');
});

it('instructions include JSON output format', function (): void {
    $agent = new IntentParserAgent;

    expect($agent->instructions())
        ->toContain('phases')
        ->toContain('total_duration')
        ->toContain('playlist_name')
        ->toContain('JSON');
});
