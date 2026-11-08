<?php

declare(strict_types=1);

namespace App\Search;

use Survos\SearchBundle\Search\SearchInterface;
use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/** Preserve automatic Field-based discovery while specializing the song search. */
#[AsDecorator(SearchProvider::class)]
final readonly class KpaSearchProvider extends SearchProvider
{
    public function __construct(#[AutowireDecorated] private SearchProvider $inner, private SongSearch $songs)
    {
        parent::__construct([]);
    }

    public function getSearch(string $name): SearchInterface
    {
        return $name === 'app_song' ? $this->songs : $this->inner->getSearch($name);
    }
}
