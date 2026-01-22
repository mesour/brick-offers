<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CompetitorSnapshot;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class CompetitorSnapshotCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CompetitorSnapshot::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Snapshot konkurence')
            ->setEntityLabelInPlural('Snapshoty konkurence')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('monitoredDomain')
            ->setLabel('Doména');

        yield IntegerField::new('totalScore')
            ->setLabel('Skóre');

        yield IntegerField::new('issueCount')
            ->setLabel('Počet problémů');

        yield IntegerField::new('scoreDelta')
            ->setLabel('Změna skóre');

        yield TextareaField::new('changesSummary')
            ->setLabel('Shrnutí změn')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno');
    }
}
