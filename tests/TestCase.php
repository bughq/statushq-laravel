<?php

declare(strict_types=1);

namespace StatusHq\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use StatusHq\Laravel\StatusHqServiceProvider;
use StatusHq\Support\FileReader;

abstract class TestCase extends Orchestra
{
    /**
     * The host every test reads from.
     *
     * One long-lived instance whose contents are swapped, rather than a fresh
     * reader per call: the CPU sampler is a singleton and captures whatever
     * reader it was built with, so re-binding the interface mid-test would
     * leave it reading the fixtures it started with.
     */
    protected MutableFileReader $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new MutableFileReader();

        // instance() rather than bind(): the sampler must see later writes to
        // this exact object.
        $this->app->instance(FileReader::class, $this->files);
    }

    protected function getPackageProviders($app): array
    {
        return [StatusHqServiceProvider::class];
    }

    /**
     * Pretend to be a Linux host with the given pseudo-files.
     *
     * The tests run on whatever the developer's laptop is, so every reading
     * has to come from a fixture — otherwise the suite passes or fails
     * depending on how full the CI runner's disk happens to be.
     *
     * @param  array<string, string>  $files
     */
    protected function fakeHost(array $files): void
    {
        $this->files->files = $files;
    }

    /**
     * @return array<string, string>
     */
    protected function containerFiles(int $usedMb = 256, int $limitMb = 1024, float $busyJiffies = 1000, float $idleJiffies = 9000): array
    {
        return [
            '/sys/fs/cgroup/memory.max' => (string) ($limitMb * 1024 * 1024),
            '/sys/fs/cgroup/memory.current' => (string) ($usedMb * 1024 * 1024),
            '/proc/stat' => sprintf("cpu  %d 0 0 %d 0 0 0 0\n", $busyJiffies, $idleJiffies),
        ];
    }
}
