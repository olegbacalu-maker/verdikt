<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Verdikt\Engine\VerdictResult;
use Verdikt\Storage\Journal;
use Verdikt\Verdict;

final class JournalTest extends TestCase
{
    private Journal $journal;

    protected function setUp(): void
    {
        $this->journal = new Journal(':memory:');
    }

    public function testLogsVerdictRequests(): void
    {
        $this->assertSame(0, $this->journal->requestCount());

        $result = new VerdictResult('rules', Verdict::TerminOk, 'ja', 'passt', "rule 'ja' matched", 0.5);
        $this->journal->logVerdict('rules', 'Ja, passt.', [$result], 0.5);

        $this->assertSame(1, $this->journal->requestCount());
    }

    public function testEvalRunRoundtrip(): void
    {
        $this->assertNull($this->journal->latestEvalRun());

        $runId = $this->journal->saveEvalRun(
            [
                'model'          => 'claude-haiku-4-5',
                'fixtures_total' => 2,
                'rules_correct'  => 1,
                'llm_correct'    => 2,
                'agree_count'    => 1,
                'input_tokens'   => 2000,
                'output_tokens'  => 150,
                'duration_ms'    => 4200.5,
            ],
            [
                [
                    'fixture_id'      => 'ja_einfach',
                    'text'            => 'Ja, passt.',
                    'note'            => 'plain agreement',
                    'expected'        => 'TERMIN_OK',
                    'rules_verdict'   => 'TERMIN_OK',
                    'rules_rule'      => 'ja',
                    'llm_verdict'     => 'TERMIN_OK',
                    'llm_explanation' => 'Kunde stimmt zu.',
                    'llm_duration_ms' => 1900.1,
                ],
                [
                    'fixture_id'      => 'garage_ohne_komma',
                    'text'            => 'Falls wir nicht da sind stellen Sie es bitte in die Garage',
                    'note'            => 'known divergence',
                    'expected'        => 'TERMIN_OK',
                    'rules_verdict'   => 'TERMIN_PASST_NICHT',
                    'rules_rule'      => 'nein_schwach',
                    'llm_verdict'     => 'TERMIN_OK',
                    'llm_explanation' => 'Implizite Zustimmung.',
                    'llm_duration_ms' => 2100.9,
                ],
            ],
        );

        $this->assertSame(1, $runId);

        $data = $this->journal->latestEvalRun();
        $this->assertNotNull($data);
        $this->assertSame('claude-haiku-4-5', $data['run']['model']);
        $this->assertSame(2, (int) $data['run']['fixtures_total']);
        $this->assertCount(2, $data['results']);
        $this->assertSame('garage_ohne_komma', $data['results'][1]['fixture_id']);
    }

    public function testLatestEvalRunReturnsNewestRun(): void
    {
        $run = [
            'model' => 'm', 'fixtures_total' => 0, 'rules_correct' => 0, 'llm_correct' => 0,
            'agree_count' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'duration_ms' => 0.0,
        ];
        $first = $this->journal->saveEvalRun($run, []);
        $second = $this->journal->saveEvalRun($run, []);

        $this->assertGreaterThan($first, $second);
        $data = $this->journal->latestEvalRun();
        $this->assertNotNull($data);
        $this->assertSame($second, (int) $data['run']['id']);
    }
}
