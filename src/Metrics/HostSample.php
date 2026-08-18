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
    ) {
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
