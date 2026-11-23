<?php

declare(strict_types=1);

namespace App\Episode;

/**
 * A synthetic host of the themed episodes. Its voices are stock voices: no real
 * person's voice is cloned (docs/themed-episodes.md).
 */
final readonly class Host
{
    /** @param array<string, string> $voices emotion => voice id */
    public function __construct(
        public string $id,
        public string $name,
        public string $persona,
        public array $voices,
    ) {
    }
}
