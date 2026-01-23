<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DiscoveryProfile;
use App\Entity\Lead;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use App\Message\AnalyzeLeadMessage;
use App\Repository\LeadRepository;
use App\Service\Proposal\ProposalService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NumericFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class LeadCrudController extends AbstractTenantCrudController
{
    public function __construct(
        private readonly ProposalService $proposalService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $messageBus,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LeadRepository $leadRepository,
    ) {
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        $entityId = $context->getRequest()->query->get('entityId');

        if ($entityId !== null) {
            /** @var User|null $user */
            $user = $this->getUser();
            $lead = $this->leadRepository->findOneWithDetailData(
                Uuid::fromString($entityId),
                $user?->isAdmin() ? null : $user
            );

            if ($lead !== null) {
                $context->getEntity()->setInstance($lead);
            }
        }

        return parent::detail($context);
    }

    public static function getEntityFqcn(): string
    {
        return Lead::class;
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $alias = $qb->getRootAliases()[0];

        // Eager load latestAnalysis to avoid N+1 queries in action displayIf callbacks
        $qb->leftJoin(sprintf('%s.latestAnalysis', $alias), 'la')
            ->addSelect('la');

        // Also eager load discoveryProfile for the association field
        $qb->leftJoin(sprintf('%s.discoveryProfile', $alias), 'dp')
            ->addSelect('dp');

        return $qb;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Lead')
            ->setEntityLabelInPlural('Leads')
            ->setSearchFields(['domain', 'companyName', 'email', 'ico'])
            ->setDefaultSort(['score' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $analyzeAction = Action::new('analyze', false, 'fa fa-chart-bar')
            ->linkToCrudAction('triggerAnalysis')
            ->setHtmlAttributes(['title' => 'Analyzovat'])
            ->displayIf(fn (Lead $lead) => $this->hasPermission(User::PERMISSION_LEADS_ANALYZE));

        $dismissAction = Action::new('dismiss', false, 'fa fa-ban')
            ->linkToCrudAction('dismissLead')
            ->setHtmlAttributes(['title' => 'Zamítnout'])
            ->addCssClass('text-danger')
            ->displayIf(fn (Lead $lead) => $this->hasPermission(User::PERMISSION_LEADS_WRITE)
                && $lead->getStatus() !== LeadStatus::DISMISSED
                && !$lead->getStatus()?->isFinalState());

        $createProposalAction = Action::new('createProposal', false, 'fa fa-file-alt')
            ->linkToCrudAction('createProposal')
            ->setHtmlAttributes(['title' => 'Vytvořit návrh'])
            ->addCssClass('text-success')
            ->displayIf(fn (Lead $lead) => $this->hasPermission(User::PERMISSION_PROPOSALS_READ)
                && $lead->getLatestAnalysis() !== null
                && $lead->getStatus() !== LeadStatus::DISMISSED
                // Discovered leads require email
                && (!$lead->getSource()->isDiscovered() || !empty($lead->getEmail())));

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $analyzeAction)
            ->add(Crud::PAGE_INDEX, $dismissAction)
            ->add(Crud::PAGE_INDEX, $createProposalAction)
            ->add(Crud::PAGE_DETAIL, $analyzeAction)
            ->add(Crud::PAGE_DETAIL, $dismissAction)
            ->add(Crud::PAGE_DETAIL, $createProposalAction)
            // Icons only on index
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn (Action $a) => $a->setIcon('fa fa-eye')->setLabel(false)->setHtmlAttributes(['title' => 'Detail']))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $a) => $a->setIcon('fa fa-pencil')->setLabel(false)->setHtmlAttributes(['title' => 'Upravit']))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $a) => $a->setIcon('fa fa-trash')->setLabel(false)->setHtmlAttributes(['title' => 'Smazat']))
            ->setPermission(Action::NEW, User::PERMISSION_LEADS_WRITE)
            ->setPermission(Action::EDIT, User::PERMISSION_LEADS_WRITE)
            ->setPermission(Action::DELETE, User::PERMISSION_LEADS_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status')->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadStatus::cases()),
                LeadStatus::cases()
            )))
            ->add(ChoiceFilter::new('source')->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadSource::cases()),
                LeadSource::cases()
            )))
            ->add(ChoiceFilter::new('industry')->setChoices(array_combine(
                array_map(fn ($i) => $i->value, Industry::cases()),
                Industry::cases()
            )))
            ->add(EntityFilter::new('discoveryProfile')->setLabel('Discovery Profil'))
            ->add(NumericFilter::new('score')->setLabel('Skóre'))
            ->add(DateTimeFilter::new('createdAt'))
            ->add(DateTimeFilter::new('lastAnalyzedAt'));
    }

    public function configureFields(string $pageName): iterable
    {
        $isNew = $pageName === Crud::PAGE_NEW;
        $isIndex = $pageName === Crud::PAGE_INDEX;

        // Index: domain, status, createdAt, actions
        // Detail/Edit: full info

        if (!$isIndex) {
            yield IdField::new('id')->hideOnForm()->setMaxLength(8);
        }

        $urlField = UrlField::new('url')
            ->setLabel('URL')
            ->setRequired(true)
            ->hideOnIndex();
        if ($isNew) {
            $urlField->setHelp('Zadejte URL webu k analýze. Ostatní údaje se doplní automaticky.');
        }
        yield $urlField;

        yield TextField::new('domain')
            ->setLabel('Doména')
            ->setTemplatePath('admin/field/lead_domain.html.twig')
            ->hideOnForm();

        // Následující pole jsou skrytá při vytváření - doplní se z analýzy
        if (!$isNew) {
            yield TextField::new('companyName')
                ->setLabel('Firma')
                ->setRequired(false)
                ->hideOnIndex();

            yield TextField::new('email')
                ->setLabel('Email')
                ->setRequired(false)
                ->hideOnIndex();
        }

        yield ChoiceField::new('status')
            ->setLabel('Status')
            ->setChoices(array_combine(
                array_map(fn ($s) => $s->value, LeadStatus::cases()),
                LeadStatus::cases()
            ))
            ->renderAsBadges([
                LeadStatus::NEW->value => 'secondary',
                LeadStatus::POTENTIAL->value => 'info',
                LeadStatus::GOOD->value => 'success',
                LeadStatus::DONE->value => 'primary',
                LeadStatus::DEAL->value => 'warning',
                LeadStatus::DISMISSED->value => 'dark',
                LeadStatus::VERY_BAD->value => 'danger',
                LeadStatus::BAD->value => 'danger',
                LeadStatus::MIDDLE->value => 'warning',
                LeadStatus::QUALITY_GOOD->value => 'success',
                LeadStatus::SUPER->value => 'success',
            ])
            ->hideWhenCreating();

        yield IntegerField::new('score')
            ->setLabel('Skóre')
            ->setSortable(true)
            ->hideOnForm();

        if (!$isNew) {
            yield ChoiceField::new('source')
                ->setLabel('Zdroj')
                ->setChoices(array_combine(
                    array_map(fn ($s) => $s->value, LeadSource::cases()),
                    LeadSource::cases()
                ))
                ->hideOnIndex();

            yield AssociationField::new('discoveryProfile')
                ->setLabel('Profil')
                ->hideOnForm();

            yield ChoiceField::new('type')
                ->setLabel('Typ')
                ->setChoices(array_combine(
                    array_map(fn ($t) => $t->value, LeadType::cases()),
                    LeadType::cases()
                ))
                ->hideOnIndex();

            yield ChoiceField::new('industry')
                ->setLabel('Odvětví')
                ->setChoices(array_combine(
                    array_map(fn ($i) => $i->value, Industry::cases()),
                    Industry::cases()
                ))
                ->setRequired(false)
                ->hideOnIndex();

            yield IntegerField::new('priority')
                ->setLabel('Priorita')
                ->setHelp('1-10, vyšší = důležitější')
                ->hideOnIndex();

            yield AssociationField::new('company')
                ->setLabel('Společnost')
                ->hideOnIndex();

            yield AssociationField::new('latestAnalysis')
                ->setLabel('Analýza')
                ->hideOnForm()
                ->hideOnIndex();

            yield IntegerField::new('analysisCount')
                ->setLabel('Počet analýz')
                ->hideOnForm()
                ->hideOnIndex();

            yield DateTimeField::new('lastAnalyzedAt')
                ->setLabel('Analyzováno')
                ->hideOnForm()
                ->hideOnIndex();

            yield AssociationField::new('user')
                ->setLabel('Uživatel')
                ->hideOnForm()
                ->hideOnIndex();

            yield DateTimeField::new('updatedAt')
                ->setLabel('Aktualizováno')
                ->hideOnForm()
                ->hideOnIndex();

            // Show screenshot, analyses and snapshots on detail page
            if ($pageName === Crud::PAGE_DETAIL) {
                yield TextField::new('screenshot')
                    ->setLabel('Screenshot')
                    ->setTemplatePath('admin/field/lead_screenshot.html.twig')
                    ->setFormTypeOption('mapped', false);

                yield AssociationField::new('analyses')
                    ->setLabel('Analyzy')
                    ->setTemplatePath('admin/field/lead_analyses.html.twig');

                yield AssociationField::new('snapshots')
                    ->setLabel('Snapshoty (trending)')
                    ->setTemplatePath('admin/field/lead_snapshots.html.twig');
            }
        }

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->setFormat('dd.MM.yyyy HH:mm')
            ->hideOnForm();
    }

    public function triggerAnalysis(AdminContext $context): Response
    {
        /** @var Lead $lead */
        $lead = $context->getEntity()->getInstance();

        if ($lead->getId() === null) {
            $this->addFlash('error', 'Lead nemá ID.');
            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl());
        }

        $this->messageBus->dispatch(new AnalyzeLeadMessage($lead->getId()));

        $this->addFlash('success', sprintf(
            'Analýza leadu %s byla spuštěna na pozadí.',
            $lead->getDomain() ?? $lead->getUrl()
        ));

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function dismissLead(AdminContext $context): Response
    {
        /** @var Lead $lead */
        $lead = $context->getEntity()->getInstance();

        $lead->setStatus(LeadStatus::DISMISSED);
        $this->em->flush();

        $this->addFlash('warning', sprintf(
            'Lead %s byl zamítnut.',
            $lead->getDomain() ?? $lead->getUrl()
        ));

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function createProposal(AdminContext $context): Response
    {
        /** @var Lead $lead */
        $lead = $context->getEntity()->getInstance();

        /** @var User $user */
        $user = $this->getUser();

        if ($lead->getLatestAnalysis() === null) {
            $this->addFlash('error', 'Lead nemá žádnou analýzu. Nejprve spusťte analýzu.');
            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($lead->getId()?->toRfc4122())
                ->generateUrl());
        }

        try {
            $proposal = $this->proposalService->createAndGenerate($lead, $user);

            $this->addFlash('success', sprintf(
                'Návrh pro %s byl vytvořen.',
                $lead->getDomain() ?? $lead->getUrl()
            ));

            // Redirect to the proposal detail
            return $this->redirect($this->adminUrlGenerator
                ->setController(ProposalCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($proposal->getId()?->toRfc4122())
                ->generateUrl());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($lead->getId()?->toRfc4122())
                ->generateUrl());
        }
    }
}
