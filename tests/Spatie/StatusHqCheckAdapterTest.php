<?php

declare(strict_types=1);

namespace StatusHq\Tests\Spatie;

use Spatie\Health\Enums\Status;
use StatusHq\Health\Check;
use StatusHq\Health\CheckResult;
use StatusHq\Spatie\HostChecks;
use StatusHq\Spatie\StatusHqCheckAdapter;
use StatusHq\Tests\TestCase;

final class StatusHqCheckAdapterTest extends TestCase
{
    private function check(CheckResult $result): Check
    {
        return new class($result) implements Check
        {
            public function __construct(private CheckResult $result)
            {
            }

            public function name(): string
            {
                return 'UsedMemory';
            }

            public function label(): string
            {
                return 'Used memory';
            }

            public function run(): CheckResult
            {
                return $this->result;
            }
        };
    }

    public function test_it_carries_the_name_and_label_across(): void
    {
        // Same identifier on both sides, so a team that moves between the two
        // packages keeps its history rather than starting a fresh series.
        $adapter = StatusHqCheckAdapter::for($this->check(CheckResult::ok('UsedMemory', 'Used memory', '25%')));

        $this->assertSame('UsedMemory', $adapter->getName());
        $this->assertSame('Used memory', $adapter->getLabel());
    }

    public function test_each_status_maps_onto_theirs(): void
    {
        foreach ([
            [CheckResult::ok('UsedMemory', 'Used memory', '25%'), Status::ok()],
            [CheckResult::warning('UsedMemory', 'Used memory', 'high', '85%'), Status::warning()],
            [CheckResult::failed('UsedMemory', 'Used memory', 'critical', '97%'), Status::failed()],
            [CheckResult::skipped('UsedMemory', 'Used memory', 'unreadable'), Status::skipped()],
            [CheckResult::crashed('UsedMemory', 'Used memory', 'boom'), Status::crashed()],
        ] as [$ours, $theirs]) {
            $result = StatusHqCheckAdapter::for($this->check($ours))->run();

            $this->assertTrue($theirs->equals($result->status), $ours->status.' should map to '.$theirs->value);
        }
    }

    public function test_the_notification_message_survives_the_crossing(): void
    {
        // Their ->failed() setter also overwrites the message, which is why
        // the adapter assigns the status rather than calling it.
        $ours = CheckResult::failed('UsedMemory', 'Used memory', 'Used memory is at 97% (fails above 95%)', '97%', ['memory_used_percentage' => 97]);

        $result = StatusHqCheckAdapter::for($this->check($ours))->run();

        $this->assertSame('Used memory is at 97% (fails above 95%)', $result->getNotificationMessage());
        $this->assertSame('97%', $result->getShortSummary());
        $this->assertSame(['memory_used_percentage' => 97], $result->meta);
    }

    public function test_the_host_checks_are_ready_to_register(): void
    {
        $this->fakeHost($this->containerFiles(usedMb: 256, limitMb: 1024));

        $checks = HostChecks::all();

        $this->assertCount(3, $checks);
        $this->assertSame(['CpuUsage', 'UsedMemory', 'UsedDiskSpace'], array_map(static fn ($c) => $c->getName(), $checks));

        $memory = $checks[1]->run();
        $this->assertTrue(Status::ok()->equals($memory->status));
        $this->assertSame('25%', $memory->getShortSummary());
    }
}
