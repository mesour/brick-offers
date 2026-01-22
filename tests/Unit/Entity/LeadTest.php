<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\LeadSource;
use App\Enum\LeadStatus;
use App\Enum\LeadType;
use App\Enum\SnapshotPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Lead::class)]
final class LeadTest extends TestCase
{
    // ==================== Default Values Tests ====================

    #[Test]
    public function constructor_setsDefaultValues(): void
    {
        $lead = new Lead();

        self::assertNull($lead->getId());
        self::assertNull($lead->getUrl());
        self::assertNull($lead->getDomain());
        self::assertSame(LeadSource::MANUAL, $lead->getSource());
        self::assertSame(LeadStatus::NEW, $lead->getStatus());
        self::assertSame(5, $lead->getPriority());
        self::assertSame([], $lead->getMetadata());
        self::assertSame(LeadType::WEBSITE, $lead->getType());
        self::assertTrue($lead->hasWebsite());
        self::assertSame(0, $lead->getAnalysisCount());
    }

    // ==================== URL Tests ====================

    #[Test]
    public function setUrl_simpleUrl_setsUrl(): void
    {
        $lead = new Lead();
        $lead->setUrl('https://example.com');

        self::assertSame('https://example.com', $lead->getUrl());
    }

    #[Test]
    public function setUrl_urlWithPath_setsUrl(): void
    {
        $lead = new Lead();
        $lead->setUrl('https://example.com/path/to/page');

        self::assertSame('https://example.com/path/to/page', $lead->getUrl());
    }

