<?php

declare(strict_types=1);

namespace App\Episode;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

final readonly class Line
{
    /** @param list<int> $sources */
    public function __construct(
        #[Schema(description: 'Id of the host speaking, e.g. "paulbot"')]
        public string $host,
        #[Schema(description: 'One of the emotions listed for this host')]
        public string $emotion,
        #[Schema(description: 'What the host says, written to be spoken aloud')]
        public string $text,
        #[Schema(description: 'Numbers of the sources backing the facts in this line; empty only for banter with no fact')]
        public array $sources,
    ) {
    }
}
