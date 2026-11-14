<?php

declare(strict_types=1);

namespace Segara\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use IntlDateFormatter;
use NumberFormatter;

final class Formatter
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|callable(): int $clock */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock === null ? static fn(): int => time() : Closure::fromCallable($clock);
    }

    public function money(mixed $amount, string $currency, string $locale = 'en'): string
    {
        $amount = $this->amount($amount);
        $currency = $this->currency($currency);
        $locale = $this->locale($locale);
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $result = $formatter->formatCurrency($amount, $currency);
        if ($result === false) {
            throw new InvalidArgumentException('Unable to format currency.');
        }

        return $result;
    }

    public function number(mixed $value, string $locale = 'en'): string
    {
        $value = $this->amount($value);
        $locale = $this->locale($locale);
        $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $result = $formatter->format($value);
        if ($result === false) {
            throw new InvalidArgumentException('Unable to format number.');
        }

        return $result;
    }

    /**
     * Format a UTC ISO-8601 instant in a target timezone. When $fuzzy is true the
     * output is a relative description; the exact instant is never discarded.
     */
    public function date(string $datetimeUtc, string $timezone, string $locale = 'en', bool $fuzzy = false): string
    {
        $instant = $this->utcInstant($datetimeUtc);
        $zone = $this->timezone($timezone);
        if ($fuzzy) {
            return $this->fuzzy($instant);
        }

        $formatter = new IntlDateFormatter(
            $this->locale($locale),
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::SHORT,
            $zone,
        );
        $result = $formatter->format($instant->getTimestamp());
        if ($result === false) {
            throw new InvalidArgumentException('Unable to format date.');
        }

        return $result;
    }

    /** Format a UTC instant in a target timezone with a PHP date pattern. */
    public function pattern(string $datetimeUtc, string $timezone, string $pattern): string
    {
        return $this->utcInstant($datetimeUtc)->setTimezone($this->timezone($timezone))->format($pattern);
    }

    /** The date part of a UTC instant in a target timezone, for a datetime attribute. */
    public function isoDate(string $datetimeUtc, string $timezone): string
    {
        return $this->utcInstant($datetimeUtc)->setTimezone($this->timezone($timezone))->format('Y-m-d');
    }

    private function fuzzy(DateTimeImmutable $instant): string
    {
        $diff = ($this->clock)() - $instant->getTimestamp();
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return $this->relative((int) floor($diff / 60), 'minute');
        }
        if ($diff < 86400) {
            return $this->relative((int) floor($diff / 3600), 'hour');
        }
        if ($diff < 604800) {
            return $this->relative((int) floor($diff / 86400), 'day');
        }
        if ($diff < 2592000) {
            return $this->relative((int) floor($diff / 604800), 'week');
        }
        if ($diff < 31536000) {
            return $this->relative((int) floor($diff / 2592000), 'month');
        }

        return $this->relative((int) floor($diff / 31536000), 'year');
    }

    private function relative(int $count, string $unit): string
    {
        return $count . ' ' . $unit . ($count === 1 ? ' ago' : 's ago');
    }

    private function utcInstant(string $datetimeUtc): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($datetimeUtc, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid UTC datetime.');
        }
    }

    private function timezone(string $timezone): DateTimeZone
    {
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('Invalid timezone.');
        }

        return new DateTimeZone($timezone);
    }

    private function currency(string $currency): string
    {
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO 4217 code.');
        }

        return $currency;
    }

    private function locale(string $locale): string
    {
        if (preg_match('/\A[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*\z/D', $locale) !== 1) {
            throw new InvalidArgumentException('Invalid locale.');
        }

        return $locale;
    }

    private function amount(mixed $value): float
    {
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new InvalidArgumentException('Amount must be numeric.');
    }
}
