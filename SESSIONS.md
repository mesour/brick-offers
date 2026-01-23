# Session Log

## 2026-01-23 (MonitoredDomain Refactoring)

### Focus
Refaktoring MonitoredDomain architektury - oddělení globálních domén od uživatelských odběrů

### Completed
- Odstraněn `user_id` z `MonitoredDomain` entity - domény jsou nyní globální
- Vytvořen CLI příkaz `app:monitor:domain` pro správu domén (add, remove, update, list)
- `MonitoredDomainCrudController` omezen na ROLE_SUPER_ADMIN
- `MonitoredDomainSubscriptionCrudController` upraven pro výběr z globálních domén
- Vytvořena migrace pro odstranění user_id a duplicit

### Files Changed
- `src/Entity/MonitoredDomain.php` - odstraněn user vztah a constraints
- `src/Command/MonitorDomainCommand.php` - nový CLI příkaz
- `migrations/Version20260123120000.php` - migrace schématu
- `src/Controller/Admin/MonitoredDomainCrudController.php` - AbstractCrudController + ROLE_SUPER_ADMIN
- `src/Controller/Admin/MonitoredDomainSubscriptionCrudController.php` - query builder pro globální domény
- `src/Controller/Admin/DashboardController.php` - permission na menu item

### Blockers
Žádné

---

## 2026-01-23 (Contact Page Crawling)

### Focus
Rozšíření PageDataExtractor o automatické prohledávání kontaktních stránek pro nalezení emailů

### Completed
- Přidána metoda `extractWithContactPages()` do `PageDataExtractor`
- Extrakce prohledává stránky jako `/kontakt`, `/contact`, `/about`, `/o-nas`, `/impressum`
- Navštíví až 3 kontaktní stránky a sloučí nalezené emaily/telefony
- Integrováno do `AbstractDiscoverySource` - discovery sources nyní automaticky crawlují kontaktní stránky
- Přidána možnost vypnout crawling: `setContactPageCrawlingEnabled(false)`
- Aktualizována dokumentace `docs/concept/discovery-sources.md`

### Files Changed
- `src/Service/Extractor/PageDataExtractor.php` - +`extractWithContactPages()`, +`findContactPageUrls()`, +`resolveUrl()`, +`getBaseUrl()`, +`getDefaultHeaders()`
- `src/Service/Discovery/AbstractDiscoverySource.php` - změna na `extractWithContactPages()`, +`setContactPageCrawlingEnabled()`
- `docs/concept/discovery-sources.md` - dokumentace nové funkce

### Blockers
Žádné

---

## 2026-01-23 (Auto Snapshots & Screenshots)

### Focus
Automatické vytváření AnalysisSnapshot a screenshot při analýze leadu

### Completed
- Rozšířen `AnalyzeLeadMessageHandler` o automatické vytváření snapshotů a screenshotů
- Po dokončení analýzy se vytvoří `AnalysisSnapshot` (pro trending/historii)
- Po analýze se asynchronně dispatchuje `TakeScreenshotMessage` (pro vizuální archivaci)
- Přidány nové dependencies: `SnapshotService`, `MessageBusInterface`

### Files Changed
- `src/MessageHandler/AnalyzeLeadMessageHandler.php` - přidány imports, dependencies, snapshot creation, screenshot dispatch

### Blockers
Žádné

---

## 2026-01-22 (Analyzer Config UI)

### Focus
Implementace intuitivního UI pro konfiguraci analyzérů v Discovery Profile

### Completed
- Rozšířen `LeadAnalyzerInterface` o metody `getName()`, `getDescription()`, `getConfigurableSettings()`
- Implementovány defaulty v `AbstractLeadAnalyzer`
- Vytvořen `AnalyzerConfigService` pro správu schémat analyzérů
- Vytvořen custom `AnalyzerConfigField` pro EasyAdmin
- Vytvořeny form types: `AnalyzerConfigType`, `AnalyzerItemType`, `ThresholdsType`
- Vytvořen form theme `analyzer_config_theme.html.twig`
- Aktualizovány všechny analyzéry s popisky
- `PerformanceAnalyzer` má konfigurovatelné thresholds (LCP, FCP, CLS, TTFB)
- Přejmenován tab "Analýza nastavení" na "Nastavení analýzy"
- Nahrazen JSON editor intuitivním UI s checkboxy, slidery a multi-select

### Files Changed
- `src/Service/Analyzer/LeadAnalyzerInterface.php` - +3 metody
- `src/Service/Analyzer/AbstractLeadAnalyzer.php` - +3 defaultní implementace
- `src/Service/AnalyzerConfigService.php` - **nový soubor**
- `src/Admin/Field/AnalyzerConfigField.php` - **nový soubor**
- `src/Form/AnalyzerConfigType.php` - **nový soubor**
- `src/Form/AnalyzerItemType.php` - **nový soubor**
- `src/Form/ThresholdsType.php` - **nový soubor**
- `templates/admin/field/analyzer_config.html.twig` - **nový soubor**
- `templates/admin/form/analyzer_config_theme.html.twig` - **nový soubor**
- `config/packages/twig.yaml` - přidán form theme
- `src/Controller/Admin/DiscoveryProfileCrudController.php` - použití nového fieldu
- Všechny `*Analyzer.php` soubory - přidán `getDescription()`

