<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Offer;
use App\Entity\User;
use App\Enum\OfferStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class OfferCrudController extends AbstractTenantCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Offer::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Nabídka')
            ->setEntityLabelInPlural('Nabídky')
            ->setSearchFields(['subject', 'recipientEmail', 'lead.domain'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $approveAction = Action::new('approve', 'Schválit', 'fa fa-check')
            ->linkToCrudAction('approveOffer')
            ->addCssClass('btn btn-success')
            ->displayIf(fn (Offer $offer) => $offer->getStatus()->canApprove() && $this->hasPermission(User::PERMISSION_OFFERS_APPROVE));

        $rejectAction = Action::new('reject', 'Odmítnout', 'fa fa-times')
            ->linkToCrudAction('rejectOffer')
            ->addCssClass('btn btn-danger')
            ->displayIf(fn (Offer $offer) => $offer->getStatus()->canReject() && $this->hasPermission(User::PERMISSION_OFFERS_APPROVE));

        $sendAction = Action::new('send', 'Odeslat', 'fa fa-paper-plane')
            ->linkToCrudAction('sendOffer')
            ->addCssClass('btn btn-primary')
            ->displayIf(fn (Offer $offer) => $offer->getStatus()->canSend() && $this->hasPermission(User::PERMISSION_OFFERS_SEND));

        $submitAction = Action::new('submit', 'Odeslat ke schválení', 'fa fa-check-circle')
            ->linkToCrudAction('submitForApproval')
            ->addCssClass('btn btn-info')
            ->displayIf(fn (Offer $offer) => $offer->getStatus()->canSubmitForApproval() && $this->hasPermission(User::PERMISSION_OFFERS_WRITE));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $approveAction)
            ->add(Crud::PAGE_INDEX, $rejectAction)
            ->add(Crud::PAGE_INDEX, $sendAction)
            ->add(Crud::PAGE_DETAIL, $approveAction)
            ->add(Crud::PAGE_DETAIL, $rejectAction)
            ->add(Crud::PAGE_DETAIL, $sendAction)
            ->add(Crud::PAGE_DETAIL, $submitAction)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action
                ->displayIf(fn (Offer $offer) => $offer->getStatus()->isEditable()))
            ->setPermission(Action::NEW, User::PERMISSION_OFFERS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_OFFERS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_OFFERS_WRITE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->label(), OfferStatus::cases()),
                OfferStatus::cases()
            )))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(DateTimeFilter::new('sentAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('lead')
            ->setLabel('Lead')
            ->setRequired(true);

        yield EmailField::new('recipientEmail')
            ->setLabel('Email příjemce')
            ->setRequired(true);

        yield TextField::new('recipientName')
            ->setLabel('Jméno příjemce')
            ->hideOnIndex();

        yield TextField::new('subject')
            ->setLabel('Předmět')
            ->setRequired(true);

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->label(), OfferStatus::cases()),
                OfferStatus::cases()
            ))
            ->renderAsBadges([
                OfferStatus::DRAFT->value => 'secondary',
                OfferStatus::PENDING_APPROVAL->value => 'warning',
                OfferStatus::APPROVED->value => 'info',
                OfferStatus::REJECTED->value => 'danger',
                OfferStatus::SENT->value => 'primary',
                OfferStatus::OPENED->value => 'success',
                OfferStatus::CLICKED->value => 'success',
                OfferStatus::RESPONDED->value => 'warning',
                OfferStatus::CONVERTED->value => 'success',
            ])
            ->setFormTypeOption('disabled', true);

        yield TextareaField::new('body')
            ->setLabel('Obsah emailu')
            ->hideOnIndex()
            ->setNumOfRows(15);

        yield TextareaField::new('plainTextBody')
            ->setLabel('Textová verze')
            ->hideOnIndex()
            ->hideOnDetail();

        yield AssociationField::new('proposal')
            ->setLabel('Návrh')
            ->hideOnIndex();

        yield AssociationField::new('analysis')
            ->setLabel('Analýza')
            ->hideOnIndex();

        yield AssociationField::new('emailTemplate')
            ->setLabel('Šablona')
            ->hideOnIndex();

        yield TextField::new('rejectionReason')
            ->setLabel('Důvod odmítnutí')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);

        yield AssociationField::new('approvedBy')
            ->setLabel('Schválil')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('approvedAt')
            ->setLabel('Schváleno')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('sentAt')
            ->setLabel('Odesláno')
            ->hideOnForm();

        yield DateTimeField::new('openedAt')
            ->setLabel('Otevřeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('clickedAt')
            ->setLabel('Prokliknuto')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('respondedAt')
            ->setLabel('Odpověď')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('convertedAt')
            ->setLabel('Konverze')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm();

        yield AssociationField::new('user')
            ->setLabel('Uživatel')
            ->hideOnIndex()
            ->setFormTypeOption('disabled', true);
    }

    public function approveOffer(AdminContext $context): RedirectResponse
    {
        /** @var Offer $offer */
        $offer = $context->getEntity()->getInstance();

        /** @var User $user */
        $user = $this->getUser();

        $offer->approve($user);
        $this->entityManager->flush();

        $this->addFlash('success', 'Nabídka byla schválena.');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function rejectOffer(AdminContext $context): RedirectResponse
    {
        /** @var Offer $offer */
        $offer = $context->getEntity()->getInstance();

        $offer->reject('Odmítnuto v administraci');
        $this->entityManager->flush();

        $this->addFlash('warning', 'Nabídka byla odmítnuta.');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function sendOffer(AdminContext $context): RedirectResponse
    {
        /** @var Offer $offer */
        $offer = $context->getEntity()->getInstance();

        // TODO: Implement actual email sending via Messenger
        $offer->markSent();
        $this->entityManager->flush();

        $this->addFlash('success', 'Nabídka byla odeslána.');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function submitForApproval(AdminContext $context): RedirectResponse
    {
        /** @var Offer $offer */
        $offer = $context->getEntity()->getInstance();

        $offer->submitForApproval();
        $this->entityManager->flush();

        $this->addFlash('info', 'Nabídka byla odeslána ke schválení.');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }
}
