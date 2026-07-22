<?php

declare(strict_types=1);

namespace Verdikt;

/**
 * The five verdicts a customer reply can get.
 *
 * The legacy PowerShell tool uses labels with spaces ('TERMIN OK'); the API
 * uses underscored identifiers. label() gives back the human form.
 */
enum Verdict: string
{
    case TerminOk = 'TERMIN_OK';
    case TerminPasstNicht = 'TERMIN_PASST_NICHT';
    case Frage = 'FRAGE';
    case Abwesend = 'ABWESEND';
    case Pruefen = 'PRUEFEN';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
