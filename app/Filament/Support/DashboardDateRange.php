<?php

namespace App\Filament\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

class DashboardDateRange
{
    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public function endExclusive(): CarbonImmutable
    {
        return $this->end->addMicrosecond();
    }

    /**
     * @param  array<string, mixed> | null  $filters
     */
    public static function fromFilters(?array $filters): self
    {
        $timezone = new DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        $today = CarbonImmutable::today($timezone);

        return match ($filters['time_range'] ?? 'today') {
            'yesterday' => self::forDay($today->subDay()),
            'last_7_days' => new self($today->subDays(6)->startOfDay(), $today->endOfDay()),
            'last_30_days' => new self($today->subDays(29)->startOfDay(), $today->endOfDay()),
            'this_week' => new self($today->startOfWeek(Carbon::MONDAY), $today->endOfDay()),
            'this_month' => new self($today->startOfMonth(), $today->endOfDay()),
            'custom' => self::custom($filters, $today),
            default => self::forDay($today),
        };
    }

    private static function forDay(CarbonImmutable $date): self
    {
        return new self($date->startOfDay(), $date->endOfDay());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function custom(array $filters, CarbonImmutable $today): self
    {
        $start = self::parseDate($filters['start_date'] ?? null, $today->getTimezone());
        $end = self::parseDate($filters['end_date'] ?? null, $today->getTimezone());

        if ($start === null || $end === null || $end->lessThan($start)) {
            return self::forDay($today);
        }

        return new self($start->startOfDay(), $end->endOfDay());
    }

    private static function parseDate(mixed $value, DateTimeZone $timezone): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return CarbonImmutable::instance($date);
    }
}
