<?php

declare(strict_types=1);

namespace StatusHq\Tests\Laravel;

use StatusHq\Tests\TestCase;

final class HealthConfigTest extends TestCase
{
    private const SECRET = 'config-test-secret';

    protected function defineEnvironment($app): void
    {
        // Without a secret the route is not registered at all.
        $app['config']->set('statushq.health.secret', self::SECRET);

        // A team replacing Oh Dear keeps the URL it already has configured
        // everywhere; a team running both packages needs them not to collide.
        // Both are one config line, which is why the default is neither.
        $app['config']->set('statushq.health.path', 'oh-dear-health-check-results');
        $app['config']->set('statushq.health.thresholds.memory', ['warn' => 10, 'fail' => 20]);
    }

    private function health(string $path): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($path, [\StatusHq\Laravel\Http\HealthController::SECRET_HEADER => self::SECRET]);
    }

    public function test_the_endpoint_path_is_configurable(): void
    {
        $this->fakeHost($this->containerFiles());

        $this->health('oh-dear-health-check-results')->assertOk();
        $this->health('statushq-health-check-results')->assertNotFound();
    }

    public function test_thresholds_come_from_config(): void
    {
        // 25% used, against a failure line lowered to 20%.
        $this->fakeHost($this->containerFiles(usedMb: 256, limitMb: 1024));

        $response = $this->health('oh-dear-health-check-results');

        $this->assertSame('failed', $response->json('checkResults.1.status'));
    }
}
