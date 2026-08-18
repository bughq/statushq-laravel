<?php

declare(strict_types=1);

namespace StatusHq\Laravel;

use StatusHq\Health\Check;

/**
 * The set of checks the health endpoint reports on.
 *
 * Starts with the three host checks and is replaced wholesale by calling
 * `checks()` from a service provider — the same shape spatie/laravel-health
 * uses, so the two are muscle-memory compatible:
 *
 *     StatusHq::checks([
 *         UsedDiskSpaceCheck::new()->failWhenAbovePercentage(95),
 *         new DatabaseCheck(),
 *     ]);
 */
final class HealthRegistry
{
    /** @var list<Check>|null */
    private ?array $checks = null;

    /**
     * @param  list<Check>  $defaults
     */
    public function __construct(private readonly array $defaults = [])
    {
    }

    /**
     * @param  iterable<Check>  $checks
     */
    public function checks(iterable $checks): self
    {
        $this->checks = [];

        foreach ($checks as $check) {
            $this->checks[] = $check;
        }

        return $this;
    }

    /**
     * @param  list<Check>  $checks
     */
    public function add(Check ...$checks): self
    {
        $this->checks = [...($this->checks ?? $this->defaults), ...$checks];

        return $this;
    }

    /**
     * @return list<Check>
     */
    public function all(): array
    {
        return $this->checks ?? $this->defaults;
    }
}
