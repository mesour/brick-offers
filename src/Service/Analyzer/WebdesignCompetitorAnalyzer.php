<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Industry-specific analyzer for web design/development agencies.
 * Checks for: portfolio, case studies, pricing, testimonials, services, CTA.
 */
class WebdesignCompetitorAnalyzer extends AbstractLeadAnalyzer
{
    // Service keywords (Czech + English)
    private const SERVICE_PATTERNS = [
        'webdesign' => '/(?:webdesign|web\s*design|tvorba\s*web)/iu',
        'webdev' => '/(?:vývoj\s*web|web\s*development|programování)/iu',
        'eshop' => '/(?:e-?shop|internetov[ýá]\s*obchod)/iu',
        'seo' => '/\bseo\b/i',
        'marketing' => '/(?:marketing|ppc|reklama|advertising)/iu',
        'branding' => '/(?:branding|brand|značka|logo|identity)/iu',
        'ux_ui' => '/(?:ux|ui|user\s*experience|user\s*interface)/i',
        'mobile' => '/(?:mobilní\s*aplikace|mobile\s*app)/iu',
        'maintenance' => '/(?:správa\s*web|údržba|maintenance|hosting)/iu',
    ];

    // Portfolio/case study patterns
    private const PORTFOLIO_PATTERNS = [
        'portfolio' => '/(?:portfolio|naše\s*práce|our\s*work|projekty|realizace)/iu',
        'case_study' => '/(?:case\s*stud|případov[áé]\s*studi)/iu',
        'reference' => '/(?:reference|klienti|clients|zákazníci)/iu',
    ];

    // CTA patterns
    private const CTA_PATTERNS = [
        'contact' => '/(?:kontaktujte\s*nás|contact\s*us|napište\s*nám)/iu',
        'quote' => '/(?:nezávazná\s*nabídka|poptávka|get\s*(?:a\s*)?quote|cenová\s*nabídka)/iu',
        'consultation' => '/(?:konzultace|consultation|schůzka|meeting)/iu',
        'call' => '/(?:zavolejte|call\s*us|volejte)/iu',
    ];

    public function getCategory(): IssueCategory
    {
        return IssueCategory::INDUSTRY_WEBDESIGN;
    }

    public function getPriority(): int
    {
        return 100; // Industry-specific analyzers run after universal ones
    }

