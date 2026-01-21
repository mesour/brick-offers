<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Lead;
use App\Entity\Offer;
use App\Entity\User;
use App\Enum\OfferStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Offer>
 */
class OfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Offer::class);
    }

    /**
     * Find all offers for a user.
     *
     * @return Offer[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all offers for a lead.
     *
     * @return Offer[]
     */
    public function findByLead(Lead $lead): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.lead = :lead')
            ->setParameter('lead', $lead)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find offers by status.
     *
     * @return Offer[]
     */
    public function findByStatus(OfferStatus $status): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', $status)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find offers by user and status.
     *
     * @return Offer[]
     */
    public function findByUserAndStatus(User $user, OfferStatus $status): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->andWhere('o.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find offers pending approval.
     *
     * @return Offer[]
     */
    public function findPendingApproval(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', OfferStatus::PENDING_APPROVAL)
            ->orderBy('o.createdAt', 'ASC');

        if ($user !== null) {
            $qb->andWhere('o.user = :user')
                ->setParameter('user', $user);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find offers sent since a given date.
     *
     * @return Offer[]
     */
    public function findSentSince(User $user, \DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->andWhere('o.sentAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('o.sentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count offers sent today by user.
     */
    public function countSentToday(User $user): int
    {
        $today = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.user = :user')
            ->andWhere('o.sentAt >= :today')
            ->setParameter('user', $user)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count offers sent in the last hour by user.
     */
    public function countSentLastHour(User $user): int
    {
        $hourAgo = new \DateTimeImmutable('-1 hour');

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.user = :user')
            ->andWhere('o.sentAt >= :hourAgo')
            ->setParameter('user', $user)
            ->setParameter('hourAgo', $hourAgo)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count offers sent today to a specific domain by user.
     */
    public function countSentToDomainToday(User $user, string $domain): int
    {
        $today = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.user = :user')
            ->andWhere('o.sentAt >= :today')
            ->andWhere('o.recipientEmail LIKE :domain')
            ->setParameter('user', $user)
            ->setParameter('today', $today)
            ->setParameter('domain', '%@' . $domain)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find offer by tracking token.
     */
    public function findByTrackingToken(string $token): ?Offer
    {
        return $this->createQueryBuilder('o')
            ->where('o.trackingToken = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get statistics by user.
     *
     * @return array<string, int>
     */
    public function getStatsByUser(User $user): array
    {
        $results = $this->createQueryBuilder('o')
            ->select('o.status, COUNT(o.id) as count')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->groupBy('o.status')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $row) {
            $stats[$row['status']->value] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Get conversion statistics.
     *
     * @return array<string, mixed>
     */
    public function getConversionStats(User $user, ?\DateTimeInterface $since = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->select('COUNT(o.id) as total')
            ->addSelect('SUM(CASE WHEN o.status IN (:sentStatuses) THEN 1 ELSE 0 END) as sent')
            ->addSelect('SUM(CASE WHEN o.openedAt IS NOT NULL THEN 1 ELSE 0 END) as opened')
            ->addSelect('SUM(CASE WHEN o.clickedAt IS NOT NULL THEN 1 ELSE 0 END) as clicked')
            ->addSelect('SUM(CASE WHEN o.respondedAt IS NOT NULL THEN 1 ELSE 0 END) as responded')
            ->addSelect('SUM(CASE WHEN o.convertedAt IS NOT NULL THEN 1 ELSE 0 END) as converted')
            ->where('o.user = :user')
            ->setParameter('user', $user)
            ->setParameter('sentStatuses', [
                OfferStatus::SENT,
                OfferStatus::OPENED,
                OfferStatus::CLICKED,
                OfferStatus::RESPONDED,
                OfferStatus::CONVERTED,
            ]);

        if ($since !== null) {
            $qb->andWhere('o.createdAt >= :since')
                ->setParameter('since', $since);
        }

        $result = $qb->getQuery()->getSingleResult();

        $sent = (int) $result['sent'];

        return [
            'total' => (int) $result['total'],
            'sent' => $sent,
            'opened' => (int) $result['opened'],
            'clicked' => (int) $result['clicked'],
            'responded' => (int) $result['responded'],
            'converted' => (int) $result['converted'],
            'openRate' => $sent > 0 ? round((int) $result['opened'] / $sent * 100, 2) : 0,
            'clickRate' => $sent > 0 ? round((int) $result['clicked'] / $sent * 100, 2) : 0,
            'responseRate' => $sent > 0 ? round((int) $result['responded'] / $sent * 100, 2) : 0,
            'conversionRate' => $sent > 0 ? round((int) $result['converted'] / $sent * 100, 2) : 0,
        ];
    }
}
