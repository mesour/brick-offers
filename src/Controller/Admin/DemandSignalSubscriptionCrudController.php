<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DemandSignalSubscription;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class DemandSignalSubscriptionCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return DemandSignalSubscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Odběr signálů')
            ->setEntityLabelInPlural('Odběry signálů')
            ->setSearchFields(['keywords'])
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

        yield TextField::new('keywords')
            ->setLabel('Klíčová slova')
            ->setHelp('Oddělená čárkou');

        yield TextField::new('signalTypes')
            ->setLabel('Typy signálů')
            ->hideOnIndex();

        yield IntegerField::new('minRelevanceScore')
            ->setLabel('Min. relevance')
            ->hideOnIndex();

        yield TextField::new('notificationEmail')
            ->setLabel('Email pro notifikace')
            ->hideOnIndex();

        yield BooleanField::new('instantNotification')
            ->setLabel('Okamžité notifikace');

        yield BooleanField::new('active')
            ->setLabel('Aktivní');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
    }
}
