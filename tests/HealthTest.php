<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Verdikt\App;

final class HealthTest extends TestCase
{
    public function testHealthEndpointRespondsOk(): void
    {
        $app = App::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/health');

        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $body['status']);
        $this->assertSame(['rules', 'llm'], $body['engines']);
    }
}