    /**
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array
    {
        return [Industry::WEBDESIGN];
    }

    public function analyze(Lead $lead): AnalyzerResult
    {
        $url = $lead->getUrl();
        if ($url === null) {
            return AnalyzerResult::failure($this->getCategory(), 'Lead URL is null');
        }

        $issues = [];
        $rawData = [
            'url' => $url,
            'checks' => [],
        ];

        $result = $this->fetchUrl($url);

        if ($result['error'] !== null) {
            return AnalyzerResult::failure($this->getCategory(), 'Failed to fetch URL: ' . $result['error']);
        }

        $content = $result['content'] ?? '';

        // Check for portfolio
        $portfolioResult = $this->checkPortfolio($content);
        $rawData['checks']['portfolio'] = $portfolioResult['data'];
        array_push($issues, ...$portfolioResult['issues']);

        // Check for case studies
        $caseStudyResult = $this->checkCaseStudies($content);
        $rawData['checks']['caseStudies'] = $caseStudyResult['data'];
        array_push($issues, ...$caseStudyResult['issues']);

        // Check for services description
        $servicesResult = $this->checkServices($content);
        $rawData['checks']['services'] = $servicesResult['data'];
        array_push($issues, ...$servicesResult['issues']);

        // Check for pricing info
        $pricingResult = $this->checkPricing($content);
        $rawData['checks']['pricing'] = $pricingResult['data'];
        array_push($issues, ...$pricingResult['issues']);

        // Check for testimonials
        $testimonialsResult = $this->checkTestimonials($content);
        $rawData['checks']['testimonials'] = $testimonialsResult['data'];
        array_push($issues, ...$testimonialsResult['issues']);

        // Check for team presentation
        $teamResult = $this->checkTeam($content);
        $rawData['checks']['team'] = $teamResult['data'];
        array_push($issues, ...$teamResult['issues']);

        // Check for CTA elements
        $ctaResult = $this->checkCTA($content);
        $rawData['checks']['cta'] = $ctaResult['data'];
        array_push($issues, ...$ctaResult['issues']);

        // Check for contact form
        $contactFormResult = $this->checkContactForm($content);
        $rawData['checks']['contactForm'] = $contactFormResult['data'];
        array_push($issues, ...$contactFormResult['issues']);

        // Check for blog/news
        $blogResult = $this->checkBlog($content);
        $rawData['checks']['blog'] = $blogResult['data'];
        array_push($issues, ...$blogResult['issues']);

        // Check for social proof
        $socialProofResult = $this->checkSocialProof($content);
        $rawData['checks']['socialProof'] = $socialProofResult['data'];
        array_push($issues, ...$socialProofResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Check for portfolio presence.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkPortfolio(string $content): array
    {
        $issues = [];
        $data = [
            'hasPortfolio' => false,
            'hasPortfolioPage' => false,
            'estimatedItems' => 0,
        ];

        // Check for portfolio section/link
        foreach (self::PORTFOLIO_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $content)) {
                $data['hasPortfolio'] = true;
                break;
            }
        }

        // Check for portfolio page link
        $data['hasPortfolioPage'] = (bool) preg_match('/href=["\'][^"\']*(?:portfolio|prace|work|projekty|realizace)["\']|<a[^>]*>(?:portfolio|naše\s*práce|realizace)</iu', $content);

        // Try to estimate portfolio items (look for repeated structures)
        if (preg_match_all('/<(?:article|div)[^>]*class=["\'][^"\']*(?:project|portfolio[_-]?item|work[_-]?item)["\'][^>]*>/iu', $content, $matches)) {
            $data['estimatedItems'] = count($matches[0]);
        }

        if (!$data['hasPortfolio'] && !$data['hasPortfolioPage']) {
            $issues[] = $this->createIssue('webdesign_no_portfolio', 'Nenalezeno portfolio ani ukázky práce');
        } elseif ($data['estimatedItems'] > 0 && $data['estimatedItems'] < 5) {
            $issues[] = $this->createIssue('webdesign_few_portfolio_items', sprintf('Portfolio obsahuje pouze %d položek', $data['estimatedItems']));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for case studies.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkCaseStudies(string $content): array
    {
        $issues = [];
        $data = [
            'hasCaseStudies' => false,
            'hasCaseStudyPage' => false,
        ];

        // Check for case study mention
        $data['hasCaseStudies'] = (bool) preg_match('/(?:case\s*stud|případov[áé]\s*studi|podrobn[ýá]\s*popis|jak\s*jsme)/iu', $content);

        // Check for case study page link
        $data['hasCaseStudyPage'] = (bool) preg_match('/href=["\'][^"\']*(?:case[_-]?stud|pripadov)/iu', $content);

        if (!$data['hasCaseStudies'] && !$data['hasCaseStudyPage']) {
            $issues[] = $this->createIssue('webdesign_no_case_studies', 'Nenalezeny případové studie realizovaných projektů');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for services description.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkServices(string $content): array
    {
        $issues = [];
        $data = [
            'services' => [],
            'hasServicesPage' => false,
            'serviceCount' => 0,
        ];

        // Check for services page link
        $data['hasServicesPage'] = (bool) preg_match('/href=["\'][^"\']*(?:sluzby|services|nabidka)["\']|>služby<|>services</iu', $content);

        // Detect specific services
        foreach (self::SERVICE_PATTERNS as $service => $pattern) {
            if (preg_match($pattern, $content)) {
                $data['services'][] = $service;
            }
        }

        $data['serviceCount'] = count($data['services']);

        if ($data['serviceCount'] < 2 && !$data['hasServicesPage']) {
            $issues[] = $this->createIssue('webdesign_no_services', sprintf('Nalezeno pouze %d služeb, chybí jasný popis nabídky', $data['serviceCount']));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for pricing information.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkPricing(string $content): array
    {
        $issues = [];
        $data = [
            'hasPricing' => false,
            'hasPricingPage' => false,
            'hasPriceRange' => false,
        ];

        // Check for pricing section/page
        $data['hasPricingPage'] = (bool) preg_match('/href=["\'][^"\']*(?:cen[iy]k|pricing|ceny)["\']|>ceník<|>ceny<|>pricing</iu', $content);

        // Check for price mentions
        $data['hasPricing'] = (bool) preg_match('/(?:cena|price|od\s*\d|from\s*\d|kč|czk|€|\$)/iu', $content);

        // Check for price range (e.g., "od 15 000 Kč")
        $data['hasPriceRange'] = (bool) preg_match('/(?:od\s*\d+\s*(?:\d+)?\s*(?:kč|czk)|from\s*\d+|cena\s*od)/iu', $content);

        if (!$data['hasPricing'] && !$data['hasPricingPage']) {
            $issues[] = $this->createIssue('webdesign_no_pricing', 'Nenalezeny informace o cenách služeb');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for testimonials.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkTestimonials(string $content): array
    {
        $issues = [];
        $data = [
            'hasTestimonials' => false,
            'hasTestimonialSection' => false,
            'estimatedCount' => 0,
        ];

        // Check for testimonial patterns
        $testimonialPatterns = [
            '/(?:testimonial|reference|co\s*říkají|what\s*.*\s*say|recenze\s*klient)/iu',
            '/(?:spokojený\s*klient|satisfied\s*customer|happy\s*client)/iu',
            '/(?:hodnocení\s*klient|client\s*review)/iu',
        ];

        foreach ($testimonialPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $data['hasTestimonials'] = true;
                break;
            }
        }

        // Check for testimonial section structure
        $data['hasTestimonialSection'] = (bool) preg_match('/<(?:section|div)[^>]*(?:class|id)=["\'][^"\']*testimonial/iu', $content);

        // Try to count testimonial items
        if (preg_match_all('/<(?:blockquote|div)[^>]*class=["\'][^"\']*(?:testimonial[_-]?item|quote|review)/iu', $content, $matches)) {
            $data['estimatedCount'] = count($matches[0]);
        }

        if (!$data['hasTestimonials'] && !$data['hasTestimonialSection']) {
            $issues[] = $this->createIssue('webdesign_no_testimonials', 'Nenalezeny reference ani testimonials od klientů');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for team presentation.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkTeam(string $content): array
    {
        $issues = [];
        $data = [
            'hasTeam' => false,
            'hasTeamPage' => false,
            'hasFounderInfo' => false,
        ];

        // Check for team section
        $data['hasTeam'] = (bool) preg_match('/(?:náš\s*tým|our\s*team|o\s*nás|about\s*us|kdo\s*jsme|who\s*we\s*are)/iu', $content);

        // Check for team page link
        $data['hasTeamPage'] = (bool) preg_match('/href=["\'][^"\']*(?:tym|team|o-nas|about)["\']|>tým<|>o\s*nás</iu', $content);

        // Check for founder/about info
        $data['hasFounderInfo'] = (bool) preg_match('/(?:zakladatel|founder|ceo|ředitel|majitel|owner)/iu', $content);

        if (!$data['hasTeam'] && !$data['hasTeamPage']) {
            $issues[] = $this->createIssue('webdesign_no_team', 'Chybí představení týmu nebo informace o společnosti');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for Call-to-Action elements.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkCTA(string $content): array
    {
        $issues = [];
        $data = [
            'ctas' => [],
            'hasPromientCTA' => false,
            'ctaCount' => 0,
        ];

        // Detect specific CTA types
        foreach (self::CTA_PATTERNS as $ctaType => $pattern) {
            if (preg_match($pattern, $content)) {
                $data['ctas'][] = $ctaType;
            }
        }

        $data['ctaCount'] = count($data['ctas']);

        // Check for prominent CTA buttons
        $data['hasPromientCTA'] = (bool) preg_match('/<(?:a|button)[^>]*class=["\'][^"\']*(?:btn|button|cta)["\'][^>]*>(?:kontakt|poptávka|nabídka|contact|quote)/iu', $content);

        if ($data['ctaCount'] === 0 && !$data['hasPromientCTA']) {
            $issues[] = $this->createIssue('webdesign_no_cta', 'Nenalezeny výrazné výzvy k akci (CTA)');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for contact form.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkContactForm(string $content): array
    {
        $issues = [];
        $data = [
            'hasContactForm' => false,
            'hasContactPage' => false,
            'formFields' => [],
        ];

        // Check for contact form
        $data['hasContactForm'] = (bool) preg_match('/<form[^>]*(?:contact|kontakt|poptavka)/iu', $content);

        // If not found in form tag, look for form with email/message fields
        if (!$data['hasContactForm']) {
            $hasEmailField = (bool) preg_match('/(?:type=["\']email["\']|name=["\']email["\'])/i', $content);
            $hasMessageField = (bool) preg_match('/<textarea/i', $content);
            $data['hasContactForm'] = $hasEmailField && $hasMessageField;
        }

        // Check for contact page link
        $data['hasContactPage'] = (bool) preg_match('/href=["\'][^"\']*(?:kontakt|contact)["\']|>kontakt<|>contact</iu', $content);

        // Detect form fields
        if (preg_match('/name=["\'](?:jmeno|name)["\']|name="name"/i', $content)) {
            $data['formFields'][] = 'name';
        }
        if (preg_match('/name=["\'](?:email|e-mail)["\']|type="email"/i', $content)) {
            $data['formFields'][] = 'email';
        }
        if (preg_match('/name=["\'](?:telefon|phone)["\']|type="tel"/i', $content)) {
            $data['formFields'][] = 'phone';
        }
        if (preg_match('/<textarea/i', $content)) {
            $data['formFields'][] = 'message';
        }

        if (!$data['hasContactForm'] && !$data['hasContactPage']) {
            $issues[] = $this->createIssue('webdesign_no_contact_form', 'Nenalezen kontaktní formulář pro poptávky');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for blog/news section.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkBlog(string $content): array
    {
        $issues = [];
        $data = [
            'hasBlog' => false,
            'hasBlogPage' => false,
        ];

        // Check for blog section
        $data['hasBlog'] = (bool) preg_match('/(?:blog|novinky|aktuality|news|články|articles)/iu', $content);

        // Check for blog page link
        $data['hasBlogPage'] = (bool) preg_match('/href=["\'][^"\']*(?:blog|novinky|aktuality|news|clanky)["\']|>blog<|>novinky</iu', $content);

        if (!$data['hasBlog'] && !$data['hasBlogPage']) {
            $issues[] = $this->createIssue('webdesign_no_blog', 'Nenalezen blog ani sekce s novinkami');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for social proof (client logos, awards, numbers).
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkSocialProof(string $content): array
    {
        $issues = [];
        $data = [
            'hasClientLogos' => false,
            'hasNumbers' => false,
            'hasAwards' => false,
            'signals' => [],
        ];

        // Check for client logos section
        $data['hasClientLogos'] = (bool) preg_match('/(?:klienti|clients|spolupracujeme|partneři|partners|loga)/iu', $content);
        if ($data['hasClientLogos']) {
            $data['signals'][] = 'client_logos';
        }

        // Check for impressive numbers (e.g., "100+ projektů", "10 let zkušeností")
        if (preg_match('/\d+\+?\s*(?:projekt|client|klient|let|years|rok|web|spokojených)/iu', $content)) {
            $data['hasNumbers'] = true;
            $data['signals'][] = 'numbers';
        }

        // Check for awards/certifications
        if (preg_match('/(?:ocenění|award|certifik|partner\s*google|google\s*partner)/iu', $content)) {
            $data['hasAwards'] = true;
            $data['signals'][] = 'awards';
        }

        $hasAnySocialProof = $data['hasClientLogos'] || $data['hasNumbers'] || $data['hasAwards'];

        if (!$hasAnySocialProof) {
            $issues[] = $this->createIssue('webdesign_no_social_proof', 'Chybí social proof (loga klientů, čísla, ocenění)');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
