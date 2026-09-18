<?php

namespace App\Entity;

use App\Api\Filter\SortFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\VideoRepository;
use Doctrine\ORM\Mapping as ORM;
use Survos\ApiGridBundle\Api\Filter\MultiFieldSearchFilter;
use Survos\ApiGridBundle\Attribute\MeiliId;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Enum\Widget;

use Survos\MeiliBundle\Metadata\MeiliIndex;
use Symfony\Component\Serializer\Attribute\Groups;

#[QueryParameter(key: 'title', filter: new PartialSearchFilter(), property: 'title', castToArray: false)]
#[QueryParameter(key: 'description', filter: new PartialSearchFilter(), property: 'description', castToArray: false)]
#[QueryParameter(key: 'order[:property]', filter: new SortFilter(), properties: ['title', 'year'], castToArray: false)]
#[QueryParameter(key: 'search', property: 'search', filter: new MultiFieldSearchFilter(properties: ['title' => null, 'description' => null]), properties: ['title', 'description'], castToArray: false)]
#[QueryParameter(key: 'facet_filter', property: 'facet_filter', filter: 'kpa.video.facets', properties: ['school', 'year'], schema: ['type' => 'array', 'items' => ['type' => 'string']])]
#[ApiResource(order: ['id' => \SortDirection::Ascending],
    operations: [new Get(),

        new GetCollection(
//            provider: MeiliSearchStateProvider::class,
        )],
    normalizationContext: ['groups' => ['video.read', 'rp']]
)]
#[RouteIdentity(field: 'youtubeId', key: 'videoId')]
#[GetCollection(
    name: self::DOCTRINE_ROUTE,
//    uriTemplate: "meili-videos", // was {indexName}
//    uriVariables: ["indexName"],
//    provider: MeiliSearchStateProvider::class,
    normalizationContext: [
        'groups' => ['video.read', 'rp'],
    ]
)]

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[Groups(['video.read'])]

#[MeiliIndex(
    ui: ['icon' => 'Video'],
    filterable: ['school','year'],
)]
#[EntityMeta(
    icon: 'tabler:video',
    order: 20,
    group: 'Catalog',
    label: 'Videos',
    description: 'YouTube videos linked to songs and indexed for browsing.'
)]

class Video implements RouteParametersInterface, \Stringable
{
    use RouteIdentityTrait;

    const DOCTRINE_ROUTE='api-video';
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: "AUTO")]
    #[ORM\Column(type: 'integer')]
    #[Groups('song.read')]
    public private(set) ?int $id = null;
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    #[Groups('song.read')]
    #[MeiliId]
    #[Field(searchable: true, sortable: true, order: 20)]
    public ?string $youtubeId = null;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['video.read'])]
    #[Field(searchable: true, sortable: true, order: 10)]
    public ?string $title = null;
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['video.read'])]
    #[Field(searchable: true, visible: false, order: 50)]
    public ?string $description=null;
    #[ORM\Column(type: 'date', nullable: true)]
    #[Groups(['video.read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Date, visible: false, order: 40, format: 'date')]
    public ?\DateTimeInterface $date {
        set {
            if (is_string($value)) {
                $value = new \DateTime($value);
            }
            $this->date = $value;
            $this->year = $value?->format('Y') ? (int)$value->format('Y') : null;
        }
    }

    #[ORM\Column(nullable: true)]
    public array $rawData = [];

    #[ORM\ManyToOne(inversedBy: 'videos', targetEntity: Song::class, cascade: ['persist'], fetch: 'EAGER')]
    #[Groups(['video.read'])]
    public ?Song $song = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups('song.read')]
    #[Field(visible: false, order: 70)]
    public ?string $thumbnailUrl = null;

    #[ORM\Column(nullable: true)]
    #[Groups('song.read')]
    public ?array $thumbnails = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['video.read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, facet: true, order: 30)]
    public ?int $year = null;
    public function getYoutubeUrl(): string
    {
        return sprintf('https://www.youtube.com/watch?v=%s', $this->youtubeId);
    }

    public function __toString()
    {
        return (string)$this->youtubeId;
    }

    #[Groups(['video.read'])]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, order: 60)]
    public function getSchool(): ?string
    {
        return $this->song?->school;
    }
}
