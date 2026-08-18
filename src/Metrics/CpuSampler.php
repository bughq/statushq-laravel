<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

use StatusHq\Support\ArrayStateStore;
use StatusHq\Support\Clock;
use StatusHq\Support\StateStore;
use StatusHq\Support\SystemClock;

/**
 * Turns two counter readings into a percentage.
 *
 * Everything here exists to avoid reporting a number that is not true. Where
 * a percentage cannot be derived — first run, counters reset by a reboot, a
 * stored sample so old the average would be meaningless — this returns null
 * and the check reports `skipped`. A plausible-looking 0% would be worse than
 * a gap: it is the reading you would also get from an idle box, so nobody
 * would ever notice it was fabricated.
 */
final class CpuSampler
{
    public const STATE_KEY = 'statushq:cpu-snapshot';

    public function __construct(
        private readonly CpuReader $reader = new CpuReader(),
        private readonly StateStore $state = new ArrayStateStore(),
        private readonly Clock $clock = new SystemClock(),
        /**
         * How old a stored reading may be and still be differenced against.
         * Fifteen minutes: long enough that a minutely schedule which misses a
         * few runs still reports, short enough that the answer still describes
         * roughly now.
         */
        private readonly int $maxAgeSeconds = 900,
    ) {
    }

    /**
     * CPU in use since the previous call, as a percentage of what this
     * container or host may use. Null when it cannot be known.
     */
    public function percent(): ?float
    {
        $current = $this->reader->snapshot();

        if ($current === null) {
            return null;
        }

        $previousState = $this->state->get(self::STATE_KEY);

        // Store first, and unconditionally: a run that cannot produce a
        // percentage must still leave the baseline the next one differences
        // against, or a sampler that starts on a stale reading never recovers.
        $this->state->put(self::STATE_KEY, $current->toArray(), $this->maxAgeSeconds * 2);

        $previous = $previousState === null ? null : CpuSnapshot::fromArray($previousState);

        return $previous === null ? null : self::percentBetween($previous, $current, $this->maxAgeSeconds);
    }

    /** Whether this host exposes CPU counters at all — see CpuReader::isSupported(). */
    public function isSupported(): bool
    {
        return $this->reader->isSupported();
    }

    /**
     * Take both readings here and now, sleeping between them.
     *
     * Only for one-off manual runs (`statushq:report --blocking`). It must
     * never be reached from a web request: a second of sleep is a second of
     * php-fpm worker held open, and under load that is how a health endpoint
     * takes down the pool it was installed to watch.
     */
    public function percentByBlockingSample(int $milliseconds = 1000): ?float
    {
        $sampler = new self($this->reader, new ArrayStateStore(), $this->clock, $this->maxAgeSeconds);

        $sampler->percent();
        usleep(max(1, $milliseconds) * 1000);

        return $sampler->percent();
    }

    public static function percentBetween(CpuSnapshot $previous, CpuSnapshot $current, int $maxAgeSeconds = 900): ?float
    {
        // A different source means the two readings are in different units.
        // Happens when a container is moved between cgroup versions, or a
        // quota is added to a running deployment.
        if ($previous->source !== $current->source) {
            return null;
        }

        $age = $current->takenAtUnix - $previous->takenAtUnix;

        if ($age < 0 || $age > $maxAgeSeconds) {
            return null;
        }

        $busyDelta = $current->busy - $previous->busy;
        $capacityDelta = $current->capacity - $previous->capacity;

        // Counters only ever climb, so a negative delta means the machine
        // rebooted (or the cgroup was recreated) between readings.
        if ($busyDelta < 0 || $capacityDelta <= 0) {
            return null;
        }

        return round(min(100, max(0, $busyDelta / $capacityDelta * 100)), 1);
    }
}
