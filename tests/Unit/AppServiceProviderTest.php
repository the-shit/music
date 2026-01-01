<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

uses(TestCase::class);

describe('AppServiceProvider', function () {

    describe('class structure', function () {

        it('extends ServiceProvider', function () {
            $provider = new AppServiceProvider($this->app);

            expect($provider)->toBeInstanceOf(ServiceProvider::class);
        });

    });

    describe('register method', function () {

        it('exists and is callable', function () {
            $provider = new AppServiceProvider($this->app);

            expect(method_exists($provider, 'register'))->toBeTrue();
        });

        it('returns void', function () {
            $provider = new AppServiceProvider($this->app);

            $result = $provider->register();

            expect($result)->toBeNull();
        });

    });

    describe('boot method', function () {

        it('exists and is callable', function () {
            $provider = new AppServiceProvider($this->app);

            expect(method_exists($provider, 'boot'))->toBeTrue();
        });

        it('returns void', function () {
            $provider = new AppServiceProvider($this->app);

            $result = $provider->boot();

            expect($result)->toBeNull();
        });

        it('loads dotenv when .env file exists', function () {
            // Create a temporary directory with a .env file
            $tempDir = sys_get_temp_dir().'/appserviceprovider_test_'.uniqid();
            mkdir($tempDir);
            file_put_contents($tempDir.'/.env', 'TEST_VAR_UNIQUE=test_value_123');

            // Mock base_path to return our temp directory
            $this->app->setBasePath($tempDir);

            $provider = new AppServiceProvider($this->app);
            $provider->boot();

            // Verify the environment variable was loaded
            expect(env('TEST_VAR_UNIQUE'))->toBe('test_value_123');

            // Cleanup
            unlink($tempDir.'/.env');
            rmdir($tempDir);
        });

        it('does not throw when .env file does not exist', function () {
            // Create a temporary directory without a .env file
            $tempDir = sys_get_temp_dir().'/appserviceprovider_test_'.uniqid();
            mkdir($tempDir);

            // Ensure no .env file exists
            if (file_exists($tempDir.'/.env')) {
                unlink($tempDir.'/.env');
            }

            $this->app->setBasePath($tempDir);

            $provider = new AppServiceProvider($this->app);

            // Should not throw an exception
            expect(fn () => $provider->boot())->not->toThrow(Exception::class);

            // Cleanup
            rmdir($tempDir);
        });

    });

    describe('provider registration', function () {

        it('is registered in the application', function () {
            $providers = $this->app->getLoadedProviders();

            expect($providers)->toHaveKey(AppServiceProvider::class);
        });

    });

});
