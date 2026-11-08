<?php

namespace App\Controller\Admin;

use App\Entity\FileAsset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Survos\EzBundle\Controller\AbstractEzCrudController;

/**
 */
final class FileAssetCrudController extends AbstractEzCrudController
{
    public static function getEntityFqcn(): string
    {
        return \App\Entity\FileAsset::class;
    }

    protected function preferredFields(string $pageName): iterable
    {
        if ($pageName !== Crud::PAGE_INDEX) {
            return [];
        }
        return [
            TextField::new('filename'),
            TextField::new('extension'),
            TextField::new('type'),
            NumberField::new('duration')->setNumDecimals(2)->setSortable(true),
            NumberField::new('size')->setSortable(true),
        ];

    }
}
