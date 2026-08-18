<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

/**
 * Assembles one host sample from the three readers.
 *
 * Every reader may return null, and none of them throwing is the contract:
 * an unreadable /proc is the normal state of a hardened container, not an
 * error worth surfacing to the application.
 */
final class Collector
{
    public function __construct(
        private readonly CpuSampler $cpu = new CpuSampler(),
        private readonly MemoryReader $memory = new MemoryReader(),
        private readonly DiskReader $disk = new DiskReader(),
        private readonly string $diskPath = '/',
        private readonly ?string $host = null,
    ) {
    }

    /**
     * @param  bool  $blocking  Take both CPU readings here, sleeping between
     *                          them, instead of differencing against the
     *                          previous scheduled run. For one-off CLI use
     *                          only — it holds the process for a full second.
     */
    public function collect(bool $blocking = false, int $blockingMilliseconds = 1000): HostSample
    {
        return new HostSample(
            $blocking ? $this->cpu->percentByBlockingSample($blockingMilliseconds) : $this->cpu->percent(),
            $this->memory->read(),
            $this->disk->read($this->diskPath),
            $this->host ?? self::defaultHost(),
        );
    }

    /**
     * Which machine this sample describes.
     *
     * Behind a load balancer the answer is different on every node, which is
     * the entire reason the ingest carries it: without a host, four app
     * servers pushing to one monitor overwrite each other and the graph shows
     * whichever node happened to report last.
     */
    public static function defaultHost(): string
    {
        $host = gethostname();

        return $host === false || $host === '' ? 'unknown' : $host;
    }
}
