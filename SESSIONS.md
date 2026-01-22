# Session Log

## 2026-01-22 (Admin Module)

### Focus
Implementace kompletního EasyAdmin dashboardu pro správu všech entit s multi-tenancy a role-based access control

### Completed
- Nainstalován EasyAdmin bundle (v4.27)
- Rozšířena User entita o autentizační pole:
  - `password` - nullable pro API-only users
  - `roles` - JSON array (ROLE_ADMIN, ROLE_USER)
  - `adminAccount` - self-reference pro sub-účty
  - `permissions` - granulární oprávnění
  - `limits` - systémové limity (nastavuje CLI)
  - Implementován `UserInterface` a `PasswordAuthenticatedUserInterface`
- Vytvořen permission system:
  - 20+ permission scopes (leads:read, offers:approve, atd.)
  - 4 permission templates (manager, approver, analyst, full)
  - Helper metody: `hasPermission()`, `isAdmin()`, `getAdminOrSelf()`
- Konfigurován Symfony Security:
  - Admin firewall s form_login
  - User provider z entity
  - Remember-me funkcionalita
- Vytvořen DashboardController s menu pro všechny entity
- Vytvořen SecurityController (login/logout)
- Vytvořen AbstractTenantCrudController pro multi-tenancy
- Vytvořeno 21 CRUD controllerů:
  - Lead Pipeline: Lead, Company, Analysis, AnalysisResult, AnalysisSnapshot
  - Workflow: Proposal, Offer (s approve/reject/send akcemi)
  - Email: EmailTemplate, UserEmailTemplate, EmailLog, EmailBlacklist
  - Monitoring: MonitoredDomain, Subscriptions, CompetitorSnapshot, DemandSignal
  - Config: User, UserAnalyzerConfig, UserCompanyNote, IndustryBenchmark
- Vytvořeny CLI příkazy:
  - `app:user:create` - vytvoření admin nebo sub-user účtů
  - `app:user:set-limits` - nastavení limitů pro tenanty
- Vytvořeny templates:
  - `admin/login.html.twig` - login stránka
  - `admin/dashboard.html.twig` - homepage
  - `admin/field/score.html.twig` - score badge
- Vytvořena migrace `Version20260122165020`

### Files Changed
- `src/Entity/User.php` - přidána autentizace, role, permissions
- `src/Controller/Admin/*.php` - **22 nových souborů**
- `src/Command/UserCreateCommand.php` - **nový soubor**
- `src/Command/UserSetLimitsCommand.php` - **nový soubor**
- `config/packages/security.yaml` - security konfigurace
- `templates/admin/*.html.twig` - **3 nové soubory**
- `public/css/admin.css` - **nový soubor**
- `migrations/Version20260122165020.php` - **nový soubor**
- `.ai/history/admin-module.md` - **nový soubor**
- `CHANGELOG.md` - přidán Admin Module entry

### Blockers
Žádné

### Usage
```bash
# Spustit migraci
bin/console doctrine:migrations:migrate

# Vytvořit admin účet
bin/console app:user:create admin@example.com password123 --admin

# Vytvořit sub-účet
bin/console app:user:create employee@example.com password123 --admin-code=admin --template=analyst

# Přístup
http://localhost:7270/admin
```

---

## 2026-01-21 (Email Module)

### Focus
Implementace Email Module - odesílání emailů s abstraktním provider systémem a blacklist managementem

### Completed
- Vytvořeny enumy:
  - `EmailStatus` (pending, sent, delivered, opened, clicked, bounced, complained, failed)
  - `EmailBounceType` (hard, soft, complaint, unsubscribe)
  - `EmailProvider` (smtp, ses, null)
- Vytvořeny entity:
  - `EmailLog` - log odeslaných emailů s delivery tracking
  - `EmailBlacklist` - dual blacklist (global pro bounces, per-user pro unsubscribes)
- Vytvořeny repository:
  - `EmailLogRepository` - findByMessageId, countSentLastHour, getStatistics
  - `EmailBlacklistRepository` - isBlacklisted, findEntry, findGlobalBounces
