<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Verdikt\Engine\RulesEngine;
use Verdikt\Verdict;

/**
 * One test per cascade branch, including the negation guards.
 * These pin the PORT: expected values were hand-traced against the
 * PowerShell original, so a regex transcription slip fails loudly.
 */
final class RulesEngineTest extends TestCase
{
    private RulesEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new RulesEngine();
    }

    /** @return array<string, array{string, Verdict, string}> [text, verdict, rule] */
    public static function cascadeCases(): array
    {
        return [
            'empty'               => ['', Verdict::Pruefen, 'empty'],
            'whitespace only'     => ["  \n\t ", Verdict::Pruefen, 'empty'],

            'abwesend autoreply'  => ['Automatische Antwort: bin im Urlaub', Verdict::Abwesend, 'abwesend'],
            'abwesend beats nein' => ['Ich bin nicht im Büro.', Verdict::Abwesend, 'abwesend'],

            'bestaetigt'          => ['Termin wird hiermit bestätigt.', Verdict::TerminOk, 'bestaetigt'],
            'bestaetigt negated'  => ['Ich kann den Termin nicht bestätigen.', Verdict::TerminPasstNicht, 'nein_stark'],

            'nein verschieben'    => ['Bitte den Termin verschieben.', Verdict::TerminPasstNicht, 'nein_stark'],
            'nein beats ja'       => ['Der Termin passt mir leider nicht.', Verdict::TerminPasstNicht, 'nein_stark'],
            'nein storno'         => ['Bitte stornieren, kein Interesse.', Verdict::TerminPasstNicht, 'nein_stark'],

            'ja geht klar'        => ['Geht klar!', Verdict::TerminOk, 'ja'],
            'ja uppercase'        => ['JA, PASST - BESTENS!', Verdict::TerminOk, 'ja'],
            'nein upper umlaut'   => ['LEIDER NICHT MÖGLICH DIESE WOCHE!', Verdict::TerminPasstNicht, 'nein_stark'],

            'ja implizit'         => ['Stellen Sie es bitte einfach in die Garage.', Verdict::TerminOk, 'ja_implizit'],
            'ja implizit blocked' => ['Bitte nicht vor die Tür stellen.', Verdict::Pruefen, 'fallback'],

            'liefern ok'          => ['Sie können am Dienstag zwischen 8 und 12 Uhr liefern.', Verdict::TerminOk, 'liefern_ok'],
            'liefern ok blocked'  => ['Können Sie am Dienstag liefern?', Verdict::Frage, 'frage'],

            'ja organisiert'      => ['Habe es doch organisieren können.', Verdict::TerminOk, 'ja_organisiert'],
            'ja org negated'      => ['Ich habe es nicht organisieren können.', Verdict::TerminPasstNicht, 'nein_stark'],
            'ja org guard'        => ['Hat nicht so richtig gut geklappt.', Verdict::Pruefen, 'fallback'],

            'nein schwach'        => ['Wir sind nächste Woche nicht zu Hause.', Verdict::TerminPasstNicht, 'nein_schwach'],
            'nein schwach urlaub' => ['Wir sind dann im Urlaub.', Verdict::TerminPasstNicht, 'nein_schwach'],

            'presence'            => ['Wir sind ab Montag wieder vor Ort.', Verdict::TerminOk, 'presence'],

            'frage warum'         => ['Warum kommt die Lieferung so spät', Verdict::Frage, 'frage'],

            'fallback pruefen'    => ['Sehr geehrte Damen und Herren, danke im Voraus.', Verdict::Pruefen, 'fallback'],
        ];
    }

    #[DataProvider('cascadeCases')]
    public function testCascade(string $text, Verdict $verdict, string $rule): void
    {
        $result = $this->engine->classify($text);

        $this->assertSame($verdict, $result->verdict, "verdict for: $text");
        $this->assertSame($rule, $result->rule, "rule for: $text");
    }

    public function testResultCarriesMatchedSnippetAndTiming(): void
    {
        $result = $this->engine->classify('Der Termin passt mir leider nicht.');

        $this->assertSame('rules', $result->engine);
        $this->assertSame('passt mir leider nicht', $result->matched);
        $this->assertStringContainsString('nein_stark', $result->explanation);
        $this->assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    /**
     * NFD input (decomposed umlauts, typical of macOS Mail pastes) must get
     * the same verdict as NFC. Without NFC normalization, PCRE2 < 10.43 stops
     * \w+ at the combining diaeresis, the nein_stark bridge over "gewünscht"
     * never completes, and this refusal flips to TERMIN_OK via ja_organisiert.
     */
    public function testNfdInputMatchesNfcVerdict(): void
    {
        $nfc = 'Wir konnten es nicht wie gewünscht einrichten.';
        $nfd = "Wir konnten es nicht wie gewu\u{0308}nscht einrichten.";
        $this->assertNotSame($nfc, $nfd, 'sanity: the two encodings differ');

        $nfcResult = $this->engine->classify($nfc);
        $nfdResult = $this->engine->classify($nfd);

        $this->assertSame(Verdict::TerminPasstNicht, $nfcResult->verdict);
        $this->assertSame($nfcResult->verdict, $nfdResult->verdict, 'NFD must not change the verdict');
        $this->assertSame($nfcResult->rule, $nfdResult->rule);
    }

    /**
     * .NET ToLower('İ') = 'i', mb_strtolower('İ') = 'i' + U+0307 — the fold
     * in classify() restores parity (Turkish keyboards autocapitalize
     * sentence-initial "in Ordnung" to "İn Ordnung").
     */
    public function testTurkishDottedCapitalIFoldsToPlainI(): void
    {
        $result = $this->engine->classify("\u{0130}n Ordnung.");

        $this->assertSame(Verdict::TerminOk, $result->verdict);
        $this->assertSame('ja', $result->rule);
    }

    public function testInvalidUtf8IsRejectedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->classify("Wir best\xE4tigen den Termin"); // Latin-1 bytes
    }
}
