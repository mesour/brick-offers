<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailLog>
 */
class EmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    /**
     * Find by provider's message ID.
     */
    public function findByMessageId(string $messageId): ?EmailLog
    {
        return $this->createQueryBuilder('e')
            ->where('e.messageId = :messageId')
            ->setParameter('messageId', $messageId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Count emails sent in the last hour by user.
     */
    public function countSentLastHour(User $user): int
    {
        $hourAgo = new \DateTimeImmutable('-1 hour');

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.user = :user')
            ->andWhere('e.sentAt >= :hourAgo')
            ->setParameter('user', $user)
            ->setParameter('hourAgo', $hourAgo)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Count emails sent today by user.
     */
    public function countSentToday(User $user): int
    {
        $today = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.user = :user')
            ->andWhere('e.sentAt >= :today')
            ->setParameter('user', $user)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find email logs older than given date.
     *
     * @return EmailLog[]
     */
    public function findOlderThan(\DateTimeImmutable $date, int $limit = 1000): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.createdAt < :date')
            ->setParameter('date', $date)
            ->orderBy('e.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get email statistics for a user.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(
        User $user,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) as total')
            ->addSelect('SUM(CASE WHEN e.status = :sent THEN 1 ELSE 0 END) as sent')
            ->addSelect('SUM(CASE WHEN e.status = :delivered THEN 1 ELSE 0 END) as delivered')
            ->addSelect('SUM(CASE WHEN e.status = :opened THEN 1 ELSE 0 END) as opened')
            ->addSelect('SUM(CASE WHEN e.status = :clicked THEN 1 ELSE 0 END) as clicked')
            ->addSelect('SUM(CASE WHEN e.status = :bounced THEN 1 ELSE 0 END) as bounced')
            ->addSelect('SUM(CASE WHEN e.status = :complained THEN 1 ELSE 0 END) as complained')
            ->addSelect('SUM(CASE WHEN e.status = :failed THEN 1 ELSE 0 END) as failed')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->setParameter('sent', EmailStatus::SENT)
            ->setParameter('delivered', EmailStatus::DELIVERED)
            ->setParameter('opened', EmailStatus::OPENED)
            ->setParameter('clicked', EmailStatus::CLICKED)
            ->setParameter('bounced', EmailStatus::BOUNCED)
            ->setParameter('complained', EmailStatus::COMPLAINED)
            ->setParameter('failed', EmailStatus::FAILED);

        if ($from !== null) {
            $qb->andWhere('e.createdAt >= :from')
                ->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('e.createdAt <= :to')
                ->setParameter('to', $to);
        }

        $result = $qb->getQuery()->getSingleResult();

        $total = (int) $result['total'];
        $sent = (int) $result['sent'] + (int) $result['delivered'] + (int) $result['opened'] + (int) $result['clicked'];

        return [
            'total' => $total,
            'sent' => $sent,
            'delivered' => (int) $result['delivered'],
            'opened' => (int) $result['opened'],
            'clicked' => (int) $result['clicked'],
            'bounced' => (int) $result['bounced'],
            'complained' => (int) $result['complained'],
            'failed' => (int) $result['failed'],
            'deliveryRate' => $sent > 0 ? round((int) $result['delivered'] / $sent * 100, 2) : 0,
            'openRate' => $sent > 0 ? round((int) $result['opened'] / $sent * 100, 2) : 0,
            'clickRate' => $sent > 0 ? round((int) $result['clicked'] / $sent * 100, 2) : 0,
            'bounceRate' => $sent > 0 ? round((int) $result['bounced'] / $sent * 100, 2) : 0,
        ];
    }

    /**
     * Find emails by user and status.
     *
     * @return EmailLog[]
     */
    public function findByUserAndStatus(User $user, EmailStatus $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent emails sent to a specific address.
     *
     * @return EmailLog[]
     */
    public function findByToEmail(string $email, int $limit = 10): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.toEmail = :email')
            ->setParameter('email', strtolower($email))
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
