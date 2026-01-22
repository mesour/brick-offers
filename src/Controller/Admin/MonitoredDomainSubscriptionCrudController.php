<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MonitoredDomainSubscription;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MonitoredDomainSubscriptionCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return MonitoredDomainSubscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Odběr domény')
            ->setEntityLabelInPlural('Odběry domén')
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

        yield AssociationField::new('monitoredDomain')
            ->setLabel('Doména')
            ->setRequired(true);

        yield BooleanField::new('notifyOnChange')
            ->setLabel('Notifikace při změně');

        yield BooleanField::new('notifyOnNewIssue')
            ->setLabel('Notifikace při novém problému');

        yield BooleanField::new('notifyOnScoreChange')
            ->setLabel('Notifikace při změně skóre');

        yield TextField::new('notificationEmail')
            ->setLabel('Email pro notifikace')
            ->hideOnIndex();

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
