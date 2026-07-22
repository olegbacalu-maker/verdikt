<?php

declare(strict_types=1);

namespace Verdikt\Eval;

use Verdikt\Engine\EngineInterface;

/**
 * Runs both engines over the fixture corpus and aggregates the comparison —
 * the measuring half of the project: not "does the LLM work" but "where do
 * the engines agree, and who is right against human ground truth".
 */
final class EvalRunner
{
    public function __construct(
        private readonly EngineInterface $rules,
        private readonly EngineInterface $llm,
    ) {
    }

    /**
     * @param list<array{id: string, text: string, expected: string, note: string}> $fixtures
     * @param callable(int, string): void|null $progress called after each fixture
     * @return array{run: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function run(array $fixtures, ?callable $progress = null): array
    {
        $t0 = hrtime(true);

        $results = [];
        $rulesCorrect = 0;
        $llmCorrect = 0;
        $agree = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $model = '';

        foreach ($fixtures as $i => $fixture) {
            $rules = $this->rules->classify($fixture['text']);
            $llm = $this->llm->classify($fixture['text']);

            $rulesCorrect += (int) ($rules->verdict->value === $fixture['expected']);
            $llmCorrect += (int) ($llm->verdict->value === $fixture['expected']);
            $agree += (int) ($rules->verdict === $llm->verdict);

            $inputTokens += (int) ($llm->meta['input_tokens'] ?? 0);
            $outputTokens += (int) ($llm->meta['output_tokens'] ?? 0);
            if ($model === '' && is_string($llm->meta['model'] ?? null)) {
                $model = $llm->meta['model'];
            }

            $results[] = [
                'fixture_id'      => $fixture['id'],
                'text'            => $fixture['text'],
                'note'            => $fixture['note'],
                'expected'        => $fixture['expected'],
                'rules_verdict'   => $rules->verdict->value,
                'rules_rule'      => $rules->rule,
                'llm_verdict'     => $llm->verdict->value,
                'llm_explanation' => $llm->explanation,
                'llm_duration_ms' => round($llm->durationMs, 3),
            ];

            if ($progress !== null) {
                $progress($i + 1, $fixture['id']);
            }
        }

        return [
            'run' => [
                'model'          => $model,
                'fixtures_total' => count($fixtures),
                'rules_correct'  => $rulesCorrect,
                'llm_correct'    => $llmCorrect,
                'agree_count'    => $agree,
                'input_tokens'   => $inputTokens,
                'output_tokens'  => $outputTokens,
                'duration_ms'    => round((hrtime(true) - $t0) / 1_000_000, 3),
            ],
            'results' => $results,
        ];
    }
}
