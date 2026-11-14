<?php

declare(strict_types=1);

namespace Segara\Content;

use Segara\Support\Formatter;

/**
 * The four ways a page date can be shown, the pattern behind each, and the guard
 * that decides whether a custom pattern is a pattern at all.
 */
final class PageDate
{
    /** @var list<string> */
    public const MODES = ['full', 'plain', 'custom', 'relative'];

    public const DEFAULT_MODE = 'full';
    public const DEFAULT_PATTERN = 'F j, Y (l)';
    public const PATTERN_MAX = 64;

    /** @var array<string, string> */
    private const PATTERNS = [
        'full' => 'F j, Y (l)',
        'plain' => 'F j, Y',
    ];

    public function __construct(private readonly Formatter $formatter = new Formatter())
    {
    }

    /**
     * Anything that is not a PHP date pattern the admin can have meant. The
     * character set is deliberately narrow: this string is printed on every page.
     */
    public static function isValidPattern(string $pattern): bool
    {
        if (trim($pattern) === '' || mb_strlen($pattern) > self::PATTERN_MAX) {
            return false;
        }
        if (preg_match('/\A[A-Za-z0-9 ,.\/:\-()]+\z/', $pattern) !== 1) {
            return false;
        }

        // The token must be a month, a day number or a year. 'l', 'D' and 'S' are
        // deliberately excluded: "hello" contains an 'l' and would print
        // "01ZSundaySunday2026" instead of being rejected.
        return preg_match('/[YyFmMnjd]/', $pattern) === 1;
    }

    /**
     * @return array{iso: string, text: string}
     */
    public function format(
        string $datetimeUtc,
        string $mode,
        string $customPattern,
        string $timezone,
        string $locale = 'en',
    ): array {
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::DEFAULT_MODE;
        }

        if ($mode === 'relative') {
            $text = $this->formatter->date($datetimeUtc, $timezone, $locale, true);
        } else {
            $pattern = self::PATTERNS[$mode]
                ?? (self::isValidPattern($customPattern) ? $customPattern : self::DEFAULT_PATTERN);
            $text = $this->formatter->pattern($datetimeUtc, $timezone, $pattern);
        }

        return ['iso' => $this->formatter->isoDate($datetimeUtc, $timezone), 'text' => $text];
    }
}
