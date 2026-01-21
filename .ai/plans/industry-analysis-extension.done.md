# Industry Analysis Extension - Implementation Plan

## Shrnutí požadavků

Rozšíření Web Analyzer systému o:
1. **Industry enum** - oblasti podnikání (webdesign, real-estate, automobile, eshop...)
2. **Opakované analýzy** - možnost spouštět analýzy vícekrát v čase
3. **Historie reportů** - ukládání a porovnávání analýz v čase
4. **Industry-specific analyzátory** - různé odvětví analyzují různé věci
5. **Benchmarking** - porovnání s odvětvím

**Use cases:**
- Nabídkový systém pro vyhledávání potenciálních klientů
- Monitoring konkurence a jejich snah v čase

---

## Architektonické rozhodnutí

### Zvolený přístup: Hybrid

**Proč ne Event Sourcing:**
- Příliš komplexní pro daný use case
- Analýzy jsou periodické batch operace, ne časté změny stavu
- Overhead event replay a snapshot management

**Proč ne čistý Time-Series (TimescaleDB):**
- Extra dependency
- Pro 1000 leadů s týdenními analýzami (~1.2 GB/rok) stačí PostgreSQL

**Hybrid přístup kombinuje:**
1. Normalizované entity (Analysis, AnalysisResult) - plná data
2. Agregované snapshoty (AnalysisSnapshot) - týdenní/měsíční souhrny
3. Industry benchmarky (IndustryBenchmark) - projekce pro porovnání
4. Retention policy - komprese/mazání starých detailních dat

**Výhody:**
- Zůstává v čistém PostgreSQL
- Podporuje všechny query patterns (historie, trending, benchmarking)
- Snadná migrace na TimescaleDB v budoucnu pokud bude potřeba
- Efektivní storage s retention policy

---

## Datový model

### Nový Enum: Industry

```php
// src/Enum/Industry.php
enum Industry: string
{
    case WEBDESIGN = 'webdesign';
    case REAL_ESTATE = 'real_estate';
    case AUTOMOBILE = 'automobile';
    case ESHOP = 'eshop';
    case RESTAURANT = 'restaurant';
    case MEDICAL = 'medical';
    case LEGAL = 'legal';
    case FINANCE = 'finance';
    case EDUCATION = 'education';
    case OTHER = 'other';
}
```

### Rozšíření Lead entity

```php
// Nové sloupce
#[ORM\Column(enumType: Industry::class, nullable: true)]
private ?Industry $industry = null;

#[ORM\OneToOne(targetEntity: Analysis::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?Analysis $latestAnalysis = null;

#[ORM\Column(type: Types::INTEGER)]
private int $analysisCount = 0;
```

### Rozšíření Analysis entity

```php
// Nové sloupce pro historii a porovnání
#[ORM\Column(enumType: Industry::class, nullable: true)]
private ?Industry $industry = null;  // denormalizováno z Lead

#[ORM\Column(type: Types::INTEGER)]
private int $sequenceNumber = 1;  // 1, 2, 3... pro daný lead

#[ORM\ManyToOne(targetEntity: Analysis::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?Analysis $previousAnalysis = null;

#[ORM\Column(type: Types::INTEGER, nullable: true)]
private ?int $scoreDelta = null;  // změna skóre oproti předchozí

#[ORM\Column(type: Types::BOOLEAN)]
private bool $isImproved = false;

#[ORM\Column(type: Types::JSON)]
private array $issueDelta = [];  // {added: [], removed: [], unchanged_count: int}
```

### Nová entita: AnalysisSnapshot

```php
// src/Entity/AnalysisSnapshot.php
// Agregovaná data pro trending - konfigurovatelná granularita
- id (UUID)
- lead (ManyToOne)
- periodType ('day' | 'week' | 'month')  // konfigurovatelné
- periodStart (Date)
- totalScore (int)
- categoryScores (JSON: {http: 85, security: 70, ...})
- issueCount (int)
- criticalIssueCount (int)
- topIssues (JSON: top 5 issues)
- scoreDelta (int, nullable)
- industry (string, denormalizováno)
```

