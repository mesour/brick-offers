<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CompetitorSnapshot;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class CompetitorSnapshotCrudController extends AbstractCrudController
{
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $qb;
        }

        $alias = $qb->getRootAliases()[0];
        $qb->join(sprintf('%s.monitoredDomain', $alias), 'domain')
            ->addSelect('domain')
            ->join('domain.subscriptions', 'subscriptions');

        if ($user->isAdmin()) {
            $tenantUsers = $user->getTenantUsers();
            $userIds = array_map(static fn (User $u) => $u->getId(), $tenantUsers);
            $qb->andWhere('subscriptions.user IN (:tenantUsers)')
                ->setParameter('tenantUsers', $userIds);
        } else {
            $qb->andWhere('subscriptions.user = :currentUser')
                ->setParameter('currentUser', $user->getId());
        }

        // Use GROUP BY instead of DISTINCT - PostgreSQL can't compare JSON columns
        $qb->groupBy(sprintf('%s.id', $alias));

        return $qb;
    }

    public static function getEntityFqcn(): string
    {
        return CompetitorSnapshot::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Snapshot konkurence')
            ->setEntityLabelInPlural('Snapshoty konkurence')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm()->setMaxLength(8);

        yield AssociationField::new('monitoredDomain')
            ->setLabel('Doména');

        yield IntegerField::new('totalScore')
            ->setLabel('Skóre');

        yield IntegerField::new('issueCount')
            ->setLabel('Počet problémů');

        yield IntegerField::new('scoreDelta')
            ->setLabel('Změna skóre');

        yield TextareaField::new('changesSummary')
            ->setLabel('Shrnutí změn')
            ->hideOnIndex();

        yield DateTimeField::new('createdAt')
            ->setLabel('Vytvořeno');
    }
}
