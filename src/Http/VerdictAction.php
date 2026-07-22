<?php

declare(strict_types=1);

namespace Verdikt\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Verdikt\Engine\EngineInterface;
use Verdikt\Text\ReplyCleaner;

/**
 * POST /api/verdict  {text: string, engine: 'rules'|'llm'|'both'}
 *
 * 'both' is all-or-nothing by design: it exists for the engine comparison,
 * and a partial comparison is not a comparison — so a missing engine is 501,
 * not a silently degraded 200 (pinned by a test, documented in the README).
 */
final class VerdictAction
{
    public const MAX_TEXT_CHARS = 20_000;

    /**
     * @param array<string, EngineInterface> $engines      configured engines, keyed by name
     * @param list<string>                   $knownEngines every engine name the API admits
     *                                                     (owned by App — single source of truth)
     */
    public function __construct(
        private readonly array $engines,
        private readonly array $knownEngines,
        private readonly ReplyCleaner $cleaner,
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();

        $text = $body['text'] ?? null;
        $engineParam = $body['engine'] ?? 'rules';

        if (!is_string($text) || trim($text) === '') {
            return self::error($response, 400, "field 'text' is required and must be a non-empty string");
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            return self::error($response, 400, 'text must be valid UTF-8');
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_CHARS) {
            return self::error($response, 400, sprintf('text too long: max %d characters', self::MAX_TEXT_CHARS));
        }
        if (!is_string($engineParam)) {
            return self::error($response, 400, "field 'engine' must be a string");
        }

        $engineParam = strtolower(trim($engineParam));
        if ($engineParam !== 'both' && !in_array($engineParam, $this->knownEngines, true)) {
            return self::error($response, 400, sprintf(
                "field 'engine' must be one of: %s, both",
                implode(', ', $this->knownEngines),
            ));
        }

        $wanted = $engineParam === 'both' ? $this->knownEngines : [$engineParam];
        $missing = array_diff($wanted, array_keys($this->engines));
        if ($missing !== []) {
            return self::error($response, 501, sprintf(
                'engine not available: %s (available: %s)',
                implode(', ', $missing),
                implode(', ', array_keys($this->engines)),
            ));
        }

        $cleaned = $this->cleaner->extract($text);

        // If cleaning ate everything, the paste was pure quoted history —
        // nothing in it is the customer's own words. The original fails safe
        // to PRUEFEN here (classify(''), rule 'empty'); keep that direction.
        $note = $cleaned === ''
            ? 'reply was entirely quoted history — verdict defaults to PRUEFEN for human review'
            : null;

        $results = [];
        foreach ($wanted as $name) {
            $results[] = $this->engines[$name]->classify($cleaned);
        }

        $payload = ['cleaned_text' => $cleaned, 'results' => $results];
        if ($note !== null) {
            $payload['note'] = $note;
        }

        return self::json($response, 200, $payload);
    }

    private static function error(Response $response, int $status, string $message): Response
    {
        return self::json($response, $status, ['error' => $message]);
    }

    /** @param array<string, mixed> $payload */
    private static function json(Response $response, int $status, array $payload): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
