<?php

declare(strict_types=1);

namespace StatusHq\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use StatusHq\Health\Runner;
use StatusHq\Laravel\HealthRegistry;

final class HealthController
{
    /** The header StatusHQ and Oh Dear both send. */
    public const SECRET_HEADER = 'oh-dear-health-check-secret';

    public function __construct(
        private readonly HealthRegistry $registry,
        private readonly Runner $runner,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('statushq.health.secret');

        if (is_string($secret) && $secret !== '' && ! $this->secretMatches($request, $secret)) {
            // No report body on a bad secret. The check names alone describe
            // the application's internals — which queues it runs, which
            // services it depends on — and that is not something to hand to
            // an unauthenticated caller.
            return response()->json(['message' => 'Invalid health check secret.'], 403);
        }

        $report = $this->runner->run($this->registry->all());

        // Always 200, including when checks failed. The status code answers
        // "did the endpoint work", the body answers "is the app healthy" —
        // conflating them means a consumer cannot tell a failing check from
        // an unreachable server.
        return response()->json($report->toArray());
    }

    private function secretMatches(Request $request, string $secret): bool
    {
        $presented = $request->header(self::SECRET_HEADER);

        // hash_equals rather than ===: string comparison returns early on the
        // first differing byte, which leaks the secret one character at a
        // time to anyone willing to measure.
        return is_string($presented) && hash_equals($secret, $presented);
    }
}
