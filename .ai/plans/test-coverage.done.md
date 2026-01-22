# Test Coverage Expansion - Implementation Plan

## Shrnutí

Rozšíření pokrytí testy pro celou aplikaci. Aktuálně **1315 testů** s 4058 assertions.

---

## Aktuální stav (21.1.2026)

| Oblast | Soubory | Pokryto | Poznámka |
|--------|---------|---------|----------|
| Entities | 22 | 5 | Offer, Lead (65 testů), Analysis, Proposal, IndustryBenchmark |
| Services | 92 | 19 | Email modul, IssueRegistry, Proposal DTOs, RateLimitResult, WeightedScoringService, BenchmarkCalculator, HttpAnalyzer, SecurityAnalyzer, Extractors, Discovery |
| Controllers | 7 | 4 | OfferController, LeadAnalysisController, LeadImportController, ProposalController |
| Enums | 22 | 9 | EmailProvider, OfferStatus, LeadStatus (24 testů), AnalysisStatus, IssueSeverity, IssueCategory, ProposalStatus, ProposalType |
| Commands | 14 | 1 | EmailBlacklistCommand |

### Opravené bugy ✓

1. ~~**LeadImportController** - Nenastavuje `user_id` na importované leady~~
   - **OPRAVENO**: Přidán `userCode` parametr a validace
   - Všech 22 testů nyní prochází

2. ~~**OfferController rate-limits** - Route konflikty s API Platform~~
   - **OPRAVENO**: Vytvořen `config/routes/00_controllers.yaml` pro prioritní načítání controller routes
   - Všechny 4 testy implementovány a procházejí

3. ~~**ProposalController estimate/recyclable** - Route konflikty s API Platform~~
   - **OPRAVENO**: Routing priorita opravena spolu s bodem 2
   - Všech 9 testů implementováno a procházejí

4. ~~**IcoExtractorTest** - Špatná testovací data~~
   - **OPRAVENO**: Opravena testovací data pro invalid IČO (12345679 je validní, nahrazeno 12345670)
   - Opravena očekávání pro IČO v tabulce (pattern nepodporuje oddělené TD elementy)

---

## Implementační plán

### Fáze 1: Lead modul (VYSOKÁ PRIORITA) ✓ DOKONČENO
- [x] `LeadStatus` enum - state machine testy
- [x] `Lead` entity - status transitions, validace
- [x] `LeadAnalysisController` API testy (18 testů)
- [x] `LeadImportControllerTest` API testy (22 testů) ✓ BUG OPRAVEN

### Fáze 2: Analysis modul (VYSOKÁ PRIORITA) ✓ DOKONČENO
- [x] `AnalysisStatus` enum (15 testů)
- [x] `Analysis` entity (38 testů)
- [x] `IssueSeverity` enum (13 testů)
- [x] `IssueCategory` enum (64 testů)
- [x] `IssueRegistry` service (54 testů)
- [x] `HttpAnalyzer` (34 testů)
- [x] `SecurityAnalyzer` (34 testů)

### Fáze 3: Proposal modul (VYSOKÁ PRIORITA) ✓ DOKONČENO
- [x] `ProposalStatus` enum (42 testů)
- [x] `ProposalType` enum (31 testů)
- [x] `Proposal` entity (65 testů)
- [x] `CostEstimate` DTO (10 testů)
- [x] `ProposalResult` DTO (17 testů)
- [x] `ProposalController` API testy (38 testů) ✓ ROUTING OPRAVEN

### Fáze 4: Tracking & Email (STŘEDNÍ PRIORITA) ✓ DOKONČENO
- [x] `TrackingController` - open/click tracking (21 testů)
- [x] `SesWebhookController` - bounce handling (21 testů)
- [x] `EmailBlacklistService` (27 testů)
- [x] `EmailService` - integration testy (17 testů)

### Fáze 5: Scoring & Benchmark (STŘEDNÍ PRIORITA) ✓ DOKONČENO
- [x] `WeightedScoringService` (33 testů)
- [x] `IndustryBenchmark` entity (29 testů)
- [x] `BenchmarkCalculator` (25 testů)

### Fáze 6: Extractors (STŘEDNÍ PRIORITA) ✓ DOKONČENO
- [x] `EmailExtractor` (44 testů)
- [x] `PhoneExtractor` (30 testů)
- [x] `CompanyNameExtractor` (27 testů)
- [x] `TechnologyDetector` (35 testů)
- [x] `IcoExtractor` (42 testů) ✓ TESTY OPRAVENY

