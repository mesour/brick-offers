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
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
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
            ->setDefaultSort(['industry' => 'ASC'])
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

        yield IntegerField::new('minScore')
            ->setLabel('Min skóre')
            ->setHelp('Minimální přijatelné skóre pro odvětví');

        yield IntegerField::new('avgScore')
            ->setLabel('Průměrné skóre')
            ->setHelp('Průměrné skóre v odvětví');

        yield IntegerField::new('goodScore')
            ->setLabel('Dobré skóre')
            ->setHelp('Skóre pro dobré weby v odvětví');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt')
            ->setLabel('Aktualizováno')
            ->hideOnForm()
            ->hideOnIndex();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
    }
}