- Vytvořen service layer:
  - `EmailSenderInterface` - abstrakce pro email providers
  - `EmailMessage` / `EmailSendResult` DTOs
  - `AbstractEmailSender` - base class s buildSymfonyEmail()
  - `SmtpEmailSender` - Symfony Mailer integration
  - `SesEmailSender` - AWS SES integration
  - `NullEmailSender` - testing sender
  - `EmailBlacklistService` - blacklist management
  - `EmailService` - orchestrace (send, processBounce, processDelivery)
- Vytvořeny controllers:
  - `SesWebhookController` - POST /api/webhook/ses pro SNS notifications
  - Aktualizován `TrackingController` - unsubscribe s confirmation form
- Vytvořeny commands:
  - `app:email:send` - odesílání approved offers
  - `app:email:cleanup` - retention policy cleanup (365 dní)
  - `app:email:blacklist` - add/remove/check/list
- Integrace s `OfferService.send()` - volá EmailService
- Konfigurace:
  - `config/packages/mailer.yaml` - Symfony Mailer
  - `config/services.yaml` - tagged senders
  - `.env.template` - EMAIL_*, AWS_SES_*, MAILER_DSN
- Migrace `Version20260121170000` pro email_logs a email_blacklist

### Files Changed
- `src/Enum/EmailStatus.php` - **nový soubor**
- `src/Enum/EmailBounceType.php` - **nový soubor**
- `src/Enum/EmailProvider.php` - **nový soubor**
- `src/Entity/EmailLog.php` - **nový soubor**
- `src/Entity/EmailBlacklist.php` - **nový soubor**
- `src/Repository/EmailLogRepository.php` - **nový soubor**
- `src/Repository/EmailBlacklistRepository.php` - **nový soubor**
- `src/Service/Email/*.php` - **nové soubory** (10 souborů)
- `src/Controller/SesWebhookController.php` - **nový soubor**
- `src/Controller/TrackingController.php` - aktualizován unsubscribe()
- `src/Command/EmailSendCommand.php` - **nový soubor**
- `src/Command/EmailCleanupCommand.php` - **nový soubor**
- `src/Command/EmailBlacklistCommand.php` - **nový soubor**
- `src/Service/Offer/OfferService.php` - integrace EmailService
- `config/services.yaml` - email sender tagging
- `config/packages/mailer.yaml` - **nový soubor**
- `.env.template` - přidány email proměnné
- `migrations/Version20260121170000.php` - **nový soubor**
- `.ai/history/email-module.md` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-21 (Offer Module)

### Focus
Implementace Offer Module - email offer generování se schvalovacím workflow

### Completed
- Vytvořen `OfferStatus` enum (9 stavů s state machine logikou)
- Vytvořena `Offer` entita:
  - Per-user ownership s rate limiting
  - Workflow: draft → approval → sent → tracking
  - Email tracking (open, click, responded, converted)
  - AI personalization metadata
  - Propojení na Lead, Proposal, Analysis, EmailTemplate
- Vytvořena `UserEmailTemplate` entita:
  - Per-user template customization
  - Industry-specific templates
  - AI personalization prompt override
- Vytvořen `OfferRepository` s query metodami:
  - `findByTrackingToken()` pro email tracking
  - `countSentToday()`, `countSentLastHour()`, `countSentToDomainToday()` pro rate limiting
  - `getConversionStats()` pro analytics
- Vytvořen `UserEmailTemplateRepository`
- Vytvořeny DTOs:
  - `OfferContent` - výsledek generování obsahu
  - `RateLimitResult` - výsledek kontroly rate limitů
- Vytvořen `RateLimitChecker` service:
  - Default: 10/hour, 50/day, 3/domain/day
  - Konfigurovatelné přes User.settings['rate_limits']
- Vytvořen `OfferGenerator` service:
  - Template hierarchy: UserEmailTemplate → EmailTemplate → default
  - Variable substitution (lead, analysis, proposal data)
  - AI personalizace přes ClaudeService
  - Tracking pixel a unsubscribe link injection
- Vytvořen `OfferService` pro orchestraci:
  - create, generate, approve, reject, send workflow
  - trackOpen(), trackClick(), markResponded(), markConverted()
- Vytvořen `OfferController` s REST API:
  - POST /api/offers/generate
  - POST /api/offers/{id}/submit, /approve, /reject, /send
  - GET /api/offers/{id}/preview
  - GET /api/offers/rate-limits
  - POST /api/offers/{id}/responded, /converted
