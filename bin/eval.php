<?php

declare(strict_types=1);

/**
 * CLI eval runner: both engines over the whole fixture corpus, result stored
 * in the SQLite journal, rendered by GET /eval.
 *
 *   composer eval   (or: php bin/eval.php)
 */

use Verdikt\App;
use Verdikt\Eval\EvalRunner;
use Verdikt\Fixtures;
use Verdikt\Storage\Journal;

require __DIR__ . '/../vendor/autoload.php';

if (is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$engines = App::engines();
if (!isset($engines['llm'])) {
    fwrite(STDERR, "error: llm engine not configured — set ANTHROPIC_API_KEY in .env\n");
    exit(1);
}

$fixtures = Fixtures::load();
echo sprintf("evaluating %d fixtures (rules + llm)...\n", count($fixtures));

$outcome = (new EvalRunner($engines['rules'], $engines['llm']))->run(
    $fixtures,
    static function (int $done, string $id) use ($fixtures): void {
        echo sprintf("  [%2d/%d] %s\n", $done, count($fixtures), $id);
    },
);

$journal = new Journal(App::dbPath());
$runId = $journal->saveEvalRun($outcome['run'], $outcome['results']);

$run = $outcome['run'];
echo sprintf(
    "\nrun #%d stored\n  rules correct: %d/%d\n  llm correct:   %d/%d\n  agreement:     %d/%d\n  tokens:        %d in / %d out\n  wall time:     %.1fs\n\nview: /eval\n",
    $runId,
    $run['rules_correct'],
    $run['fixtures_total'],
    $run['llm_correct'],
    $run['fixtures_total'],
    $run['agree_count'],
    $run['fixtures_total'],
    $run['input_tokens'],
    $run['output_tokens'],
    $run['duration_ms'] / 1000,
);
