<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailTemplate;
use App\Entity\User;
use App\Enum\Industry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailTemplate>
 *
 * @method EmailTemplate|null find($id, $lockMode = null, $lockVersion = null)
 * @method EmailTemplate|null findOneBy(array $criteria, array $orderBy = null)
 * @method EmailTemplate[]    findAll()
 * @method EmailTemplate[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EmailTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTemplate::class);
    }

    /**
     * Get all templates for a user (including global templates).
     *
     * @return EmailTemplate[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->orWhere('t.user IS NULL')
            ->setParameter('user', $user)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get user's own templates.
     *
     * @return EmailTemplate[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['name' => 'ASC']);
    }

    /**
     * Get global templates.
     *
     * @return EmailTemplate[]
     */
    public function findGlobal(): array
    {
        return $this->findBy(['user' => null], ['name' => 'ASC']);
    }

    /**
     * Get global templates for an industry.
     *
     * @return EmailTemplate[]
     */
    public function findGlobalByIndustry(Industry $industry): array
    {
        return $this->findBy(['user' => null, 'industry' => $industry], ['name' => 'ASC']);
    }

    /**
     * Find the best matching template with hierarchical resolution.
     *
     * Resolution order:
     * 1. User's template with matching name
     * 2. Global template for industry with matching name
     * 3. Global default template with matching name
     * 4. User's default template
     * 5. Global industry default
     * 6. Global default
     */
    public function findBestMatch(User $user, ?Industry $industry = null, ?string $name = null): ?EmailTemplate
    {
        // Try user's specific template by name
        if ($name !== null) {
            $userTemplate = $this->findOneBy(['user' => $user, 'name' => $name]);
            if ($userTemplate !== null) {
                return $userTemplate;
            }

            // Try global template for industry by name
            if ($industry !== null) {
                $industryTemplate = $this->findOneBy(['user' => null, 'industry' => $industry, 'name' => $name]);
                if ($industryTemplate !== null) {
                    return $industryTemplate;
                }
            }

            // Try global default by name
            $globalTemplate = $this->findOneBy(['user' => null, 'industry' => null, 'name' => $name]);
            if ($globalTemplate !== null) {
                return $globalTemplate;
            }
        }

        // Try user's default template
        $userDefault = $this->findOneBy(['user' => $user, 'isDefault' => true]);
        if ($userDefault !== null) {
            return $userDefault;
        }

        // Try global industry default
        if ($industry !== null) {
            $industryDefault = $this->findOneBy(['user' => null, 'industry' => $industry, 'isDefault' => true]);
            if ($industryDefault !== null) {
                return $industryDefault;
            }
        }

        // Try global default
        return $this->findOneBy(['user' => null, 'industry' => null, 'isDefault' => true]);
    }

    /**
     * Find template by name for user (with fallback to global).
     */
    public function findByNameForUser(User $user, string $name): ?EmailTemplate
    {
        // First try user's template
        $template = $this->findOneBy(['user' => $user, 'name' => $name]);
        if ($template !== null) {
            return $template;
        }

        // Fallback to global
        return $this->findOneBy(['user' => null, 'name' => $name]);
    }
}