### Blockers
Žádné

---

## 2026-01-22 (Admin Documentation)

### Focus
Vytvoření uživatelské dokumentace pro admin rozhraní v češtině

### Completed
- Vytvořena složka `docs/admin/` pro dokumentaci
- Vytvořeno 7 dokumentačních souborů:
  - `README.md` - přehled admin panelu, navigace, základní workflow
  - `lead-pipeline.md` - Leady, Firmy, Analýzy, Výsledky, Snapshoty
  - `workflow.md` - Návrhy (Proposals) a Nabídky (Offers)
  - `email.md` - Moje šablony, Systémové šablony, Email logy, Blacklist
  - `monitoring.md` - Sledované domény, Odběry, Snapshoty konkurence, Signály poptávky
  - `configuration.md` - Uživatelé, Konfigurace analyzátorů, Poznámky k firmám, Benchmarky
  - `permissions.md` - Přehled 20 oprávnění, role ADMIN/USER, typické kombinace

### Files Changed
- `docs/admin/README.md` - **nový soubor**
- `docs/admin/lead-pipeline.md` - **nový soubor**
- `docs/admin/workflow.md` - **nový soubor**
- `docs/admin/email.md` - **nový soubor**
- `docs/admin/monitoring.md` - **nový soubor**
- `docs/admin/configuration.md` - **nový soubor**
- `docs/admin/permissions.md` - **nový soubor**

### Blockers
Žádné

---

## 2026-01-22 (User Industry Configuration)

### Focus
Implementace konfigurace odvětví (industries) pro uživatele s výběrem v adminu

### Completed
- Přidáno `allowedIndustries` pole do User entity (JSON array)
- Implementovány helper metody: `getAllowedIndustries()`, `hasIndustry()`, `canUseIndustry()`, `addIndustry()`, `removeIndustry()`, `getDefaultIndustry()`
- Sub-users automaticky dědí industries z admin účtu
- Vytvořen CLI command `app:user:set-industries`:
  - Options: `--set`, `--add`, `--remove`, `--list`
  - Podpora identifikace uživatele přes code nebo email
- Vytvořen `CurrentIndustryService` pro správu session:
  - Ukládá current industry do session
  - Validuje přístup uživatele k industry
  - Vrací available industries pro dropdown
- Vytvořen `IndustryExtension` Twig extension:
  - Twig globals: `current_industry`, `current_industry_value`, `available_industries`, `has_multiple_industries`
  - Twig functions: `get_current_industry()`, `get_available_industries()`, `get_industry_label()`
- Přidán endpoint `POST /admin/switch-industry` do DashboardController
- Override EasyAdmin layout pro industry switcher:
  - Dropdown v headeru vedle user menu
  - AJAX přepínání s reload stránky
  - Zobrazení pouze pokud má user více industries
- Přidán `data-industry="..."` atribut na `<body>` tag
- Přidány CSS styly pro industry switcher
- Vytvořena migrace `Version20260122185459`

### Files Changed
- `src/Entity/User.php` - přidáno `allowedIndustries` pole + metody
- `src/Command/UserSetIndustriesCommand.php` - **nový soubor**
- `src/Service/CurrentIndustryService.php` - **nový soubor**
- `src/Twig/IndustryExtension.php` - **nový soubor**
- `src/Controller/Admin/DashboardController.php` - přidán switch-industry endpoint
- `templates/bundles/EasyAdminBundle/layout.html.twig` - **nový soubor** (layout override)
- `public/css/admin.css` - přidány industry switcher styly
- `migrations/Version20260122185459.php` - **nový soubor**
- `.ai/history/user-industry-configuration.md` - **nový soubor**

### Blockers
Žádné

### Usage
```bash
# Nastavit industries pro uživatele
bin/console app:user:set-industries admin --set=eshop,webdesign

# Přidat industry
bin/console app:user:set-industries admin --add=real_estate

# Odebrat industry
bin/console app:user:set-industries admin --remove=eshop

# Zobrazit aktuální industries
bin/console app:user:set-industries admin --list
```

---

## 2026-01-22 (Admin Module Fixes & Improvements)

### Focus
Oprava bugů v admin modulu a vylepšení multi-tenancy systému

### Completed
- Opraven Nginx Unit port mismatch (80 → 8080, www → public)
- Opraven UUID toBinary() PostgreSQL encoding error
- Vytvořen PermissionVoter pro propojení EasyAdmin permissions s User::hasPermission()
- Přidána metoda __toString() do entit: Lead, Analysis, Company, EmailTemplate, MonitoredDomain, Offer, Proposal
- Implementován kompletní tenant filtering pro všechny CRUD controllery:
  - Přímý vztah: AbstractTenantCrudController
  - Nepřímý vztah: custom query pro Analysis, AnalysisResult, AnalysisSnapshot, Company, MonitoredDomain, CompetitorSnapshot
