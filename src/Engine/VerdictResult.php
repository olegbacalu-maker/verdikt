<?php

declare(strict_types=1);

namespace Verdikt\Engine;

use Verdikt\Verdict;

final readonly class VerdictResult implements \JsonSerializable
{
    /** @param array<string, mixed> $meta engine-specific extras (llm: model + token usage) */
    public function __construct(
        public string $engine,
        public Verdict $verdict,
        public ?string $rule,        // rules engine: cascade step that fired; llm: null
        public ?string $matched,     // rules engine: the exact snippet that matched
        public string $explanation,
        public float $durationMs,
        public array $meta = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'engine'      => $this->engine,
            'verdict'     => $this->verdict->value,
            'rule'        => $this->rule,
            'matched'     => $this->matched,
            'explanation' => $this->explanation,
            'duration_ms' => round($this->durationMs, 3),
        ];

        if ($this->meta !== []) {
            $data['meta'] = $this->meta;
        }

        return $data;
    }
}
