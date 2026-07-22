<?php

declare(strict_types=1);

namespace Verdikt\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET / — the demo page. Static template, no server-side rendering: the page
 * talks to the same public JSON API a curl user gets.
 */
final class HomeAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/templates/index.html');
        if ($html === false) {
            throw new \RuntimeException('templates/index.html is missing');
        }

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
