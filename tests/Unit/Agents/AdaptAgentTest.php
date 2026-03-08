<?php

use App\Agents\AdaptAgent;
use Laravel\Ai\Contracts\Agent;

it('implements Agent interface', function () {
    expect(new AdaptAgent)->toBeInstanceOf(Agent::class);
});

it('returns non-empty instructions', function () {
    $agent = new AdaptAgent;

    expect($agent->instructions())->toBeString()->not->toBeEmpty();
});

it('uses the configured adapt model', function () {
    config(['ai.session.adapt_model' => 'test/adapt-789']);

    $agent = new AdaptAgent;

    expect($agent->model())->toBe('test/adapt-789');
});

it('uses default model from config', function () {
    // Default from config/ai.php is anthropic/claude-haiku-4.5
    $agent = new AdaptAgent;

    expect($agent->model())->toBeString()->not->toBeEmpty();
});

it('uses openrouter provider', function () {
    $agent = new AdaptAgent;

    expect($agent->provider())->toBe('openrouter');
});

it('instructions include JSON output format', function () {
    $agent = new AdaptAgent;

    expect($agent->instructions())
        ->toContain('should_adjust')
        ->toContain('adjusted_phases')
        ->toContain('reasoning')
        ->toContain('JSON');
});
