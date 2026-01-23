<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DemandSignalSubscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class DemandSignalSubscriptionCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return DemandSignalSubscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Odběr signálu')
            ->setEntityLabelInPlural('Odběry signálů')
            ->setSearchFields(['demandSignal.title', 'demandSignal.companyName', 'notes'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW) // Subscriptions are created automatically based on MarketWatchFilters
            ->setPermission(Action::EDIT, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::DELETE, User::PERMISSION_COMPETITORS_MANAGE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('demandSignal')
            ->setLabel('Signál')
            ->setRequired(true);

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnForm();

        yield ChoiceField::new('status')
            ->setLabel('Stav')
            ->setChoices(array_combine(
                array_map(fn (SubscriptionStatus $s) => $s->getLabel(), SubscriptionStatus::cases()),
                SubscriptionStatus::cases()
            ))
            ->renderAsBadges([
                SubscriptionStatus::NEW->value => 'primary',
                SubscriptionStatus::REVIEWED->value => 'info',
                SubscriptionStatus::DISMISSED->value => 'secondary',
                SubscriptionStatus::CONVERTED->value => 'success',
            ]);

        yield TextareaField::new('notes')
            ->setLabel('Poznámky')
            ->hideOnIndex();

        yield AssociationField::new('convertedLead')
            ->setLabel('Konvertovaný lead')
            ->hideOnIndex()
            ->hideOnForm();

        yield DateTimeField::new('convertedAt')
            ->setLabel('Konvertováno')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt')
            ->setLabel('Aktualizováno')
            ->hideOnForm()
            ->hideOnIndex();
    }
}
