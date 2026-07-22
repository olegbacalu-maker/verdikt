<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Verdikt\Engine\RulesEngine;
use Verdikt\Verdict;

/**
 * Runs the rules engine over the whole fixture corpus.
 *
 * 'expected' in fixtures.json is the human ground truth. Where the legacy
 * cascade is known to disagree with a human, the divergence is pinned here
 * explicitly — so it is documented, and a regex change that silently alters
 * behavior (in either direction) fails the suite.
 */
final class FixturesTest extends TestCase
{
    /**
     * fixture id => verdict the RULES engine produces (≠ ground truth).
     * Small on purpose: this list IS the honest error budget of the legacy port.
     */
    private const KNOWN_RULES_DIVERGENCES = [
        // Missing comma merges "nicht da sind" with "stellen Sie" — the
        // negation guard of ja_implizit fires and weak-NO wins. The /eval
        // page exists to show exactly this class of case to the LLM engine.
        'garage_ohne_komma' => Verdict::TerminPasstNicht,
    ];

    public function testRulesEngineOnAllFixtures(): void
    {
        $engine = new RulesEngine();
        $failures = [];

        foreach (self::fixtures() as $fixture) {
            $id = $fixture['id'];
            $groundTruth = Verdict::from($fixture['expected']);
            $rulesExpected = self::KNOWN_RULES_DIVERGENCES[$id] ?? $groundTruth;

            $actual = $engine->classify($fixture['text'])->verdict;

            if ($actual !== $rulesExpected) {
                $failures[] = sprintf(
                    "%s: expected %s, got %s (text: %s)",
                    $id,
                    $rulesExpected->value,
                    $actual->value,
                    $fixture['text'],
                );
            }
        }

        $this->assertSame([], $failures, "rules engine drifted from pinned fixture verdicts:\n" . implode("\n", $failures));
    }

    public function testFixtureCorpusIsWellFormed(): void
    {
        $fixtures = self::fixtures();

        $this->assertGreaterThanOrEqual(15, count($fixtures), 'plan says ~15 fixtures minimum');

        $ids = array_column($fixtures, 'id');
        $this->assertSame($ids, array_unique($ids), 'fixture ids must be unique');

        foreach ($fixtures as $fixture) {
            $this->assertNotSame('', trim($fixture['text']), "fixture {$fixture['id']}: empty text");
            $this->assertNotNull(Verdict::tryFrom($fixture['expected']), "fixture {$fixture['id']}: bad verdict");
        }

        // every divergence entry must point at an existing fixture
        foreach (array_keys(self::KNOWN_RULES_DIVERGENCES) as $id) {
            $this->assertContains($id, $ids, "divergence entry '$id' has no fixture");
        }
    }

    /** @return list<array{id: string, text: string, expected: string, note: string}> */
    private static function fixtures(): array
    {
        return \Verdikt\Fixtures::load();
    }
}
