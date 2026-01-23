<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserEmailTemplate;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UserEmailTemplateCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserEmailTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Moje šablona')
            ->setEntityLabelInPlural('Moje šablony')
            ->setSearchFields(['name', 'subjectTemplate'])
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

        yield TextField::new('subjectTemplate')
            ->setLabel('Předmět')
            ->setRequired(true)
            ->setHelp('Můžete použít {{placeholdery}} pro dynamické hodnoty');

        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextareaField::new('bodyTemplate')
                ->setLabel('HTML obsah')
                ->setTemplatePath('admin/field/html_preview.html.twig');
        } else {
            yield TextareaField::new('bodyTemplate')
                ->setLabel('HTML obsah')
                ->hideOnIndex()
                ->setNumOfRows(15)
                ->setHelp('HTML šablona emailu s {{placeholdery}}');
        }

        yield BooleanField::new('isActive')
            ->setLabel('Aktivní');

        yield AssociationField::new('baseTemplate')
            ->setLabel('Základní šablona')
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp('Volitelně vyberte systémovou šablonu jako základ');

        yield BooleanField::new('aiPersonalizationEnabled')
            ->setLabel('AI personalizace')
            ->hideOnIndex();

        yield TextareaField::new('aiPersonalizationPrompt')
            ->setLabel('AI prompt')
            ->hideOnIndex()
            ->setNumOfRows(5)
            ->setHelp('Vlastní instrukce pro AI personalizaci');

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnForm();
    }
}
