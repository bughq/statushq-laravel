<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use Illuminate\Support\Facades\Http;
use StatusHq\Tests\TestCase;

final class ReportMetricsCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('statushq.metrics.token', 'test-token');
        $app['config']->set('statushq.metrics.url', 'https://statushq.test');
    }

    public function test_it_posts_the_sample_to_the_ingest(): void
    {
        Http::fake(['*' => Http::response(['success' => true])]);
        $this->fakeHost($this->containerFiles(usedMb: 256, limitMb: 1024));

        // First run only stores the CPU baseline; the second is the one that
        // has a rate to report.
        $this->artisan('statushq:report')->assertSuccessful();
        $this->fakeHost($this->containerFiles(usedMb: 256, limitMb: 1024, busyJiffies: 1030, idleJiffies: 9070));

        $this->artisan('statushq:report')->assertSuccessful();

        Http::assertSent(function ($request): bool {
            $this->assertSame('https://statushq.test/api/agent/test-token/metrics', $request->url());
            $this->assertSame(30.0, $request->data()['cpuPercent']);
            $this->assertSame(25.0, $request->data()['ramPercent']);
            $this->assertSame(256, $request->data()['ramUsedMb']);
            $this->assertSame(1024, $request->data()['ramTotalMb']);
            $this->assertArrayHasKey('host', $request->data());

            return true;
        });
    }

    public function test_the_first_run_after_a_deploy_is_not_an_error(): void
    {
        // It has no previous counter to difference against. Exiting non-zero
        // would paint the schedule red after every deployment, which is how
        // people learn to ignore a red schedule.
        Http::fake();
        $this->fakeHost($this->containerFiles());

        $this->artisan('statushq:report')
            ->expectsOutputToContain('needs a previous sample')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_dry_run_collects_without_sending(): void
    {
        Http::fake();
        $this->fakeHost($this->containerFiles());

        $this->artisan('statushq:report --dry')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_configured_install_schedules_itself_every_minute(): void
    {
        $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();

        $matching = array_filter($events, static fn ($event): bool => str_contains($event->command ?? '', 'statushq:report'));

        $this->assertCount(1, $matching);
        $this->assertSame('* * * * *', reset($matching)->expression);
    }

    public function test_an_ingest_error_is_reported_as_a_failure(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Monitor not found'], 404)]);
        $this->fakeHost($this->containerFiles());

        $this->artisan('statushq:report')->assertSuccessful();
        $this->fakeHost($this->containerFiles(busyJiffies: 1030, idleJiffies: 9070));

        $this->artisan('statushq:report')
            ->expectsOutputToContain('ingest responded 404')
            ->assertFailed();
    }
}
