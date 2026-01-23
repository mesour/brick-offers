<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\MonitoredDomain;
use App\Entity\MonitoredDomainSubscription;
use App\Entity\User;
use App\Enum\ChangeSignificance;
use App\Enum\CompetitorSnapshotType;
use App\Repository\MonitoredDomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class MonitoredDomainSubscriptionCrudController extends AbstractTenantCrudController
{
    public function __construct(
        private readonly MonitoredDomainRepository $domainRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return MonitoredDomainSubscription::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Odběr domény')
            ->setEntityLabelInPlural('Odběry domén')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::EDIT, User::PERMISSION_COMPETITORS_MANAGE)
            ->setPermission(Action::DELETE, User::PERMISSION_COMPETITORS_MANAGE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        // On index/detail show the actual domain from relation
        if (in_array($pageName, [Crud::PAGE_INDEX, Crud::PAGE_DETAIL], true)) {
            yield TextField::new('monitoredDomain.domain')
                ->setLabel('Doména');
        } else {
            // In form use text input for domain
            yield TextField::new('domainInput')
                ->setLabel('Doména')
                ->setRequired(true)
                ->setHelp('Zadejte doménu (např. example.com). Pokud neexistuje, bude automaticky přidána.');
        }

        yield ChoiceField::new('snapshotTypes')
            ->setLabel('Typy snapshotů')
            ->setChoices(array_combine(
                array_map(fn (CompetitorSnapshotType $type) => $type->getLabel(), CompetitorSnapshotType::cases()),
                array_map(fn (CompetitorSnapshotType $type) => $type->value, CompetitorSnapshotType::cases()),
            ))
            ->allowMultipleChoices()
            ->setHelp('Prázdný výběr = všechny typy');

        yield BooleanField::new('alertOnChange')
            ->setLabel('Upozornit při změně');

        yield ChoiceField::new('minSignificance')
            ->setLabel('Min. významnost změny')
            ->setChoices([
                'Kritická' => ChangeSignificance::CRITICAL,
                'Vysoká' => ChangeSignificance::HIGH,
                'Střední' => ChangeSignificance::MEDIUM,
                'Nízká' => ChangeSignificance::LOW,
            ])
            ->setHelp('Minimální úroveň změny pro notifikaci');

        yield TextareaField::new('notes')
            ->setLabel('Poznámky')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno')
            ->hideOnForm()
            ->hideOnIndex();

        yield TextField::new('user.email')
            ->setLabel('Uživatel')
            ->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $em, $entity): void
    {
        $this->resolveMonitoredDomain($entity);
        parent::persistEntity($em, $entity);
    }

    public function updateEntity(EntityManagerInterface $em, $entity): void
    {
        $this->resolveMonitoredDomain($entity);
        parent::updateEntity($em, $entity);
    }

    private function resolveMonitoredDomain(MonitoredDomainSubscription $subscription): void
    {
        $domainInput = $subscription->getDomainInput();
        if ($domainInput === null || $domainInput === '') {
            return;
        }

        $domain = strtolower(trim($domainInput));
        $monitoredDomain = $this->domainRepository->findByDomain($domain);

        if ($monitoredDomain === null) {
            $monitoredDomain = new MonitoredDomain();
            $monitoredDomain->setDomain($domain);
            $monitoredDomain->setUrl('https://' . $domain);
            // Default values: crawlFrequency=weekly (entity default), active=true (entity default)
            $this->entityManager->persist($monitoredDomain);
        }

        $subscription->setMonitoredDomain($monitoredDomain);
    }
}
