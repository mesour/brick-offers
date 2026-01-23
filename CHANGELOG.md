# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **MonitoredDomain Architecture Refactoring** - Oddělení globálních domén od uživatelských odběrů
  - `MonitoredDomain` je nyní globální entita spravovaná přes CLI
  - `MonitoredDomainSubscription` zůstává per-tenant (uživatelské odběry)
  - Nový CLI příkaz `app:monitor:domain` pro správu domén (add, remove, update, list)
  - Admin panel pro `MonitoredDomain` omezen na ROLE_SUPER_ADMIN
  - Migrace odstraňuje `user_id` sloupec a řeší duplicitní záznamy

### Added
- **Contact Page Crawling** - Automatické prohledávání kontaktních stránek pro emaily
  - `PageDataExtractor::extractWithContactPages()` - crawluje kontakt/about stránky
  - Hledá CZ odkazy (`/kontakt`, `/o-nas`), EN (`/contact`, `/about`) a DE (`/impressum`)
  - Navštíví až 3 kontaktní stránky a sloučí nalezené emaily/telefony
  - Integrováno do discovery sources - standardně povoleno
  - `AbstractDiscoverySource::setContactPageCrawlingEnabled()` pro vypnutí

- **Auto Snapshots & Screenshots** - Automatické vytváření při analýze leadu
  - `AnalyzeLeadMessageHandler` rozšířen o automatické snapshot/screenshot triggery
  - Po dokončení analýzy se vytvoří `AnalysisSnapshot` pro trending a historii
  - Po analýze se asynchronně dispatchuje `TakeScreenshotMessage` pro vizuální archivaci
  - Snapshot se vytváří pouze při úspěšné analýze (`AnalysisStatus::COMPLETED`)

- **Intuitivní konfigurace analyzérů** - UI pro Discovery Profile místo JSON textarea
  - `LeadAnalyzerInterface` rozšířen o `getName()`, `getDescription()`, `getConfigurableSettings()`
  - `AnalyzerConfigService` pro správu schémat analyzérů a jejich metadata
  - Custom `AnalyzerConfigField` pro EasyAdmin s collapsible panels
  - Form types: `AnalyzerConfigType`, `AnalyzerItemType`, `ThresholdsType`
  - Checkbox pro enable/disable analyzéru, slider pro prioritu
  - Multi-select pro ignorování issue kódů
  - Dynamické thresholds (např. PerformanceAnalyzer: LCP, FCP, CLS, TTFB)
  - Badge pro označení univerzální vs industry-specific analyzérů

- **Discovery Profiles** - Named profiles combining discovery settings, analyzer configuration, and industry
  - `DiscoveryProfile` entity with discovery settings (sources, queries, limits) and analyzer configs
  - Profile-based batch discovery with auto-analyze support
  - Admin CRUD controller with Duplicate and Set as Default actions
  - CLI commands for profile-based discovery:
    - `app:discovery:batch --profile=NAME` - run specific profile
    - `app:discovery:migrate-profiles` - migrate legacy user settings
  - Updated message handlers to use profile configurations
  - Analyzer configs with ignoreCodes and priority overrides
  - Backward compatibility with legacy User.settings['discovery']

- **Admin Documentation** - Uživatelská dokumentace pro admin rozhraní v češtině
  - `docs/admin/README.md` - přehled admin panelu, navigace, základní workflow
  - `docs/admin/lead-pipeline.md` - dokumentace Lead Pipeline sekce
  - `docs/admin/workflow.md` - dokumentace Návrhy a Nabídky
  - `docs/admin/email.md` - dokumentace Email management
  - `docs/admin/monitoring.md` - dokumentace Monitoring domén a konkurence
  - `docs/admin/configuration.md` - dokumentace Nastavení a uživatelé
  - `docs/admin/permissions.md` - přehled 20 oprávnění a rolí

- **User Industry Configuration** - Industry-based access control and session-based switching
  - `allowedIndustries` field on User entity (JSON array of Industry enum values)
  - Sub-users inherit industries from admin account
  - CLI command `app:user:set-industries` for managing allowed industries
  - `CurrentIndustryService` for session-based industry selection
  - `IndustryExtension` Twig extension with globals and functions
  - Industry switcher dropdown in admin header
  - `data-industry` attribute on `<body>` for CSS styling hooks
  - EasyAdmin layout override for switcher integration
- **PermissionVoter** - Symfony Voter pro propojení EasyAdmin permissions s User::hasPermission()
- **Entity __toString() methods** - Lead, Analysis, Company, EmailTemplate, MonitoredDomain, Offer, Proposal

### Changed
- **Discovery Profile Admin** - Přejmenován tab "Analýza nastavení" na "Nastavení analýzy"
- **Admin Module** - Vylepšený multi-tenancy systém:
  - Kompletní tenant filtering pro všechny CRUD controllery
  - Automatické nastavení user při vytváření entit
  - Skrytí pole user ve formulářích (nastavuje se automaticky)
  - Zjednodušené formuláře: Lead (pouze URL), Company (pouze IČO)
  - Proposal - zakázáno manuální vytváření (AI-generated)
  - EmailTemplate - změněno na read-only (systémové šablony)
  - Přejmenování menu: "Systémové šablony", "Moje šablony"

### Fixed
- Nginx Unit port mismatch (80 → 8080) a cesta (www → public)
- UUID toBinary() PostgreSQL encoding error v tenant filtering
- Chybějící __toString() způsobující "could not be converted to string"
- Špatné názvy polí v EmailTemplateCrudController a UserEmailTemplateCrudController

