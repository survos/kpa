<?php

namespace App\Controller\Admin;

use App\Index\Sample;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Survos\EzBundle\Controller\AbstractEzCrudController;
use Survos\EzBundle\Field\LinkedTextField;

/**
 */
final class SampleCrudController extends AbstractEzCrudController
{
    /** TODO: adjust SHOW_ROUTE to your app show route */
    public const SHOW_ROUTE = 'admin_app_sample_show';

    public static function getEntityFqcn(): string
    {
        return \App\Index\Sample::class;
    }

    protected function preferredFields(string $pageName): iterable
    {
        if ($pageName !== Crud::PAGE_INDEX) {
            return [];
        }

        yield LinkedTextField::new('id', 'Id')
            ->setRoute(self::SHOW_ROUTE, 'id', 'id');
    }
}
