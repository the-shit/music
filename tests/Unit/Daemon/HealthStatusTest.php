<?php

declare(strict_types=1);

use App\Services\Daemon\HealthStatus;

describe('daemon health status', function (): void {
    it('degrades when connect is playing and the spotifyd sink-input is gone', function (): void {
        expect(HealthStatus::resolve(4242, 0, 1.2, true))->toBe('degraded');
    });

    it('stays healthy when the sink-input is present and the log is quiet', function (): void {
        expect(HealthStatus::resolve(4242, 0, 1.2, false))->toBe('healthy');
    });

    it('stays dead when the process is gone even if the sink gap flag is set', function (): void {
        expect(HealthStatus::resolve(null, 0, null, true))->toBe('dead');
    });

    it('still degrades on a large error count or a bloated cache', function (): void {
        expect(HealthStatus::resolve(4242, 11, 1.0, false))->toBe('degraded');
        expect(HealthStatus::resolve(4242, 0, 500.1, false))->toBe('degraded');
    });

    it('does not let a handful of errors flip the status', function (): void {
        expect(HealthStatus::resolve(4242, 10, 500.0, false))->toBe('healthy');
    });
});
