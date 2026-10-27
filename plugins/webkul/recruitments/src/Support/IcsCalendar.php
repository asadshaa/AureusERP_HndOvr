<?php

namespace Webkul\Recruitment\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Builds a one-event iCalendar (RFC 5545) invitation or cancellation (RFC
 * 5546 METHOD REQUEST / CANCEL) in plain PHP: every mail and calendar
 * client can add it, with no calendar API or account involved. Times are
 * written in UTC, text values are escaped, and lines longer than 75 octets
 * are folded without splitting a UTF-8 character.
 */
final class IcsCalendar
{
    public const METHOD_REQUEST = 'REQUEST';

    public const METHOD_CANCEL = 'CANCEL';

    private const PRODUCT_ID = '-//Aureus ERP//Recruitment Interviews//EN';

    private const MAX_LINE_OCTETS = 75;

    /**
     * @param  array{
     *     uid: string,
     *     sequence: int,
     *     start: DateTimeInterface,
     *     end: DateTimeInterface,
     *     summary: string,
     *     location?: string|null,
     *     description?: string|null,
     *     url?: string|null,
     *     organizer?: array{email: string, name?: string|null}|null,
     *     attendees?: array<int, array{email: string, name?: string|null}>,
     *     stamp?: DateTimeInterface|null,
     * }  $event
     */
    public static function event(string $method, array $event): string
    {
        $method = $method === self::METHOD_CANCEL ? self::METHOD_CANCEL : self::METHOD_REQUEST;

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:'.self::PRODUCT_ID,
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:'.$method,
            'BEGIN:VEVENT',
            'UID:'.self::text($event['uid']),
            'SEQUENCE:'.max(0, (int) $event['sequence']),
            'DTSTAMP:'.self::utc($event['stamp'] ?? new DateTimeImmutable('now')),
            'DTSTART:'.self::utc($event['start']),
            'DTEND:'.self::utc($event['end']),
            'SUMMARY:'.self::text($event['summary']),
        ];

        if (filled($event['location'] ?? null)) {
            $lines[] = 'LOCATION:'.self::text((string) $event['location']);
        }

        if (filled($event['description'] ?? null)) {
            $lines[] = 'DESCRIPTION:'.self::text((string) $event['description']);
        }

        if (self::isWebUrl($event['url'] ?? null)) {
            $lines[] = 'URL:'.self::stripControl((string) $event['url']);
        }

        if (($organizer = $event['organizer'] ?? null) && self::isEmail($organizer['email'] ?? null)) {
            $lines[] = 'ORGANIZER'.self::commonName($organizer['name'] ?? null).':mailto:'.$organizer['email'];
        }

        foreach ($event['attendees'] ?? [] as $attendee) {
            if (! self::isEmail($attendee['email'] ?? null)) {
                continue;
            }

            $lines[] = 'ATTENDEE'.self::commonName($attendee['name'] ?? null)
                .';CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP='
                .($method === self::METHOD_REQUEST ? 'TRUE' : 'FALSE')
                .':mailto:'.$attendee['email'];
        }

        $lines[] = 'STATUS:'.($method === self::METHOD_CANCEL ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'TRANSP:OPAQUE';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** A TEXT value (RFC 5545 3.3.11): backslash, semicolon, comma and line breaks escaped. */
    public static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = self::stripControl($value, keepNewlines: true);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /** Fold a content line to at most 75 octets per line (continuations start with one space). */
    public static function fold(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }

        $folded = '';
        $current = '';
        $limit = self::MAX_LINE_OCTETS;

        foreach (mb_str_split($line, 1, 'UTF-8') as $character) {
            if (strlen($current) + strlen($character) > $limit) {
                $folded .= $current."\r\n ";
                $current = '';
                $limit = self::MAX_LINE_OCTETS - 1;
            }

            $current .= $character;
        }

        return $folded.$current;
    }

    private static function utc(DateTimeInterface $moment): string
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /** ;CN="..." parameter: quoted, so ":" ";" "," are safe; quotes and control characters dropped. */
    private static function commonName(?string $name): string
    {
        $name = trim(str_replace('"', '', self::stripControl((string) $name)));

        return $name === '' ? '' : ';CN="'.$name.'"';
    }

    private static function stripControl(string $value, bool $keepNewlines = false): string
    {
        return preg_replace($keepNewlines ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value) ?? $value;
    }

    private static function isEmail(?string $email): bool
    {
        return filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function isWebUrl(?string $url): bool
    {
        return filled($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
