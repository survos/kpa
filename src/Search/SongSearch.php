<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Song;
use Survos\FieldBundle\Service\FieldReader;
use App\Service\SongEmbeddingService;
use Survos\SearchBundle\Search\AbstractFieldSearch;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/** FieldReader supplies the catalog metadata; only vector behavior is application-specific. */
final class SongSearch extends AbstractFieldSearch implements HitTemplateSearchInterface
{
    private ?string $hitTemplate = null;

    public function __construct(private SongEmbeddingService $embeddings, private PropertyAccessorInterface $accessor, private FieldReader $fields) {}
    public function getAdapterName(): ?string { return 'es'; }
    public function getIndexName(): ?string { return Song::class; }
    protected function getFieldClass(array $options = []): string { return Song::class; }
    public function getHitTemplate(): ?string { return $this->hitTemplate; }

    public function createQuery(): \Survos\SearchBundle\Search\Query
    {
        return parent::createQuery()->setActiveSort(null);
    }

    public function build(array $options = []): void
    {
        $this->setAdapterParameters([]);
        parent::build($options);
        $this->hitTemplate = $options['hitTemplate'] ?? null;
        $parameters = $this->getAdapterParameters();
        unset($parameters['mappings']['embeddingFingerprint']);
        $parameters['sourceFields'] = array_values(array_diff($parameters['sourceFields'], ['embedding', 'embeddingFingerprint']));
        // Searchable content remains searchable even when hidden in a grid column.
        foreach ($this->fields->getDescriptors(Song::class) as $field) {
            if ($field->searchable) {
                $parameters['searchFields'][] = $field->name;
                $parameters['sourceFields'][] = $field->name;
                $parameters['mappings'][$field->name] ??= ['type' => 'text'];
            }
        }
        $parameters['searchFields'] = array_values(array_unique($parameters['searchFields']));
        $parameters['sourceFields'] = array_values(array_unique($parameters['sourceFields']));
        // Recording counts are computed from Audio links, not a mapped Doctrine column.
        $parameters['sourceFields'][] = 'fileCount';
        $parameters['mappings']['fileCount'] = ['type' => 'integer'];
        $parameters['facetFields']['fileCount'] = 'fileCount';
        $parameters['sortFields']['fileCount'] = 'fileCount';
        $fields = $parameters['sourceFields'];
        $parameters['mappings']['embedding'] = ['type' => 'dense_vector', 'dims' => SongEmbeddingService::DIMENSIONS, 'index' => true, 'similarity' => 'cosine'];
        $parameters['vectorDimensions'] = SongEmbeddingService::DIMENSIONS;
        $parameters['embeddingProvider'] = $this->embeddings->enabled ? $this->embeddings : null;
        $parameters['retrievalMode'] = $this->embeddings->enabled ? ($options['retrievalMode'] ?? 'lexical') : 'lexical';
        $parameters['documentMapper'] = function (Song $song) use ($fields): array {
            $document = [];
            foreach ($fields as $field) {
                $document[$field] = $this->accessor->getValue($song, $field);
            }
            // Explicit null also clears an old vector during incremental upserts after a content edit.
            $document['embedding'] = $this->embeddings->cachedVector($song);
            return $document;
        };
        $this->setAdapterParameters($parameters);
        $this->addAvailableSort(null, 'Relevance');
    }
}
