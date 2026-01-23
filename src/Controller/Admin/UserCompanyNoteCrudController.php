<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserCompanyNote;
use App\Enum\RelationshipStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class UserCompanyNoteCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserCompanyNote::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Poznámka k firmě')
            ->setEntityLabelInPlural('Poznámky k firmám')
            ->setSearchFields(['notes', 'company.name'])
            ->setDefaultSort(['updatedAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, User::PERMISSION_LEADS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_LEADS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_LEADS_WRITE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('company')
            ->setLabel('Firma')
            ->setRequired(true);

        yield ChoiceField::new('relationshipStatus')
            ->setLabel('Stav vztahu')
            ->setChoices([
                'Prospect' => RelationshipStatus::PROSPECT,
                'Kontaktován' => RelationshipStatus::CONTACTED,
                'Vyjednávání' => RelationshipStatus::NEGOTIATING,
                'Klient' => RelationshipStatus::CLIENT,
                'Bývalý klient' => RelationshipStatus::FORMER_CLIENT,
                'Blacklist' => RelationshipStatus::BLACKLISTED,
            ])
            ->renderAsBadges([
                RelationshipStatus::PROSPECT->value => 'secondary',
                RelationshipStatus::CONTACTED->value => 'info',
                RelationshipStatus::NEGOTIATING->value => 'warning',
                RelationshipStatus::CLIENT->value => 'success',
                RelationshipStatus::FORMER_CLIENT->value => 'light',
                RelationshipStatus::BLACKLISTED->value => 'danger',
            ]);

        yield TextareaField::new('notes')
            ->setLabel('Poznámky')
            ->setNumOfRows(5)
            ->hideOnIndex();

        yield ArrayField::new('tags')
            ->setLabel('Štítky')
            ->hideOnIndex();

        yield ArrayField::new('customFields')
            ->setLabel('Vlastní pole')
            ->hideOnIndex()
            ->setHelp('assigned_to, priority, last_call, ...');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt')
            ->setLabel('Aktualizováno')
            ->hideOnForm();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnForm();
    }
}
