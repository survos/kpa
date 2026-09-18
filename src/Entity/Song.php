<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\FreeTextQueryFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\SongRepository;
use App\Workflow\SongWFDefinition;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\CoreBundle\Entity\RouteParametersInterface;
use App\Api\Filter\SortFilter;

use ApiPlatform\Metadata\ApiResource;
use Survos\ApiGridBundle\Api\Filter\MultiFieldSearchFilter;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Enum\Widget;

use Survos\MeiliBundle\Metadata\Facet;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Workflow\Marking;

use Doctrine\ORM\Mapping\Column;

use Doctrine\DBAL\Types\Types;
#[QueryParameter(key: 'title', filter: new PartialSearchFilter(), property: 'title', castToArray: false)]
#[QueryParameter(key: 'order[:property]', filter: new SortFilter(), properties: ['title', 'year', 'lyricsLength', 'publisher', 'writers'], castToArray: false)]
#[QueryParameter(key: 'search', property: 'search', filter: new MultiFieldSearchFilter(properties: ['title' => null, 'publisher' => null, 'writers' => null]), properties: ['title', 'publisher', 'writers'], castToArray: false)]
#[QueryParameter(key: 'facet_filter', property: 'facet_filter', filter: 'kpa.song.facets', properties: ['school', 'year'], schema: ['type' => 'array', 'items' => ['type' => 'string']])]
#[ORM\Entity(repositoryClass: SongRepository::class)]
#[RouteIdentity(field: 'id', key: 'songId')]
#[ApiResource(order: ['id' => \SortDirection::Ascending], operations: [
    new GetCollection(
        uriTemplate: '/songs/export.{_format}',
        formats: ['csv' => ['text/csv']],
        normalizationContext: ['groups' => ['song.csv'], 'skip_null_values' => false],
        paginationEnabled: false,
        name: 'songs_csv',
    ),
    new Get(),
    new GetCollection(name: self::DOCTRINE_ROUTE)],
    parameters: [
        'q' => new QueryParameter(
            filter: new FreeTextQueryFilter(new PartialSearchFilter()),
            properties: ['title'],
        ),
    ],
    normalizationContext: ['groups' => ['song.read', 'rp']])]
#[Groups(['song.read'])]
#[Assert\EnableAutoMapping]
#[EntityMeta(
    icon: 'tabler:music',
    order: 10,
    group: 'Catalog',
    label: 'Songs',
    description: 'Songs, lyrics, credits, recordings, and related media.'
)]
#[MeiliIndex(
    ui: ['icon' => 'Song'],
    persisted: ['id', 'title', 'chorus', 'year', 'school', 'lyrics', 'lyricsLength', 'writersArray', 'publishersArray', 'description', 'rp', 'fileCount'],
    searchable: ['lyrics','title','chorus'],
    filterable: ['writersArray', 'publishersArray', 'year', 'lyricsLength', 'fileCount'],
    sortable: ['lyricsLength', 'year', 'title']
)]
class Song implements RouteParametersInterface, \Stringable, MarkingInterface
{
    use RouteIdentityTrait;
    use MarkingTrait;

