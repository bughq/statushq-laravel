<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use StatusHq\Tests\TestCase;

/**
 * The escape hatch for endpoints that are not publicly reachable — a private
 * network, or a Kubernetes liveness probe that cannot send a header.
 */
final class HealthUnauthenticatedTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('statushq.health.allow_unauthenticated', true);
    }

    public function test_it_serves_without_a_secret_when_explicitly_allowed(): void
    {
        $this->fakeHost($this->containerFiles());

        $this->getJson('statushq-health-check-results')
            ->assertOk()
            ->assertJsonStructure(['finishedAt', 'checkResults']);
    }
}
