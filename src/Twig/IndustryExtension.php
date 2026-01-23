<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Industry;
use App\Service\CurrentIndustryService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig extension for industry-related functionality.
 * Provides global variables and functions for templates.
 */
class IndustryExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly CurrentIndustryService $currentIndustryService,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'current_industry' => $this->currentIndustryService->getCurrentIndustry(),
            'current_industry_value' => $this->currentIndustryService->getCurrentIndustryValue(),
            'has_industry' => $this->currentIndustryService->hasIndustry(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_current_industry', [$this, 'getCurrentIndustry']),
            new TwigFunction('get_industry_label', [$this, 'getIndustryLabel']),
        ];
    }

    public function getCurrentIndustry(): ?Industry
    {
        return $this->currentIndustryService->getCurrentIndustry();
    }

    public function getIndustryLabel(?Industry $industry): string
    {
        return $industry?->getLabel() ?? '';
    }
}