    public const DOCTRINE_ROUTE = 'doctrine_songs';

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.read', 'song.csv', 'searchable', 'video.read'])]
    #[Field(searchable: true, visible: false, order: 80)]
    public ?string $description = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Groups(['song.read', 'song.csv', 'searchable'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Date, visible: false, order: 70, format: 'date')]
    public ?\DateTimeInterface $date = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['song.facet', 'song.read', 'song.csv', 'video.read', 'searchable'])]
    #[Facet]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, facet: true, order: 30)]
    public ?int $year = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Groups(['song.read', 'song.csv', 'video.read', 'searchable'])]
    #[Facet]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, order: 40)]
    public ?string $school = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.csv'])]
    #[Field(searchable: true, visible: false, order: 90)]
    public ?string $lyrics {
        set {
            $this->lyrics = $value;
            $this->lyricsLength = $value ? mb_strlen($value) : null;
        }
    }

    /**
     * First {start_of_chorus}…{end_of_chorus} block, derived from $lyrics.
     * No DB column — computed on access. Indexed in Meili via the
     * persisted/searchable arrays below so grids + search pick it up.
     */
    #[Groups(['song.read', 'song.csv', 'searchable'])]
    #[Field(searchable: true, visible: true, order: 25)]
    public ?string $chorus {
        get => \App\Service\ChordProInspector::firstChorus($this->lyrics);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.read', 'song.csv'])]
    #[Field(searchable: true, visible: false, order: 95)]
    public ?string $featuredArtist = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.csv'])]
    #[Field(searchable: true, visible: false, order: 100)]
    public ?string $recordingCredits = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.csv'])]
    #[Field(searchable: true, visible: false, order: 110)]
    public ?string $musicians = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.csv'])]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, visible: false, order: 50)]
    public ?string $writers = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $wordpressPageId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.csv'])]
    public ?string $recording = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.read', 'song.csv', 'video.read', 'searchable'])]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, visible: false, order: 60)]
    public ?string $publisher = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['song.read', 'song.csv'])]
    #[Field(searchable: true, visible: false, order: 120)]
    public ?string $notes = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $aliases = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['song.read', 'song.csv'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, visible: false, order: 75)]
    public ?int $lyricsLength = null;

    #[ORM\OneToMany(mappedBy: 'song', targetEntity: Video::class)]
    #[Groups('song.read')]
    public Collection $videos;

    #[ORM\OneToMany(mappedBy: 'song', targetEntity: Audio::class)]
    #[Ignore]
    public Collection $audios;

    #[ORM\OneToMany(mappedBy: 'song', targetEntity: SongLyrics::class)]
    #[Ignore]
    public Collection $songLyrics;

    public function __construct(    #[ORM\Id]
                                    #[ORM\Column(length: 255)]
                                    #[Groups(['song.csv'])]
                                    #[Field(searchable: true, sortable: true, order: 20)]
                                    public string $id
    )
    {
        $this->videos = new ArrayCollection();
        $this->audios = new ArrayCollection();
        $this->songLyrics = new ArrayCollection();
        $this->marking = SongWFDefinition::PLACE_NEW;
    }

    #[Groups(['song.facet', 'song.read', 'song.csv'])]
    #[Facet]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, facet: true, order: 65)]
    public function getFileCount(): int
    {
        return $this->audios->count();
    }

    #[Groups(['song.read'])]
    public function getWritersArray(): array
    {
        return array_values(array_filter(array_map('trim', explode('/', (string) $this->writers)), 'strlen'));
    }

    #[Groups(['song.read'])]
    public function getPublishersArray(): array
    {
        return array_values(array_filter(array_map('trim', explode('/', $this->publisher ?? '')), 'strlen'));
    }

    public function addVideo(Video $video): static
    {
        if (!$this->videos->contains($video)) {
            $this->videos->add($video);
            $video->song = $this;
        }
        return $this;
    }

    public function removeVideo(Video $video): static
    {
        if ($this->videos->removeElement($video)) {
            // set the owning side to null (unless already changed)
            if ($video->song === $this) {
                $video->song = null;
            }
        }
        return $this;
    }

    public static function createCode(string $title, ?string $school = null, string|int|null $year = null): string
    {
        $words = explode(" ", $title);
        $code = sprintf('%s-%d-%s', self::initials($school ?? 'no-school'), $year, join('-', array_slice($words, 0, 2)));
        return substr($code, 0, 32);
    }

    public static function initials(?string $name): string
    {
        preg_match_all('#([A-Z]+)#', $name, $capitals);
        if (count($capitals[1]) >= 2) {
            return mb_substr(implode('', $capitals[1]), 0, 2, 'UTF-8');
        }
        return mb_strtoupper(mb_substr($name, 0, 2, 'UTF-8'), 'UTF-8');
    }

    #[Groups(['song.read'])]
    #[SerializedName('locale')]
    public function getLocale(): string
    {
        return 'en';
    }

    public function __toString(): string
    {
        return $this->title;
    }
    #[Groups(['song.read', 'song.csv'])]
    #[Column(type: Types::TEXT, nullable: true)]
    #[Field(searchable: true, sortable: true, order: 10)]
    public ?string $title = null;

}
