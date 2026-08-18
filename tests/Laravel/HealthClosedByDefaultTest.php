<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use StatusHq\Tests\TestCase;

/**
 * A fresh install serves nothing.
 *
 * `composer require` must not be enough to publish an unauthenticated
 * description of the application's internals — check names say which queues it
 * runs and which services it depends on, and the host checks add disk usage and
 * memory totals.
 */
final class HealthClosedByDefaultTest extends TestCase
{
    public function test_without_a_secret_the_route_does_not_exist(): void
    {
        $this->fakeHost($this->containerFiles());

        // 404 rather than 403: an unregistered route is indistinguishable from
        // any other unknown path, so the response does not advertise that this
        // package is installed.
        $this->getJson('statushq-health-check-results')->assertNotFound();
    }

    public function test_metrics_push_still_works_with_no_health_secret(): void
    {
        // The two halves are independent. Someone who installed this to push
        // CPU and memory must not have to configure a health secret they will
        // never use.
        $this->assertTrue($this->app->bound(\StatusHq\Laravel\Reporter::class));
    }
}
