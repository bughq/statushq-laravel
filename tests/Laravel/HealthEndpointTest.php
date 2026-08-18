<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use StatusHq\Health\Check;
use StatusHq\Health\CheckResult;
use StatusHq\Laravel\HealthRegistry;
use StatusHq\Laravel\Http\HealthController;
use StatusHq\Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_it_serves_the_schema_the_consumer_parses(): void
    {
        $this->fakeHost($this->containerFiles());

        $response = $this->getJson('statushq-health-check-results');

        $response->assertOk();
        $response->assertJsonStructure([
            'finishedAt',
            'checkResults' => [['name', 'label', 'status', 'notificationMessage', 'shortSummary', 'meta']],
        ]);

        // A string of unix seconds, not an ISO date and not a number: Oh Dear
        // and StatusHQ both measure their staleness window against it.
        $this->assertIsString($response->json('finishedAt'));
        $this->assertMatchesRegularExpression('/^\d{10}$/', $response->json('finishedAt'));
    }

    public function test_the_three_host_checks_are_reported_by_default(): void
    {
        $this->fakeHost($this->containerFiles(usedMb: 256, limitMb: 1024));

        $response = $this->getJson('statushq-health-check-results');

        $this->assertSame(['CpuUsage', 'UsedMemory', 'UsedDiskSpace'], $response->json('checkResults.*.name'));
        $this->assertSame('ok', $response->json('checkResults.1.status'));
        $this->assertSame('25%', $response->json('checkResults.1.shortSummary'));
    }

    public function test_a_wrong_secret_gets_no_report_at_all(): void
    {
        // The check names describe the application's internals — which queues
        // it runs, which services it talks to. That is not for anonymous
        // callers, so the body is withheld rather than merely unauthenticated.
        config()->set('statushq.health.secret', 'the-real-secret');
        $this->fakeHost($this->containerFiles());

        $response = $this->getJson('statushq-health-check-results', [HealthController::SECRET_HEADER => 'guess']);

        $response->assertForbidden();
        $response->assertJsonMissingPath('checkResults');
    }

    public function test_the_right_secret_is_let_through(): void
    {
        config()->set('statushq.health.secret', 'the-real-secret');
        $this->fakeHost($this->containerFiles());

        $this->getJson('statushq-health-check-results', [HealthController::SECRET_HEADER => 'the-real-secret'])
            ->assertOk();
    }

    public function test_a_missing_secret_header_is_rejected_when_one_is_configured(): void
    {
        config()->set('statushq.health.secret', 'the-real-secret');

        $this->getJson('statushq-health-check-results')->assertForbidden();
    }

    public function test_a_failing_check_still_returns_two_hundred(): void
    {
        // The status code answers "did the endpoint work". The body answers
        // "is the app healthy". A 500 here is indistinguishable from the
        // server being down, which loses the detail the endpoint carries.
        $this->fakeHost($this->containerFiles(usedMb: 1000, limitMb: 1024));

        $response = $this->getJson('statushq-health-check-results');

        $response->assertOk();
        $this->assertSame('failed', $response->json('checkResults.1.status'));
    }

    public function test_an_unreadable_host_is_skipped_rather_than_failed(): void
    {
        // macOS, or a container with open_basedir clamped down. "We could not
        // look" is not evidence of ill health.
        $this->fakeHost([]);

        $response = $this->getJson('statushq-health-check-results');

        $response->assertOk();
        $this->assertSame('skipped', $response->json('checkResults.1.status'));
    }

    public function test_the_registry_replaces_the_defaults(): void
    {
        $this->fakeHost($this->containerFiles());

        app(HealthRegistry::class)->checks([
            new class implements Check
            {
                public function name(): string
                {
                    return 'Database';
                }

                public function label(): string
                {
                    return 'Database';
                }

                public function run(): CheckResult
                {
                    return CheckResult::ok($this->name(), $this->label(), 'reachable');
                }
            },
        ]);

        $response = $this->getJson('statushq-health-check-results');

        $this->assertSame(['Database'], $response->json('checkResults.*.name'));
    }

}
