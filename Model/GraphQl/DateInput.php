<?php
/**
 * Parsing and validating the date strings the rental schema accepts.
 *
 * Since 1.2.197 the rental tables hold MySQL datetimes, so that is the one format this
 * API speaks in both directions: "Y-m-d H:i:s", or "Y-m-d" where a field is a whole day.
 * Anything else is rejected here rather than being coerced, because strtotime() quietly
 * turning "03/04/2026" into either March or April is exactly the class of bug an API
 * should not have.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\GraphQl;

use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\Phrase;

class DateInput
{
    public const FORMAT_DATETIME = 'Y-m-d H:i:s';
    public const FORMAT_DATE = 'Y-m-d';

    /** How far apart a calendar window's ends may be, in days. */
    public const MAX_WINDOW_DAYS = 400;

    /**
     * Parse a required datetime field.
     *
     * @param array $input
     * @param string $key field name, used in the error message
     * @return \DateTimeImmutable
     * @throws GraphQlInputException
     */
    public function requireDateTime(array $input, string $key): \DateTimeImmutable
    {
        if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === null) {
            throw new GraphQlInputException(new Phrase('"%1" is required.', [$key]));
        }

        return $this->parse((string)$input[$key], $key);
    }

    /**
     * Parse an optional datetime field.
     *
     * @param array $input
     * @param string $key
     * @return \DateTimeImmutable|null null when the field was not supplied
     * @throws GraphQlInputException
     */
    public function optionalDateTime(array $input, string $key): ?\DateTimeImmutable
    {
        if (!isset($input[$key]) || $input[$key] === '' || $input[$key] === null) {
            return null;
        }

        return $this->parse((string)$input[$key], $key);
    }

    /**
     * Parse one string in either accepted format.
     *
     * A bare "Y-m-d" becomes midnight of that day, which is what every caller means by it.
     *
     * @param string $value
     * @param string $key field name for the error message
     * @return \DateTimeImmutable
     * @throws GraphQlInputException
     */
    public function parse(string $value, string $key = 'date'): \DateTimeImmutable
    {
        $value = trim($value);

        foreach ([self::FORMAT_DATETIME, self::FORMAT_DATE] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            // createFromFormat is lenient about overflow ("2026-02-31" becomes March 3),
            // so round-trip the result and insist it matches what came in.
            if ($parsed instanceof \DateTimeImmutable && $parsed->format($format) === $value) {
                return $parsed;
            }
        }

        throw new GraphQlInputException(
            new Phrase(
                '"%1" must be a date in "Y-m-d" or "Y-m-d H:i:s" format, got "%2".',
                [$key, $value]
            )
        );
    }

    /**
     * Check a start/end pair reads the right way round.
     *
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     * @param bool $allowEqual a same-instant range is legitimate for a single-day product
     * @return void
     * @throws GraphQlInputException
     */
    public function assertOrdered(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        bool $allowEqual = true
    ): void {
        if ($allowEqual ? $end < $start : $end <= $start) {
            throw new GraphQlInputException(
                new Phrase(
                    'The end date (%1) must be %2 the start date (%3).',
                    [
                        $end->format(self::FORMAT_DATETIME),
                        $allowEqual ? 'on or after' : 'after',
                        $start->format(self::FORMAT_DATETIME),
                    ]
                )
            );
        }
    }

    /**
     * Cap how much calendar a single query may ask for.
     *
     * A per-day answer over an unbounded window is an easy way to make the server do a
     * lot of work for one cheap-looking request, so the window is bounded at the edge.
     *
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     * @param int $maxDays
     * @return void
     * @throws GraphQlInputException
     */
    public function assertWindowWithin(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $maxDays = self::MAX_WINDOW_DAYS
    ): void {
        $days = (int)$start->setTime(0, 0)->diff($end->setTime(0, 0))->format('%a');
        if ($days > $maxDays) {
            throw new GraphQlInputException(
                new Phrase(
                    'The window asked for is %1 days; at most %2 days can be returned in one query.',
                    [$days, $maxDays]
                )
            );
        }
    }

    /**
     * Format for output, or null for a null/zero date.
     *
     * The tables still hold "0000-00-00 00:00:00" in places for "no end date", and that
     * must not reach a client as a date.
     *
     * @param mixed $value
     * @param string $format
     * @return string|null
     */
    public function format($value, string $format = self::FORMAT_DATETIME): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00' || $value === '0000-00-00') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        try {
            return (new \DateTimeImmutable((string)$value))->format($format);
        } catch (\Exception $e) {
            return null;
        }
    }
}
