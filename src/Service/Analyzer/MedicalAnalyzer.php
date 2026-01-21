<?php

declare(strict_types=1);

namespace App\Service\Analyzer;

use App\Entity\Lead;
use App\Enum\Industry;
use App\Enum\IssueCategory;

/**
 * Industry-specific analyzer for medical/healthcare websites.
 * Skeleton implementation - checks for: online appointment, services, doctor profiles.
 */
class MedicalAnalyzer extends AbstractLeadAnalyzer
{
    public function getCategory(): IssueCategory
    {
        return IssueCategory::INDUSTRY_MEDICAL;
    }

    public function getPriority(): int
    {
        return 100;
    }

    /**
     * @return array<Industry>
     */
    public function getSupportedIndustries(): array
    {
        return [Industry::MEDICAL];
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

        // Check for online appointment booking
        $appointmentResult = $this->checkAppointment($content);
        $rawData['checks']['appointment'] = $appointmentResult['data'];
        array_push($issues, ...$appointmentResult['issues']);

        // Check for services listing
        $servicesResult = $this->checkServices($content);
        $rawData['checks']['services'] = $servicesResult['data'];
        array_push($issues, ...$servicesResult['issues']);

        // Check for doctor profiles
        $doctorResult = $this->checkDoctorProfiles($content);
        $rawData['checks']['doctors'] = $doctorResult['data'];
        array_push($issues, ...$doctorResult['issues']);

        return AnalyzerResult::success($this->getCategory(), $issues, $rawData);
    }

    /**
     * Check for online appointment booking.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkAppointment(string $content): array
    {
        $issues = [];
        $data = [
            'hasAppointment' => false,
            'hasOnlineBooking' => false,
            'hasBookingSystem' => false,
        ];

        // Check for appointment mentions
        $data['hasAppointment'] = (bool) preg_match('/(?:objednat\s*se|objednávka|termín|appointment|book\s*(?:an\s*)?appointment)/iu', $content);

        // Check for online booking
        $data['hasOnlineBooking'] = (bool) preg_match('/(?:online\s*objednání|online\s*rezervace|objednat\s*online)/iu', $content);

        // Check for booking systems (Reservio, Calendly, etc.)
        $data['hasBookingSystem'] = (bool) preg_match('/(?:reservio|calendly|docplanner|urosvdba|objednejte|samobslužná\s*objednávka)/iu', $content);

        // Check for booking form
        $hasBookingForm = (bool) preg_match('/<form[^>]*(?:objedn|appointment|booking)/iu', $content);

        if (!$data['hasAppointment'] && !$data['hasOnlineBooking'] && !$data['hasBookingSystem'] && !$hasBookingForm) {
            $issues[] = $this->createIssue('medical_no_appointment', 'Chybí možnost online objednání k lékaři');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for services listing.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkServices(string $content): array
    {
        $issues = [];
        $data = [
            'hasServices' => false,
            'hasServicesList' => false,
            'detectedServices' => [],
        ];

        // Check for services section
        $data['hasServices'] = (bool) preg_match('/(?:služby|nabídka|services|co\s*nabízíme|naše\s*služby)/iu', $content);

        // Check for medical service keywords
        $services = [];
        $servicePatterns = [
            'preventive' => '/(?:prevence|preventivní|prohlídka)/iu',
            'dental' => '/(?:zubn[íý]|stomatolog|dental)/iu',
            'lab' => '/(?:laboratorní|odběr\s*krve|rozbor)/iu',
            'surgery' => '/(?:chirurgie|operace|zákrok)/iu',
            'therapy' => '/(?:terapie|rehabilitace|fyzioterapie)/iu',
            'diagnostics' => '/(?:diagnos|vyšetření|ultrazvuk|rtg|ct|mri)/iu',
        ];

        foreach ($servicePatterns as $service => $pattern) {
            if (preg_match($pattern, $content)) {
                $services[] = $service;
            }
        }

        $data['detectedServices'] = $services;
        $data['hasServicesList'] = count($services) >= 2;

        if (!$data['hasServices'] && !$data['hasServicesList']) {
            $issues[] = $this->createIssue('medical_no_services', 'Chybí přehled nabízených zdravotnických služeb');
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * Check for doctor profiles.
     *
     * @return array{data: array<string, mixed>, issues: array<Issue>}
     */
    private function checkDoctorProfiles(string $content): array
    {
        $issues = [];
        $data = [
            'hasDoctors' => false,
            'hasDoctorProfiles' => false,
            'hasSpecializations' => false,
        ];

        // Check for doctor mentions
        $data['hasDoctors'] = (bool) preg_match('/(?:lékař|doktor|mudr|mvdr|doctor|physician|náš\s*tým)/iu', $content);

        // Check for doctor profiles section
        $data['hasDoctorProfiles'] = (bool) preg_match('/(?:náši\s*lékaři|our\s*doctors|tým\s*lékařů|personál|naši\s*specialisté)/iu', $content);

        // Check for specializations
        $data['hasSpecializations'] = (bool) preg_match('/(?:specializace|specialization|atestace|obor|interní|chirurg|gynekolog|pediatr|kardiolog)/iu', $content);

        if (!$data['hasDoctors'] && !$data['hasDoctorProfiles']) {
            $issues[] = $this->createIssue('medical_no_doctor_profiles', 'Chybí profily lékařů a jejich specializací');
        }

        return ['data' => $data, 'issues' => $issues];
    }
}
