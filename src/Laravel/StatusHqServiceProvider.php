<?php

declare(strict_types=1);

namespace StatusHq\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use StatusHq\Health\Checks\CpuUsageCheck;
use StatusHq\Health\Checks\UsedDiskSpaceCheck;
use StatusHq\Health\Checks\UsedMemoryCheck;
use StatusHq\Health\Runner;
use StatusHq\Laravel\Console\ReportMetricsCommand;
use StatusHq\Laravel\Http\HealthController;
use StatusHq\Metrics\Collector;
use StatusHq\Metrics\CpuReader;
use StatusHq\Metrics\CpuSampler;
use StatusHq\Metrics\DiskReader;
use StatusHq\Metrics\MemoryReader;
use StatusHq\Support\Clock;
use StatusHq\Support\FileReader;
use StatusHq\Support\StateStore;
use StatusHq\Support\SystemClock;
use StatusHq\Support\SystemFileReader;

final class StatusHqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/statushq.php', 'statushq');

        // Bound as interfaces so an application's own tests can swap in
        // fixtures and assert on what its health endpoint reports under a
        // full disk — without a full disk.
        $this->app->bind(FileReader::class, SystemFileReader::class);
        $this->app->bind(Clock::class, SystemClock::class);

        $this->app->singleton(StateStore::class, fn ($app) => new CacheStateStore($app->make(Repository::class)));

        $this->app->singleton(CpuSampler::class, fn ($app) => new CpuSampler(
            new CpuReader($app->make(FileReader::class), $app->make(Clock::class)),
            $app->make(StateStore::class),
            $app->make(Clock::class),
        ));

        $this->app->singleton(MemoryReader::class, fn ($app) => new MemoryReader($app->make(FileReader::class)));
        $this->app->singleton(DiskReader::class, fn () => new DiskReader());
        $this->app->singleton(Runner::class, fn ($app) => new Runner($app->make(Clock::class)));

        $this->app->singleton(Collector::class, fn ($app) => new Collector(
            $app->make(CpuSampler::class),
            $app->make(MemoryReader::class),
            $app->make(DiskReader::class),
            (string) config('statushq.metrics.disk_path', '/'),
            config('statushq.metrics.host') ?: null,
        ));

        $this->app->singleton(HealthRegistry::class, fn ($app) => new HealthRegistry($this->defaultChecks($app)));

        // Bound unconditionally, and told about the token only when it is
        // resolved. Deciding here whether to bind at all would read config
        // during register(), which is not reliably populated yet — the
        // binding would silently vanish depending on bootstrap order.
        $this->app->singleton(Reporter::class, fn ($app) => new Reporter(
            $app->make(Factory::class),
            (string) config('statushq.metrics.url', 'https://statushq.org'),
            (string) (config('statushq.metrics.token') ?? ''),
            (int) config('statushq.metrics.timeout', 5),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/statushq.php' => config_path('statushq.php'),
            ], 'statushq-config');

            $this->commands([ReportMetricsCommand::class]);
        }

        $this->registerHealthRoute();
        $this->registerSchedule();
    }

    private function registerHealthRoute(): void
    {
        if (! config('statushq.health.enabled', true)) {
            return;
        }

        // Fail closed. `composer require` must not be enough to publish an
        // unauthenticated description of the application's internals: without
        // a secret the route is never registered, so the URL 404s like any
        // other unknown path rather than advertising that this is installed.
        $secret = config('statushq.health.secret');
        $unauthenticated = (bool) config('statushq.health.allow_unauthenticated', false);

        if (! $unauthenticated && (! is_string($secret) || $secret === '')) {
            return;
        }

        $path = (string) config('statushq.health.path', 'statushq-health-check-results');

        Route::get($path, HealthController::class)
            ->middleware((array) config('statushq.health.middleware', []))
            ->name('statushq.health');
    }

    private function registerSchedule(): void
    {
        if (! config('statushq.metrics.enabled', true) || ! config('statushq.metrics.schedule', true)) {
            return;
        }

        // An unconfigured install must not run a minutely task that can only
        // ever fail: that is noise in every log its owner reads afterwards.
        if (! $this->app->make(Reporter::class)->isConfigured()) {
            return;
        }

        // callAfterResolving rather than resolving the scheduler here: asking
        // for it during boot instantiates it on every request, including the
        // ones that will never schedule anything.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command(ReportMetricsCommand::class)
                ->everyMinute()
                // Without this, a slow ingest response can stack runs on top
                // of each other until the box is full of php processes.
                ->withoutOverlapping()
                ->runInBackground();
        });
    }

    /**
     * @return list<\StatusHq\Health\Check>
     */
    private function defaultChecks(\Illuminate\Contracts\Foundation\Application $app): array
    {
        $thresholds = (array) config('statushq.health.thresholds', []);

        return [
            new CpuUsageCheck(
                $app->make(CpuSampler::class),
                (float) ($thresholds['cpu']['warn'] ?? 75),
                (float) ($thresholds['cpu']['fail'] ?? 90),
            ),
            new UsedMemoryCheck(
                $app->make(MemoryReader::class),
                (float) ($thresholds['memory']['warn'] ?? 80),
                (float) ($thresholds['memory']['fail'] ?? 95),
            ),
            new UsedDiskSpaceCheck(
                $app->make(DiskReader::class),
                (string) config('statushq.metrics.disk_path', '/'),
                (float) ($thresholds['disk']['warn'] ?? 70),
                (float) ($thresholds['disk']['fail'] ?? 90),
            ),
        ];
    }
}
