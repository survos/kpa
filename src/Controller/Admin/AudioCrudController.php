<?php

namespace App\Controller\Admin;

use App\Entity\Audio;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Survos\EzBundle\Controller\AbstractEzCrudController;
use Survos\EzBundle\Field\LinkedTextField;

/**
 */
final class AudioCrudController extends AbstractEzCrudController
{
    /** TODO: adjust SHOW_ROUTE to your app show route */
    public const SHOW_ROUTE = 'audio_show';

    public static function getEntityFqcn(): string
    {
        return \App\Entity\Audio::class;
    }

    protected function preferredFields(string $pageName): iterable
    {
        if ($pageName !== Crud::PAGE_INDEX) {
            return [];
        }
        return [];

    }
}
