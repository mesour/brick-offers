<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;

/**
 * Base CRUD controller with multi-tenancy support.
 *
 * Automatically filters data based on user's tenant:
 * - ADMIN sees their own data + all sub-users' data
 * - USER sees only their own data
 *
 * Also automatically sets the user field to the tenant owner on new entities.
 */
abstract class AbstractTenantCrudController extends AbstractCrudController
{
    /**
     * Get the field name that references the User entity.
     * Override this if your entity uses a different field name.
     */
    protected function getUserFieldName(): string
    {
        return 'user';
    }

    /**
     * Whether this entity has user-based filtering.
     * Override and return false for entities without user relationship.
     */
    protected function hasUserFilter(): bool
    {
        return true;
    }

    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if (!$this->hasUserFilter()) {
            return $qb;
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $qb;
        }

        $userField = $this->getUserFieldName();
        $alias = $qb->getRootAliases()[0];

        if ($user->isAdmin()) {
            // Admin sees their own data + all sub-users' data
            $tenantUsers = $user->getTenantUsers();
            $userIds = array_map(
                static fn (User $u) => $u->getId(),
                $tenantUsers
            );

            $qb->andWhere(sprintf('%s.%s IN (:tenantUsers)', $alias, $userField))
                ->setParameter('tenantUsers', $userIds);
        } else {
            // Regular user sees only their own data
            $qb->andWhere(sprintf('%s.%s = :currentUser', $alias, $userField))
                ->setParameter('currentUser', $user->getId());
        }

        return $qb;
    }

    /**
     * Get the current authenticated user.
     */
    protected function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * Check if current user has a specific permission.
     */
    protected function hasPermission(string $permission): bool
    {
        $user = $this->getCurrentUser();

        return $user !== null && $user->hasPermission($permission);
    }

    /**
     * Get the tenant owner (admin account) for the current user.
     * For admins, returns themselves. For sub-users, returns their admin.
     */
    protected function getTenantOwner(): ?User
    {
        $user = $this->getCurrentUser();

        return $user?->getAdminOrSelf();
    }

    /**
     * Automatically set the user field to tenant owner when creating new entities.
     */
    public function persistEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        if ($this->hasUserFilter()) {
            $userField = $this->getUserFieldName();
            $setter = 'set' . ucfirst($userField);

            if (method_exists($entityInstance, $setter)) {
                $tenantOwner = $this->getTenantOwner();
                if ($tenantOwner !== null) {
                    $entityInstance->$setter($tenantOwner);
                }
            }
        }

        parent::persistEntity($entityManager, $entityInstance);
    }
}
