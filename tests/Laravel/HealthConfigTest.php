<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use StatusHq\Tests\TestCase;

final class HealthConfigTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // A team replacing Oh Dear keeps the URL it already has configured
        // everywhere; a team running both packages needs them not to collide.
        // Both are one config line, which is why the default is neither.
        $app['config']->set('statushq.health.path', 'oh-dear-health-check-results');
        $app['config']->set('statushq.health.thresholds.memory', ['warn' => 10, 'fail' => 20]);
    }

    public function test_the_endpoint_path_is_configurable(): void
    {
        $this->fakeHost($this->containerFiles());

        $this->getJson('oh-dear-health-check-results')->assertOk();
        $this->getJson('statushq-health-check-results')->assertNotFound();
    }

    public function test_thresholds_come_from_config(): void
    {
        // 25% used, against a failure line lowered to 20%.
        $this->fakeHost($this->containerFiles(usedMb: 256, limitMb: 1024));

        $response = $this->getJson('oh-dear-health-check-results');

        $this->assertSame('failed', $response->json('checkResults.1.status'));
    }
}
