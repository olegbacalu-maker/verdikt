<?php

declare(strict_types=1);

namespace Verdikt\Engine;

use Verdikt\Verdict;

final readonly class VerdictResult implements \JsonSerializable
{
    public function __construct(
        public string $engine,
        public Verdict $verdict,
        public ?string $rule,        // rules engine: cascade step that fired; llm: null
        public ?string $matched,     // rules engine: the exact snippet that matched
        public string $explanation,
        public float $durationMs,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'engine'      => $this->engine,
            'verdict'     => $this->verdict->value,
            'rule'        => $this->rule,
            'matched'     => $this->matched,
            'explanation' => $this->explanation,
            'duration_ms' => round($this->durationMs, 3),
        ];
    }
}
