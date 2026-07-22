<?php

declare(strict_types=1);

namespace Verdikt\Engine;

use Verdikt\Verdict;

/**
 * Rules engine: a 1:1 PHP port of the regex cascade from my production
 * PowerShell tool that sorts customer replies for a dispatch desk every day.
 *
 * Design notes carried over from the original:
 *  - Rule ORDER matters — first hit wins. NO is split into strong (an
 *    unambiguous refusal) and weak (contextual: "nicht zu Hause" / "Urlaub"
 *    often appear inside AGREEMENTS — "if we're not home, put it in the
 *    garage"), which is why weak-NO sits far down the cascade.
 *  - Several YES rules carry a negation guard: the rule only fires if the
 *    guard regex does NOT match anywhere in the text.
 *  - Matching runs on the lowercased text; the original relied on
 *    PowerShell's case-insensitive -match, so /i is kept for parity.
 *  - (*UCP) makes \b and \w Unicode-aware like .NET (umlauts are word
 *    characters). PHP's /u already implies UCP, so the prefix is redundant —
 *    kept as explicit documentation of the .NET-parity requirement.
 *  - Input is normalized to NFC first. Decomposed umlauts (a + combining
 *    diaeresis, typical of macOS Mail pastes) otherwise break every
 *    (\w+\s+){0,n} bridge in the negation guards on PCRE2 < 10.43, where
 *    \w excludes combining marks — flipping refusals into TERMIN_OK.
 *    Found by adversarial review, pinned by tests.
 */
final class RulesEngine implements EngineInterface
{
    public const NAME = 'rules';

    private const RE_ABWESEND = 'abwesen|out\s+of\s+office|automatische\s+antwort|autoreply|auto-reply|nicht\s+im\s+b(ü|ue)ro';

    // direct confirmation by the customer — beats everything (unless negated)
    private const RE_BESTAETIGT = '\bbest(ä|ae)tig(e|en|t)\b';
    private const RE_BESTAETIGT_NEG = 'nicht\s+(\w+\s+)?best(ä|ae)tig';

    private const RE_NEIN_STARK = 'passt\s+(mir\s+)?(leider\s+)?nicht|nicht\s+m(ö|oe)glich|leider\s+nicht\b(?!\s+erreich|\s+angetroffen)|nicht\s+in\s+ordnung|anderen?\s+termin|neue[nr]?\s+termin|verschieben|\bumstellen|umbuchen|storn|geht\s+(leider\s+)?nicht|klappt\s+(leider\s+)?nicht|kein\s+interesse|nicht\s+best(ä|ae)tig|nicht\s+(\w+\s+)?(liefern|anliefern|zustellen|kommen)\b|doch\s+(\w+\s+){0,2}nicht|gar\s+nicht|nicht\s+(\w+\s+){0,2}(organisier|einrichten|einricht|hinbekomm|geregelt|hinkriegen|hingekriegt|geklappt|geschafft)|nicht\s+(\w+\s+){0,2}(passen|wahrnehmen|wahrgenommen)|passen\s+(leider\s+)?nicht';

    private const RE_JA = '\b(pass(t|en|end)|in\s+ordnung|einverstanden|geht\s+klar|geht\s+in\s+ordnung|klappt|okay|ok|ja|super|perfekt|gerne|top|wahrnehmen|wahrgenommen)\b';

    // permission to leave the parcel somewhere = implicit YES (Abstellgenehmigung)
    private const RE_JA_IMPLIZIT = 'ab(ge)?stell|ab(ge)?leg|hin(ge)?stell|hin(ge)?leg|abge(ge)?ben|(stell|leg)en\s+sie\s+(das|die|es|ihn)|\bdeponieren|vor\s+d(ie|er)\s+(haus)?t(ü|ue)r|\bgarage\b|nachbarn?|m(ü|ue)lltonn|\bterrasse|\bcarport|\bschuppen|hintert(ü|ue)r|seiteneingang';
    private const RE_JA_IMPLIZIT_NEG = '(nicht|kein\w*)\s+(\w+\s+){0,4}(ab(ge)?stell|ab(ge)?leg|hin(ge)?stell|(stell|leg)en|abge(ge)?ben)|nicht\s+vor\s+d|\bumstellen';

    // "Sie können am … liefern" = permission to deliver = YES (but not with nicht/kein/question)
    private const RE_LIEFERN_OK = '\b(sie\s+k(ö|oe)nnen|k(ö|oe)nnen\s+sie|sie\s+d(ü|ue)rfen|d(ü|ue)rfen\s+sie)\s+(gerne\s+)?(am|zwischen|ab|bis|morgen|(ü|ue)bermorgen|heute|jederzeit|montag|dienstag|mittwoch|donnerstag|freitag|samstag).{0,70}?(liefern|anliefern|zustellen|vorbeikommen|kommen)|\b(liefern|kommen)\s+sie\s+(am|gerne|zwischen|einfach|ruhig)';
    private const RE_LIEFERN_OK_NEG = '\?|\bnicht\b|\bkein';

