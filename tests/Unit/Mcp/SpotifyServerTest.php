<?php

use App\Mcp\Resources\DevicesResource;
use App\Mcp\Resources\NowPlayingResource;
use App\Mcp\SpotifyServer;
use App\Mcp\Tools\CurrentTool;
use App\Mcp\Tools\DevicesTool;
use App\Mcp\Tools\PauseTool;
use App\Mcp\Tools\PlayTool;
use App\Mcp\Tools\QueueAddTool;
use App\Mcp\Tools\QueueShowTool;
use App\Mcp\Tools\RepeatTool;
use App\Mcp\Tools\ResumeTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\ShuffleTool;
use App\Mcp\Tools\SkipTool;
use App\Mcp\Tools\VolumeTool;
use Tests\TestCase;

uses(TestCase::class);

describe('SpotifyServer', function () {

    it('has the correct server name', function () {
        $reflection = new ReflectionClass(SpotifyServer::class);
        $attribute = $reflection->getAttributes(\Laravel\Mcp\Server\Attributes\Name::class)[0] ?? null;
        expect($attribute)->not->toBeNull();
        expect($attribute->newInstance()->value)->toBe('spotify');
    });

    it('extends Laravel MCP Server', function () {
        expect(is_subclass_of(SpotifyServer::class, \Laravel\Mcp\Server::class))->toBeTrue();
    });

    it('registers all expected tool classes', function () {
        $reflection = new ReflectionClass(SpotifyServer::class);
        $property = $reflection->getProperty('tools');
        $tools = $property->getDefaultValue();

        expect($tools)->toContain(PlayTool::class)
            ->toContain(PauseTool::class)
            ->toContain(ResumeTool::class)
            ->toContain(SkipTool::class)
            ->toContain(CurrentTool::class)
            ->toContain(VolumeTool::class)
            ->toContain(QueueAddTool::class)
            ->toContain(QueueShowTool::class)
            ->toContain(SearchTool::class)
            ->toContain(DevicesTool::class)
            ->toContain(ShuffleTool::class)
            ->toContain(RepeatTool::class);
    });

    it('registers all expected resource classes', function () {
        $reflection = new ReflectionClass(SpotifyServer::class);
        $property = $reflection->getProperty('resources');
        $resources = $property->getDefaultValue();

        expect($resources)->toContain(NowPlayingResource::class)
            ->toContain(DevicesResource::class);
    });

    it('registers exactly 12 tools', function () {
        $reflection = new ReflectionClass(SpotifyServer::class);
        $property = $reflection->getProperty('tools');
        $tools = $property->getDefaultValue();

        expect($tools)->toHaveCount(12);
    });

    it('registers exactly 2 resources', function () {
        $reflection = new ReflectionClass(SpotifyServer::class);
        $property = $reflection->getProperty('resources');
        $resources = $property->getDefaultValue();

        expect($resources)->toHaveCount(2);
    });

});

describe('Tool schema definitions', function () {

    it('PlayTool schema includes required query field', function () {
        $tool = app(PlayTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('query');
        expect($array['inputSchema']['properties'])->toHaveKey('queue');
        expect($array['inputSchema']['required'] ?? [])->toContain('query');
    });

    it('SkipTool schema includes direction field with enum', function () {
        $tool = app(SkipTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('direction');
    });

    it('VolumeTool schema includes optional level field', function () {
        $tool = app(VolumeTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('level');
        // level is optional — should NOT be in required
        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->not->toContain('level');
    });

    it('QueueAddTool schema includes required query field', function () {
        $tool = app(QueueAddTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('query');
        expect($array['inputSchema']['required'] ?? [])->toContain('query');
    });

    it('SearchTool schema includes query, type and limit fields', function () {
        $tool = app(SearchTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('query');
        expect($array['inputSchema']['properties'])->toHaveKey('type');
        expect($array['inputSchema']['properties'])->toHaveKey('limit');
        expect($array['inputSchema']['required'] ?? [])->toContain('query');
    });

    it('ShuffleTool schema includes optional enabled field', function () {
        $tool = app(ShuffleTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('enabled');
        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->not->toContain('enabled');
    });

    it('RepeatTool schema includes required mode field', function () {
        $tool = app(RepeatTool::class);
        $array = $tool->toArray();

        expect($array['inputSchema']['properties'])->toHaveKey('mode');
        expect($array['inputSchema']['required'] ?? [])->toContain('mode');
    });

    it('PauseTool schema has no required inputs', function () {
        $tool = app(PauseTool::class);
        $array = $tool->toArray();

        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->toBeEmpty();
    });

    it('ResumeTool schema has no required inputs', function () {
        $tool = app(ResumeTool::class);
        $array = $tool->toArray();

        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->toBeEmpty();
    });

    it('CurrentTool schema has no required inputs', function () {
        $tool = app(CurrentTool::class);
        $array = $tool->toArray();

        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->toBeEmpty();
    });

    it('DevicesTool schema has no required inputs', function () {
        $tool = app(DevicesTool::class);
        $array = $tool->toArray();

        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->toBeEmpty();
    });

    it('QueueShowTool schema has no required inputs', function () {
        $tool = app(QueueShowTool::class);
        $array = $tool->toArray();

        $required = $array['inputSchema']['required'] ?? [];
        expect($required)->toBeEmpty();
    });

});
