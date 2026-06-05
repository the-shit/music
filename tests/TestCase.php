<?php

namespace Tests;

use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Point spotify.token_path at a per-process temp dir by default.
     *
     * WHY: the SpotifyRateLimit breaker persists its 429 deadline NEXT TO the
     * token file. With the real default (~/.config/spotify-cli/) a genuine
     * rate-limit on the developer's machine would short-circuit every faked
     * HTTP call in the suite — tests must never read (or write) the real
     * breaker/token state. Tests that care about the path still override it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['spotify.token_path' => sys_get_temp_dir().'/spotify-cli-test-'.getmypid().'/token.json']);
    }
}
