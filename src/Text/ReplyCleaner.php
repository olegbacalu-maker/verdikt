<?php

declare(strict_types=1);

namespace Verdikt\Text;

/**
 * Extracts the customer's own words from a pasted email reply: cuts quoted
 * history, mail-client separators, header blocks and "> " quote lines.
 *
 * Port of the extractor from the original PowerShell tool. Two intentional
 * drops vs production (both client-specific, both would matter if real
 * production pastes were ever replayed here — see README): the cut anchor
 * "Im Auftrag von DHL" (first line of every quoted dispatcher mail) and the
 * scrub of the tool's own "(?)" date placeholder.
 */
final class ReplyCleaner
{
    /** Everything from the first of these anchors on is quoted history. */
    private const CUT_ANCHORS = [
        '-----Urspr',                        // "-----Ursprüngliche Nachricht-----"
        '-----Original Message-----',
        '________________________________',  // Outlook divider
    ];

    public function extract(string $body): string
    {
        if (!mb_check_encoding($body, 'UTF-8')) {
            // loud, not silent: with /u patterns an invalid subject would
            // quietly disable every filter below
            throw new \InvalidArgumentException('body must be valid UTF-8');
        }

        if (trim($body) === '') {
            return '';
        }

        // zero-width characters some mail clients sprinkle in
        $text = preg_replace('~[\x{200B}\x{200E}\x{200F}\x{FEFF}]~u', '', $body) ?? $body;

        foreach (self::CUT_ANCHORS as $anchor) {
            $pos = mb_stripos($text, $anchor);
            if ($pos !== false) {
                $text = mb_substr($text, 0, $pos);
            }
        }

        $keep = [];
        foreach (preg_split('~\r?\n~', $text) ?: [] as $line) {
            // header block of a quoted mail — everything below is history
            if (preg_match('~^\s*(Von|From|Gesendet|Sent|An|To|Betreff|Subject):\s~iu', $line) === 1) {
                break;
            }
            if (preg_match('~^\s*Am\s.+schrieb\s~iu', $line) === 1) {
                break;
            }
            if (preg_match('~^\s*>~u', $line) === 1) {
                continue; // quoted line
            }
            if (preg_match('~^\s*(Re|Aw|WG|FW|Fwd):~iu', $line) === 1) {
                continue; // subject line pasted along
            }
            if (preg_match('~^\s*.{0,60}<[^>]+@[^>]+>\s*$~u', $line) === 1) {
                continue; // "Name <email>" line
            }
            if (preg_match('~^\s*(You|Sie|An\s+mich)\s*$~iu', $line) === 1) {
                continue; // mail-client panel header
            }
            $keep[] = $line;
        }

        return trim(preg_replace('~\s+~u', ' ', implode(' ', $keep)) ?? '');
    }
}