- Vytvořen `TrackingController`:
  - GET /api/track/open/{token} - tracking pixel (1x1 GIF)
  - GET /api/track/click/{token}?url= - click tracking redirect
  - GET /unsubscribe/{token} - unsubscribe endpoint
- Vytvořen `OfferGenerateCommand`:
  - `--lead`, `--user`, `--proposal`, `--email`, `--template`
  - `--batch`, `--limit`, `--dry-run`, `--send`, `--skip-ai`
- Vytvořena migrace `Version20260121161630`

### Files Changed
- `src/Enum/OfferStatus.php` - **nový soubor**
- `src/Entity/Offer.php` - **nový soubor**
- `src/Entity/UserEmailTemplate.php` - **nový soubor**
- `src/Repository/OfferRepository.php` - **nový soubor**
- `src/Repository/UserEmailTemplateRepository.php` - **nový soubor**
- `src/Service/Offer/OfferContent.php` - **nový soubor**
- `src/Service/Offer/RateLimitResult.php` - **nový soubor**
- `src/Service/Offer/RateLimitChecker.php` - **nový soubor**
- `src/Service/Offer/OfferGenerator.php` - **nový soubor**
- `src/Service/Offer/OfferService.php` - **nový soubor**
- `src/Controller/OfferController.php` - **nový soubor**
- `src/Controller/TrackingController.php` - **nový soubor**
- `src/Command/OfferGenerateCommand.php` - **nový soubor**
- `migrations/Version20260121161630.php` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-21 (Proposal Generator Module)

### Focus
Implementace Proposal Generator Module - abstraktní rozhraní pro generování návrhů

### Completed
- Vytvořeny enumy `ProposalStatus` a `ProposalType`
- Vytvořena `Proposal` entita s recyklací:
  - Per-user ownership s original_user_id tracking
  - outputs JSON pro vygenerované soubory
  - ai_metadata JSON pro AI statistiky
  - Metody `canBeRecycled()` a `recycleTo()`
- Vytvořen `ProposalRepository` s query metodami:
  - `findRecyclable()` - najde recyklovatelný návrh
  - `findPendingGeneration()` - pro batch processing
- Vytvořen `ClaudeService` s dual-mode:
  - CLI mode pro lokální development
  - API mode pro server deployment
- Vytvořen `ScreenshotService` pro Chrome headless
- Vytvořen generator framework:
  - `ProposalGeneratorInterface`
  - `ProposalResult`, `CostEstimate` DTOs
  - `AbstractProposalGenerator` base class
  - `DesignProposalGenerator` - webdesign implementace
- Vytvořen `ProposalService` pro orchestraci
- Vytvořen `ProposalGenerateCommand`:
  - Single a batch mode
  - Recycle, force, dry-run options
- Aktualizován `services.yaml` s DI konfigurací
- Přidány env variables (CLAUDE_*, CHROME_SCREENSHOT_URL)
- Vytvořena migrace `Version20260121154144`
- Vytvořen prompt template `design_mockup.prompt.md`
- Vytvořen `ProposalController` s REST API:
  - POST /api/proposals/generate
  - POST /api/proposals/{id}/approve
  - POST /api/proposals/{id}/reject
  - POST /api/proposals/{id}/recycle
  - GET /api/proposals/estimate
  - GET /api/proposals/recyclable

### Files Changed
- `src/Enum/ProposalStatus.php` - **nový soubor**
- `src/Enum/ProposalType.php` - **nový soubor**
- `src/Entity/Proposal.php` - **nový soubor**
- `src/Repository/ProposalRepository.php` - **nový soubor**
- `src/Service/AI/ClaudeService.php` - **nový soubor**
- `src/Service/AI/ClaudeResponse.php` - **nový soubor**
- `src/Service/Screenshot/ScreenshotService.php` - **nový soubor**
- `src/Service/Proposal/ProposalGeneratorInterface.php` - **nový soubor**
- `src/Service/Proposal/ProposalResult.php` - **nový soubor**
- `src/Service/Proposal/CostEstimate.php` - **nový soubor**
- `src/Service/Proposal/AbstractProposalGenerator.php` - **nový soubor**
- `src/Service/Proposal/DesignProposalGenerator.php` - **nový soubor**
- `src/Service/Proposal/ProposalService.php` - **nový soubor**
- `src/Command/ProposalGenerateCommand.php` - **nový soubor**
- `src/Controller/ProposalController.php` - **nový soubor**
- `templates/prompts/design_mockup.prompt.md` - **nový soubor**
- `config/services.yaml` - přidána DI konfigurace
- `.env`, `.env.template` - nové env variables
- `migrations/Version20260121154144.php` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-21 (Company Entity & ARES Integration)

