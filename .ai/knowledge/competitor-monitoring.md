# Competitor Monitoring System

Systém pro sledování konkurence a poptávkových signálů.

---

## Přehled

Systém má dvě hlavní části:
1. **Competitor Monitoring** - sledování změn na webech konkurentů
2. **Demand Signals** - detekce obchodních příležitostí (tendry, poptávky, hiring)

---

## Entity

### MonitoredDomain
Doména ke sledování.

| Pole | Typ | Popis |
|------|-----|-------|
| `domain` | string | Doména (např. "competitor.cz") |
| `url` | string | Konkrétní URL ke sledování |
| `crawlFrequency` | CrawlFrequency | DAILY, WEEKLY, BIWEEKLY, MONTHLY |
| `lastCrawledAt` | datetime | Poslední kontrola |
| `active` | bool | Aktivní/neaktivní |
| `user` | User | Vlastník (tenant) |

**Vztahy:**
- 1:N → `MonitoredDomainSubscription` (kdo odebírá změny)
- 1:N → `CompetitorSnapshot` (historické snapshoty)

**Metody:**
- `shouldCrawl()` - zkontroluje, zda je čas na nový crawl podle frekvence

### MonitoredDomainSubscription
Odběr změn konkrétní domény uživatelem.

| Pole | Typ | Popis |
|------|-----|-------|
| `user` | User | Kdo odebírá |
| `monitoredDomain` | MonitoredDomain | Kterou doménu |
| `snapshotTypes[]` | array | Typy ke sledování (portfolio, pricing, services) |
| `alertOnChange` | bool | Posílat upozornění |
| `minSignificance` | ChangeSignificance | Min. úroveň pro alert (CRITICAL, HIGH, MEDIUM, LOW) |
| `notes` | string | Poznámky |

### CompetitorSnapshot
Snapshot dat z konkurenční domény v určitém čase.

| Pole | Typ | Popis |
|------|-----|-------|
| `monitoredDomain` | MonitoredDomain | Odkud snapshot |
| `snapshotType` | CompetitorSnapshotType | PORTFOLIO, PRICING, SERVICES, TEAM, TECHNOLOGY, CONTENT |
| `contentHash` | string | SHA256 hash dat (pro detekci změn) |
| `previousHash` | string | Hash předchozího snapshotu |
| `hasChanges` | bool | Byly detekovány změny |
| `significance` | ChangeSignificance | Významnost změn |
| `rawData[]` | array | Extrahovaná data |
| `changes[]` | array | Pole změn (field, before, after, significance) |
| `metrics[]` | array | Vypočítané metriky |
| `sourceUrl` | string | URL které bylo analyzováno |

**Detekce změn:**
1. Vytvoří se nový snapshot s hashem dat
2. Porovná se s předchozím hashem
3. Pokud se liší → volá se `detectChanges()` pro detailní porovnání
4. Každá změna má svou `significance`

### DemandSignal
Obchodní příležitost (poptávka, tendr, job posting, ARES změna).

| Pole | Typ | Popis |
|------|-----|-------|
| `source` | DemandSignalSource | EPOPTAVKA, NEN, JOBS_CZ, ARES_CHANGE, MANUAL... |
| `signalType` | DemandSignalType | HIRING_WEBDEV, TENDER_WEB, RFP_ESHOP, NEW_COMPANY... |
| `status` | DemandSignalStatus | NEW, QUALIFIED, DISQUALIFIED, CONVERTED, EXPIRED |
| `title` | string | Titulek |
| `companyName` | string | Název firmy |
| `ico` | string | IČO |
| `contactEmail` | string | Kontakt |
| `value` / `valueMax` | decimal | Rozpočet |
| `industry` | Industry | Odvětví |
| `deadline` | datetime | Deadline příležitosti |
| `sourceUrl` | string | Odkaz na zdroj |
| `isShared` | bool | Viditelný pro všechny uživatele |
| `convertedLead` | Lead | Pokud byl převeden na lead |

### DemandSignalSubscription
Per-user pohled na sdílený signál.

| Pole | Typ | Popis |
|------|-----|-------|
| `user` | User | Který uživatel |
| `demandSignal` | DemandSignal | Který signál |
| `status` | SubscriptionStatus | NEW, REVIEWED, DISMISSED, CONVERTED |
| `notes` | string | Poznámky uživatele |
| `convertedLead` | Lead | Pokud uživatel převedl na lead |

### MarketWatchFilter
Uživatelský filtr pro automatické párování signálů.

| Pole | Typ | Popis |
|------|-----|-------|
| `user` | User | Vlastník filtru |
| `name` | string | Název (např. "Web development") |
| `active` | bool | Aktivní/neaktivní |
| `industries[]` | array | Odvětví (webdesign, eshop...) |
| `regions[]` | array | Regiony (Praha, Brno...) |
| `signalTypes[]` | array | Typy signálů |
| `keywords[]` | array | Musí obsahovat |
| `excludeKeywords[]` | array | Nesmí obsahovat |
| `minValue` / `maxValue` | decimal | Rozpočtový rozsah |

