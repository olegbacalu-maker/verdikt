<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use PHPUnit\Framework\TestCase;
use Verdikt\Anthropic\AnthropicApiException;
use Verdikt\Anthropic\AnthropicClient;
use Verdikt\Engine\LlmEngine;
use Verdikt\Verdict;

/**
 * All tests run against a Guzzle MockHandler — no network, no API key, no
 * cost. Request counts are asserted via the mock's remaining queue: if the
 * queue holds N responses and M remain, exactly N−M requests were made.
 */
final class LlmEngineTest extends TestCase
{
    private MockHandler $mock;

    /** @param array<int, GuzzleResponse|\Throwable> $queue */
    private function engine(array $queue): LlmEngine
    {
        $this->mock = new MockHandler($queue);

        $client = new AnthropicClient(
            new Client(['handler' => HandlerStack::create($this->mock)]),
            'sk-ant-test-key',
            sleep: static function (int $ms): void {}, // tests must not wait
        );

        return new LlmEngine($client);
    }

    private static function success(string $verdict = 'TERMIN_OK', string $begruendung = 'Der Kunde stimmt zu.'): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode([
            'id'          => 'msg_test',
            'model'       => 'claude-haiku-4-5',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_test',
                'name'  => 'verdict',
                'input' => ['verdict' => $verdict, 'begruendung' => $begruendung],
            ]],
            'usage'       => ['input_tokens' => 420, 'output_tokens' => 55],
        ], JSON_THROW_ON_ERROR));
    }

    public function testParsesForcedToolCallIntoVerdict(): void
    {
        $result = $this->engine([self::success()])->classify('Ja, der Termin passt.');

        $this->assertSame('llm', $result->engine);
        $this->assertSame(Verdict::TerminOk, $result->verdict);
        $this->assertNull($result->rule);
        $this->assertSame('Der Kunde stimmt zu.', $result->explanation);
        $this->assertSame('claude-haiku-4-5', $result->meta['model']);
        $this->assertSame(420, $result->meta['input_tokens']);
    }

    public function testRequestCarriesAuthAndForcedToolChoice(): void
    {
        $this->engine([self::success()])->classify('Passt.');

        $request = $this->mock->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('sk-ant-test-key', $request->getHeaderLine('x-api-key'));
        $this->assertSame('2023-06-01', $request->getHeaderLine('anthropic-version'));

        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['type' => 'tool', 'name' => 'verdict'], $body['tool_choice']);
        $this->assertTrue($body['tools'][0]['strict']);
        $this->assertSame(
            ['TERMIN_OK', 'TERMIN_PASST_NICHT', 'FRAGE', 'ABWESEND', 'PRUEFEN'],
            $body['tools'][0]['input_schema']['properties']['verdict']['enum'],
        );
        $this->assertStringContainsString('Kundenantwort', $body['messages'][0]['content']);
    }

    public function testRetriesOverloadedThenSucceeds(): void
    {
        $result = $this->engine([
            new GuzzleResponse(529, [], '{"error":{"type":"overloaded_error","message":"Overloaded"}}'),
            self::success(),
        ])->classify('Passt.');

        $this->assertSame(Verdict::TerminOk, $result->verdict);
        $this->assertSame(0, $this->mock->count(), 'expected exactly one retry');
    }

    public function testRetriesTransportErrorThenSucceeds(): void
    {
        $result = $this->engine([
            new ConnectException('timeout', new GuzzleRequest('POST', 'test')),
            self::success(),
        ])->classify('Passt.');

        $this->assertSame(Verdict::TerminOk, $result->verdict);
        $this->assertSame(0, $this->mock->count());
    }

    public function testClientErrorFailsFastWithoutRetry(): void
    {
        // two extra queued successes: if a retry happened, one would be consumed
        $engine = $this->engine([
            new GuzzleResponse(401, [], '{"error":{"type":"authentication_error","message":"invalid x-api-key"}}'),
            self::success(),
            self::success(),
        ]);

        try {
            $engine->classify('Passt.');
            $this->fail('expected AnthropicApiException');
        } catch (AnthropicApiException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertStringContainsString('invalid x-api-key', $e->getMessage());
        }

        $this->assertSame(2, $this->mock->count(), '4xx must not be retried');
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        // four queued errors: only three may be consumed
        $engine = $this->engine([
            new GuzzleResponse(529, [], '{}'),
            new GuzzleResponse(529, [], '{}'),
            new GuzzleResponse(529, [], '{}'),
            new GuzzleResponse(529, [], '{}'),
        ]);

        try {
            $engine->classify('Passt.');
            $this->fail('expected AnthropicApiException');
        } catch (AnthropicApiException $e) {
            $this->assertSame(529, $e->getCode());
        }

        $this->assertSame(1, $this->mock->count(), 'expected exactly three attempts');
    }

    public function testMissingToolCallIsAnError(): void
    {
        $engine = $this->engine([new GuzzleResponse(200, [], json_encode([
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => 'TERMIN_OK']],
        ], JSON_THROW_ON_ERROR))]);

        $this->expectException(AnthropicApiException::class);
        $this->expectExceptionMessage('no verdict tool call');

        $engine->classify('Passt.');
    }

    public function testUnknownVerdictValueIsAnError(): void
    {
        $engine = $this->engine([self::success(verdict: 'VIELLEICHT')]);

        $this->expectException(AnthropicApiException::class);
        $this->expectExceptionMessage('unknown verdict');

        $engine->classify('Passt.');
    }
}
