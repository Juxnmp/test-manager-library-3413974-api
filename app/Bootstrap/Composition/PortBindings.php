<?php

declare(strict_types=1);

namespace App\Bootstrap\Composition;

use App\Adapters\Outbound\Persistence\PostgresBookRepository;
use App\Adapters\Outbound\Persistence\PostgresCategoryRepository;
use App\Adapters\Outbound\Persistence\PostgresLoanRepository;
use App\Adapters\Outbound\Persistence\PostgresMemberRepository;
use App\Adapters\Outbound\Persistence\PostgresReportReader;
use App\Adapters\Outbound\Persistence\PostgresUserRepository;
use App\Adapters\Outbound\Security\JwtTokenIssuer;
use App\Adapters\Outbound\Security\NativePasswordHasher;
use App\Adapters\Outbound\Storage\LocalCoverStorage;
use App\Application\Ports\Outbound\BookRepository;
use App\Application\Ports\Outbound\CategoryRepository;
use App\Application\Ports\Outbound\Clock;
use App\Application\Ports\Outbound\CoverStorage;
use App\Application\Ports\Outbound\LoanRepository;
use App\Application\Ports\Outbound\MemberRepository;
use App\Application\Ports\Outbound\PasswordHasher;
use App\Application\Ports\Outbound\ReportReader;
use App\Application\Ports\Outbound\TokenIssuer;
use App\Application\Ports\Outbound\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Single point of composition (Constitution Article III, architecture.md §2).
 * Connects all application outbound ports with their concrete outbound adapters.
 * Outside of this module, no outbound adapter class may be instantiated.
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

        $this->app->singleton(PasswordHasher::class, static function (): PasswordHasher {
            return new NativePasswordHasher();
        });

        $this->app->singleton(TokenIssuer::class, static function (Application $app): TokenIssuer {
            $secret = (string) config('app.jwt_secret', env('JWT_SECRET', ''));
            $issuerUrl = (string) config('app.url', 'http://localhost:8000');

            return new JwtTokenIssuer(
                secret: $secret,
                issuerUrl: $issuerUrl,
                clock: $app->make(Clock::class),
            );
        });

        $this->app->singleton(CoverStorage::class, static function (): CoverStorage {
            $coversDir = (string) config('app.covers_dir', storage_path('app/covers'));

            return new LocalCoverStorage($coversDir);
        });

        $this->app->singleton(CategoryRepository::class, static function (): CategoryRepository {
            return new PostgresCategoryRepository();
        });

        $this->app->singleton(UserRepository::class, static function (): UserRepository {
            return new PostgresUserRepository();
        });

        $this->app->singleton(MemberRepository::class, static function (): MemberRepository {
            return new PostgresMemberRepository();
        });

        $this->app->singleton(BookRepository::class, static function (): BookRepository {
            return new PostgresBookRepository();
        });

        $this->app->singleton(LoanRepository::class, static function (): LoanRepository {
            return new PostgresLoanRepository();
        });

        $this->app->singleton(ReportReader::class, static function (): ReportReader {
            return new PostgresReportReader();
        });
    }
}
