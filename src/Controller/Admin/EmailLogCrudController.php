<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EmailLog;
use App\Enum\EmailStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class EmailLogCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return EmailLog::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Email log')
            ->setEntityLabelInPlural('Email logy')
            ->setSearchFields(['toEmail', 'subject', 'messageId'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->label(), EmailStatus::cases()),
                EmailStatus::cases()
            )))
            ->add(DateTimeFilter::new('sentAt'))
            ->add(DateTimeFilter::new('createdAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield EmailField::new('toEmail')
            ->setLabel('Příjemce');

        yield TextField::new('subject')
            ->setLabel('Předmět');

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->label(), EmailStatus::cases()),
                EmailStatus::cases()
            ))
            ->renderAsBadges([
                EmailStatus::PENDING->value => 'secondary',
                EmailStatus::SENT->value => 'info',
                EmailStatus::DELIVERED->value => 'primary',
                EmailStatus::OPENED->value => 'success',
                EmailStatus::CLICKED->value => 'success',
                EmailStatus::BOUNCED->value => 'danger',
                EmailStatus::COMPLAINED->value => 'danger',
                EmailStatus::FAILED->value => 'danger',
            ]);

        yield AssociationField::new('offer')
            ->setLabel('Nabídka')
            ->hideOnIndex();

        yield TextField::new('fromEmail')
            ->setLabel('Odesílatel')
            ->hideOnIndex();

        yield TextField::new('toName')
            ->setLabel('Jméno příjemce')
            ->hideOnIndex();

        yield TextField::new('messageId')
            ->setLabel('Message ID')
            ->hideOnIndex();

        yield TextareaField::new('bounceMessage')
            ->setLabel('Chyba')
            ->hideOnIndex();

        yield DateTimeField::new('sentAt')
            ->setLabel('Odesláno');

        yield DateTimeField::new('deliveredAt')
            ->setLabel('Doručeno')
            ->hideOnIndex();

        yield DateTimeField::new('openedAt')
            ->setLabel('Otevřeno')
            ->hideOnIndex();

        yield DateTimeField::new('clickedAt')
            ->setLabel('Prokliknuto')
            ->hideOnIndex();

        yield DateTimeField::new('bouncedAt')
            ->setLabel('Bounce')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnIndex();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnIndex();
    }
}
