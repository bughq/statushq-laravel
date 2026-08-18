<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

/**
 * One reading of the host, shaped for the StatusHQ metrics ingest.
 */
final class HostSample
{
    public function __construct(
        public readonly ?float $cpuPercent,
        public readonly ?MemoryUsage $memory,
        public readonly ?DiskUsage $disk,
        public readonly string $host,
        /** Whether this host exposes CPU counters at all — see CpuReader::isSupported(). */
        public readonly bool $cpuMeasurable = true,
    ) {
    }

    /**
     * Why this sample cannot be sent, or null when it can.
     *
     * Named precisely, because the two silences need different reactions: a
     * first run resolves itself a minute later, while a host with no readable
     * counters never will, and a caller told to "wait for the next sample"
     * would wait forever.
     */
    public function whyNotReportable(): ?string
    {
        if ($this->cpuPercent === null) {
            return $this->cpuMeasurable
                ? 'nothing to report yet: CPU usage is a rate, so it needs a previous sample to compare against'
                : 'this host exposes no CPU counters (/proc/stat and the cgroup files are Linux-only), so metrics cannot be pushed from it';
        }

        if ($this->memory === null) {
            return 'this host exposes no readable memory interface (cgroup or /proc/meminfo), so metrics cannot be pushed from it';
        }

        return null;
    }

    /**
     * Rows for display, with unmeasured values shown as unmeasured.
     *
     * Not the ingest payload: that coerces nulls to 0 because the endpoint
     * requires numbers, and printing those zeroes to a human reads as "your
     * machine is idle" rather than "this was never measured".
     *
     * @return list<array{0: string, 1: string}>
     */
    public function toDisplayRows(): array
    {
        $show = static fn (?float $value, string $unit = ''): string => $value === null ? '—' : $value.$unit;

        return [
            ['cpu', $show($this->cpuPercent, '%')],
            ['memory', $show($this->memory?->percent(), '%')],
            ['memory used', $this->memory === null ? '—' : $this->memory->usedMegabytes().' MB of '.$this->memory->totalMegabytes().' MB'],
            ['memory source', $this->memory?->source ?? '—'],
            ['disk', $show($this->disk?->percent(), '%')],
            ['host', $this->host],
        ];
    }

    /**
     * Whether this sample can be pushed at all.
     *
     * The ingest requires CPU and memory together and rejects a payload
     * missing either, so a first run — which has no previous CPU counter to
     * difference against — is deliberately not sent. One skipped minute is a
     * better failure than a fabricated 0%.
     */
    public function isReportable(): bool
    {
        return $this->cpuPercent !== null && $this->memory !== null;
    }

    /**
     * @return array<string, float|int|string>
     */
    public function toIngestPayload(): array
    {
        $payload = [
            'cpuPercent' => $this->cpuPercent ?? 0.0,
            'ramPercent' => $this->memory?->percent() ?? 0.0,
            'ramUsedMb' => $this->memory?->usedMegabytes() ?? 0,
            'ramTotalMb' => $this->memory?->totalMegabytes() ?? 0,
            'host' => $this->host,
        ];

        if ($this->disk !== null) {
            $payload['diskPercent'] = $this->disk->percent();
        }

        return $payload;
    }
}