### Focus
Implementace Company entity a integrace s ARES API

### Completed
- Vytvořena `Company` entita s ARES poli:
  - IČO, DIČ, název, právní forma
  - Adresa (ulice, město, PSČ)
  - Business status, raw ARES data
- Vytvořen `CompanyRepository` s metodami pro vyhledávání
- Aktualizována `Lead` entita s ManyToOne vztahem na Company
- Vytvořeny ARES services:
  - `AresData` DTO pro API response
  - `AresClient` s rate limiting (200ms delay)
- Vytvořen `CompanyService` pro orchestraci
- Vytvořen `CompanyNameExtractor`:
  - Schema.org JSON-LD parsing
  - Open Graph meta tags
  - Copyright notice extraction
  - Title tag fallback
- Integrováno do `PageDataExtractor`
- Vytvořen `CompanySyncAresCommand`:
  - `--ico` pro sync konkrétního IČO
  - `--limit`, `--force-refresh`, `--dry-run`
- Přidána `--link-company` option do `LeadDiscoverCommand`
- Vytvořena migrace `Version20260121100000`

### Files Changed
- `src/Entity/Company.php` - **nový soubor**
- `src/Repository/CompanyRepository.php` - **nový soubor**
- `src/Service/Ares/AresData.php` - **nový soubor**
- `src/Service/Ares/AresClient.php` - **nový soubor**
- `src/Service/Company/CompanyService.php` - **nový soubor**
- `src/Service/Extractor/CompanyNameExtractor.php` - **nový soubor**
- `src/Command/CompanySyncAresCommand.php` - **nový soubor**
- `src/Entity/Lead.php` - přidán company vztah
- `src/Service/Extractor/PageDataExtractor.php` - integrace CompanyNameExtractor
- `src/Command/LeadDiscoverCommand.php` - přidána --link-company option
- `migrations/Version20260121100000.php` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-21 (Lead Discovery Extension)

### Focus
Implementace Lead Discovery Extension - extrakce kontaktů a detekce technologií

### Completed
- Vytvořen `LeadType` enum (WEBSITE, BUSINESS_WITHOUT_WEB)
- Rozšířena `Lead` entita o nová pole:
  - `type`, `hasWebsite` - typ leadu
  - `ico`, `companyName` - firemní identifikace
  - `email`, `phone`, `address` - kontaktní údaje
  - `detectedCms`, `detectedTechnologies`, `socialMedia` - technologie
- Vytvořeny extraction services:
  - `EmailExtractor` - extrakce a prioritizace emailů
  - `PhoneExtractor` - české telefonní formáty
  - `IcoExtractor` - validace IČO (modulo 11)
  - `TechnologyDetector` - CMS a tech stack detection
  - `SocialMediaExtractor` - FB, IG, LinkedIn, atd.
  - `PageDataExtractor` - orchestrátor
- Integrováno do discovery sources (AbstractDiscoverySource)
- Přidána `--extract` / `-x` option do LeadDiscoverCommand
- Vytvořena migrace `Version20260121013200`
- Vytvořena dokumentace v `.ai/history/lead-discovery-extension.md`

### Files Changed
- `src/Enum/LeadType.php` - **nový soubor**
- `src/Entity/Lead.php` - nová pole
- `src/Service/Extractor/*.php` - **nové soubory** (8 souborů)
- `src/Service/Discovery/AbstractDiscoverySource.php` - extraction support
- `src/Command/LeadDiscoverCommand.php` - nová option
- `migrations/Version20260121013200.php` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-21

### Focus
Implementace Reference Crawler Discovery Source

