<?php

declare(strict_types=1);

namespace StatusHq\Laravel\Console;

use Illuminate\Console\Command;
use StatusHq\Laravel\Reporter;
use StatusHq\Metrics\Collector;

final class ReportMetricsCommand extends Command
{
    protected $signature = 'statushq:report
                            {--blocking : Take both CPU readings now, sleeping a second between them, instead of differencing against the previous run}
                            {--dry : Collect and print the sample without sending it}';

    protected $description = 'Push a CPU, memory and disk sample to StatusHQ';

    public function handle(Collector $collector, Reporter $reporter): int
    {
        $sample = $collector->collect(blocking: (bool) $this->option('blocking'));

        $this->table(
            ['metric', 'value'],
            collect($sample->toIngestPayload())->map(fn ($value, $key): array => [$key, (string) $value])->values()->all(),
        );

        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        if (! $reporter->isConfigured()) {
            $this->components->error('No metrics token configured. Set STATUSHQ_METRICS_TOKEN to the token from your metrics monitor.');

            return self::FAILURE;
        }

        $outcome = $reporter->report($sample);

        if (! $outcome['sent']) {
            // Not a failure exit when the sample simply is not ready: the
            // first run after a deploy has no previous CPU counter to
            // difference against, and a red scheduled task every deploy is
            // how people learn to ignore red scheduled tasks.
            if (! $sample->isReportable()) {
                $this->components->info($outcome['reason']);

                return self::SUCCESS;
            }

            $this->components->error($outcome['reason']);

            return self::FAILURE;
        }

        $this->components->info('Sample sent for '.$sample->host.'.');

        return self::SUCCESS;
    }
}
