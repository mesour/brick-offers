<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailTemplate;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EmailTemplateCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Email šablona')
            ->setEntityLabelInPlural('Email šablony')
            ->setSearchFields(['name', 'subject'])
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

        yield TextField::new('name')
            ->setLabel('Název')
            ->setRequired(true);

        yield TextField::new('subject')
            ->setLabel('Předmět')
            ->setRequired(true);

        yield TextareaField::new('bodyHtml')
            ->setLabel('HTML obsah')
            ->hideOnIndex()
            ->setNumOfRows(15);

        yield TextareaField::new('bodyText')
            ->setLabel('Textový obsah')
            ->hideOnIndex()
            ->setNumOfRows(10);

        yield BooleanField::new('isDefault')
            ->setLabel('Výchozí');

        yield BooleanField::new('active')
            ->setLabel('Aktivní');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

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
