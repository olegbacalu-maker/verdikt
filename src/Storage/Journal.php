<?php

declare(strict_types=1);

namespace Verdikt\Storage;

/**
 * SQLite journal: logs classify requests and stores eval runs.
 *
 * Schema is created on first use — SQLite needs no migrations for a
 * demo-sized project, and the file lives under var/ (gitignored).
 */
final class Journal
{
    private readonly \PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS verdict_requests (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at  TEXT NOT NULL,
                engine      TEXT NOT NULL,
                text        TEXT NOT NULL,
                results     TEXT NOT NULL,
                duration_ms REAL NOT NULL
            );

            CREATE TABLE IF NOT EXISTS eval_runs (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at     TEXT NOT NULL,
                model          TEXT NOT NULL,
                fixtures_total INTEGER NOT NULL,
                rules_correct  INTEGER NOT NULL,
                llm_correct    INTEGER NOT NULL,
                agree_count    INTEGER NOT NULL,
                input_tokens   INTEGER NOT NULL,
                output_tokens  INTEGER NOT NULL,
                duration_ms    REAL NOT NULL
            );

            CREATE TABLE IF NOT EXISTS eval_results (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id          INTEGER NOT NULL REFERENCES eval_runs(id),
                fixture_id      TEXT NOT NULL,
                text            TEXT NOT NULL,
                note            TEXT NOT NULL,
                expected        TEXT NOT NULL,
                rules_verdict   TEXT NOT NULL,
                rules_rule      TEXT,
                llm_verdict     TEXT NOT NULL,
                llm_explanation TEXT NOT NULL,
                llm_duration_ms REAL NOT NULL
            );
            SQL);
    }

    /** @param list<\Verdikt\Engine\VerdictResult> $results */
    public function logVerdict(string $engine, string $text, array $results, float $durationMs): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO verdict_requests (created_at, engine, text, results, duration_ms)
             VALUES (:created_at, :engine, :text, :results, :duration_ms)',
        );
        $statement->execute([
            'created_at'  => gmdate('c'),
            'engine'      => $engine,
            'text'        => mb_substr($text, 0, 2000, 'UTF-8'),
            'results'     => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'duration_ms' => $durationMs,
        ]);
    }

    public function requestCount(): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM verdict_requests')->fetchColumn();
    }

    /**
     * @param array<string, mixed>       $run
     * @param list<array<string, mixed>> $results
     * @return int the new run id
     */
    public function saveEvalRun(array $run, array $results): int
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO eval_runs
                    (created_at, model, fixtures_total, rules_correct, llm_correct,
                     agree_count, input_tokens, output_tokens, duration_ms)
                 VALUES
                    (:created_at, :model, :fixtures_total, :rules_correct, :llm_correct,
                     :agree_count, :input_tokens, :output_tokens, :duration_ms)',
            );
            $statement->execute([
                'created_at'     => gmdate('c'),
                'model'          => $run['model'],
                'fixtures_total' => $run['fixtures_total'],
                'rules_correct'  => $run['rules_correct'],
                'llm_correct'    => $run['llm_correct'],
                'agree_count'    => $run['agree_count'],
                'input_tokens'   => $run['input_tokens'],
                'output_tokens'  => $run['output_tokens'],
                'duration_ms'    => $run['duration_ms'],
            ]);
            $runId = (int) $this->pdo->lastInsertId();

            $statement = $this->pdo->prepare(
                'INSERT INTO eval_results
                    (run_id, fixture_id, text, note, expected,
                     rules_verdict, rules_rule, llm_verdict, llm_explanation, llm_duration_ms)
                 VALUES
                    (:run_id, :fixture_id, :text, :note, :expected,
                     :rules_verdict, :rules_rule, :llm_verdict, :llm_explanation, :llm_duration_ms)',
            );
            foreach ($results as $result) {
                $statement->execute([
                    'run_id'          => $runId,
                    'fixture_id'      => $result['fixture_id'],
                    'text'            => $result['text'],
                    'note'            => $result['note'],
                    'expected'        => $result['expected'],
                    'rules_verdict'   => $result['rules_verdict'],
                    'rules_rule'      => $result['rules_rule'],
                    'llm_verdict'     => $result['llm_verdict'],
                    'llm_explanation' => $result['llm_explanation'],
                    'llm_duration_ms' => $result['llm_duration_ms'],
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $runId;
    }

    /** @return array{run: array<string, mixed>, results: list<array<string, mixed>>}|null */
    public function latestEvalRun(): ?array
    {
        $run = $this->query('SELECT * FROM eval_runs ORDER BY id DESC LIMIT 1')->fetch();
        if ($run === false) {
            return null;
        }

        $statement = $this->pdo->prepare('SELECT * FROM eval_results WHERE run_id = :run_id ORDER BY id');
        $statement->execute(['run_id' => $run['id']]);

        /** @var list<array<string, mixed>> $results */
        $results = $statement->fetchAll();

        return ['run' => $run, 'results' => $results];
    }

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            // unreachable with ERRMODE_EXCEPTION, but the contract says false is possible
            throw new \RuntimeException('query failed: ' . $sql);
        }

        return $statement;
    }
}