---

- **Admin Module** - Complete EasyAdmin dashboard for entity management
  - EasyAdmin 4.x integration with custom dashboard
  - User authentication with form login and remember-me
  - Multi-tenant data isolation (ROLE_ADMIN sees tenant, ROLE_USER sees own data)
  - Granular permission system with 20+ permission scopes
  - Permission templates: manager, approver, analyst, full
  - Admin account hierarchy (admin → sub-users)
  - CRUD controllers for all entities:
    - Lead Pipeline: Lead, Company, Analysis, AnalysisResult, AnalysisSnapshot
    - Workflow: Proposal, Offer with approve/reject/send actions
    - Email: EmailTemplate, UserEmailTemplate, EmailLog, EmailBlacklist
    - Monitoring: MonitoredDomain, Subscriptions, CompetitorSnapshot, DemandSignal
    - Config: User, UserAnalyzerConfig, UserCompanyNote, IndustryBenchmark
  - CLI commands for user management:
    - `app:user:create` - create admin or sub-user accounts
    - `app:user:set-limits` - set tenant limits (CLI only)
  - User entity extended with:
    - password, roles, permissions fields
    - adminAccount self-reference for tenant hierarchy
    - limits JSON for tenant-level quotas
  - Security configuration:
    - Admin firewall with form login
    - Role hierarchy (ROLE_ADMIN includes ROLE_USER)
    - Access control for /admin routes
  - Custom templates: login page, dashboard, score field

- **Email Module** - Email sending with abstract provider system and blacklist management
  - `EmailStatus` enum (pending, sent, delivered, opened, clicked, bounced, complained, failed)
  - `EmailBounceType` enum (hard, soft, complaint, unsubscribe)
  - `EmailProvider` enum (smtp, ses, null for testing)
  - `EmailLog` entity - delivery tracking with timestamps
  - `EmailBlacklist` entity - dual blacklist:
    - Global (user_id = NULL) for hard bounces and complaints
    - Per-user (user_id set) for unsubscribes
  - Provider abstraction with `EmailSenderInterface`:
    - `SmtpEmailSender` - Symfony Mailer integration
    - `SesEmailSender` - AWS SES integration
    - `NullEmailSender` - testing/development sender
  - `EmailBlacklistService` for blacklist management
  - `EmailService` for orchestration (send, processBounce, processDelivery)
  - SES Webhook controller for SNS notifications:
    - `POST /api/webhook/ses` - bounce, complaint, delivery handling
  - Enhanced unsubscribe endpoint with confirmation form
  - CLI commands:
    - `app:email:send` - send approved offers via email
    - `app:email:cleanup` - retention policy cleanup (365 days)
    - `app:email:blacklist` - add/remove/check/list blacklist entries
  - Integration with OfferService.send() for actual email delivery
  - Configuration:
    - `config/packages/mailer.yaml` - Symfony Mailer
    - Email provider tagging in services.yaml
    - Environment variables: EMAIL_*, AWS_SES_*, MAILER_DSN

- **Offer Module** - Email offer generation with template + AI personalization
  - `OfferStatus` enum (draft, pending_approval, approved, rejected, sent, opened, clicked, responded, converted)
  - `Offer` entity with:
    - Workflow status tracking (draft → approval → sent → tracking)
    - Per-user ownership with rate limiting
    - Email tracking (open pixel, click tracking)
    - AI personalization metadata
    - Links to Lead, Proposal, Analysis, EmailTemplate
  - `UserEmailTemplate` entity for per-user template customization:
    - Override global EmailTemplate per user
    - Industry-specific templates
    - AI personalization prompt customization
  - `OfferService` for orchestration:
    - Create, generate, approve, reject, send workflow
    - trackOpen(), trackClick(), markResponded(), markConverted()
  - `OfferGenerator` for email content:
    - Template hierarchy: UserEmailTemplate → EmailTemplate → default
    - Variable substitution (lead, analysis, proposal data)
    - AI personalization via ClaudeService
    - Tracking pixel and unsubscribe link injection
  - `RateLimitChecker` with per-user limits:
    - Default: 10/hour, 50/day, 3/domain/day
    - Configurable via User.settings['rate_limits']
  - `app:offer:generate` command with options:
    - `--lead`, `--user`, `--proposal`, `--email`, `--template`
    - `--batch`, `--limit`, `--dry-run`, `--send`, `--skip-ai`
  - REST API:
    - API Platform CRUD on Offer entity with filters
    - `POST /api/offers/generate` - create and generate offer
    - `POST /api/offers/{id}/submit` - submit for approval
    - `POST /api/offers/{id}/approve` - approve offer
    - `POST /api/offers/{id}/reject` - reject with reason
    - `POST /api/offers/{id}/send` - send email (rate limited)
    - `GET /api/offers/{id}/preview` - preview email content
    - `GET /api/offers/rate-limits` - current rate limit status
    - `POST /api/offers/{id}/responded` - mark as responded
    - `POST /api/offers/{id}/converted` - mark as converted
  - Tracking endpoints:
    - `GET /api/track/open/{token}` - tracking pixel (1x1 transparent GIF)
    - `GET /api/track/click/{token}?url=` - click tracking with redirect
    - `GET /unsubscribe/{token}` - unsubscribe endpoint

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