- Automatické nastavení user při vytváření entit (AbstractTenantCrudController::persistEntity)
- Skrytí pole user ve formulářích (hideOnForm) - user se nastavuje automaticky
- Zjednodušené formuláře:
  - Lead: pouze URL při vytváření
  - Company: pouze IČO při vytváření
- Zakázáno manuální vytváření Proposals (AI-generated)
- EmailTemplate změněno na read-only (systémové šablony)
- Opraveny názvy polí v EmailTemplateCrudController a UserEmailTemplateCrudController
- Přejmenování v menu: "Systémové šablony" a "Moje šablony"

### Files Changed
- `.infrastructure/docker/php/unit.json` - port fix
- `src/Controller/Admin/AbstractTenantCrudController.php` - tenant filtering, auto-set user
- `src/Controller/Admin/*CrudController.php` - tenant filtering, field fixes
- `src/Security/Voter/PermissionVoter.php` - **nový soubor**
- `src/Entity/*.php` - přidány __toString() metody

### Blockers
- Žádné

---

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

---

## 2026-01-22 (Discovery Profiles)

### Focus
Implementace Discovery Profiles - pojmenované profily spojující discovery nastavení, analyzer konfigurace a industry

### Completed
- Vytvořena `DiscoveryProfile` entita s kompletní strukturou:
  - Discovery settings (sources, queries, limit, extractData, linkCompany, priority)
  - Analysis settings (autoAnalyze, analyzerConfigs JSON)
  - Industry binding
  - isDefault flag pro označení výchozího profilu
- Vytvořen `DiscoveryProfileRepository` s helper metodami:
  - `findDefaultForUser()`, `findActiveForUser()`, `findAllActiveForBatch()`
  - `clearDefaultForUser()`, `countLeadsByProfile()`
- Aktualizována `Lead` entita - přidán vztah `discoveryProfile`
- Aktualizována `User` entita - přidán vztah `discoveryProfiles`
- Vytvořen `DiscoveryProfileCrudController` pro Admin:
  - CRUD s taby (Basic Info, Discovery, Analyzers, Metadata)
  - Custom actions: Duplicate, Set as Default
  - Transform pro textarea→array queries
- Přidáno "Discovery Profiles" do admin menu
- Aktualizované Messages:
  - `DiscoverLeadsMessage` + profileId, industryFilter, autoAnalyze
  - `AnalyzeLeadMessage` + profileId
  - `BatchDiscoveryMessage` + profileName, useProfiles
- Aktualizované MessageHandlers:
  - `DiscoverLeadsMessageHandler` - podpora profilů, auto-analyze dispatch
  - `BatchDiscoveryMessageHandler` - profile-based vs legacy discovery
  - `AnalyzeLeadMessageHandler` - profile analyzer configs, ignoreCodes
- Aktualizován `DiscoveryBatchCommand`:
  - `--profile` option pro konkrétní profil
  - `--legacy` flag pro legacy mode
  - Rozšířen `--show-config` o zobrazení profilů
- Vytvořena migrace `Version20260122215543`

### Files Changed
- `src/Entity/DiscoveryProfile.php` - **nový soubor**
- `src/Repository/DiscoveryProfileRepository.php` - **nový soubor**
- `src/Controller/Admin/DiscoveryProfileCrudController.php` - **nový soubor**
- `migrations/Version20260122215543.php` - **nový soubor**
- `src/Entity/Lead.php` - přidán discoveryProfile vztah
- `src/Entity/User.php` - přidán discoveryProfiles vztah
- `src/Message/DiscoverLeadsMessage.php` - nové parametry
- `src/Message/AnalyzeLeadMessage.php` - profileId parametr
- `src/Message/BatchDiscoveryMessage.php` - profile support
- `src/MessageHandler/DiscoverLeadsMessageHandler.php` - profile support
- `src/MessageHandler/BatchDiscoveryMessageHandler.php` - profile-based discovery
- `src/MessageHandler/AnalyzeLeadMessageHandler.php` - analyzer configs
- `src/Command/DiscoveryBatchCommand.php` - profile options
- `src/Controller/Admin/DashboardController.php` - menu item
- `.ai/history/discovery-profiles.md` - **nový soubor**

### Blockers
Žádné

### Usage
```bash
# Vytvořit profil v Admin UI
# Admin → Configuration → Discovery Profiles → New

# Spustit discovery s profilem
bin/console app:discovery:batch --user=admin --profile="E-shopy Praha" --dry-run

# Spustit discovery pro všechny profily
bin/console app:discovery:batch --all-users

# Zobrazit konfiguraci profilů
bin/console app:discovery:batch --all-users --show-config
```
