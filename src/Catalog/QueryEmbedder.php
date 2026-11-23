<?php

declare(strict_types=1);

namespace App\Catalog;

/** Embeds a search question in the same space as the songs' persisted vectors. */
interface QueryEmbedder
{
    /** @return list<float> */
    public function embed(string $text): array;
}
