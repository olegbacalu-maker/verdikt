<?php

declare(strict_types=1);

namespace Verdikt;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;
use Verdikt\Engine\EngineInterface;
use Verdikt\Engine\RulesEngine;
use Verdikt\Http\VerdictAction;
use Verdikt\Text\ReplyCleaner;

/**
 * Application factory: builds the Slim app with all routes and middleware.
 * Kept separate from public/index.php so tests can boot the exact same app.
 */
final class App
{
    /**
     * Every engine name the API admits — the single source of truth.
     * engines() decides which of these are actually usable right now.
     */
    public const KNOWN_ENGINES = [RulesEngine::NAME, 'llm'];

    /** @return SlimApp<\Psr\Container\ContainerInterface|null> */
    public static function create(?bool $debug = null): SlimApp
    {
        // safe default: stack traces stay off unless APP_DEBUG=true in .env
        $debug ??= filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL);

        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware($debug, true, true);
        // an API should not answer 404/405/500 with an HTML page
        $errorHandler = $errorMiddleware->getDefaultErrorHandler();
        if ($errorHandler instanceof \Slim\Handlers\ErrorHandler) {
            $errorHandler->forceContentType('application/json');
        }

        self::routes($app, self::engines());

        return $app;
    }

    /** @return array<string, EngineInterface> engines that are actually usable right now */
    private static function engines(): array
    {
        return [
            RulesEngine::NAME => new RulesEngine(),
            // 'llm' arrives on day 3 (requires ANTHROPIC_API_KEY)
        ];
    }

    /**
     * @param SlimApp<\Psr\Container\ContainerInterface|null> $app
     * @param array<string, EngineInterface>                  $engines
     */
    private static function routes(SlimApp $app, array $engines): void
    {
        $app->get('/api/health', function (Request $request, Response $response) use ($engines): Response {
            $payload = [
                'status'  => 'ok',
                'app'     => 'verdikt',
                'php'     => PHP_VERSION,
                'engines' => array_keys($engines),
                'time'    => gmdate('c'),
            ];

            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->post('/api/verdict', new VerdictAction($engines, self::KNOWN_ENGINES, new ReplyCleaner()));
    }
}
