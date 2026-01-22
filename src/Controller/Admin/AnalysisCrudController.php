<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Analysis;
use App\Enum\AnalysisStatus;
use App\Enum\Industry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class AnalysisCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Analysis::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Analýza')
            ->setEntityLabelInPlural('Analýzy')
            ->setSearchFields(['lead.domain'])
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
            )))
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            )))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(DateTimeFilter::new('completedAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('lead')
            ->setLabel('Lead');

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

        yield IntegerField::new('totalScore')
            ->setLabel('Skóre')
            ->setTemplatePath('admin/field/score.html.twig');

        yield IntegerField::new('issueCount')
            ->setLabel('Počet problémů')
            ->hideOnForm();

        yield IntegerField::new('criticalIssueCount')
            ->setLabel('Kritických')
            ->hideOnForm();

        yield BooleanField::new('isEshop')
            ->setLabel('E-shop')
            ->hideOnForm();

        yield ChoiceField::new('industry')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            ))
            ->hideOnIndex();

        yield IntegerField::new('sequenceNumber')
            ->setLabel('Pořadí')
            ->hideOnIndex();

        yield AssociationField::new('previousAnalysis')
            ->setLabel('Předchozí')
            ->hideOnIndex();

        yield IntegerField::new('scoreDelta')
            ->setLabel('Změna skóre')
            ->hideOnIndex();

        yield BooleanField::new('isImproved')
            ->setLabel('Zlepšeno')
            ->hideOnIndex();

        yield DateTimeField::new('startedAt')
            ->setLabel('Zahájeno')
            ->hideOnIndex();

        yield DateTimeField::new('completedAt')
            ->setLabel('Dokončeno');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnIndex();
    }
}
