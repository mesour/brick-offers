<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Discovery;

use App\Service\Discovery\DomainMatcher;
use PHPUnit\Framework\TestCase;

class DomainMatcherTest extends TestCase
{
    private DomainMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new DomainMatcher();
    }

    public function testExactMatch(): void
    {
        $this->assertTrue($this->matcher->matches('example.com', 'example.com'));
        $this->assertFalse($this->matcher->matches('example.org', 'example.com'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertTrue($this->matcher->matches('Example.COM', 'example.com'));
        $this->assertTrue($this->matcher->matches('example.com', 'EXAMPLE.COM'));
    }

    public function testWwwPrefixStripped(): void
    {
        $this->assertTrue($this->matcher->matches('www.example.com', 'example.com'));
        $this->assertTrue($this->matcher->matches('www.Example.COM', 'example.com'));
    }

    public function testSubdomainWildcard(): void
    {
        $pattern = '*.example.com';

        $this->assertTrue($this->matcher->matches('www.example.com', $pattern));
        $this->assertTrue($this->matcher->matches('sub.example.com', $pattern));
        $this->assertTrue($this->matcher->matches('deep.sub.example.com', $pattern));
        $this->assertFalse($this->matcher->matches('example.com', $pattern));
        $this->assertFalse($this->matcher->matches('notexample.com', $pattern));
    }

    public function testTldWildcard(): void
    {
        $pattern = 'example.*';

        $this->assertTrue($this->matcher->matches('example.com', $pattern));
        $this->assertTrue($this->matcher->matches('example.org', $pattern));
        $this->assertTrue($this->matcher->matches('example.co.uk', $pattern));
        $this->assertFalse($this->matcher->matches('notexample.com', $pattern));
    }

    public function testContainsWildcard(): void
    {
        $pattern = '*example*';

        $this->assertTrue($this->matcher->matches('example.com', $pattern));
        $this->assertTrue($this->matcher->matches('myexample.com', $pattern));
        $this->assertTrue($this->matcher->matches('example-site.org', $pattern));
        $this->assertFalse($this->matcher->matches('test.com', $pattern));
    }

    public function testIsExcludedWithMultiplePatterns(): void
    {
        $patterns = [
            '*.firmy.cz',
            'firmy.cz',
            '*.seznam.cz',
            'blacklisted.com',
        ];

        $this->assertTrue($this->matcher->isExcluded('firmy.cz', $patterns));
        $this->assertTrue($this->matcher->isExcluded('www.firmy.cz', $patterns));
        $this->assertTrue($this->matcher->isExcluded('profil.firmy.cz', $patterns));
        $this->assertTrue($this->matcher->isExcluded('blacklisted.com', $patterns));
        $this->assertTrue($this->matcher->isExcluded('www.seznam.cz', $patterns));

        $this->assertFalse($this->matcher->isExcluded('example.com', $patterns));
        $this->assertFalse($this->matcher->isExcluded('mysite.cz', $patterns));
    }

    public function testIsExcludedWithEmptyPatterns(): void
    {
        $this->assertFalse($this->matcher->isExcluded('example.com', []));
    }

    public function testMatchesEmptyPattern(): void
    {
        $this->assertFalse($this->matcher->matches('example.com', ''));
        $this->assertFalse($this->matcher->matches('example.com', '   '));
    }

    public function testFilterExcluded(): void
    {
        $results = [
            ['domain' => 'allowed.com'],
            ['domain' => 'www.firmy.cz'],
            ['domain' => 'another-allowed.org'],
            ['domain' => 'profil.firmy.cz'],
        ];

        $patterns = ['*.firmy.cz', 'firmy.cz'];

        $filtered = $this->matcher->filterExcluded(
            $results,
            $patterns,
            fn (array $r) => $r['domain']
        );

        $filteredDomains = array_map(fn (array $r) => $r['domain'], $filtered);
        $this->assertCount(2, $filtered);
        $this->assertContains('allowed.com', $filteredDomains);
        $this->assertContains('another-allowed.org', $filteredDomains);
        $this->assertNotContains('www.firmy.cz', $filteredDomains);
        $this->assertNotContains('profil.firmy.cz', $filteredDomains);
    }

    public function testFilterExcludedWithEmptyPatterns(): void
    {
        $results = [
            ['domain' => 'example.com'],
            ['domain' => 'test.org'],
        ];

        $filtered = $this->matcher->filterExcluded(
            $results,
            [],
            fn (array $r) => $r['domain']
        );

        $this->assertCount(2, $filtered);
    }

    public function testRealWorldCatalogDomains(): void
    {
        // Test real-world catalog domains from LeadSource
        $catalogPatterns = [
            'firmy.cz',
            '*.firmy.cz',
            'firmy.seznam.cz',
            '*.firmy.seznam.cz',
            'zivefirmy.cz',
            '*.zivefirmy.cz',
            'najisto.centrum.cz',
            '*.najisto.centrum.cz',
            'zlatestranky.cz',
            '*.zlatestranky.cz',
        ];

        // These should be excluded
        $this->assertTrue($this->matcher->isExcluded('firmy.cz', $catalogPatterns));
        $this->assertTrue($this->matcher->isExcluded('www.firmy.cz', $catalogPatterns));
        $this->assertTrue($this->matcher->isExcluded('profil.firmy.cz', $catalogPatterns));
        $this->assertTrue($this->matcher->isExcluded('firmy.seznam.cz', $catalogPatterns));
        $this->assertTrue($this->matcher->isExcluded('zivefirmy.cz', $catalogPatterns));

        // These should NOT be excluded
        $this->assertFalse($this->matcher->isExcluded('restaurace-u-kocoura.cz', $catalogPatterns));
        $this->assertFalse($this->matcher->isExcluded('www.kvetinarstvi-jana.cz', $catalogPatterns));
    }
}