**Metoda `matches(DemandSignal)`:**
1. Kontroluje industry (pokud definováno)
2. Kontroluje region (substring match)
3. Kontroluje signal type
4. Kontroluje value range
5. Kontroluje keywords (OR logika)
6. Kontroluje excludeKeywords (jakékoliv = vyloučeno)

---

## Enumy

### CrawlFrequency
```
DAILY (1 den), WEEKLY (7 dní), BIWEEKLY (14 dní), MONTHLY (30 dní)
```

### CompetitorSnapshotType
```
PORTFOLIO (7 dní), PRICING (14 dní), SERVICES (14 dní),
TEAM (30 dní), TECHNOLOGY (30 dní), CONTENT (7 dní)
```

### ChangeSignificance
```
CRITICAL (100), HIGH (75), MEDIUM (50), LOW (25)
- shouldAlert() vrací true pro CRITICAL a HIGH
```

### DemandSignalSource
```
EPOPTAVKA, NEN, EZAKAZKY - RFP platformy
JOBS_CZ, PRACE_CZ, LINKEDIN, STARTUP_JOBS - job portály
ARES_CHANGE - změny v rejstříku
MANUAL - ruční zadání
```

### DemandSignalType
```
HIRING_* - nábor (WEBDEV, DESIGNER, MARKETING, SEO, PPC)
TENDER_* - veřejné zakázky (WEB, ESHOP, APP, MARKETING)
RFP_* - poptávky (WEB, ESHOP, APP, SEO, REDESIGN)
ARES - změny (NEW_COMPANY, ADDRESS_CHANGE, DIRECTOR_CHANGE, BANKRUPTCY)
```

---

## Services

### Competitor Monitoring (`src/Service/Competitor/`)

**CompetitorMonitorInterface:**
```php
getType(): CompetitorSnapshotType
supports(Lead): bool
createSnapshot(Lead): CompetitorSnapshot
detectChanges(previous, current): array
```

**AbstractCompetitorMonitor:**
- Base třída s common logikou
- `createSnapshot()` flow:
  1. `extractData()` - abstraktní, implementují potomci
  2. Vypočítá hash
  3. Načte předchozí snapshot
  4. Porovná hashe → pokud rozdílné, volá `detectChanges()`
  5. `calculateMetrics()` pro analytiku

**PortfolioMonitor:**
- Hledá stránky s portfoliem (/portfolio, /reference, /nase-prace...)
- Extrahuje položky (title, client, category, image)
- Metriky: total_count, unique_clients, categories, case_studies

**Určení significance:**
- Numerické změny: >50% = CRITICAL, >25% = HIGH, >10% = MEDIUM
- Array změny: ≥5 položek nebo ≥50% = HIGH
- String změny: Levenshtein distance >50% = HIGH, >20% = MEDIUM

### Demand Signal Sources (`src/Service/Demand/`)

**DemandSignalSourceInterface:**
```php
supports(string): bool
getSource(): DemandSignalSource
discover(options, limit): DemandSignalResult[]
```

**AbstractDemandSource:**
- `parseCzechDate()` - české datumy
- `parsePrice()` - extrakce čísel
- `detectIndustry()` - odhad odvětví
- `detectSignalType()` - klasifikace typu
- `extractIco()` + `validateIco()` - IČO s modulo 11

**Implementace:**
- `EpoptavkaSource` - scraper ePoptavka.cz
- `NenSource` - Národní elektronický nástroj (tendry)
- `JobsCzSource` - Jobs.cz

---

## Commands

### `app:competitor:monitor`
```bash
bin/console app:competitor:monitor [options]
  --type (-t)              portfolio|pricing|services|all
  --competitor (-c)        Konkrétní doména
  --industry (-i)          Filtr podle odvětví
  --limit (-l)             Max počet (default: 50)
  --only-changes           Jen změny
  --min-significance       critical|high|medium|low
  --dry-run                Bez ukládání
  --cleanup                Smazat staré snapshoty (keep 10)
```

### `app:demand:monitor`
```bash
bin/console app:demand:monitor [options]
  --source (-s)    epoptavka|nen|jobs_cz|all
  --limit (-l)     Max signálů (default: 50)
  --query (-q)     Vyhledávací dotaz
  --category       Kategorie
  --region         Region
  --min-value      Min. rozpočet
  --user-id (-u)   Přiřadit uživateli
  --dry-run        Bez ukládání
  --expire-old     Označit expirované
```

---

## Workflow

### Nastavení sledování konkurence

