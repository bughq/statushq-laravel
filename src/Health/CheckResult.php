<?php

declare(strict_types=1);

namespace StatusHq\Health;

/**
 * One check's verdict, in the shape StatusHQ and Oh Dear both already read.
 *
 * The five statuses are not ours to extend: a consumer that meets a sixth has
 * to decide what it means, and StatusHQ's parser deliberately treats anything
 * unrecognised as down rather than assume healthy.
 */
final class CheckResult
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CRASHED = 'crashed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $status,
        public readonly string $shortSummary = '',
        public readonly string $notificationMessage = '',
        public readonly array $meta = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function ok(string $name, string $label, string $shortSummary = '', array $meta = []): self
    {
        return new self($name, $label, self::STATUS_OK, $shortSummary, '', $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function warning(string $name, string $label, string $message, string $shortSummary = '', array $meta = []): self
    {
        return new self($name, $label, self::STATUS_WARNING, $shortSummary, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function failed(string $name, string $label, string $message, string $shortSummary = '', array $meta = []): self
    {
        return new self($name, $label, self::STATUS_FAILED, $shortSummary, $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function skipped(string $name, string $label, string $message, array $meta = []): self
    {
        return new self($name, $label, self::STATUS_SKIPPED, 'unavailable', $message, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function crashed(string $name, string $label, string $message, array $meta = []): self
    {
        return new self($name, $label, self::STATUS_CRASHED, 'crashed', $message, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'status' => $this->status,
            'notificationMessage' => $this->notificationMessage,
            'shortSummary' => $this->shortSummary,
            'meta' => (object) $this->meta,
        ];
    }
}
