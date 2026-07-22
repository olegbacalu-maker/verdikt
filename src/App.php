<?php

declare(strict_types=1);

namespace Verdikt;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Application factory: builds the Slim app with all routes and middleware.
 * Kept separate from public/index.php so tests can boot the exact same app.
 */
final class App
{
    public static function create(): SlimApp
    {
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        // displayErrorDetails=true is fine for now; flip to false at deploy.
        $app->addErrorMiddleware(true, true, true);

        self::routes($app);

        return $app;
    }

    private static function routes(SlimApp $app): void
    {
        $app->get('/api/health', function (Request $request, Response $response): Response {
            $payload = [
                'status'  => 'ok',
                'app'     => 'verdikt',
                'php'     => PHP_VERSION,
                'engines' => ['rules', 'llm'],
                'time'    => gmdate('c'),
            ];

            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json');
        });
    }
}