1. **Admin → Monitored Domains**
   - Vytvořit MonitoredDomain (doména, URL, frekvence)

2. **Admin → Domain Subscriptions**
   - Vytvořit odběr (který uživatel, která doména)
   - Nastavit typy snapshotů (portfolio, pricing...)
   - Nastavit alertOnChange a minSignificance

3. **Cron job** (periodicky):
   ```bash
   bin/console app:competitor:monitor --cleanup
   ```

4. **Systém pro každou doménu:**
   - Zkontroluje `shouldCrawl()` podle frekvence
   - Vytvoří snapshoty pro relevantní typy
   - Porovná s předchozími
   - Uloží změny

5. **Uživatel vidí:**
   - Admin → Competitor Snapshots
   - Změny s barevným označením significance

### Nastavení sledování poptávek

1. **Admin → Market Watch Filters**
   - Vytvořit filtr (název, odvětví, regiony, keywords...)

2. **Cron job** (periodicky):
   ```bash
   bin/console app:demand:monitor --source all --expire-old
   ```

3. **Systém:**
   - Stáhne nové signály ze zdrojů
   - Pro každý signál najde matching filtry
   - Vytvoří DemandSignalSubscription pro uživatele

4. **Uživatel vidí:**
   - Admin → Demand Signals (globální)
   - Admin → Signal Subscriptions (osobní pohled)
   - Může: Review, Dismiss, Convert to Lead

---

## Datový tok

```
COMPETITOR MONITORING:

MonitoredDomain ──1:N──► MonitoredDomainSubscription
       │                        │
       │ crawl                  │ alert config
       ▼                        │
CompetitorSnapshot              │
  - rawData                     │
  - contentHash                 │
  - changes[] ◄─────────────────┘ shouldAlertForSignificance()
  - significance


DEMAND SIGNALS:

MarketWatchFilter
       │
       │ matches()
       ▼
DemandSignal ──1:N──► DemandSignalSubscription
  - source                      │
  - signalType                  │ per-user status
  - status                      │
  - deadline                    ▼
                          User view
                            - NEW
                            - REVIEWED
                            - CONVERTED
```

---

## Admin UI

| Controller | Funkce | Akce |
|------------|--------|------|
| `MonitoredDomainCrudController` | Správa domén | CRUD + "Zkontrolovat nyní" (TODO) |
| `MonitoredDomainSubscriptionCrudController` | Odběry domén | CRUD |
| `CompetitorSnapshotCrudController` | Snapshoty | Read-only |
| `DemandSignalCrudController` | Signály | Read-only |
| `DemandSignalSubscriptionCrudController` | Odběry signálů | CRUD |
| `MarketWatchFilterCrudController` | Filtry | CRUD |

---

## Známé TODO

1. **MonitoredDomainCrudController:87** - tlačítko "Zkontrolovat nyní" není napojeno na Messenger
2. **Automatické alerting** - detekce změn funguje, ale notifikace se neposílají
3. **Filter matching v SQL** - `MarketWatchFilter.matches()` běží v PHP, ne v DB

---

## Soubory

**Entity:**
- `src/Entity/MonitoredDomain.php`
- `src/Entity/MonitoredDomainSubscription.php`
- `src/Entity/CompetitorSnapshot.php`
- `src/Entity/DemandSignal.php`
- `src/Entity/DemandSignalSubscription.php`
- `src/Entity/MarketWatchFilter.php`

**Enumy:**
- `src/Enum/CrawlFrequency.php`
- `src/Enum/CompetitorSnapshotType.php`
- `src/Enum/ChangeSignificance.php`
- `src/Enum/DemandSignalSource.php`
- `src/Enum/DemandSignalType.php`
- `src/Enum/DemandSignalStatus.php`
- `src/Enum/SubscriptionStatus.php`

**Services:**
- `src/Service/Competitor/CompetitorMonitorInterface.php`
- `src/Service/Competitor/AbstractCompetitorMonitor.php`
- `src/Service/Competitor/PortfolioMonitor.php`
- `src/Service/Demand/DemandSignalSourceInterface.php`
- `src/Service/Demand/AbstractDemandSource.php`
- `src/Service/Demand/DemandSignalResult.php`

**Commands:**
- `src/Command/CompetitorMonitorCommand.php`
- `src/Command/DemandMonitorCommand.php`

**Admin:**
- `src/Controller/Admin/MonitoredDomainCrudController.php`
- `src/Controller/Admin/MonitoredDomainSubscriptionCrudController.php`
- `src/Controller/Admin/CompetitorSnapshotCrudController.php`
- `src/Controller/Admin/DemandSignalCrudController.php`
- `src/Controller/Admin/DemandSignalSubscriptionCrudController.php`
- `src/Controller/Admin/MarketWatchFilterCrudController.php`
