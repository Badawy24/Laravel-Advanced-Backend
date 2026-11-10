<?php

declare(strict_types=1);

namespace App\Traits;

use Carbon\CarbonInterface;
use DateTimeInterface;

trait FormatsApiData
{
    /**
     * Format an ID with leading zeros.
     */
    protected function formatId(int|string|null $id, int $length = 6): ?string
    {
        if ($id === null) {
            return null;
        }

        return str_pad((string) $id, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Format a date string or instance to Y-m-d.
     */
    protected function formatDate(DateTimeInterface|string|null $date, string $format = 'Y-m-d'): ?string
    {
        if ($date === null) {
            return null;
        }

        if (is_string($date)) {
            return date($format, strtotime($date));
        }

        return $date->format($format);
    }

    /**
     * Format a time string or instance to H:i.
     */
    protected function formatTime(DateTimeInterface|string|null $time, string $format = 'H:i'): ?string
    {
        if ($time === null) {
            return null;
        }

        if (is_string($time)) {
            return date($format, strtotime($time));
        }

        return $time->format($format);
    }

    /**
     * Format a datetime string or instance.
     */
    protected function formatDateTime(DateTimeInterface|string|null $date, string $format = 'Y-m-d H:i'): ?string
    {
        if ($date === null) {
            return null;
        }

        if (is_string($date)) {
            return date($format, strtotime($date));
        }

        return $date->format($format);
    }

    /**
     * Format a date to UTC ISO 8601.
     */
    protected function formatIsoDateTime(DateTimeInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        if ($date instanceof CarbonInterface) {
            return $date->copy()->setTimezone('UTC')->toISOString();
        }

        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d\TH:i:s\Z');
        }

        return date('Y-m-d\TH:i:s\Z', strtotime($date));
    }

    /**
     * Format a price to a float rounded to the given precision.
     */
    protected function formatPrice(float|int|string|null $amount, int $precision = 2): float
    {
        if ($amount === null) {
            return 0.0;
        }

        return round((float) $amount, $precision);
    }

    /**
     * Format an amount with currency symbol.
     */
    protected function formatAmountCurrency(float|int|string|null $amount, ?string $currency = null): string
    {
        $currencyCode = $currency ?? 'EGP';
        $formattedPrice = number_format((float) ($amount ?? 0), 2);

        return "{$formattedPrice} {$currencyCode}";
    }

    /**
     * Format international phone number.
     */
    protected function formatPhoneNumber(?string $phone, ?string $phoneCode = null): ?string
    {
        if ($phone === null) {
            return null;
        }

        if ($phoneCode !== null && ! str_starts_with($phone, '+')) {
            return '+'.ltrim($phoneCode, '+').ltrim($phone, '0');
        }

        return $phone;
    }
}