### Fáze 7: Discovery & Commands (NIŽŠÍ PRIORITA) ✓ DOKONČENO
- [x] `DiscoveryResult` DTO (29 testů)
- [x] `ManualDiscoverySource` (32 testů)
- [x] `AbstractDiscoverySource` (37 testů)
- [x] `EmailBlacklistCommand` (21 testů)
- [ ] Další discovery sources (FirmyCz, Google, Seznam, atd.) - volitelné rozšíření
- [ ] Další commands - volitelné rozšíření

---

## Struktura testů

```
tests/
├── Unit/
│   ├── Command/
│   │   └── EmailBlacklistCommandTest.php ✓ (21 testů)
│   ├── Entity/
│   │   ├── OfferTest.php ✓
│   │   ├── LeadTest.php ✓ (65 testů)
│   │   ├── AnalysisTest.php ✓
│   │   ├── ProposalTest.php ✓
│   │   └── IndustryBenchmarkTest.php ✓ (29 testů)
│   ├── Enum/
│   │   ├── EmailProviderTest.php ✓
│   │   ├── OfferStatusTest.php ✓
│   │   ├── LeadStatusTest.php ✓ (24 testů)
│   │   ├── AnalysisStatusTest.php ✓
│   │   ├── IssueSeverityTest.php ✓
│   │   ├── IssueCategoryTest.php ✓
│   │   ├── ProposalStatusTest.php ✓
│   │   └── ProposalTypeTest.php ✓
│   └── Service/
│       ├── Email/ ✓
│       ├── Offer/ ✓
│       ├── Analyzer/
│       │   ├── IssueRegistryTest.php ✓
│       │   ├── HttpAnalyzerTest.php ✓ (34 testů)
│       │   └── SecurityAnalyzerTest.php ✓ (34 testů)
│       ├── Discovery/
│       │   ├── DiscoveryResultTest.php ✓ (29 testů)
│       │   ├── ManualDiscoverySourceTest.php ✓ (32 testů)
│       │   └── AbstractDiscoverySourceTest.php ✓ (37 testů)
│       ├── Proposal/
│       │   ├── CostEstimateTest.php ✓
│       │   └── ProposalResultTest.php ✓
│       ├── Extractor/
│       │   ├── EmailExtractorTest.php ✓ (44 testů)
│       │   ├── PhoneExtractorTest.php ✓ (30 testů)
│       │   ├── CompanyNameExtractorTest.php ✓ (27 testů)
│       │   ├── TechnologyDetectorTest.php ✓ (35 testů)
│       │   └── IcoExtractorTest.php ✓ (42 testů)
│       ├── Scoring/
│       │   └── WeightedScoringServiceTest.php ✓ (33 testů)
│       └── Benchmark/
│           └── BenchmarkCalculatorTest.php ✓ (25 testů)
└── Integration/
    ├── Controller/
    │   ├── OfferControllerTest.php ✓ (vč. rate-limits)
    │   ├── LeadAnalysisControllerTest.php ✓
    │   ├── LeadImportControllerTest.php ✓
    │   ├── ProposalControllerTest.php ✓ (vč. estimate/recyclable)
    │   ├── TrackingControllerTest.php ✓ (21 testů)
    │   └── SesWebhookControllerTest.php ✓ (21 testů)
    └── Service/
        ├── Email/ ✓
        ├── EmailBlacklistServiceTest.php ✓ (27 testů)
        └── EmailServiceTest.php ✓ (17 testů)
```

---

## Verifikace

```bash
# Spustit všechny testy
php vendor/bin/phpunit tests/

# Spustit s coverage reportem
php vendor/bin/phpunit tests/ --coverage-html var/coverage
```

---

## Poznámky

- Všechny bugy opraveny, žádné přeskočené testy
- Routing priorita opravena vytvořením `config/routes/00_controllers.yaml` (načítá se před API Platform)
- LeadImportController oprava: přidán povinný `userCode` parametr
- Fáze 1-7 dokončeny
- Celkem přidáno **~700 nových testů** + 4 opravy bugů
- Volitelné rozšíření: další discovery sources a commands (FirmyCz, Google, Seznam, BenchmarkCalculateCommand, atd.)