### Completed
- Přidán `REFERENCE_CRAWLER` enum do `LeadSource.php`
- Vytvořen `ReferenceDiscoverySource.php` s kompletní logikou crawleru
- Přidána `--inner-source` option do `LeadDiscoverCommand`
- Implementováno URL truncation pro řešení VARCHAR(500) limitu
- Rozšířen SKIP_DOMAINS o problematické domény (Reddit, Trustpilot, Webnode, etc.)
- Testováno s Google a Firmy.cz inner sources
- Vytvořena dokumentace v `.ai/history/reference-crawler-discovery.md`

### Files Changed
- `src/Enum/LeadSource.php` - nová enum hodnota
- `src/Command/LeadDiscoverCommand.php` - nová option
- `src/Service/Discovery/ReferenceDiscoverySource.php` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-21 (2. část)

### Focus
Implementace Fáze 6: Retention a archivace (Industry Analysis Extension)

### Completed
- Vytvořen `ArchiveStats` DTO pro statistiky archivace
- Vytvořen `ArchiveService` s retention logikou:
  - Komprese rawData (30-90 dní) pomocí gzip + base64
  - Mazání rawData (90-365 dní)
  - Mazání AnalysisResult (365+ dní)
- Přidány repository metody pro archivaci (`AnalysisResultRepository`)
- Vytvořen `AnalysisArchiveCommand` s opcemi:
  - `--compress-after`, `--clear-after`, `--delete-after`
  - `--dry-run`, `--show-counts`, `--batch-size`
- Opraveny JSONB queries pro PostgreSQL (::text cast)
- Aktualizována dokumentace v README.md
- Aktualizován `industry-analysis-extension.todo.md` - Fáze 6 kompletní

### Files Changed
- `src/Service/Archive/ArchiveStats.php` - **nový soubor**
- `src/Service/Archive/ArchiveService.php` - **nový soubor**
- `src/Command/AnalysisArchiveCommand.php` - **nový soubor**
- `src/Repository/AnalysisResultRepository.php` - nové metody pro archivaci
- `README.md` - dokumentace archive command
- `.ai/plans/industry-analysis-extension.todo.md` - Fáze 6 dokončena

### Blockers
Žádné

---

## 2026-01-21 (3. část)

### Focus
Implementace REST API pro analýzy a benchmarky

### Completed
- Vytvořen `LeadAnalysisController.php` s endpointy:
  - GET /api/leads/{id}/analyses - historie analýz pro lead
  - GET /api/leads/{id}/trend - trending data (snapshoty)
  - GET /api/leads/{id}/benchmark - porovnání s industry benchmarkem
- Vytvořen `IndustryBenchmarkController.php` s endpointy:
  - GET /api/industries - seznam všech odvětví s benchmark statusem
  - GET /api/industries/{industry}/benchmark - detail benchmarku
  - GET /api/industries/{industry}/benchmark/history - historie benchmarků
- Aktualizována dokumentace v README.md (Analysis REST API sekce)
- Aktualizován todo plan (všechny API endpointy dokončeny)

### Files Changed
- `src/Controller/LeadAnalysisController.php` - **nový soubor**
- `src/Controller/IndustryBenchmarkController.php` - **nový soubor**
- `README.md` - přidána sekce Analysis REST API + Přehled CLI příkazů + Workflow
- `.ai/plans/industry-analysis-extension.todo.md` → `.done.md` - plán dokončen
- `.ai/history/industry-analysis-extension.md` - **nový soubor** - dokumentace implementace

### Blockers
Žádné

### Notes
Celý plán `industry-analysis-extension` je nyní kompletně dokončen:
- Fáze 1-6 implementovány
- Všechny API endpointy hotovy
- Dokumentace v README.md aktualizována
- Historie implementace zdokumentována v `.ai/history/`

---

## 2026-01-21 (4. část)

### Focus
MVP rozšíření: Poptávkový SW & Sledování Konkurence

### Completed

#### Poptávkový SW - Demand Signal Tracking
- Vytvořeny nové enumy:
  - `DemandSignalSource` (EPOPTAVKA, NEN, JOBS_CZ, PRACE_CZ, LINKEDIN, STARTUP_JOBS, ARES_CHANGE)
  - `DemandSignalType` (HIRING_*, TENDER_*, RFP_*, ARES changes)
  - `DemandSignalStatus` (NEW, QUALIFIED, DISQUALIFIED, CONVERTED, EXPIRED)
