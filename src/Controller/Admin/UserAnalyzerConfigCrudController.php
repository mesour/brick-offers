<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserAnalyzerConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UserAnalyzerConfigCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserAnalyzerConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Konfigurace analyzátoru')
            ->setEntityLabelInPlural('Konfigurace analyzátorů')
            ->setSearchFields(['analyzerCode'])
            ->setDefaultSort(['analyzerCode' => 'ASC'])
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

        yield TextField::new('analyzerCode')
            ->setLabel('Analyzátor')
            ->setRequired(true);

        yield BooleanField::new('enabled')
            ->setLabel('Aktivní');

        yield IntegerField::new('weight')
            ->setLabel('Váha')
            ->setHelp('Vyšší = důležitější');

        yield IntegerField::new('timeout')
            ->setLabel('Timeout (s)')
            ->hideOnIndex();

        yield IntegerField::new('retries')
            ->setLabel('Počet opakování')
            ->hideOnIndex();

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
