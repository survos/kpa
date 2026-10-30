<?php

namespace App\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Routing\RouterInterface;

class MusicDisplayField # implements FieldInterface
{
    use FieldTrait;

    private RouterInterface $router;
    private ?string $code;

    public function __construct(RouterInterface $router)
    {
        $this->router = $router;
    }

    public function formatValue($value, $entity): string
    {
        if (!$entity->text) {
            return '<div class="alert alert-warning">No ChordPro data available</div>';
        }

        $url = $this->router->generate('app_lyrics_music', ['code' => $entity->code]);

        return sprintf('
            <div data-controller="music-display"
                 data-music-display-url-value="%s"
                 data-music-display-width-value="800"
                 data-music-display-height-value="600"
                 style="min-height: 400px; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem;"
                 class="text-center text-muted">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden">Loading music...</span>
                </div>
                <p class="mt-2">Loading sheet music...</p>
            </div>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        );
    }

    public function getAsDto(): FieldDto
    {
        return (new self())
            ->setProperty('musicSheet')
            ->setLabel('Music Sheet')
            ->setTemplateName('crud/field/text')
            ->setFormType(TextType::class)
            ->addCssClass('field-text');
    }

    public function __clone(): void
    {
        // Not implemented
    }

    public function __toString(): string
    {
        return 'Music Sheet';
    }

    public static function new(string $propertyName, ?string $label = null)
    {
        // TODO: Implement new() method.
    }
}
