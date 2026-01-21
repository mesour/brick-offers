<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserAnalyzerConfig;
use App\Enum\IssueCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAnalyzerConfig>
 *
 * @method UserAnalyzerConfig|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserAnalyzerConfig|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserAnalyzerConfig[]    findAll()
 * @method UserAnalyzerConfig[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserAnalyzerConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAnalyzerConfig::class);
    }

    /**
     * Get all enabled analyzer configs for a user, sorted by priority.
     *
     * @return UserAnalyzerConfig[]
     */
    public function findEnabledByUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.user = :user')
            ->andWhere('c.enabled = true')
            ->setParameter('user', $user)
            ->orderBy('c.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get enabled categories for a user.
     *
     * @return IssueCategory[]
     */
    public function findEnabledCategoriesByUser(User $user): array
    {
        $configs = $this->findEnabledByUser($user);

        return array_map(fn (UserAnalyzerConfig $c) => $c->getCategory(), $configs);
    }

    /**
     * Get config for a specific category and user.
     */
    public function findByUserAndCategory(User $user, IssueCategory $category): ?UserAnalyzerConfig
    {
        return $this->findOneBy([
            'user' => $user,
            'category' => $category,
        ]);
    }

    /**
     * Check if a category is enabled for a user.
     * Returns true if no config exists (default is enabled).
     */
    public function isCategoryEnabledForUser(User $user, IssueCategory $category): bool
    {
        $config = $this->findByUserAndCategory($user, $category);

        // If no config exists, category is enabled by default
        if ($config === null) {
            return true;
        }

        return $config->isEnabled();
    }

    /**
     * Get all configs for a user indexed by category value.
     *
     * @return array<string, UserAnalyzerConfig>
     */
    public function findAllByUserIndexed(User $user): array
    {
        $configs = $this->findBy(['user' => $user]);
        $indexed = [];

        foreach ($configs as $config) {
            $indexed[$config->getCategory()->value] = $config;
        }

        return $indexed;
    }

    /**
     * Create default configs for a user (all categories enabled).
     *
     * @return UserAnalyzerConfig[]
     */
    public function createDefaultConfigsForUser(User $user): array
    {
        $configs = [];
        $em = $this->getEntityManager();

        foreach (IssueCategory::cases() as $category) {
            $config = new UserAnalyzerConfig();
            $config->setUser($user);
            $config->setCategory($category);
            $config->setEnabled(true);
            $config->setPriority($this->getDefaultPriorityForCategory($category));

            $em->persist($config);
            $configs[] = $config;
        }

        return $configs;
    }

    /**
     * Get default priority for a category.
     */
    private function getDefaultPriorityForCategory(IssueCategory $category): int
    {
        return match ($category) {
            IssueCategory::HTTP => 10,
            IssueCategory::SECURITY => 9,
            IssueCategory::SEO => 7,
            IssueCategory::PERFORMANCE => 6,
            IssueCategory::ACCESSIBILITY => 5,
            IssueCategory::RESPONSIVENESS => 5,
            IssueCategory::VISUAL => 4,
            IssueCategory::LIBRARIES => 4,
            IssueCategory::OUTDATED_CODE => 3,
            IssueCategory::DESIGN_MODERNITY => 3,
            IssueCategory::ESHOP_DETECTION => 2,
            default => 5, // Industry-specific categories
        };
    }
}
