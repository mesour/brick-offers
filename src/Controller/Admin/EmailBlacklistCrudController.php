<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailBlacklist;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EmailBlacklistCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailBlacklist::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Blacklist')
            ->setEntityLabelInPlural('Blacklist')
            ->setSearchFields(['email', 'domain', 'reason'])
            ->setDefaultSort(['createdAt' => 'DESC'])
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

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield EmailField::new('email')
            ->setLabel('Email')
            ->setHelp('Konkrétní email nebo prázdné pro blokování celé domény');

        yield TextField::new('domain')
            ->setLabel('Doména')
            ->setHelp('Blokuje všechny emaily z domény');

        yield TextField::new('reason')
            ->setLabel('Důvod')
            ->setRequired(true);

        yield TextareaField::new('notes')
            ->setLabel('Poznámky')
            ->hideOnIndex();

        yield DateTimeField::new('expiresAt')
            ->setLabel('Expirace')
            ->setHelp('Prázdné = trvalé blokování');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
    }
}