### Konfigurace snapshot granularity

```php
// Na Lead entitě - volitelné přepsání default hodnoty
#[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
private ?string $snapshotPeriod = null;  // null = použít industry default

// Na Industry enum - default hodnota
public function getDefaultSnapshotPeriod(): string
{
    return match($this) {
        self::WEBDESIGN => 'week',      // konkurence se nemění rychle
        self::ESHOP => 'day',           // e-shopy se mění často
        self::REAL_ESTATE => 'week',
        default => 'week',
    };
}
```

### Nová entita: IndustryBenchmark

```php
// src/Entity/IndustryBenchmark.php
// Projekce pro porovnání v rámci odvětví
- id (UUID)
- industry (string)
- periodStart (Date)
- avgScore (float)
- medianScore (float)
- percentiles (JSON: {p25: 45, p50: 60, p75: 78, p90: 85})
- avgCategoryScores (JSON)
- topIssues (JSON: nejčastější issues v odvětví)
- sampleSize (int)
```

---

## Rozšíření analyzátorů o Industry support

### Rozšíření LeadAnalyzerInterface

```php
interface LeadAnalyzerInterface
{
    // Existující metody...
    public function supports(IssueCategory $category): bool;
    public function getCategory(): IssueCategory;
    public function analyze(Lead $lead): AnalyzerResult;
    public function getPriority(): int;

    // NOVÉ metody
    public function getSupportedIndustries(): array;  // prázdné = všechna odvětví
    public function isUniversal(): bool;  // true = běží vždy
}
```

### Typy analyzátorů

**Univerzální (běží vždy):**
- HttpAnalyzer, SecurityAnalyzer, SeoAnalyzer
- PerformanceAnalyzer, ResponsivenessAnalyzer
- AccessibilityAnalyzer, OutdatedWebAnalyzer

**Industry-specific (nové):**

| Industry | Analyzátor | Priorita | Co kontroluje |
|----------|------------|----------|---------------|
| ESHOP | EshopAnalyzer | **1. PLNÁ** | product pages, cart UX, payment methods, shipping, trust signals |
| WEBDESIGN | WebdesignCompetitorAnalyzer | **2. PLNÁ** | portfolio, case studies, pricing, design trends, testimonials |
| REAL_ESTATE | RealEstateAnalyzer | skeleton | property listings, IDX, virtual tours, contact forms, maps |
| AUTOMOBILE | AutomobileAnalyzer | skeleton | inventory, financing calc, test drive booking, service scheduling |
| RESTAURANT | RestaurantAnalyzer | skeleton | menu, reservations, delivery integration, reviews, photos |
| MEDICAL | MedicalAnalyzer | skeleton | appointment booking, HIPAA signals, doctor profiles, insurance |

**Pozn.:** "skeleton" = základní struktura + 2-3 hlavní checks, plná implementace později.

### Nová IssueCategory pro industry-specific

```php
// Rozšíření IssueCategory enum
case INDUSTRY_WEBDESIGN = 'industry_webdesign';
case INDUSTRY_REAL_ESTATE = 'industry_real_estate';
case INDUSTRY_AUTOMOBILE = 'industry_automobile';
case INDUSTRY_ESHOP = 'industry_eshop';
case INDUSTRY_RESTAURANT = 'industry_restaurant';
case INDUSTRY_MEDICAL = 'industry_medical';
```

---

## Rozšíření příkazů

### LeadAnalyzeCommand

```bash
# Nový parametr --industry
php bin/console app:lead:analyze \
    --industry=webdesign \
    --limit=50 \
    --reanalyze

# Všechny existující parametry zůstávají
```

