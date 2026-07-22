<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Verdikt\Engine\EngineInterface;
use Verdikt\Engine\RulesEngine;
use Verdikt\Engine\VerdictResult;
use Verdikt\Eval\EvalRunner;
use Verdikt\Verdict;

final class EvalRunnerTest extends TestCase
{
    /** @param array<string, string> $answers text => verdict value */
    private static function fakeLlm(array $answers): EngineInterface
    {
        return new class($answers) implements EngineInterface {
            /** @param array<string, string> $answers */
            public function __construct(private readonly array $answers)
            {
            }

            public function name(): string
            {
                return 'llm';
            }

            public function classify(string $text): VerdictResult
            {
                return new VerdictResult(
                    engine: 'llm',
                    verdict: Verdict::from($this->answers[$text]),
                    rule: null,
                    matched: null,
                    explanation: 'Begründung.',
                    durationMs: 100.0,
                    meta: ['model' => 'fake-model', 'input_tokens' => 1000, 'output_tokens' => 50],
                );
            }
        };
    }

    public function testAggregatesCorrectnessAgreementAndTokens(): void
    {
        $fixtures = [
            // rules ✓, llm ✓, agree
            ['id' => 'ja', 'text' => 'Ja, der Termin passt.', 'expected' => 'TERMIN_OK', 'note' => ''],
            // rules ✗ (legacy divergence), llm ✓, disagree
            ['id' => 'garage', 'text' => 'Falls wir nicht da sind stellen Sie es bitte in die Garage', 'expected' => 'TERMIN_OK', 'note' => ''],
            // rules ✓, llm ✗, disagree
            ['id' => 'frage', 'text' => 'Wann genau kommen Sie am Dienstag?', 'expected' => 'FRAGE', 'note' => ''],
        ];

        $llm = self::fakeLlm([
            'Ja, der Termin passt.' => 'TERMIN_OK',
            'Falls wir nicht da sind stellen Sie es bitte in die Garage' => 'TERMIN_OK',
            'Wann genau kommen Sie am Dienstag?' => 'PRUEFEN',
        ]);

        $progressed = [];
        $outcome = (new EvalRunner(new RulesEngine(), $llm))->run(
            $fixtures,
            static function (int $done, string $id) use (&$progressed): void {
                $progressed[] = $id;
            },
        );

        $run = $outcome['run'];
        $this->assertSame(3, $run['fixtures_total']);
        $this->assertSame(2, $run['rules_correct']);
        $this->assertSame(2, $run['llm_correct']);
        $this->assertSame(1, $run['agree_count']);
        $this->assertSame(3000, $run['input_tokens']);
        $this->assertSame(150, $run['output_tokens']);
        $this->assertSame('fake-model', $run['model']);

        $this->assertCount(3, $outcome['results']);
        $this->assertSame('nein_schwach', $outcome['results'][1]['rules_rule']);
        $this->assertSame(['ja', 'garage', 'frage'], $progressed);
    }
}
