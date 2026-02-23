<?php

use App\Commands\AgentCommand;
use App\Commands\AiCommand;

describe('AgentCommand', function () {

    it('has correct command name', function () {
        $command = $this->app->make(AgentCommand::class);
        expect($command->getName())->toBe('agent');
    });

    it('has a description', function () {
        $command = $this->app->make(AgentCommand::class);
        expect($command->getDescription())->not->toBeEmpty();
    });

    it('has action argument', function () {
        $command = $this->app->make(AgentCommand::class);
        $definition = $command->getDefinition();
        expect($definition->hasArgument('action'))->toBeTrue();
    });

    it('has --fix option', function () {
        $command = $this->app->make(AgentCommand::class);
        $definition = $command->getDefinition();
        expect($definition->hasOption('fix'))->toBeTrue();
    });

    it('shows error for unknown agent action', function () {
        $this->artisan('agent', ['action' => 'bogus-agent'])
            ->expectsOutputToContain('Unknown agent')
            ->assertFailed();
    });

    it('lists available agents in error output', function () {
        $this->artisan('agent', ['action' => 'unknown'])
            ->expectsOutputToContain('lint')
            ->expectsOutputToContain('analyze')
            ->assertFailed();
    });

});

describe('AiCommand', function () {

    it('has correct command name', function () {
        $command = $this->app->make(AiCommand::class);
        expect($command->getName())->toBe('ai');
    });

    it('has a description', function () {
        $command = $this->app->make(AiCommand::class);
        expect($command->getDescription())->not->toBeEmpty();
    });

    it('has action argument', function () {
        $command = $this->app->make(AiCommand::class);
        $definition = $command->getDefinition();
        expect($definition->hasArgument('action'))->toBeTrue();
    });

    it('has file argument', function () {
        $command = $this->app->make(AiCommand::class);
        $definition = $command->getDefinition();
        expect($definition->hasArgument('file'))->toBeTrue();
    });

    it('shows help when no action provided', function () {
        $this->artisan('ai')
            ->expectsOutputToContain('AI Agents')
            ->assertSuccessful();
    });

    it('shows help for unknown action', function () {
        $this->artisan('ai', ['action' => 'unknown-action'])
            ->expectsOutputToContain('AI Agents')
            ->assertSuccessful();
    });

    it('help output includes all actions', function () {
        $this->artisan('ai')
            ->expectsOutputToContain('review')
            ->expectsOutputToContain('explain')
            ->expectsOutputToContain('optimize')
            ->expectsOutputToContain('test')
            ->expectsOutputToContain('doc')
            ->assertSuccessful();
    });

});