**Změny v logice:**
1. Filtrovat analyzátory podle `getSupportedIndustries()`
2. Při vytváření Analysis nastavit `industry` a `sequenceNumber`
3. Propojit s `previousAnalysis` a vypočítat `scoreDelta` + `issueDelta`
4. Aktualizovat `Lead.latestAnalysis` a `Lead.analysisCount`

### Nové příkazy

```bash
# Generování snapshotů (cron: weekly)
php bin/console app:analysis:snapshot --period=week

# Přepočet industry benchmarků (cron: weekly)
php bin/console app:benchmark:calculate

# Retention - archivace starých dat (cron: monthly)
php bin/console app:analysis:archive --older-than=90days
```

---

## API Endpoints

### Nové/rozšířené endpointy

```
# Historie analýz pro lead
GET /api/leads/{id}/analyses
    ?limit=10
    &offset=0

# Trending data (snapshoty)
GET /api/leads/{id}/trend
    ?period=week
    &limit=52

# Porovnání s benchmarkem
GET /api/leads/{id}/benchmark

# Industry benchmark
GET /api/industries/{industry}/benchmark

# Leady s novými kritickými issues (alerting)
GET /api/alerts/critical-issues
    ?since=24h
```

---

## Retention Policy

| Stáří dat | Akce | Úspora |
|-----------|------|--------|
| 0-30 dní | Plná data | 0% |
| 30-90 dní | Komprese rawData (gzip) | 70% |
| 90-365 dní | Smazání rawData, zachovat issues | 85% |
| 365+ dní | Jen snapshoty, smazat AnalysisResult | 95% |

**Odhad storage:**
- 1000 leadů × týdenní analýzy × rok = ~1.2 GB (bez retention)
- S retention policy: ~200 MB/rok

---

## Implementační plán

### Fáze 1: Základní struktury ✅ DOKONČENO
- [x] Vytvořit `Industry` enum (`src/Enum/Industry.php`)
- [x] Vytvořit `SnapshotPeriod` enum (`src/Enum/SnapshotPeriod.php`)
- [x] Rozšířit `Lead` entitu (industry, latestAnalysis, analysisCount, snapshotPeriod)
- [x] Rozšířit `Analysis` entitu (industry, sequenceNumber, previousAnalysis, scoreDelta, issueDelta)
- [x] Rozšířit `IssueCategory` enum o industry-specific hodnoty
- [x] Vytvořit `AnalysisSnapshot` entitu
- [x] Vytvořit `IndustryBenchmark` entitu
- [x] Vytvořit databázové migrace (`Version20260120204402.php`)
- [x] API resources automaticky aktualizovány (API Platform)

### Fáze 2: Historie analýz ✅ DOKONČENO
- [x] Přidat `--industry` parametr do `LeadAnalyzeCommand`
- [x] Filtrovat analyzátory podle industry (universal + industry-specific)
- [x] Upravit `LeadAnalyzeCommand` pro podporu opakovaných analýz
- [x] Implementovat výpočet `scoreDelta` a `issueDelta` v `Analysis::calculateDelta()`
- [x] Přidat propojení `previousAnalysis` a `sequenceNumber`
- [x] Aktualizovat `Lead.latestAnalysis`, `Lead.analysisCount`, `Lead.lastAnalyzedAt`
- [x] Rozšířit `AnalysisRepository` o nové metody (findHistoryByLead, findByIndustry, getDeltaStats)
- [x] Zobrazit delta statistiky ve výstupu příkazu

### Fáze 3: Snapshot systém ✅ DOKONČENO
- [x] Vytvořit `AnalysisSnapshot` entitu (již hotovo v Fázi 1)
- [x] Implementovat `SnapshotService` (`src/Service/Snapshot/SnapshotService.php`)
- [x] Vytvořit `app:analysis:snapshot` command (`src/Command/AnalysisSnapshotCommand.php`)
- [x] Aktualizovat README s dokumentací commandů
- [x] Přidat API endpoint pro trending (`GET /api/leads/{id}/trend`)