    #[Test]
    #[DataProvider('utmParametersProvider')]
    public function setUrl_stripsUtmParameters(string $input, string $expected): void
    {
        $lead = new Lead();
        $lead->setUrl($input);

        self::assertSame($expected, $lead->getUrl());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function utmParametersProvider(): iterable
    {
        yield 'utm_source' => [
            'https://example.com?utm_source=google',
            'https://example.com',
        ];
        yield 'utm_medium' => [
            'https://example.com?utm_medium=cpc',
            'https://example.com',
        ];
        yield 'utm_campaign' => [
            'https://example.com?utm_campaign=summer',
            'https://example.com',
        ];
        yield 'multiple utm' => [
            'https://example.com?utm_source=google&utm_medium=cpc&utm_campaign=test',
            'https://example.com',
        ];
        yield 'gclid' => [
            'https://example.com?gclid=abc123',
            'https://example.com',
        ];
        yield 'fbclid' => [
            'https://example.com?fbclid=xyz789',
            'https://example.com',
        ];
        yield 'msclkid' => [
            'https://example.com?msclkid=def456',
            'https://example.com',
        ];
        yield 'mixed utm and regular params' => [
            'https://example.com?page=1&utm_source=google&sort=name',
            'https://example.com?page=1&sort=name',
        ];
        yield 'preserves non-utm params' => [
            'https://example.com?category=shoes&size=42',
            'https://example.com?category=shoes&size=42',
        ];
        yield 'preserves fragment' => [
            'https://example.com?utm_source=test#section',
            'https://example.com#section',
        ];
        yield 'preserves path' => [
            'https://example.com/products?utm_source=test',
            'https://example.com/products',
        ];
    }

    #[Test]
    public function setUrl_preservesPort(): void
    {
        $lead = new Lead();
        $lead->setUrl('https://example.com:8080/path?utm_source=test');

        self::assertSame('https://example.com:8080/path', $lead->getUrl());
    }

    // ==================== Domain Tests ====================

    #[Test]
    public function setDomain_setsDomain(): void
    {
        $lead = new Lead();
        $lead->setDomain('example.com');

        self::assertSame('example.com', $lead->getDomain());
    }

    // ==================== Source Tests ====================

    #[Test]
    public function setSource_setsSource(): void
    {
        $lead = new Lead();
        $lead->setSource(LeadSource::GOOGLE);

        self::assertSame(LeadSource::GOOGLE, $lead->getSource());
    }

    // ==================== Status Tests ====================

    #[Test]
    public function setStatus_setsStatus(): void
    {
        $lead = new Lead();
        $lead->setStatus(LeadStatus::POTENTIAL);

        self::assertSame(LeadStatus::POTENTIAL, $lead->getStatus());
    }

    #[Test]
    public function setStatus_qualityStatus_setsStatus(): void
    {
        $lead = new Lead();
        $lead->setStatus(LeadStatus::SUPER);

        self::assertSame(LeadStatus::SUPER, $lead->getStatus());
    }

    // ==================== Priority Tests ====================

    #[Test]
    public function setPriority_setsPriority(): void
    {
        $lead = new Lead();
        $lead->setPriority(10);

        self::assertSame(10, $lead->getPriority());
    }

    #[Test]
    public function setPriority_minimumValue_setsPriority(): void
    {
        $lead = new Lead();
        $lead->setPriority(1);

        self::assertSame(1, $lead->getPriority());
    }

    // ==================== Metadata Tests ====================

    #[Test]
    public function setMetadata_setsMetadata(): void
    {
        $lead = new Lead();
        $metadata = ['key' => 'value', 'number' => 42];
        $lead->setMetadata($metadata);

        self::assertSame($metadata, $lead->getMetadata());
    }

    #[Test]
    public function addMetadata_addsToExisting(): void
    {
        $lead = new Lead();
        $lead->setMetadata(['existing' => 'value']);
        $lead->addMetadata('new', 'data');

        self::assertSame([
            'existing' => 'value',
            'new' => 'data',
        ], $lead->getMetadata());
    }

    #[Test]
    public function addMetadata_overwritesExistingKey(): void
    {
        $lead = new Lead();
        $lead->setMetadata(['key' => 'old']);
        $lead->addMetadata('key', 'new');

        self::assertSame(['key' => 'new'], $lead->getMetadata());
    }

    // ==================== Industry Tests ====================

    #[Test]
    public function setIndustry_setsIndustry(): void
    {
        $lead = new Lead();
        $lead->setIndustry(Industry::ESHOP);

        self::assertSame(Industry::ESHOP, $lead->getIndustry());
    }

    #[Test]
    public function setIndustry_null_clearsIndustry(): void
    {
        $lead = new Lead();
        $lead->setIndustry(Industry::ESHOP);
        $lead->setIndustry(null);

        self::assertNull($lead->getIndustry());
    }

    // ==================== Type Tests ====================

    #[Test]
    public function setType_setsType(): void
    {
        $lead = new Lead();
        $lead->setType(LeadType::BUSINESS_WITHOUT_WEB);

        self::assertSame(LeadType::BUSINESS_WITHOUT_WEB, $lead->getType());
    }

    // ==================== hasWebsite Tests ====================

    #[Test]
    public function setHasWebsite_false_setsHasWebsite(): void
    {
        $lead = new Lead();
        $lead->setHasWebsite(false);

        self::assertFalse($lead->hasWebsite());
    }

    // ==================== Analysis Count Tests ====================

    #[Test]
    public function setAnalysisCount_setsCount(): void
    {
        $lead = new Lead();
        $lead->setAnalysisCount(5);

        self::assertSame(5, $lead->getAnalysisCount());
    }

    #[Test]
    public function incrementAnalysisCount_incrementsByOne(): void
    {
        $lead = new Lead();
        $lead->setAnalysisCount(3);
        $lead->incrementAnalysisCount();

        self::assertSame(4, $lead->getAnalysisCount());
    }

    #[Test]
    public function incrementAnalysisCount_fromZero_returnsOne(): void
    {
        $lead = new Lead();
        $lead->incrementAnalysisCount();

        self::assertSame(1, $lead->getAnalysisCount());
    }

    // ==================== Contact Information Tests ====================

    #[Test]
    public function setEmail_setsEmail(): void
    {
        $lead = new Lead();
        $lead->setEmail('info@example.com');

        self::assertSame('info@example.com', $lead->getEmail());
    }

    #[Test]
    public function setPhone_setsPhone(): void
    {
        $lead = new Lead();
        $lead->setPhone('+420123456789');

        self::assertSame('+420123456789', $lead->getPhone());
    }

    #[Test]
    public function setAddress_setsAddress(): void
    {
        $lead = new Lead();
        $lead->setAddress('123 Main St, Prague');

        self::assertSame('123 Main St, Prague', $lead->getAddress());
    }

    // ==================== Company Information Tests ====================

    #[Test]
    public function setIco_setsIco(): void
    {
        $lead = new Lead();
        $lead->setIco('12345678');

        self::assertSame('12345678', $lead->getIco());
    }

    #[Test]
    public function setCompanyName_setsCompanyName(): void
    {
        $lead = new Lead();
        $lead->setCompanyName('Test Company s.r.o.');

        self::assertSame('Test Company s.r.o.', $lead->getCompanyName());
    }

    // ==================== Technology Detection Tests ====================

    #[Test]
    public function setDetectedCms_setsCms(): void
    {
        $lead = new Lead();
        $lead->setDetectedCms('WordPress');

        self::assertSame('WordPress', $lead->getDetectedCms());
    }

    #[Test]
    public function setDetectedTechnologies_setsTechnologies(): void
    {
        $lead = new Lead();
        $technologies = ['PHP', 'MySQL', 'jQuery'];
        $lead->setDetectedTechnologies($technologies);

        self::assertSame($technologies, $lead->getDetectedTechnologies());
    }

    #[Test]
    public function setSocialMedia_setsSocialMedia(): void
    {
        $lead = new Lead();
        $socialMedia = [
            'facebook' => 'https://facebook.com/test',
            'instagram' => 'https://instagram.com/test',
        ];
        $lead->setSocialMedia($socialMedia);

        self::assertSame($socialMedia, $lead->getSocialMedia());
    }

    // ==================== Snapshot Period Tests ====================

    #[Test]
    public function setSnapshotPeriod_setsSnapshotPeriod(): void
    {
        $lead = new Lead();
        $lead->setSnapshotPeriod(SnapshotPeriod::MONTH);

        self::assertSame(SnapshotPeriod::MONTH, $lead->getSnapshotPeriod());
    }

    #[Test]
    public function getEffectiveSnapshotPeriod_customSet_returnsCustom(): void
    {
        $lead = new Lead();
        $lead->setSnapshotPeriod(SnapshotPeriod::MONTH);

        self::assertSame(SnapshotPeriod::MONTH, $lead->getEffectiveSnapshotPeriod());
    }

    #[Test]
    public function getEffectiveSnapshotPeriod_noCustomNoIndustry_returnsWeek(): void
    {
        $lead = new Lead();

        self::assertSame(SnapshotPeriod::WEEK, $lead->getEffectiveSnapshotPeriod());
    }

    #[Test]
    public function getEffectiveSnapshotPeriod_industrySet_returnsIndustryDefault(): void
    {
        $lead = new Lead();
        $lead->setIndustry(Industry::ESHOP);

        // Should return industry default (which varies by industry)
        $expected = Industry::ESHOP->getDefaultSnapshotPeriod();
        self::assertSame($expected, $lead->getEffectiveSnapshotPeriod());
    }

    // ==================== Timestamps Tests ====================

    #[Test]
    public function setAnalyzedAt_setsTimestamp(): void
    {
        $lead = new Lead();
        $now = new \DateTimeImmutable();
        $lead->setAnalyzedAt($now);

        self::assertSame($now, $lead->getAnalyzedAt());
    }

    #[Test]
    public function setDoneAt_setsTimestamp(): void
    {
        $lead = new Lead();
        $now = new \DateTimeImmutable();
        $lead->setDoneAt($now);

        self::assertSame($now, $lead->getDoneAt());
    }

    #[Test]
    public function setDealAt_setsTimestamp(): void
    {
        $lead = new Lead();
        $now = new \DateTimeImmutable();
        $lead->setDealAt($now);

        self::assertSame($now, $lead->getDealAt());
    }

    #[Test]
    public function setLastAnalyzedAt_setsTimestamp(): void
    {
        $lead = new Lead();
        $now = new \DateTimeImmutable();
        $lead->setLastAnalyzedAt($now);

        self::assertSame($now, $lead->getLastAnalyzedAt());
    }

    // ==================== Lifecycle Callbacks Tests ====================

    #[Test]
    public function setCreatedAtValue_setsCreatedAndUpdatedAt(): void
    {
        $lead = new Lead();
        $lead->setCreatedAtValue();

        self::assertNotNull($lead->getCreatedAt());
        self::assertNotNull($lead->getUpdatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $lead->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $lead->getUpdatedAt());
    }

    #[Test]
    public function setUpdatedAtValue_setsUpdatedAt(): void
    {
        $lead = new Lead();
        $lead->setCreatedAtValue();

        $originalUpdated = $lead->getUpdatedAt();

        // Simulate time passing
        usleep(1000);

        $lead->setUpdatedAtValue();

        self::assertNotSame($originalUpdated, $lead->getUpdatedAt());
    }

    // ==================== Fluent Interface Tests ====================

    #[Test]
    public function setters_returnSelf_forFluentInterface(): void
    {
        $lead = new Lead();

        self::assertSame($lead, $lead->setUrl('https://example.com'));
        self::assertSame($lead, $lead->setDomain('example.com'));
        self::assertSame($lead, $lead->setSource(LeadSource::GOOGLE));
        self::assertSame($lead, $lead->setStatus(LeadStatus::NEW));
        self::assertSame($lead, $lead->setPriority(5));
        self::assertSame($lead, $lead->setMetadata([]));
        self::assertSame($lead, $lead->addMetadata('key', 'value'));
        self::assertSame($lead, $lead->setIndustry(Industry::ESHOP));
        self::assertSame($lead, $lead->setType(LeadType::WEBSITE));
        self::assertSame($lead, $lead->setHasWebsite(true));
        self::assertSame($lead, $lead->setAnalysisCount(0));
        self::assertSame($lead, $lead->incrementAnalysisCount());
    }
}
