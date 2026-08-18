<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use StatusHq\Tests\TestCase;

final class MissingTokenTest extends TestCase
{
    public function test_the_command_says_what_to_set(): void
    {
        Http::fake();
        $this->fakeHost($this->containerFiles());

        $this->artisan('statushq:report')
            ->expectsOutputToContain('STATUSHQ_METRICS_TOKEN')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_nothing_is_scheduled_without_a_token(): void
    {
        // An unconfigured install must not run a minutely task that can only
        // ever fail — that is noise in every log the user reads afterwards.
        $events = app(Schedule::class)->events();

        $this->assertEmpty(array_filter(
            $events,
            static fn ($event): bool => str_contains($event->command ?? '', 'statushq:report'),
        ));
    }
}
