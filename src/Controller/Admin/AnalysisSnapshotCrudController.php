<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AnalysisSnapshot;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AnalysisSnapshotCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AnalysisSnapshot::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Snapshot analýzy')
            ->setEntityLabelInPlural('Snapshoty analýz')
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

        yield AssociationField::new('lead')
            ->setLabel('Lead');

        yield TextField::new('period')
            ->setLabel('Období');

        yield IntegerField::new('totalScore')
            ->setLabel('Skóre');

        yield IntegerField::new('issueCount')
            ->setLabel('Počet problémů');

        yield IntegerField::new('criticalIssueCount')
            ->setLabel('Kritických');

        yield DateTimeField::new('periodStart')
            ->setLabel('Začátek období')
            ->hideOnIndex();

        yield DateTimeField::new('periodEnd')
            ->setLabel('Konec období')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno');
    }
}
