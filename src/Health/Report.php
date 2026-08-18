<?php

declare(strict_types=1);

namespace StatusHq\Health;

final class Report
{
    /**
     * @param  list<CheckResult>  $checkResults
     */
    public function __construct(
        public readonly int $finishedAt,
        public readonly array $checkResults,
    ) {
    }

    /**
     * @return array{finishedAt: string, checkResults: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            // Unix seconds as a string, which is what spatie/laravel-health
            // emits and therefore what every consumer's staleness window is
            // written against. An ISO string would parse too, but only because
            // StatusHQ is lenient — Oh Dear is not.
            'finishedAt' => (string) $this->finishedAt,
            'checkResults' => array_map(static fn (CheckResult $result): array => $result->toArray(), $this->checkResults),
        ];
    }
}
