<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ProposalCrudController extends AbstractTenantCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Proposal::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Návrh')
            ->setEntityLabelInPlural('Návrhy')
            ->setSearchFields(['title', 'lead.domain'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $approveAction = Action::new('approve', 'Schválit', 'fa fa-check')
            ->linkToCrudAction('approveProposal')
            ->addCssClass('btn btn-success')
            ->displayIf(fn (Proposal $p) => $p->getStatus()->canApprove() && $this->hasPermission(User::PERMISSION_PROPOSALS_APPROVE));

        $rejectAction = Action::new('reject', 'Odmítnout', 'fa fa-times')
            ->linkToCrudAction('rejectProposal')
            ->addCssClass('btn btn-danger')
            ->displayIf(fn (Proposal $p) => $p->getStatus()->canReject() && $this->hasPermission(User::PERMISSION_PROPOSALS_REJECT));

        $previewAction = Action::new('preview', 'Náhled', 'fa fa-eye')
            ->linkToUrl(fn (Proposal $p) => $p->getOutput('html_url') ?? '#')
            ->setHtmlAttributes(['target' => '_blank'])
            ->displayIf(fn (Proposal $p) => $p->getOutput('html_url') !== null);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $approveAction)
            ->add(Crud::PAGE_INDEX, $rejectAction)
            ->add(Crud::PAGE_INDEX, $previewAction)
            ->add(Crud::PAGE_DETAIL, $approveAction)
            ->add(Crud::PAGE_DETAIL, $rejectAction)
            ->add(Crud::PAGE_DETAIL, $previewAction)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => $action
                ->displayIf(fn (Proposal $p) => $p->getStatus()->isEditable()))
            ->setPermission(Action::NEW, User::PERMISSION_PROPOSALS_APPROVE)
            ->setPermission(Action::EDIT, User::PERMISSION_PROPOSALS_APPROVE)
            ->setPermission(Action::DELETE, User::PERMISSION_PROPOSALS_APPROVE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->label(), ProposalStatus::cases()),
                ProposalStatus::cases()
            )))
            ->add(ChoiceFilter::new('type')->setChoices(array_combine(
                array_map(fn ($t) => $t->value, ProposalType::cases()),
                ProposalType::cases()
            )))
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            )))
            ->add(BooleanFilter::new('isAiGenerated'))
            ->add(BooleanFilter::new('recyclable'))
            ->add(DateTimeFilter::new('createdAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield TextField::new('title')
            ->setLabel('Název')
            ->setRequired(true);

        yield AssociationField::new('lead')
            ->setLabel('Lead');

        yield ChoiceField::new('type')
            ->setLabel('Typ')
            ->setChoices(array_combine(
                array_map(fn ($t) => $t->value, ProposalType::cases()),
                ProposalType::cases()
            ))
            ->setRequired(true);

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->label(), ProposalStatus::cases()),
                ProposalStatus::cases()
            ))
            ->renderAsBadges([
                ProposalStatus::GENERATING->value => 'warning',
                ProposalStatus::DRAFT->value => 'secondary',
                ProposalStatus::APPROVED->value => 'success',
                ProposalStatus::REJECTED->value => 'danger',
                ProposalStatus::USED->value => 'primary',
                ProposalStatus::RECYCLED->value => 'info',
                ProposalStatus::EXPIRED->value => 'secondary',
            ])
            ->setFormTypeOption('disabled', true);

        yield ChoiceField::new('industry')
            ->setLabel('Odvětví')
            ->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            ))
            ->hideOnIndex();

        yield TextareaField::new('summary')
            ->setLabel('Shrnutí')
            ->hideOnIndex()
            ->setNumOfRows(5);

        yield TextareaField::new('content')
            ->setLabel('Obsah')
            ->hideOnIndex()
            ->hideOnDetail()
            ->setNumOfRows(15);

        yield BooleanField::new('isAiGenerated')
            ->setLabel('AI generováno')
            ->hideOnForm();

        yield BooleanField::new('isCustomized')
            ->setLabel('Upraveno')
            ->hideOnForm()
            ->hideOnIndex();

        yield BooleanField::new('recyclable')
            ->setLabel('Recyklovatelné')
            ->hideOnForm()
            ->hideOnIndex();

        yield AssociationField::new('analysis')
            ->setLabel('Analýza')
            ->hideOnIndex();

        yield AssociationField::new('originalUser')
            ->setLabel('Původní uživatel')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('expiresAt')
            ->setLabel('Expirace')
            ->hideOnIndex();

        yield DateTimeField::new('recycledAt')
            ->setLabel('Recyklováno')
            ->hideOnForm()
            ->hideOnIndex();

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

    public function approveProposal(AdminContext $context): RedirectResponse
    {
        /** @var Proposal $proposal */
        $proposal = $context->getEntity()->getInstance();

        $proposal->approve();
        $this->entityManager->flush();

        $this->addFlash('success', 'Návrh byl schválen.');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function rejectProposal(AdminContext $context): RedirectResponse
    {
        /** @var Proposal $proposal */
        $proposal = $context->getEntity()->getInstance();

        $proposal->reject();
        $this->entityManager->flush();

        $this->addFlash('warning', 'Návrh byl odmítnut.');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }
}
