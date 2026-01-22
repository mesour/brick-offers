<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MarketWatchFilter;
use App\Entity\User;
use App\Enum\Industry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MarketWatchFilterCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return MarketWatchFilter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Filtr sledování trhu')
            ->setEntityLabelInPlural('Filtry sledování trhu')
            ->setSearchFields(['name', 'keywords'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::EDIT, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::DELETE, User::PERMISSION_COMPETITORS_MANAGE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('name')
            ->setLabel('Název')
            ->setRequired(true);

        yield TextField::new('keywords')
            ->setLabel('Klíčová slova')
            ->setHelp('Oddělená čárkou');

        yield ChoiceField::new('industries')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            ))
            ->allowMultipleChoices()
            ->hideOnIndex();

        yield TextField::new('regions')
            ->setLabel('Regiony')
            ->hideOnIndex();

        yield IntegerField::new('minScore')
            ->setLabel('Min. skóre')
            ->hideOnIndex();

        yield IntegerField::new('maxScore')
            ->setLabel('Max. skóre')
            ->hideOnIndex();

        yield BooleanField::new('active')
            ->setLabel('Aktivní');

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
