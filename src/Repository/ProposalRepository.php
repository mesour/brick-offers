<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Analysis;
use App\Entity\Lead;
use App\Entity\Proposal;
use App\Entity\User;
use App\Enum\Industry;
use App\Enum\ProposalStatus;
use App\Enum\ProposalType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Proposal>
 *
 * @method Proposal|null find($id, $lockMode = null, $lockVersion = null)
 * @method Proposal|null findOneBy(array $criteria, array $orderBy = null)
 * @method Proposal[]    findAll()
 * @method Proposal[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Proposal::class);
    }

    /**
     * Find all proposals for a user.
     *
     * @return Proposal[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all proposals for a lead.
     *
     * @return Proposal[]
     */
    public function findByLead(Lead $lead): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find proposal by lead and type.
     */
    public function findByLeadAndType(Lead $lead, ProposalType $type): ?Proposal
    {
        return $this->findOneBy(['lead' => $lead, 'type' => $type]);
    }

    /**
     * Find proposals by user and status.
     *
     * @return Proposal[]
     */
    public function findByUserAndStatus(User $user, ProposalStatus $status): array
    {
        return $this->findBy(['user' => $user, 'status' => $status], ['createdAt' => 'DESC']);
    }

    /**
     * Find a recyclable proposal for the given industry and type.
     * Returns the oldest rejected, AI-generated, non-customized proposal.
     */
    public function findRecyclable(Industry $industry, ProposalType $type): ?Proposal
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.industry = :industry')
            ->andWhere('p.type = :type')
            ->andWhere('p.isAiGenerated = true')
            ->andWhere('p.isCustomized = false')
            ->andWhere('p.recyclable = true')
            ->setParameter('status', ProposalStatus::REJECTED)
            ->setParameter('industry', $industry)
            ->setParameter('type', $type)
            ->orderBy('p.createdAt', 'ASC') // Oldest first
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all recyclable proposals.
     *
     * @return Proposal[]
     */
    public function findAllRecyclable(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.isAiGenerated = true')
            ->andWhere('p.isCustomized = false')
            ->andWhere('p.recyclable = true')
            ->setParameter('status', ProposalStatus::REJECTED)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find proposals pending generation.
     *
     * @return Proposal[]
     */
    public function findPendingGeneration(int $limit = 10): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', ProposalStatus::GENERATING)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count proposals by user and status.
     */
    public function countByUserAndStatus(User $user, ProposalStatus $status): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->andWhere('p.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count all proposals by user.
     */
    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find proposals by analysis.
     *
     * @return Proposal[]
     */
    public function findByAnalysis(Analysis $analysis): array
    {
        return $this->findBy(['analysis' => $analysis], ['createdAt' => 'DESC']);
    }

    /**
     * Find expired proposals that need status update.
     *
     * @return Proposal[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.expiresAt IS NOT NULL')
            ->andWhere('p.expiresAt < :now')
            ->andWhere('p.status NOT IN (:finalStatuses)')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('finalStatuses', [
                ProposalStatus::USED,
                ProposalStatus::RECYCLED,
                ProposalStatus::EXPIRED,
            ])
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for a user.
     *
     * @return array<string, int>
     */
    public function getStatsByUser(User $user): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('p.status, COUNT(p.id) as count')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->groupBy('p.status')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($result as $row) {
            $stats[$row['status']->value] = (int) $row['count'];
        }

        return $stats;
    }
}
