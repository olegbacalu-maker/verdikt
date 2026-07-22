<?php

declare(strict_types=1);

namespace Verdikt;

/**
 * Loads the synthetic fixture corpus (fixtures/fixtures.json) — shared by
 * the test suite and the eval runner.
 */
final class Fixtures
{
    /** @return list<array{id: string, text: string, expected: string, note: string}> */
    public static function load(): array
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/fixtures.json');
        if ($raw === false) {
            throw new \RuntimeException('fixtures/fixtures.json is missing');
        }

        /** @var array{fixtures: list<array{id: string, text: string, expected: string, note: string}>} $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data['fixtures'];
    }
}
