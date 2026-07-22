<?php

declare(strict_types=1);

use Verdikt\App;

require __DIR__ . '/../vendor/autoload.php';

// .env is optional at this stage: /api/health and the rules engine work without
// any secrets. The LLM engine will require ANTHROPIC_API_KEY and say so explicitly.
if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

App::create()->run();
