<?php

declare(strict_types=1);

namespace Verdikt\Anthropic;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

/**
 * Minimal Anthropic Messages API client on raw Guzzle.
 *
 * Deliberately hand-rolled instead of pulling in the official PHP SDK: this
 * project is a PHP/REST showcase, and the interesting parts — auth headers,
 * retry policy, error taxonomy — are exactly what an SDK would hide.
 *
 * Retry policy: 429 and 5xx (incl. 529 overloaded) and transport errors are
 * retried with exponential backoff, honoring Retry-After but capping the wait
 * so a web request stays bounded. Other 4xx fail fast — they won't get better.
 */
final class AnthropicClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private const MAX_ATTEMPTS = 3;
    private const BASE_DELAY_MS = 400;
    private const MAX_DELAY_MS = 2_000;

    /** @var callable(int): void milliseconds sleeper — injectable so tests don't wait */
    private $sleep;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        ?callable $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * POST /v1/messages.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> decoded response body
     */
    public function messages(array $payload): array
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $this->backoff($attempt, $lastRetryAfter ?? null);
            }

            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => [
                        'x-api-key'         => $this->apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type'      => 'application/json',
                    ],
                    'body'            => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'http_errors'     => false,
                    'timeout'         => 30,
                    'connect_timeout' => 5,
                ]);
            } catch (TransferException $e) {
                $lastError = new AnthropicApiException('transport error: ' . $e->getMessage(), 0, $e);
                $lastRetryAfter = null;
                continue;
            }

            $status = $response->getStatusCode();

            if ($status < 300) {
                return self::decode($response);
            }

            $error = new AnthropicApiException(self::errorMessage($response, $status), $status);

            $retryable = $status === 429 || $status >= 500;
            if (!$retryable) {
                throw $error; // 4xx (except 429): our request is wrong, retrying won't help
            }

            $lastError = $error;
            $lastRetryAfter = $response->hasHeader('retry-after')
                ? (int) $response->getHeaderLine('retry-after')
                : null;
        }

        throw $lastError;
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds): void
    {
        $delayMs = self::BASE_DELAY_MS * (2 ** max(0, $attempt - 2)); // 400ms, 800ms, ...
        if ($retryAfterSeconds !== null) {
            $delayMs = max($delayMs, $retryAfterSeconds * 1000);
        }

        ($this->sleep)(min($delayMs, self::MAX_DELAY_MS));
    }

    /** @return array<string, mixed> */
    private static function decode(ResponseInterface $response): array
    {
        try {
            /** @var array<string, mixed> */
            return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AnthropicApiException('API returned unparseable JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    private static function errorMessage(ResponseInterface $response, int $status): string
    {
        $body = json_decode((string) $response->getBody(), true);
        $apiMessage = is_array($body) ? ($body['error']['message'] ?? null) : null;

        return is_string($apiMessage)
            ? sprintf('API error (HTTP %d): %s', $status, $apiMessage)
            : sprintf('API error (HTTP %d)', $status);
    }
}
