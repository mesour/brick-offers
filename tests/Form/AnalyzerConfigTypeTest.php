<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\DiscoveryProfile;
use App\Enum\Industry;
use App\Form\AnalyzerConfigType;
use App\Service\AnalyzerConfigService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

class AnalyzerConfigTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;
    private AnalyzerConfigService $analyzerConfigService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->formFactory = $container->get(FormFactoryInterface::class);
        $this->analyzerConfigService = $container->get(AnalyzerConfigService::class);
    }

    public function testGetAnalyzerSchemasForNullIndustry(): void
    {
        $schemas = $this->analyzerConfigService->getAnalyzerSchemas(null);

        // Should only contain universal analyzers
        self::assertNotEmpty($schemas);

        foreach ($schemas as $categoryCode => $schema) {
            // All should be universal when industry is null
            self::assertTrue($schema['universal'], "Analyzer {$categoryCode} should be universal");
        }
    }

    public function testGetAnalyzerSchemasForEshopIndustry(): void
    {
        $schemas = $this->analyzerConfigService->getAnalyzerSchemas(Industry::ESHOP);

        // Should contain both universal and e-shop specific analyzers
        self::assertNotEmpty($schemas);

        $hasUniversal = false;
        $hasIndustrySpecific = false;

        foreach ($schemas as $categoryCode => $schema) {
            if ($schema['universal']) {
                $hasUniversal = true;
            } else {
                $hasIndustrySpecific = true;
            }
        }

        self::assertTrue($hasUniversal, 'Should have universal analyzers');
        self::assertTrue($hasIndustrySpecific, 'Should have industry-specific analyzers');
    }

    public function testAnalyzerSchemaContainsRequiredFields(): void
    {
        $schemas = $this->analyzerConfigService->getAnalyzerSchemas(null);

        foreach ($schemas as $categoryCode => $schema) {
            self::assertArrayHasKey('name', $schema, "Schema {$categoryCode} should have 'name'");
            self::assertArrayHasKey('description', $schema, "Schema {$categoryCode} should have 'description'");
            self::assertArrayHasKey('universal', $schema, "Schema {$categoryCode} should have 'universal'");
            self::assertArrayHasKey('priority', $schema, "Schema {$categoryCode} should have 'priority'");
            self::assertArrayHasKey('settings', $schema, "Schema {$categoryCode} should have 'settings'");
            self::assertArrayHasKey('availableIssueCodes', $schema, "Schema {$categoryCode} should have 'availableIssueCodes'");
        }
    }

    public function testPerformanceAnalyzerHasConfigurableSettings(): void
    {
        $schemas = $this->analyzerConfigService->getAnalyzerSchemas(null);

        self::assertArrayHasKey('performance', $schemas);
        self::assertNotEmpty($schemas['performance']['settings']);

        $settings = $schemas['performance']['settings'];
        self::assertArrayHasKey('lcp_good', $settings);
        self::assertArrayHasKey('lcp_poor', $settings);
        self::assertArrayHasKey('fcp_good', $settings);
        self::assertArrayHasKey('fcp_poor', $settings);
    }

    public function testMergeWithDefaultsCreatesCompleteConfig(): void
    {
        $userConfig = [
            'performance' => [
                'enabled' => false,
                'priority' => 8,
                'thresholds' => ['lcp_good' => 3000],
            ],
        ];

        $merged = $this->analyzerConfigService->mergeWithDefaults($userConfig, null);

        // Should have all universal analyzers
        self::assertArrayHasKey('performance', $merged);
        self::assertArrayHasKey('security', $merged);
        self::assertArrayHasKey('seo', $merged);

        // User overrides should be applied
        self::assertFalse($merged['performance']['enabled']);
        self::assertSame(8, $merged['performance']['priority']);
        self::assertSame(3000, $merged['performance']['thresholds']['lcp_good']);

        // Other thresholds should have defaults
        self::assertArrayHasKey('lcp_poor', $merged['performance']['thresholds']);
    }

    public function testFormCreation(): void
    {
        $form = $this->formFactory->create(AnalyzerConfigType::class, [], [
            'industry' => null,
        ]);

        // Form should have children for each analyzer category
        self::assertTrue($form->has('http'));
        self::assertTrue($form->has('security'));
        self::assertTrue($form->has('seo'));
        self::assertTrue($form->has('performance'));
    }

    public function testFormSubmission(): void
    {
        $form = $this->formFactory->create(AnalyzerConfigType::class, [], [
            'industry' => null,
        ]);

        // Note: Checkboxes not present in submitted data are false
        // Checkboxes with value '1' are true
        $form->submit([
            'http' => [
                'enabled' => '1',
                'priority' => '7',
                'ignoreCodes' => ['ssl_not_https'],
            ],
            'security' => [
                // 'enabled' not submitted = false
                'priority' => '3',
            ],
        ]);

        // Form should be submitted (valid or not, the data should be transformed)
        self::assertTrue($form->isSubmitted());

        $data = $form->getData();

        self::assertArrayHasKey('http', $data);
        self::assertTrue($data['http']['enabled']);
        self::assertSame(7, $data['http']['priority']);
        self::assertContains('ssl_not_https', $data['http']['ignoreCodes']);

        self::assertArrayHasKey('security', $data);
        self::assertFalse($data['security']['enabled']);
        self::assertSame(3, $data['security']['priority']);
    }
}