### Fáze 4: Industry benchmarky ✅ DOKONČENO
- [x] Vytvořit `IndustryBenchmark` entitu (již hotovo v Fázi 1)
- [x] Implementovat `BenchmarkCalculator` service (`src/Service/Benchmark/BenchmarkCalculator.php`)
- [x] Vytvořit `app:benchmark:calculate` command (`src/Command/BenchmarkCalculateCommand.php`)
- [x] Aktualizovat README s dokumentací
- [x] Přidat API endpointy pro benchmarking:
  - `GET /api/leads/{id}/analyses` - historie analýz
  - `GET /api/leads/{id}/benchmark` - porovnání s benchmarkem
  - `GET /api/industries` - seznam odvětví
  - `GET /api/industries/{industry}/benchmark` - detail benchmarku
  - `GET /api/industries/{industry}/benchmark/history` - historie benchmarků

### Fáze 5: Industry-specific analyzátory ✅ DOKONČENO
- [x] Rozšířit `LeadAnalyzerInterface` o industry metody (`getSupportedIndustries()`, `isUniversal()`, `supportsIndustry()`)
- [x] Aktualizovat `AbstractLeadAnalyzer` s default implementacemi
- [x] `--industry` parametr již přidán v Fázi 2
- [x] Rozšířit `IssueRegistry` o industry-specific issues (14 eshop, 13 webdesign, 3 real estate, 3 automobile, 3 restaurant, 3 medical)
- [x] **EshopAnalyzer** (`src/Service/Analyzer/EshopAnalyzer.php`) - plná implementace:
  - [x] Detekce product pages a schema markup
  - [x] Cart/checkout analýza
  - [x] Payment methods (9 typů: kartou, převodem, dobírkou, PayPal, GoPay, Comgate, Stripe, Apple Pay, Google Pay)
  - [x] Shipping info (7 dopravců + doprava zdarma)
  - [x] Trust signals (SSL seal, reviews, Heureka/Zboží, certifikáty)
  - [x] Vyhledávání produktů
  - [x] Kontaktní informace
  - [x] Return policy
- [x] **WebdesignCompetitorAnalyzer** (`src/Service/Analyzer/WebdesignCompetitorAnalyzer.php`) - plná implementace:
  - [x] Portfolio/case studies detekce
  - [x] Pricing page analýza
  - [x] Services description (9 typů služeb)
  - [x] Testimonials/reference
  - [x] Team presentation
  - [x] CTA analýza (4 typy: kontakt, poptávka, konzultace, volání)
  - [x] Contact form
  - [x] Blog/news
  - [x] Social proof (loga klientů, čísla, ocenění)
- [x] **RealEstateAnalyzer** (`src/Service/Analyzer/RealEstateAnalyzer.php`) - skeleton:
  - [x] Property listings detekce
  - [x] Search filters
  - [x] Agent contact
- [x] **AutomobileAnalyzer** (`src/Service/Analyzer/AutomobileAnalyzer.php`) - skeleton:
  - [x] Vehicle inventory
  - [x] Financing info
  - [x] Test drive booking
- [x] **RestaurantAnalyzer** (`src/Service/Analyzer/RestaurantAnalyzer.php`) - skeleton:
  - [x] Menu presence (HTML vs PDF)
  - [x] Online reservation
- [x] **MedicalAnalyzer** (`src/Service/Analyzer/MedicalAnalyzer.php`) - skeleton:
  - [x] Online appointment booking
  - [x] Services listing
  - [x] Doctor profiles

### Fáze 6: Retention a archivace ✅ DOKONČENO
- [x] Implementovat `app:analysis:archive` command
- [x] Dokumentovat cron setup (Symfony Scheduler není nainstalován)
- [x] Testovat data integrity

---

## Klíčové soubory k úpravě

