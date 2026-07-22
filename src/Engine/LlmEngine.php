<?php

declare(strict_types=1);

namespace Verdikt\Engine;

use Verdikt\Anthropic\AnthropicApiException;
use Verdikt\Anthropic\AnthropicClient;
use Verdikt\Verdict;

/**
 * LLM engine: classifies via the Anthropic API (Claude Haiku by default).
 *
 * Structured output through a forced tool call: `tool_choice` pins the model
 * to the `verdict` tool and `strict: true` guarantees the input validates
 * against the schema — no free-text parsing, the verdict comes back as a
 * clean enum member plus a one-sentence German justification.
 */
final class LlmEngine implements EngineInterface
{
    public const NAME = 'llm';
    public const DEFAULT_MODEL = 'claude-haiku-4-5';

    private const MAX_TOKENS = 300;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        Du bist Disponent bei einem Zustelldienst und sortierst Kundenantworten auf Terminvorschläge.
        Klassifiziere die Kundenantwort in genau eine Kategorie:
        - TERMIN_OK: Kunde stimmt dem Termin zu — auch implizit (Abstellgenehmigung wie "in die Garage stellen", Nachbar nimmt an, Kunde hat es selbst organisiert, Kunde bestätigt seine Anwesenheit).
        - TERMIN_PASST_NICHT: Termin abgelehnt, Verschiebung oder anderer Termin gewünscht, Storno — oder Kunde ist abwesend / nicht zu Hause OHNE eine Zustimmung.
        - FRAGE: Kunde stellt eine Frage, die zuerst beantwortet werden muss.
        - ABWESEND: automatische Abwesenheitsnotiz (Out of Office / Autoreply).
        - PRUEFEN: unklar, kein verwertbarer Inhalt oder braucht menschliche Bearbeitung (z. B. Rückrufbitte, falscher Empfänger).
        Wichtig: "nicht da" oder "Urlaub" INNERHALB einer Zustimmung ("falls wir nicht da sind, stellen Sie es in die Garage") ist TERMIN_OK.
        Melde das Ergebnis über das Tool 'verdict' mit einer kurzen deutschen Begründung (ein Satz).
        PROMPT;

    public function __construct(
        private readonly AnthropicClient $client,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {
    }

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

        $response = $this->client->messages([
            'model'       => $this->model,
            'max_tokens'  => self::MAX_TOKENS,
            'temperature' => 0, // classification should be reproducible
            'system'      => self::SYSTEM_PROMPT,
            'messages'    => [
                ['role' => 'user', 'content' => "Kundenantwort:\n" . trim($text)],
            ],
            'tools'       => [self::verdictTool()],
            'tool_choice' => ['type' => 'tool', 'name' => 'verdict'],
        ]);

        $input = self::extractToolInput($response);

        $verdict = Verdict::tryFrom((string) ($input['verdict'] ?? ''));
        if ($verdict === null) {
            // strict:true should make this impossible — but "should" is not a contract
            throw new AnthropicApiException(sprintf(
                'model returned unknown verdict %s',
                json_encode($input['verdict'] ?? null),
            ));
        }

        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];

        return new VerdictResult(
            engine: self::NAME,
            verdict: $verdict,
            rule: null,
            matched: null,
            explanation: trim((string) ($input['begruendung'] ?? '')),
            durationMs: (hrtime(true) - $t0) / 1_000_000,
            meta: [
                'model'         => (string) ($response['model'] ?? $this->model),
                'input_tokens'  => $usage['input_tokens'] ?? null,
                'output_tokens' => $usage['output_tokens'] ?? null,
            ],
        );
    }

    /** @return array<string, mixed> */
    private static function verdictTool(): array
    {
        return [
            'name'        => 'verdict',
            'description' => 'Melde das Klassifikations-Ergebnis für die Kundenantwort.',
            'strict'      => true,
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'verdict' => [
                        'type' => 'string',
                        'enum' => array_map(static fn (Verdict $v) => $v->value, Verdict::cases()),
                    ],
                    'begruendung' => [
                        'type'        => 'string',
                        'description' => 'Eine kurze deutsche Begründung, ein Satz.',
                    ],
                ],
                'required'             => ['verdict', 'begruendung'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed> the tool_use input
     */
    private static function extractToolInput(array $response): array
    {
        $content = is_array($response['content'] ?? null) ? $response['content'] : [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === 'verdict') {
                return is_array($block['input'] ?? null) ? $block['input'] : [];
            }
        }

        throw new AnthropicApiException(sprintf(
            'no verdict tool call in response (stop_reason: %s)',
            (string) ($response['stop_reason'] ?? 'unknown'),
        ));
    }
}
