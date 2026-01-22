<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AnalysisResult;
use App\Enum\AnalysisStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

class AnalysisResultCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AnalysisResult::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Výsledek analýzy')
            ->setEntityLabelInPlural('Výsledky analýz')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->value, AnalysisStatus::cases()),
                AnalysisStatus::cases()
            )));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('analysis')
            ->setLabel('Analýza');

        yield TextField::new('category')
            ->setLabel('Kategorie');

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->value, AnalysisStatus::cases()),
                AnalysisStatus::cases()
            ))
            ->renderAsBadges([
                AnalysisStatus::PENDING->value => 'secondary',
                AnalysisStatus::RUNNING->value => 'warning',
                AnalysisStatus::COMPLETED->value => 'success',
                AnalysisStatus::FAILED->value => 'danger',
            ]);

        yield IntegerField::new('score')
            ->setLabel('Skóre');

        yield IntegerField::new('issueCount')
            ->setLabel('Počet problémů')
            ->hideOnForm();

        yield IntegerField::new('criticalIssueCount')
            ->setLabel('Kritických')
            ->hideOnForm();

        yield TextareaField::new('errorMessage')
            ->setLabel('Chyba')
            ->hideOnIndex();

        yield DateTimeField::new('startedAt')
            ->setLabel('Zahájeno')
            ->hideOnIndex();

        yield DateTimeField::new('completedAt')
            ->setLabel('Dokončeno')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnIndex();
    }
}