- Vytvořena `DemandSignal` entita pro tracking poptávek:
  - Zdroj, typ, status signálu
  - Company/kontaktní informace
  - Hodnota/rozpočet, deadline
  - Propojení na converted Lead
- Vytvořen `DemandSignalRepository` s query metodami
- Vytvořeny demand sources:
  - `EpoptavkaSource` - ePoptávka.cz (11k+ měsíčních RFP)
  - `NenSource` - Věstník veřejných zakázek (NEN)
  - `JobsCzSource` - Jobs.cz job portál
- Vytvořen `DemandMonitorCommand`:
  - `--source` (epoptavka, nen, jobs_cz, all)
  - `--query`, `--category`, `--region`
  - `--min-value`, `--dry-run`, `--expire-old`

#### Sledování Konkurence - Competitor Monitoring
- Vytvořeny nové enumy:
  - `ChangeSignificance` (CRITICAL, HIGH, MEDIUM, LOW)
  - `CompetitorSnapshotType` (PORTFOLIO, PRICING, SERVICES, TEAM, TECHNOLOGY)
- Vytvořena `CompetitorSnapshot` entita:
  - Content hash pro change detection
  - Raw data, changes array, metrics
  - Previous snapshot linking
  - Significance calculation
- Vytvořen `CompetitorSnapshotRepository` s query metodami
- Vytvořeny competitor monitors:
  - `PortfolioMonitor` - sledování portfolia/referencí konkurentů
  - `PricingMonitor` - sledování ceníků a cenových změn
  - `ServiceMonitor` - sledování nabídky služeb a technologií
- Vytvořen `CompetitorMonitorCommand`:
  - `--type` (portfolio, pricing, services, all)
  - `--competitor`, `--industry`
  - `--min-significance`, `--only-changes`
  - `--cleanup` pro mazání starých snapshotů

#### Infrastruktura
- Migrace `Version20260121120000` pro demand_signals a competitor_snapshots tabulky
- Aktualizace `services.yaml` pro DI tagging
- Interface a abstract classes pro extensibilitu

### Files Changed
- `src/Enum/DemandSignalSource.php` - **nový soubor**
- `src/Enum/DemandSignalType.php` - **nový soubor**
- `src/Enum/DemandSignalStatus.php` - **nový soubor**
- `src/Enum/ChangeSignificance.php` - **nový soubor**
- `src/Enum/CompetitorSnapshotType.php` - **nový soubor**
- `src/Entity/DemandSignal.php` - **nový soubor**
- `src/Entity/CompetitorSnapshot.php` - **nový soubor**
- `src/Repository/DemandSignalRepository.php` - **nový soubor**
- `src/Repository/CompetitorSnapshotRepository.php` - **nový soubor**
- `src/Service/Demand/DemandSignalSourceInterface.php` - **nový soubor**
- `src/Service/Demand/DemandSignalResult.php` - **nový soubor**
- `src/Service/Demand/AbstractDemandSource.php` - **nový soubor**
- `src/Service/Demand/EpoptavkaSource.php` - **nový soubor**
- `src/Service/Demand/NenSource.php` - **nový soubor**
- `src/Service/Demand/JobsCzSource.php` - **nový soubor**
- `src/Service/Competitor/CompetitorMonitorInterface.php` - **nový soubor**
- `src/Service/Competitor/AbstractCompetitorMonitor.php` - **nový soubor**
- `src/Service/Competitor/PortfolioMonitor.php` - **nový soubor**
- `src/Service/Competitor/PricingMonitor.php` - **nový soubor**
- `src/Service/Competitor/ServiceMonitor.php` - **nový soubor**
- `src/Command/DemandMonitorCommand.php` - **nový soubor**
- `src/Command/CompetitorMonitorCommand.php` - **nový soubor**
- `migrations/Version20260121120000.php` - **nový soubor**
- `config/services.yaml` - přidány tagy pro DI

### Blockers
Žádné

### Notes
ARES Monitor rozšíření (sledování změn ve firmách) nebylo implementováno - může být přidáno v další iteraci.
