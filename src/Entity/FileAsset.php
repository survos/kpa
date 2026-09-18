<?php

namespace App\Entity;

use App\Api\Filter\SortFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Api\Filter\FacetsFieldSearchFilter;
use App\Repository\FileAssetRepository;
use App\Workflow\FileAssetWFDefinition;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\ApiGridBundle\Api\Filter\MultiFieldSearchFilter;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[QueryParameter(key: 'filename', filter: new PartialSearchFilter(), property: 'filename', castToArray: false)]
#[QueryParameter(key: 'relativePath', filter: new PartialSearchFilter(), property: 'relativePath', castToArray: false)]
#[QueryParameter(key: 'path', filter: new PartialSearchFilter(), property: 'path', castToArray: false)]
#[QueryParameter(key: 'dirname', filter: new PartialSearchFilter(), property: 'dirname', castToArray: false)]
#[QueryParameter(key: 'extension', filter: new PartialSearchFilter(), property: 'extension', castToArray: false)]
#[QueryParameter(key: 'mimeType', filter: new PartialSearchFilter(), property: 'mimeType', castToArray: false)]
#[QueryParameter(key: 'type', filter: new PartialSearchFilter(), property: 'type', castToArray: false)]
#[QueryParameter(key: 'order[:property]', filter: new SortFilter(), properties: ['filename', 'size', 'modifiedTime', 'duration', 'extension', 'mimeType', 'type'], castToArray: false)]
#[QueryParameter(key: 'search', property: 'search', filter: new MultiFieldSearchFilter(properties: ['filename' => null, 'relativePath' => null, 'dirname' => null]), properties: ['filename', 'relativePath', 'dirname'], castToArray: false)]
#[QueryParameter(key: 'facet_filter', property: 'facet_filter', filter: 'kpa.fileasset.facets', properties: ['type'], schema: ['type' => 'array', 'items' => ['type' => 'string']])]
#[ORM\Entity(repositoryClass: FileAssetRepository::class)]
#[ORM\UniqueConstraint(name: 'file_asset_path', columns: ['path'])]
#[ApiResource(order: ['id' => \SortDirection::Ascending], operations: [
    new GetCollection(
        uriTemplate: '/file-assets/export.{_format}',
        formats: ['csv' => ['text/csv']],
        normalizationContext: ['groups' => ['fileasset.csv'], 'skip_null_values' => false],
        paginationEnabled: false,
        name: 'file_assets_csv',
    ),
    new Get(),
    new GetCollection(name: self::DOCTRINE_ROUTE),
])]
#[EntityMeta(
    icon: 'tabler:files',
    order: 30,
    group: 'Catalog',
    label: 'Files',
    description: 'Source documents, charts, audio, video, and imported file metadata.'
)]
#[MeiliIndex(
    ui: ['icon' => 'FileAsset'],
    filterable: ['type'],
)]
class FileAsset implements MarkingInterface
{
    use MarkingTrait;

    public const DOCTRINE_ROUTE = 'api_file_assets';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['fileasset.csv'])]
    public private(set) ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'text')]
        #[Groups(['fileasset.csv'])]
        public string $path,
        #[ORM\Column(length: 512)]
        #[Groups(['fileasset.csv'])]
        public string $relativePath,
        #[ORM\Column(length: 255)]
        #[Groups(['fileasset.csv'])]
        public string $filename,
        #[ORM\Column(length: 16)]
        #[Groups(['fileasset.csv'])]
        public string $extension,
        #[ORM\Column(length: 512, nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?string $dirname = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?int $size = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?int $modifiedTime = null,
        #[ORM\Column(type: 'boolean')]
        #[Groups(['fileasset.csv'])]
        public bool $isReadable = true,
        #[ORM\Column(length: 32)]
        #[Groups(['fileasset.csv'])]
        public string $type = 'other',
        #[ORM\Column(length: 255, nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?string $school = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?int $year = null,
        #[ORM\Column(length: 127, nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?string $mimeType = null,
        #[ORM\Column(type: Types::FLOAT, nullable: true)]
        #[Groups(['fileasset.csv'])]
        public ?float $duration = null,
        #[ORM\Column(type: Types::JSONB, nullable: true)]
        public ?array $probedData = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $lyricsCandidates = null,
    ) {
        $this->marking = FileAssetWFDefinition::PLACE_NEW;
    }

    /**
     * Object key within the archive bucket once uploaded (e.g. "mp3/48/48ef….mp3").
     * Null until the FileAssetWorkflow upload transition succeeds. Doubles as the
     * sync flag: null = not in S3, set = uploaded.
     */
    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['fileasset.csv'])]
    public ?string $s3 = null;
}
