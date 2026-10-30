<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Workflow\AudioWFDefinition;
use App\Workflow\FileAssetWFDefinition;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\MeiliBundle\Api\Filter\FacetsFieldSearchFilter;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use ApiPlatform\Metadata\ApiFilter;
use App\Entity\Traits\MarkingTrait;

#[ORM\Entity]
#[ApiResource(operations: [
    new Get(),
    new GetCollection(name: self::DOCTRINE_ROUTE),
])]
#[ApiFilter(FacetsFieldSearchFilter::class,
    properties: ['format', 'variant'],
    arguments: ["searchParameterName" => "facet_filter"]
)]
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
class Audio
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