| Soubor | Změna | Stav |
|--------|-------|------|
| `src/Enum/Industry.php` | NOVÝ - Industry enum | ✅ |
| `src/Enum/SnapshotPeriod.php` | NOVÝ - SnapshotPeriod enum | ✅ |
| `src/Enum/IssueCategory.php` | Přidat industry-specific kategorie | ✅ |
| `src/Entity/Lead.php` | Přidat industry, latestAnalysis, analysisCount | ✅ |
| `src/Entity/Analysis.php` | Přidat industry, sequenceNumber, previousAnalysis, scoreDelta, issueDelta | ✅ |
| `src/Entity/AnalysisSnapshot.php` | NOVÝ - agregovaná data | ✅ |
| `src/Entity/IndustryBenchmark.php` | NOVÝ - benchmark projekce | ✅ |
| `src/Service/Analyzer/LeadAnalyzerInterface.php` | Přidat getSupportedIndustries(), isUniversal(), supportsIndustry() | ✅ |
| `src/Service/Analyzer/AbstractLeadAnalyzer.php` | Default implementace industry metod | ✅ |
| `src/Service/Analyzer/IssueRegistry.php` | Přidat industry-specific issues (39 nových) | ✅ |
| `src/Service/Analyzer/EshopAnalyzer.php` | NOVÝ - e-shop analýza | ✅ |
| `src/Service/Analyzer/WebdesignCompetitorAnalyzer.php` | NOVÝ - webdesign analýza | ✅ |
| `src/Service/Analyzer/RealEstateAnalyzer.php` | NOVÝ - reality (skeleton) | ✅ |
| `src/Service/Analyzer/AutomobileAnalyzer.php` | NOVÝ - automobily (skeleton) | ✅ |
| `src/Service/Analyzer/RestaurantAnalyzer.php` | NOVÝ - restaurace (skeleton) | ✅ |
| `src/Service/Analyzer/MedicalAnalyzer.php` | NOVÝ - zdravotnictví (skeleton) | ✅ |
| `src/Command/LeadAnalyzeCommand.php` | Přidat --industry, upravit logiku | ✅ |
| `src/Command/AnalysisSnapshotCommand.php` | NOVÝ | ✅ |
| `src/Command/BenchmarkCalculateCommand.php` | NOVÝ | ✅ |
| `src/Command/AnalysisArchiveCommand.php` | NOVÝ | ✅ |
| `src/Service/Snapshot/SnapshotService.php` | NOVÝ | ✅ |
| `src/Service/Benchmark/BenchmarkCalculator.php` | NOVÝ | ✅ |
| `src/Service/Archive/ArchiveService.php` | NOVÝ | ✅ |
| `src/Controller/LeadAnalysisController.php` | NOVÝ - API endpointy pro lead analýzy | ✅ |
| `src/Controller/IndustryBenchmarkController.php` | NOVÝ - API endpointy pro benchmarky | ✅ |

---

## Verifikace

1. **Unit testy:**
   - Industry enum values
   - ScoreDelta a IssueDelta výpočty
   - Snapshot agregace

2. **Integration testy:**
   - Opakovaná analýza stejného leadu
   - Propojení previousAnalysis
   - Benchmark calculation

3. **Manuální testování:**
   - Spustit analýzu s `--industry=webdesign`
   - Spustit druhou analýzu stejného leadu
   - Ověřit scoreDelta a issueDelta
   - Vygenerovat snapshoty
   - Zkontrolovat API endpointy

---

## Rozhodnutí z diskuze

1. **Industry zadávání:** Vždy ručně - zadává se jako parametr příkazu nebo při vytvoření leadu. Žádná auto-detekce.

2. **Granularita snapshotů:** Konfigurovatelné per-lead nebo per-industry (denní/týdenní/měsíční)

3. **Priority analyzátorů:**
   - Implementovat skeleton pro všechny základní industry
   - Plně implementovat: **E-shop** a **Webdesign** jako první
   - Ostatní postupně rozšiřovat