    // "geht doch / ich konnte es organisieren / hab es geregelt" = customer arranged it = YES
    private const RE_JA_ORG = '(geht|passt|klappt)\s+doch|organisier|eingerichtet|einrichten|geregelt|hinbekommen|hingekriegt|hinkriegen|geklappt|geschafft';
    private const RE_JA_ORG_NEG = 'nicht\s+(\w+\s+){0,3}(organisier|einrichten|hinbekomm|geregelt|hinkriegen|geklappt|geschafft)';

    private const RE_NEIN_SCHWACH = 'nicht\s+(da\b|zu\s*hause|zuhause)|niemand\s+(da\b|vor\s+ort|zu\s*hause|zuhause)|\burlaub\b';

    // "ich bin morgen zu Hause / wir sind da" = customer confirms presence = YES
    // (the negative "nicht da"/"Urlaub" is caught by nein_schwach one step earlier)
    private const RE_PRESENCE = '(ich\s+bin|wir\s+sind|bin\s+(morgen|dann|ab|den))\s+(\w+\s+){0,8}(zu\s*hause|zuhause|daheim|anwesend|vor\s+ort|\bda\b)';

    private const RE_FRAGE = '\?|(^|\s)(wann|wie|warum|wieso|wo)\b|was\s+kostet|k(ö|oe)nn(t)?en\s+sie';

    /**
     * The cascade, in original order: [rule name, pattern, guard (must NOT match), verdict].
     * @var array<int, array{string, string, ?string, Verdict}>
     */
    private const CASCADE = [
        ['abwesend',       self::RE_ABWESEND,    null,                     Verdict::Abwesend],
        ['bestaetigt',     self::RE_BESTAETIGT,  self::RE_BESTAETIGT_NEG,  Verdict::TerminOk],
        ['nein_stark',     self::RE_NEIN_STARK,  null,                     Verdict::TerminPasstNicht],
        ['ja',             self::RE_JA,          null,                     Verdict::TerminOk],
        ['ja_implizit',    self::RE_JA_IMPLIZIT, self::RE_JA_IMPLIZIT_NEG, Verdict::TerminOk],
        ['liefern_ok',     self::RE_LIEFERN_OK,  self::RE_LIEFERN_OK_NEG,  Verdict::TerminOk],
        ['ja_organisiert', self::RE_JA_ORG,      self::RE_JA_ORG_NEG,      Verdict::TerminOk],
        ['nein_schwach',   self::RE_NEIN_SCHWACH, null,                    Verdict::TerminPasstNicht],
        ['presence',       self::RE_PRESENCE,    null,                     Verdict::TerminOk],
        ['frage',          self::RE_FRAGE,       null,                     Verdict::Frage],
    ];

    public function name(): string
    {
        return self::NAME;
    }

    public function classify(string $text): VerdictResult
    {
        $t0 = hrtime(true);

        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('text must be valid UTF-8');
        }

        $t = \Normalizer::normalize(trim($text), \Normalizer::FORM_C);
        if ($t === false) {
            throw new \InvalidArgumentException('text could not be normalized to NFC');
        }
        $t = mb_strtolower($t, 'UTF-8');
        // .NET ToLower('İ') gives plain 'i'; mb_strtolower gives 'i' + U+0307
        // (no precomposed form, so NFC can't help) — fold it for parity.
        $t = str_replace("i\u{0307}", 'i', $t);

        $verdict = Verdict::Pruefen;
        $rule = 'fallback';
        $matched = null;

        if ($t === '') {
            $rule = 'empty';
        } else {
            foreach (self::CASCADE as [$name, $pattern, $guard, $v]) {
                $m = self::match($pattern, $t);
                if ($m === null) {
                    continue;
                }
                if ($guard !== null && self::match($guard, $t) !== null) {
                    continue;
                }
                [$verdict, $rule, $matched] = [$v, $name, trim($m[0])];
                break;
            }
        }

        return new VerdictResult(
            engine: self::NAME,
            verdict: $verdict,
            rule: $rule,
            matched: $matched,
            explanation: $matched !== null
                ? sprintf('rule \'%s\' matched "%s"', $rule, $matched)
                : sprintf('no rule matched (%s) — needs a human look', $rule),
            durationMs: (hrtime(true) - $t0) / 1_000_000,
        );
    }

    /**
     * preg_match wrapper that refuses to confuse "engine failed" with "no
     * match": a deterministic engine whose selling point is trustworthy
     * verdicts must not turn a PCRE error into a confident PRUEFEN.
     *
     * @return array<int|string, string>|null the match array, or null if no match
     */
    private static function match(string $pattern, string $subject): ?array
    {
        $result = preg_match('~(*UCP)(?:' . $pattern . ')~iu', $subject, $m);
        if ($result === false) {
            throw new \RuntimeException('regex failure: ' . preg_last_error_msg());
        }

        return $result === 1 ? $m : null;
    }
}
