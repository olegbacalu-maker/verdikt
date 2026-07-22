<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Verdikt\App;

final class VerdictApiTest extends TestCase
{
    public function testRulesEngineHappyPath(): void
    {
        $response = $this->post(['text' => 'Ja, der Termin passt uns gut.', 'engine' => 'rules']);
        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['results']);
        $this->assertSame('rules', $body['results'][0]['engine']);
        $this->assertSame('TERMIN_OK', $body['results'][0]['verdict']);
        $this->assertSame('ja', $body['results'][0]['rule']);
    }

    public function testEngineDefaultsToRules(): void
    {
        $response = $this->post(['text' => 'Bitte verschieben.']);
        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('TERMIN_PASST_NICHT', $body['results'][0]['verdict']);
    }

    public function testQuotedHistoryIsStrippedBeforeClassifying(): void
    {
        $response = $this->post([
            'text' => "Passt leider nicht.\r\nVon: Service <s@example.com>\r\n> Ihr Termin ist bestätigt",
        ]);
        $body = $this->json($response);

        $this->assertSame('Passt leider nicht.', $body['cleaned_text']);
        // without the cleaner, "bestätigt" from the quoted mail would flip this to TERMIN_OK
        $this->assertSame('TERMIN_PASST_NICHT', $body['results'][0]['verdict']);
    }

    public function testMissingTextIsRejected(): void
    {
        $response = $this->post(['engine' => 'rules']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertArrayHasKey('error', $this->json($response));
    }

    public function testUnknownEngineIsRejected(): void
    {
        $response = $this->post(['text' => 'Passt.', 'engine' => 'oracle']);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testLlmEngineNotConfiguredYet(): void
    {
        $response = $this->post(['text' => 'Passt.', 'engine' => 'llm']);
        $body = $this->json($response);

        $this->assertSame(501, $response->getStatusCode());
        $this->assertStringContainsString('llm', $body['error']);
    }

    public function testBothIsAllOrNothing(): void
    {
        // 'both' exists for the engine comparison; a partial comparison is
        // not a comparison — with llm unconfigured this is a 501, not a
        // silently degraded rules-only 200. Contract test for day 3.
        $response = $this->post(['text' => 'Passt.', 'engine' => 'both']);

        $this->assertSame(501, $response->getStatusCode());
    }

    public function testRealJsonBodyGoesThroughParsingMiddleware(): void
    {
        // everything else uses withParsedBody(); this one pins the actual
        // wiring — remove addBodyParsingMiddleware() and only this fails
        $stream = (new StreamFactory())->createStream(
            json_encode(['text' => 'Ja, der Termin passt.', 'engine' => 'rules'], JSON_THROW_ON_ERROR),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/verdict')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);

        $response = App::create()->handle($request);
        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('TERMIN_OK', $body['results'][0]['verdict']);
    }

    public function testNonStringTextIsRejected(): void
    {
        $response = $this->post(['text' => ['a', 'b'], 'engine' => 'rules']);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testNonUtf8TextIsRejected(): void
    {
        $response = $this->post(['text' => "Wir best\xE4tigen den Termin"]); // Latin-1 bytes

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('UTF-8', $this->json($response)['error']);
    }

    public function testOverlongTextIsRejected(): void
    {
        $response = $this->post(['text' => str_repeat('a', 20_001)]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testPureQuotedHistoryFailsSafeToPruefen(): void
    {
        // dispatcher's own confirmation template quoted back, customer added
        // nothing — must NOT classify the quoted "bestätigt" as TERMIN_OK
        $response = $this->post(['text' => "> Der Liefertermin ist hiermit bestätigt\r\n> Ihr Zustellteam"]);
        $body = $this->json($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $body['cleaned_text']);
        $this->assertSame('PRUEFEN', $body['results'][0]['verdict']);
        $this->assertArrayHasKey('note', $body);
    }

    public function testApiErrorsAreJsonEvenForRouting(): void
    {
        // 405 (wrong method) is produced by Slim's error middleware, not our
        // action — it must still speak JSON, not an HTML error page
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/verdict');
        $response = App::create()->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    /** @param array<string, mixed> $payload */
    private function post(array $payload): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/verdict')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);

        return App::create()->handle($request);
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
