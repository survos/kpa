<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Survos\EzBundle\Controller\BaseCrudController;

class SongCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return \App\Entity\Song::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('title');
        yield TextField::new('school')->hideOnForm();
        yield IntegerField::new('year')->hideOnForm();
        yield IntegerField::new('fileCount', 'Files')
            ->hideOnForm()
            ->setVirtual(true)
            ->setSortable(false);
        foreach (parent::configureFields($pageName) as $field) {
            // The legacy EZ fallback still asks EasyAdmin to infer every Doctrine column.
            // Vector caches are internal search state, not editable catalog fields.
            if (in_array($field->getAsDto()->getProperty(), ['embedding', 'embeddingFingerprint'], true)) {
                continue;
            }
            yield $field;
        }
    }
}
