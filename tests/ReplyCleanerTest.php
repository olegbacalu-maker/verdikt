<?php

declare(strict_types=1);

namespace Verdikt\Tests;

use PHPUnit\Framework\TestCase;
use Verdikt\Text\ReplyCleaner;

final class ReplyCleanerTest extends TestCase
{
    private ReplyCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new ReplyCleaner();
    }

    public function testCutsQuotedHistoryAtHeaderBlock(): void
    {
        $body = "Passt uns gut.\r\n\r\nVon: Zustellservice <service@example.com>\r\nGesendet: Montag\r\n> alter Text";

        $this->assertSame('Passt uns gut.', $this->cleaner->extract($body));
    }

    public function testCutsAtOutlookSeparator(): void
    {
        $body = "Ja, in Ordnung.\n________________________________\nVorherige Nachricht hier";

        $this->assertSame('Ja, in Ordnung.', $this->cleaner->extract($body));
    }

    public function testSkipsQuoteLinesAndSubjectLines(): void
    {
        $body = "AW: Liefertermin\nDer Termin passt.\n> Sie erhalten am Dienstag\n> Ihre Lieferung";

        $this->assertSame('Der Termin passt.', $this->cleaner->extract($body));
    }

    public function testBreaksAtAmSchriebLine(): void
    {
        $body = "Klappt bei uns.\nAm 21.07.2026 um 10:00 schrieb Zustellservice:\nText der alten Mail";

        $this->assertSame('Klappt bei uns.', $this->cleaner->extract($body));
    }

    public function testEmptyInput(): void
    {
        $this->assertSame('', $this->cleaner->extract("  \n "));
    }

    public function testSkipsMailClientPanelHeaderLines(): void
    {
        $body = "Sie\nAn mich\nPasst uns gut.";

        $this->assertSame('Passt uns gut.', $this->cleaner->extract($body));
    }

    public function testInvalidUtf8IsRejectedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cleaner->extract("Gr\xFC\xDFe"); // Latin-1 bytes
    }
}
