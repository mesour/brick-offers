<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MarketWatchFilter;
use App\Entity\User;
use App\Enum\DemandSignalType;
use App\Enum\Industry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
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
            ->setSearchFields(['name'])
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

        yield ArrayField::new('keywords')
            ->setLabel('Klíčová slova')
            ->setHelp('Každé klíčové slovo na nový řádek');

        yield ChoiceField::new('industries')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn (Industry $i) => $i->getLabel(), Industry::cases()),
                array_map(fn (Industry $i) => $i->value, Industry::cases())
            ))
            ->allowMultipleChoices()
            ->hideOnIndex();

        yield ChoiceField::new('signalTypes')
            ->setLabel('Typy signálů')
            ->setChoices(array_combine(
                array_map(fn (DemandSignalType $t) => $t->getLabel(), DemandSignalType::cases()),
                array_map(fn (DemandSignalType $t) => $t->value, DemandSignalType::cases())
            ))
            ->allowMultipleChoices()
            ->hideOnIndex();

        yield ArrayField::new('regions')
            ->setLabel('Regiony')
            ->setHelp('Každý region na nový řádek')
            ->hideOnIndex();

        yield ArrayField::new('excludeKeywords')
            ->setLabel('Vyloučit klíčová slova')
            ->setHelp('Signály obsahující tato slova budou ignorovány')
            ->hideOnIndex();

        yield NumberField::new('minValue')
            ->setLabel('Min. hodnota')
            ->setHelp('Minimální hodnota zakázky/poptávky')
            ->hideOnIndex();

        yield NumberField::new('maxValue')
            ->setLabel('Max. hodnota')
            ->setHelp('Maximální hodnota zakázky/poptávky')
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
            ->hideOnForm();
    }
}
