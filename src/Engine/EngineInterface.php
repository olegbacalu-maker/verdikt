<?php

declare(strict_types=1);

namespace Verdikt\Engine;

interface EngineInterface
{
    /** Short identifier used in the API ('rules', 'llm'). */
    public function name(): string;

    /** Classify one customer reply (plain text, already stripped of quoted history). */
    public function classify(string $text): VerdictResult;
}
