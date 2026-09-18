<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Workflow\AudioWFDefinition;
use App\Workflow\FileAssetWFDefinition;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;

use Survos\MeiliBundle\Metadata\MeiliIndex;

use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;

#[QueryParameter(key: 'facet_filter', property: 'facet_filter', filter: 'kpa.audio.facets', properties: ['format', 'variant'], schema: ['type' => 'array', 'items' => ['type' => 'string']])]
#[ORM\Entity]
#[ApiResource(order: ['id' => \SortDirection::Ascending], operations: [
    new Get(),
    new GetCollection(name: self::DOCTRINE_ROUTE),
])]
#[EntityMeta(
    icon: 'tabler:wave-sine',
    order: 40,
    group: 'Catalog',
    label: 'Audio',
    description: 'Audio recordings and generated music metadata.'
)]
#[MeiliIndex(
    ui: ['icon' => 'Audio'],
    filterable: ['format', 'variant'],
)]
class Audio implements MarkingInterface
{
    use MarkingTrait;

    public const DOCTRINE_ROUTE = 'api_audio';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    public function __construct(
        #[ORM\OneToOne(targetEntity: FileAsset::class, cascade: ['persist'])]
        #[ORM\JoinColumn(nullable: false, unique: true)]
        public FileAsset $fileAsset,
        #[ORM\ManyToOne(targetEntity: Song::class, inversedBy: 'audios')]
        #[ORM\JoinColumn(nullable: false)]
        public Song $song,
        #[ORM\Column(length: 255)]
        public string $title,
        #[ORM\Column(length: 16)]
        public string $format,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $size = null,
        #[ORM\Column(length: 32, nullable: true)]
        public ?string $variant = null,
    ) {
        $this->marking = AudioWFDefinition::PLACE_NEW;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $musicXml = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $lyricsJson = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lyricsText = null;
}
