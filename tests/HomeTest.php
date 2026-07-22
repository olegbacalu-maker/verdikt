<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Verdikt\App;

final class HomeTest extends TestCase
{
    public function testHomePageRenders(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = App::create()->handle($request);
        $html = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('VERDIKT', $html);
        $this->assertStringContainsString('/assets/app.js', $html);
        $this->assertStringContainsString('lang="de"', $html);
    }
}
