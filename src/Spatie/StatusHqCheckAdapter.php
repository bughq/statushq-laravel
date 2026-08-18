<?php

declare(strict_types=1);

namespace StatusHq\Spatie;

use Spatie\Health\Checks\Check as SpatieCheck;
use Spatie\Health\Checks\Result;
use Spatie\Health\Enums\Status;
use StatusHq\Health\Check as StatusHqCheck;
use StatusHq\Health\CheckResult;

/**
 * Runs a StatusHQ check inside spatie/laravel-health.
 *
 * This is the bridge, and it points this way on purpose. If an application
 * already runs spatie/laravel-health it already has an endpoint, a scheduler
 * entry and a result store — so the useful thing is to add the checks it is
 * missing (CPU, memory, and a disk check that does not shell out) to the
 * endpoint it already has, not to stand up a second one beside it.
 *
 * The class extends a type from a suggested dependency, which is legal
 * because PHP only autoloads a class when it is referenced: an application
 * without spatie/laravel-health installed never touches this file.
 */
class StatusHqCheckAdapter extends SpatieCheck
{
    protected StatusHqCheck $check;

    public static function for(StatusHqCheck $check): static
    {
        $adapter = static::new();
        $adapter->check = $check;

        // Carry the names across so a monitor's history survives the move in
        // either direction — the disk check is called UsedDiskSpace on both
        // sides for exactly this reason.
        return $adapter->name($check->name())->label($check->label());
    }

    public function run(): Result
    {
        $result = $this->check->run();

        $spatieResult = Result::make($result->notificationMessage)
            ->shortSummary($result->shortSummary)
            ->meta($result->meta);

        // Assigned rather than called through ->ok()/->warning()/->failed():
        // those setters also overwrite the notification message, and there is
        // no ->skipped() setter at all.
        $spatieResult->status = match ($result->status) {
            CheckResult::STATUS_OK => Status::ok(),
            CheckResult::STATUS_WARNING => Status::warning(),
            CheckResult::STATUS_FAILED => Status::failed(),
            CheckResult::STATUS_SKIPPED => Status::skipped(),
            default => Status::crashed(),
        };

        return $spatieResult;
    }
}
