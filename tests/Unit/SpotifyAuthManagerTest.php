<?php

use App\Services\SpotifyAuthManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tokenFile = sys_get_temp_dir().'/spotify_auth_test_token.json';

    // Set config BEFORE constructing — constructor calls loadTokenData()
    Config::set('spotify.client_id', 'test_client_id');
    Config::set('spotify.client_secret', 'test_client_secret');
    Config::set('spotify.token_path', $this->tokenFile);

    // Ensure no leftover token file from previous test
    if (file_exists($this->tokenFile)) {
        unlink($this->tokenFile);
    }

    $this->auth = new SpotifyAuthManager;
});

afterEach(function () {
    if (file_exists($this->tokenFile)) {
        unlink($this->tokenFile);
    }
});

describe('SpotifyAuthManager', function () {

    describe('Authentication', function () {

        it('checks if configured correctly', function () {
            if (file_exists($this->tokenFile)) {
                unlink($this->tokenFile);
            }

            expect($this->auth->isConfigured())->toBeFalse();

            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'test_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() + 3600,
            ]));

            $method = (new ReflectionClass($this->auth))->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($this->auth);

            expect($this->auth->isConfigured())->toBeTrue();
        });

        it('reports configured when only refresh_token is available', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => null,
                'refresh_token' => 'refresh_token',
                'expires_at' => null,
            ]));

            $method = (new ReflectionClass($this->auth))->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($this->auth);

            expect($this->auth->isConfigured())->toBeTrue();
        });

        it('saves cleared state to disk on 4xx refresh failure', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'revoked_token',
                'expires_at' => time() - 100,
            ]));

            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'error' => 'invalid_grant',
                    'error_description' => 'Refresh token revoked',
                ], 400),
            ]);

            $method = (new ReflectionClass($this->auth))->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($this->auth);

            $refresh = (new ReflectionClass($this->auth))->getMethod('refreshAccessToken');
            $refresh->setAccessible(true);
            $refresh->invoke($this->auth);

            // Verify the cleared state was persisted to disk
            $tokenData = json_decode(file_get_contents($this->tokenFile), true);
            expect($tokenData['access_token'])->toBeNull();
            expect($tokenData['expires_at'])->toBeNull();
        });

        it('reloads token data from disk', function () {
            // Start with expired token
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() - 100,
            ]));

            $method = (new ReflectionClass($this->auth))->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($this->auth);

            // Simulate external login writing fresh tokens
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'fresh_external_token',
                'refresh_token' => 'fresh_refresh_token',
                'expires_at' => time() + 3600,
            ]));

            $reload = (new ReflectionClass($this->auth))->getMethod('reloadFromDisk');
            $reload->setAccessible(true);
            $reload->invoke($this->auth);

            $tokenProp = (new ReflectionClass($this->auth))->getProperty('accessToken');
            $tokenProp->setAccessible(true);
            expect($tokenProp->getValue($this->auth))->toBe('fresh_external_token');
        });

        it('refreshes expired token', function () {
            file_put_contents($this->tokenFile, json_encode([
                'access_token' => 'old_token',
                'refresh_token' => 'refresh_token',
                'expires_at' => time() - 100,
            ]));

            Http::fake([
                'accounts.spotify.com/api/token' => Http::response([
                    'access_token' => 'new_token',
                    'expires_in' => 3600,
                ]),
            ]);

            $method = (new ReflectionClass($this->auth))->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($this->auth);

            $method = (new ReflectionClass($this->auth))->getMethod('ensureValidToken');
            $method->setAccessible(true);
            $method->invoke($this->auth);

            $tokenData = json_decode(file_get_contents($this->tokenFile), true);
            expect($tokenData['access_token'])->toBe('new_token');
        });
    });

    describe('Legacy token migration', function () {

        it('reads token from JSON file at primary token path', function () {
            // Verify that a JSON token file at the primary path is loaded correctly
            $tmpFile = sys_get_temp_dir().'/legacy_test_primary_'.uniqid().'.json';
            file_put_contents($tmpFile, json_encode([
                'access_token' => 'primary_token',
                'refresh_token' => 'primary_refresh',
                'expires_at' => time() + 3600,
            ]));

            $auth = new SpotifyAuthManager;
            $reflection = new ReflectionClass($auth);

            $prop = $reflection->getProperty('tokenFile');
            $prop->setAccessible(true);
            $prop->setValue($auth, $tmpFile);

            $accessProp = $reflection->getProperty('accessToken');
            $accessProp->setAccessible(true);
            $accessProp->setValue($auth, null);

            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($auth);

            expect($accessProp->getValue($auth))->toBe('primary_token');

            @unlink($tmpFile);
        });

        it('returns without setting token when tokenFile contains invalid JSON', function () {
            // If tokenFile exists but contains garbage data, should not crash
            $tmpFile = sys_get_temp_dir().'/legacy_test_bad_'.uniqid().'.json';
            file_put_contents($tmpFile, 'not-json-and-not-a-legacy-location');

            $auth = new SpotifyAuthManager;
            $reflection = new ReflectionClass($auth);

            $prop = $reflection->getProperty('tokenFile');
            $prop->setAccessible(true);
            $prop->setValue($auth, $tmpFile);

            $accessProp = $reflection->getProperty('accessToken');
            $accessProp->setAccessible(true);
            $accessProp->setValue($auth, null);

            // Should not throw — just won't load any token
            $method = $reflection->getMethod('loadTokenData');
            $method->setAccessible(true);
            $method->invoke($auth);

            // Token remains null (bad JSON = falsy decode)
            expect($accessProp->getValue($auth))->toBeNull();

            @unlink($tmpFile);
        });

    });

    describe('Token persistence', function () {

        it('creates config directory if it does not exist when saving token', function () {
            $dir = sys_get_temp_dir().'/spotify_new_dir_'.uniqid();
            $newFile = $dir.'/token.json';

            expect(is_dir($dir))->toBeFalse();

            // Re-build auth with new tokenFile path
            $auth = new SpotifyAuthManager;
            $reflection = new ReflectionClass($auth);

            $tokenFileProp = $reflection->getProperty('tokenFile');
            $tokenFileProp->setAccessible(true);
            $tokenFileProp->setValue($auth, $newFile);

            $accessProp = $reflection->getProperty('accessToken');
            $accessProp->setAccessible(true);
            $accessProp->setValue($auth, 'some_token');

            $method = $reflection->getMethod('saveTokenData');
            $method->setAccessible(true);
            $method->invoke($auth);

            expect(is_dir($dir))->toBeTrue();
            expect(file_exists($newFile))->toBeTrue();

            // Cleanup
            unlink($newFile);
            rmdir($dir);
        });

    });

});
