<?php

declare(strict_types=1);

namespace App\Bootstrap\Composition;

use App\Application\Ports\Outbound\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\ServiceProvider;

/**
 * Single point of composition (Constitution Article III, architecture.md §2).
 * Connects application outbound ports with their concrete adapters.
 */
final class PortBindings extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, static function (): Clock {
            return new class implements Clock {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
                }
            };
        });
    }
}
