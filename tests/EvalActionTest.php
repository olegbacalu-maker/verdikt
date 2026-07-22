<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Verdikt\Http\EvalAction;
use Verdikt\Storage\Journal;

final class EvalActionTest extends TestCase
{
    public function testEmptyStateExplainsHowToRun(): void
    {
        $html = $this->renderWith(new Journal(':memory:'));

        $this->assertStringContainsString('Noch kein Auswertungslauf', $html);
        $this->assertStringContainsString('composer eval', $html);
    }

    public function testMissingJournalIsExplained(): void
    {
        $html = $this->renderWith(null);

        $this->assertStringContainsString('Journal nicht verfügbar', $html);
    }

    public function testRendersLatestRunAsTable(): void
    {
        $journal = new Journal(':memory:');
        $journal->saveEvalRun(
            [
                'model' => 'claude-haiku-4-5', 'fixtures_total' => 1, 'rules_correct' => 0,
                'llm_correct' => 1, 'agree_count' => 0, 'input_tokens' => 1155,
                'output_tokens' => 93, 'duration_ms' => 5500.0,
            ],
            [[
                'fixture_id'      => 'garage_ohne_komma',
                'text'            => 'Falls wir nicht da sind stellen Sie es <bitte> in die Garage',
                'note'            => '',
                'expected'        => 'TERMIN_OK',
                'rules_verdict'   => 'TERMIN_PASST_NICHT',
                'rules_rule'      => 'nein_schwach',
                'llm_verdict'     => 'TERMIN_OK',
                'llm_explanation' => 'Implizite Zustimmung mit Abstellgenehmigung.',
                'llm_duration_ms' => 5500.0,
            ]],
        );

        $html = $this->renderWith($journal);

        $this->assertStringContainsString('garage_ohne_komma', $html);
        $this->assertStringContainsString('row-disagree', $html);
        $this->assertStringContainsString('0/1', $html);          // rules correct
        $this->assertStringContainsString('1/1', $html);          // llm correct
        $this->assertStringContainsString('claude-haiku-4-5', $html);
        // XSS guard: fixture text must arrive escaped
        $this->assertStringNotContainsString('<bitte>', $html);
        $this->assertStringContainsString('&lt;bitte&gt;', $html);
    }

    private function renderWith(?Journal $journal): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/eval');
        $response = (new EvalAction($journal))($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        return (string) $response->getBody();
    }
}
