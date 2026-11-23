<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Catalog\QueryEmbedder;

/** A bag-of-words embedding: texts that share words are close. Deterministic, no Ollama. */
final class FakeEmbedder implements QueryEmbedder
{
    public function embed(string $text): array
    {
        $v = array_fill(0, 768, 0.0);
        foreach (preg_split('/\W+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $v[crc32($word) % 768] += 1.0;
        }
        $v[767] += 0.01; // never the zero vector

        return $v;
    }
}
