# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Proposal Generator Module** - Abstraktní rozhraní pro generování návrhů podle odvětví
  - `ProposalGeneratorInterface` s tagged iterator pattern (`app.proposal_generator`)
  - Per-user ownership s možností recyklace AI-generovaných návrhů
  - `Proposal` entita s recyklací (status, outputs JSON, AI metadata)
  - `ProposalStatus` enum (generating, draft, approved, rejected, used, recycled, expired)
  - `ProposalType` enum (design_mockup, marketing_audit, conversion_report, security_report, etc.)
  - `ClaudeService` pro AI generování (dual-mode: CLI pro lokální dev, API pro server)
  - `ScreenshotService` pro Chrome headless screenshots
  - `DesignProposalGenerator` - první implementace pro webdesign odvětví
  - `ProposalService` pro orchestraci (create, generate, recycle, approve, reject)
  - `app:proposal:generate` command s opcemi:
    - `--lead`, `--analysis`, `--user`, `--type`
    - `--batch`, `--limit`, `--dry-run`, `--force`, `--recycle`
  - Prompt templates v `templates/prompts/`
  - Storage abstrakce (LocalStorageService, S3StorageService)
  - REST API:
    - API Platform CRUD na Proposal entity s filtry
    - `POST /api/proposals/generate` - generování pro lead
    - `POST /api/proposals/{id}/approve` - schválení
    - `POST /api/proposals/{id}/reject` - zamítnutí
    - `POST /api/proposals/{id}/recycle` - recyklace
    - `GET /api/proposals/estimate` - odhad nákladů
    - `GET /api/proposals/recyclable` - dostupnost recyklace

- **Company Entity & ARES Integration** - Centralized company management with automatic data enrichment
  - New `Company` entity with ARES data fields (IČO, DIČ, name, legal form, address, business status)
  - One-to-many relationship: Company → Leads (one company can have multiple websites)
  - `AresClient` service for fetching data from Czech ARES registry API
  - `AresData` DTO for parsing ARES API responses
  - `CompanyService` for company management and lead linking
  - `CompanyNameExtractor` for extracting company names from web pages:
    - Schema.org JSON-LD parsing (Organization, LocalBusiness)
    - Open Graph `og:site_name` meta tag
    - Legal form pattern matching (s.r.o., a.s., etc.)
    - Copyright notice extraction
    - Title tag fallback
  - `app:company:sync-ares` command for ARES data synchronization:
    - `--ico` option for specific company sync
    - `--limit`, `--force-refresh`, `--dry-run` options
  - `--link-company` option in `LeadDiscoverCommand` for automatic company linking
  - Rate limiting (200ms delay) to comply with ARES API limits
  - ARES data caching with 30-day refresh policy
  - Database migration for companies table and leads.company_id foreign key

- **Lead Discovery Extension** - Contact extraction and technology detection for leads
  - New `LeadType` enum (`WEBSITE`, `BUSINESS_WITHOUT_WEB`)
  - Extended `Lead` entity with: type, hasWebsite, ico, companyName, email, phone, address, detectedCms, detectedTechnologies, socialMedia
  - New extraction services:
    - `EmailExtractor` - email extraction with priority sorting (info@, kontakt@ first)
    - `PhoneExtractor` - Czech phone formats (+420, normalization)
    - `IcoExtractor` - IČO extraction with modulo 11 validation
    - `TechnologyDetector` - CMS detection (WordPress, Shoptet, Wix, etc.) and tech stack
    - `SocialMediaExtractor` - Facebook, Instagram, LinkedIn, Twitter, YouTube extraction
    - `PageDataExtractor` - orchestrates all extractors
  - New `--extract` / `-x` option in `LeadDiscoverCommand`
  - Integration with AbstractDiscoverySource for opt-in extraction
  - Database migration for new Lead fields

- **Analysis REST API** - New endpoints for accessing analysis data, trends, and benchmarks
  - `GET /api/leads/{id}/analyses` - Analysis history for a lead
  - `GET /api/leads/{id}/trend` - Trending data (snapshots) with period filter
  - `GET /api/leads/{id}/benchmark` - Compare lead with industry benchmark
  - `GET /api/industries` - List all industries with benchmark status
  - `GET /api/industries/{industry}/benchmark` - Industry benchmark details
  - `GET /api/industries/{industry}/benchmark/history` - Benchmark history
  - `LeadAnalysisController` and `IndustryBenchmarkController` implementations

- **Analysis Archive Command** - New command for archiving old analysis data (Fáze 6)
  - Retention policy: compress (30-90 days), clear rawData (90-365 days), delete (365+ days)
  - `app:analysis:archive` command with dry-run and show-counts options
  - `ArchiveService` with batch processing to avoid OOM
  - Compression uses gzip + base64 for safe JSON storage
  - Documentation for cron setup (monthly archivation)

- **Reference Crawler Discovery Source** - New discovery source that finds client websites from agency portfolios
  - Uses existing sources (Google, Seznam, Firmy.cz) as inner source to find agencies
  - Crawls agency websites to find reference/portfolio pages
  - Extracts client website URLs from those pages
  - New enum value `REFERENCE_CRAWLER` in `LeadSource`
  - New `--inner-source` option in `LeadDiscoverCommand`
  - Comprehensive domain filtering (social networks, stock photos, CMS sites, etc.)
  - URL truncation to handle long URLs (500 char DB limit)

- **Demand Signal Tracking** - Active demand monitoring from job portals, tenders, and RFP platforms
  - New `DemandSignal` entity for tracking active inquiries and opportunities
  - New enums: `DemandSignalSource`, `DemandSignalType`, `DemandSignalStatus`
  - Demand sources:
    - `EpoptavkaSource` - ePoptávka.cz integration (11k+ monthly RFPs)
    - `NenSource` - Czech public procurement (Věstník veřejných zakázek)
    - `JobsCzSource` - Jobs.cz job portal integration
  - `app:demand:monitor` command with options:
    - `--source` (epoptavka, nen, jobs_cz, all)
    - `--query`, `--category`, `--region`, `--min-value`
    - `--dry-run`, `--expire-old`
  - Automatic expiration of signals with passed deadlines
  - Conversion tracking (DemandSignal → Lead)
  - Industry and signal type classification

- **Competitor Monitoring** - Comprehensive tracking of competitor changes
  - New `CompetitorSnapshot` entity with hash-based change detection
  - New enums: `ChangeSignificance`, `CompetitorSnapshotType`
  - Competitor monitors:
    - `PortfolioMonitor` - Tracks portfolio/reference changes, new clients
    - `PricingMonitor` - Tracks pricing page changes, package updates
    - `ServiceMonitor` - Tracks service offerings, technology stack, certifications
  - `app:competitor:monitor` command with options:
    - `--type` (portfolio, pricing, services, all)
    - `--competitor`, `--industry`
    - `--min-significance` (critical, high, medium, low)
    - `--only-changes`, `--cleanup`
  - Significance-based change classification
  - Automatic cleanup of old snapshots
