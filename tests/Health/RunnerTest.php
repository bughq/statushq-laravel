<?php

declare(strict_types=1);

namespace StatusHq\Tests\Health;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StatusHq\Health\Check;
use StatusHq\Health\CheckResult;
use StatusHq\Health\Runner;
use StatusHq\Tests\FakeClock;

final class RunnerTest extends TestCase
{
    private function check(string $name, callable $run): Check
    {
        return new class($name, $run) implements Check
        {
            public function __construct(private string $checkName, private $run)
            {
            }

            public function name(): string
            {
                return $this->checkName;
            }

            public function label(): string
            {
                return ucfirst($this->checkName);
            }

            public function run(): CheckResult
            {
                return ($this->run)($this);
            }
        };
    }

    public function test_a_throwing_check_is_crashed_not_a_five_hundred(): void
    {
        // A health endpoint that 500s when one check throws is
        // indistinguishable from the application being down — it loses
        // exactly the detail the endpoint exists to carry.
        $checks = [
            $this->check('database', fn (Check $c) => CheckResult::ok($c->name(), $c->label(), 'reachable')),
            $this->check('redis', function (): never {
                throw new RuntimeException('Connection refused');
            }),
        ];

        $report = (new Runner(new FakeClock()))->run($checks);

        $this->assertCount(2, $report->checkResults);
        $this->assertSame(CheckResult::STATUS_OK, $report->checkResults[0]->status);
        $this->assertSame(CheckResult::STATUS_CRASHED, $report->checkResults[1]->status);
        $this->assertSame('Connection refused', $report->checkResults[1]->notificationMessage);
        $this->assertSame(RuntimeException::class, $report->checkResults[1]->meta['exception']);
    }

    public function test_a_crashed_check_keeps_its_own_name(): void
    {
        // Attributing it to "UnknownCheck" would hide which dependency broke.
        $report = (new Runner(new FakeClock()))->run([
            $this->check('horizon', function (): never {
                throw new RuntimeException('nope');
            }),
        ]);

        $this->assertSame('horizon', $report->checkResults[0]->name);
        $this->assertSame('Horizon', $report->checkResults[0]->label);
    }

    public function test_the_report_is_the_shape_the_consumer_parses(): void
    {
        $clock = new FakeClock(unixSeconds: 1_638_879_833);

        $report = (new Runner($clock))->run([
            $this->check('database', fn (Check $c) => CheckResult::ok($c->name(), $c->label(), 'reachable', ['latency_ms' => 12])),
        ]);

        // Asserted through json_encode because the wire format is the
        // contract — an in-memory array can hold shapes JSON cannot.
        $wire = json_decode(json_encode($report->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        // finishedAt is a string of unix seconds — what spatie/laravel-health
        // emits, and therefore what every consumer's staleness window is
        // written against.
        $this->assertSame('1638879833', $wire['finishedAt']);
        $this->assertSame([
            'name' => 'database',
            'label' => 'Database',
            'status' => 'ok',
            'notificationMessage' => '',
            'shortSummary' => 'reachable',
            'meta' => ['latency_ms' => 12],
        ], $wire['checkResults'][0]);
    }

    public function test_empty_meta_encodes_as_an_object_not_an_array(): void
    {
        // json_encode turns an empty PHP array into `[]`, and a consumer
        // expecting an object either errors or silently drops it.
        $report = (new Runner(new FakeClock()))->run([
            $this->check('ping', fn (Check $c) => CheckResult::ok($c->name(), $c->label(), 'pong')),
        ]);

        $json = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"meta":{}', $json);
    }

    public function test_no_checks_is_an_empty_report_rather_than_an_error(): void
    {
        $report = (new Runner(new FakeClock()))->run([]);

        $this->assertSame([], $report->toArray()['checkResults']);
    }
}
