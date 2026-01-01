<?php

describe('InspireCommand', function () {

    it('outputs an inspiring quote', function () {
        $this->artisan('inspire')
            ->expectsOutputToContain('Simplicity is the ultimate sophistication')
            ->assertExitCode(0);
    });

    it('displays Laravel Zero branding', function () {
        $this->artisan('inspire')
            ->expectsOutputToContain('Laravel Zero')
            ->assertExitCode(0);
    });

    it('accepts an optional name argument', function () {
        $this->artisan('inspire', ['name' => 'Developer'])
            ->assertExitCode(0);
    });

    it('uses default name when no argument provided', function () {
        $this->artisan('inspire')
            ->assertExitCode(0);
    });

});
