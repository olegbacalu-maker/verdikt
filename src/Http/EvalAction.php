<?php

declare(strict_types=1);

namespace Verdikt\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Verdikt\Storage\Journal;

/**
 * GET /eval — renders the latest stored eval run (rules vs LLM over the whole
 * fixture corpus). The run itself is produced by `composer eval` (bin/eval.php):
 * 22 LLM calls per run belong in a deliberate CLI step, not on every page view.
 */
final class EvalAction
{
    /** Claude Haiku 4.5 pricing, USD per million tokens — display only. */
    private const PRICE_IN_PER_MTOK = 1.0;
    private const PRICE_OUT_PER_MTOK = 5.0;

    public function __construct(private readonly ?Journal $journal)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $this->journal?->latestEvalRun();

        if ($data === null) {
            $html = self::render('eval-empty.php', [
                'journalAvailable' => $this->journal !== null,
            ]);
        } else {
            $run = $data['run'];
            $costUsd = ((int) $run['input_tokens'] / 1e6) * self::PRICE_IN_PER_MTOK
                + ((int) $run['output_tokens'] / 1e6) * self::PRICE_OUT_PER_MTOK;

            $llmMsValues = array_map(
                static fn (array $r): float => (float) $r['llm_duration_ms'],
                $data['results'],
            );

            $html = self::render('eval.php', [
                'run'          => $run,
                'results'      => $data['results'],
                'costUsd'      => $costUsd,
                'avgLlmMs'     => $llmMsValues === [] ? 0.0 : array_sum($llmMsValues) / count($llmMsValues),
                'requestCount' => $this->journal->requestCount(), // non-null: latestEvalRun() came from it
            ]);
        }

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** @param array<string, mixed> $vars */
    private static function render(string $template, array $vars): string
    {
        extract($vars);

        ob_start();
        require dirname(__DIR__, 2) . '/templates/' . $template;

        return (string) ob_get_clean();
    }
}
