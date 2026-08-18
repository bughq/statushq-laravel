<?php

declare(strict_types=1);

namespace StatusHq\Laravel;

use Illuminate\Http\Client\Factory;
use StatusHq\Metrics\HostSample;
use Throwable;

/**
 * Pushes one sample to a StatusHQ metrics monitor.
 */
final class Reporter
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 5,
    ) {
    }

    /** Whether a metrics token has been set at all. */
    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/api/agent/'.rawurlencode($this->token).'/metrics';
    }

    /**
     * @return array{sent: bool, reason: string}
     */
    public function report(HostSample $sample): array
    {
        if (! $sample->isReportable()) {
            return ['sent' => false, 'reason' => 'nothing to report yet: CPU usage needs a previous sample to compare against'];
        }

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), $sample->toIngestPayload());
        } catch (Throwable $exception) {
            // A monitoring agent that throws into the scheduler takes down
            // the rest of the schedule with it. Whatever went wrong out
            // there, the correct local behaviour is to say so and continue.
            return ['sent' => false, 'reason' => $exception->getMessage()];
        }

        if ($response->failed()) {
            return ['sent' => false, 'reason' => 'ingest responded '.$response->status().': '.$response->body()];
        }

        return ['sent' => true, 'reason' => 'ok'];
    }
}
