<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailBlacklist;
use App\Entity\User;
use App\Enum\EmailBounceType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
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
            ->setSearchFields(['email', 'reason'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            // Users can only add unsubscribes, not edit/delete global entries
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a
                ->displayIf(fn (EmailBlacklist $b) => $b->getUser() !== null))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $a) => $a
                ->displayIf(fn (EmailBlacklist $b) => $b->getUser() !== null))
            ->setPermission(Action::NEW, User::PERMISSION_SETTINGS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_SETTINGS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_SETTINGS_WRITE);
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = $pageName === Crud::PAGE_NEW;

        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield EmailField::new('email')
            ->setLabel('Email')
            ->setRequired(true);

        // Users can only add unsubscribes, hard bounces are global and automatic
        if ($isNew) {
            yield ChoiceField::new('type')
                ->setLabel('Typ')
                ->setChoices([
                    'Odhlášení' => EmailBounceType::UNSUBSCRIBE,
                ])
                ->setHelp('Hard bounce a stížnosti jsou globální a vznikají automaticky');
        } else {
            yield ChoiceField::new('type')
                ->setLabel('Typ')
                ->setChoices([
                    'Hard bounce' => EmailBounceType::HARD_BOUNCE,
                    'Soft bounce' => EmailBounceType::SOFT_BOUNCE,
                    'Stížnost' => EmailBounceType::COMPLAINT,
                    'Odhlášení' => EmailBounceType::UNSUBSCRIBE,
                ])
                ->renderAsBadges([
                    EmailBounceType::HARD_BOUNCE->value => 'danger',
                    EmailBounceType::SOFT_BOUNCE->value => 'warning',
                    EmailBounceType::COMPLAINT->value => 'danger',
                    EmailBounceType::UNSUBSCRIBE->value => 'info',
                ]);
        }

        yield TextField::new('reason')
            ->setLabel('Důvod')
            ->hideOnIndex();

        yield AssociationField::new('sourceEmailLog')
            ->setLabel('Zdrojový email')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnForm()
            ->formatValue(fn ($value) => $value === null ? 'Globální' : $value);
    }

    /**
     * Override to set type to UNSUBSCRIBE for user-created entries.
     */
    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        // Force unsubscribe type for user-created entries
        if ($entityInstance instanceof EmailBlacklist) {
            $entityInstance->setType(EmailBounceType::UNSUBSCRIBE);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }
}
