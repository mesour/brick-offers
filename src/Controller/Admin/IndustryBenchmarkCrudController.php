<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\IndustryBenchmark;
use App\Entity\User;
use App\Enum\Industry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;

class IndustryBenchmarkCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return IndustryBenchmark::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Industry benchmark')
            ->setEntityLabelInPlural('Industry benchmarky')
            ->setDefaultSort(['periodStart' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, User::PERMISSION_SETTINGS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_SETTINGS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_SETTINGS_WRITE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            )));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield ChoiceField::new('industry')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            ))
            ->setRequired(true);

        yield DateField::new('periodStart')
            ->setLabel('Období')
            ->setHelp('Začátek období pro benchmark');

        yield NumberField::new('avgScore')
            ->setLabel('Průměrné skóre')
            ->setNumDecimals(1);

        yield NumberField::new('medianScore')
            ->setLabel('Medián skóre')
            ->setNumDecimals(1);

        yield IntegerField::new('sampleSize')
            ->setLabel('Počet vzorků')
            ->setHelp('Počet analýz zahrnutých v benchmarku');

        yield NumberField::new('avgIssueCount')
            ->setLabel('Prům. problémů')
            ->setNumDecimals(1)
            ->hideOnIndex();

        yield NumberField::new('avgCriticalIssueCount')
            ->setLabel('Prům. kritických')
            ->setNumDecimals(1)
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnForm();
    }
}
