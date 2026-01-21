<?php

declare(strict_types=1);

namespace App\Service\Email;

use App\Entity\EmailBlacklist;
use App\Entity\EmailLog;
use App\Entity\User;
use App\Enum\EmailBounceType;
use App\Repository\EmailBlacklistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing email blacklist.
 */
class EmailBlacklistService
{
    public function __construct(
        private readonly EmailBlacklistRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if an email is blocked (global or per-user).
     */
    public function isBlocked(string $email, ?User $user = null): bool
    {
        return $this->repository->isBlacklisted($email, $user);
    }

    /**
     * Get the blacklist entry for an email.
     */
    public function getEntry(string $email, ?User $user = null): ?EmailBlacklist
    {
        return $this->repository->findEntry($email, $user);
    }

    /**
     * Add a global bounce entry.
     */
    public function addGlobalBounce(
        string $email,
        EmailBounceType $type,
        ?string $reason = null,
        ?EmailLog $sourceLog = null,
    ): EmailBlacklist {
        // Check if already exists
        $existing = $this->repository->findEntry($email, null);
        if ($existing !== null && $existing->isGlobal()) {
            $this->logger->debug('Email already in global blacklist', [
                'email' => $email,
            ]);

            return $existing;
        }

        $entry = new EmailBlacklist();
        $entry->setEmail($email);
        $entry->setUser(null);
        $entry->setType($type);
        $entry->setReason($reason);
        $entry->setSourceEmailLog($sourceLog);

        $this->em->persist($entry);
        $this->em->flush();

        $this->logger->info('Added email to global blacklist', [
            'email' => $email,
            'type' => $type->value,
            'reason' => $reason,
        ]);

        return $entry;
    }

    /**
     * Add a per-user unsubscribe entry.
     */
    public function addUnsubscribe(
        string $email,
        User $user,
        ?string $reason = null,
        ?EmailLog $sourceLog = null,
    ): EmailBlacklist {
        // Check if already exists
        $existing = $this->repository->findEntry($email, $user);
        if ($existing !== null) {
            $this->logger->debug('Email already blacklisted for user', [
                'email' => $email,
                'user' => $user->getCode(),
            ]);

            return $existing;
        }

        $entry = new EmailBlacklist();
        $entry->setEmail($email);
        $entry->setUser($user);
        $entry->setType(EmailBounceType::UNSUBSCRIBE);
        $entry->setReason($reason ?? 'User unsubscribed');
        $entry->setSourceEmailLog($sourceLog);

        $this->em->persist($entry);
        $this->em->flush();

        $this->logger->info('Added email to user blacklist (unsubscribe)', [
            'email' => $email,
            'user' => $user->getCode(),
        ]);

        return $entry;
    }

    /**
     * Add a custom blacklist entry.
     */
    public function add(
        string $email,
        EmailBounceType $type,
        ?User $user = null,
        ?string $reason = null,
        ?EmailLog $sourceLog = null,
    ): EmailBlacklist {
        if ($type->isGlobal() || $user === null) {
            return $this->addGlobalBounce($email, $type, $reason, $sourceLog);
        }

        // Check if already exists
        $existing = $this->repository->findEntry($email, $user);
        if ($existing !== null) {
            return $existing;
        }

        $entry = new EmailBlacklist();
        $entry->setEmail($email);
        $entry->setUser($user);
        $entry->setType($type);
        $entry->setReason($reason);
        $entry->setSourceEmailLog($sourceLog);

        $this->em->persist($entry);
        $this->em->flush();

        $this->logger->info('Added email to blacklist', [
            'email' => $email,
            'type' => $type->value,
            'user' => $user?->getCode(),
        ]);

        return $entry;
    }

    /**
     * Remove an email from blacklist.
     */
    public function remove(string $email, ?User $user = null): bool
    {
        $result = $this->repository->removeEntry($email, $user);

        if ($result) {
            $this->logger->info('Removed email from blacklist', [
                'email' => $email,
                'user' => $user?->getCode(),
            ]);
        }

        return $result;
    }

    /**
     * Get global bounces.
     *
     * @return EmailBlacklist[]
     */
    public function getGlobalBounces(int $limit = 100, int $offset = 0): array
    {
        return $this->repository->findGlobalBounces($limit, $offset);
    }

    /**
     * Get user's unsubscribes.
     *
     * @return EmailBlacklist[]
     */
    public function getUserUnsubscribes(User $user, int $limit = 100): array
    {
        return $this->repository->findUserUnsubscribes($user, $limit);
    }

    /**
     * Count global blacklist entries.
     */
    public function countGlobal(): int
    {
        return $this->repository->countGlobal();
    }

    /**
     * Count user's blacklist entries.
     */
    public function countByUser(User $user): int
    {
        return $this->repository->countByUser($user);
    }
}
