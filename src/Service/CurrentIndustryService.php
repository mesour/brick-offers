<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\Industry;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Service to get the current industry for the logged-in user.
 * Industry is stored on the User entity (not in session).
 */
class CurrentIndustryService
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Get the industry for the logged-in user.
     */
    public function getCurrentIndustry(): ?Industry
    {
        return $this->getUser()?->getIndustry();
    }

    /**
     * Get the current industry value as string (for templates).
     */
    public function getCurrentIndustryValue(): ?string
    {
        return $this->getCurrentIndustry()?->value;
    }

    /**
     * Check if user has an industry set.
     */
    public function hasIndustry(): bool
    {
        return $this->getCurrentIndustry() !== null;
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
