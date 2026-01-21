# Multi-Tenancy Ownership Model - Implementation History

## Datum implementace

2026-01-21

## Zadání

Implementovat multi-tenancy ownership model pro entity v databázi:
1. **Company** - sdílená data (jeden záznam per IČO)
2. **Competitor tracking** - sdílené snapshoty přes MonitoredDomain
3. **Demand signals** - sdílené signály s per-user subscriptions
4. **User configs** - konfigurovatelné analyzátory per user
5. **Email templates** - hierarchické šablony (user → industry → global)

## Vytvořené soubory

### Nové Entity

- `src/Entity/UserAnalyzerConfig.php` - konfigurace analyzátorů per user
- `src/Entity/UserCompanyNote.php` - poznámky k firmám per user
- `src/Entity/MonitoredDomain.php` - sdílená sledovaná doména
- `src/Entity/MonitoredDomainSubscription.php` - odběr sledování domény
- `src/Entity/DemandSignalSubscription.php` - odběr tržních signálů
- `src/Entity/MarketWatchFilter.php` - automatické filtrování signálů
- `src/Entity/EmailTemplate.php` - hierarchické email šablony

### Nové Repositorie

- `src/Repository/UserAnalyzerConfigRepository.php`
- `src/Repository/UserCompanyNoteRepository.php`
- `src/Repository/MonitoredDomainRepository.php`
- `src/Repository/MonitoredDomainSubscriptionRepository.php`
- `src/Repository/DemandSignalSubscriptionRepository.php`
- `src/Repository/MarketWatchFilterRepository.php`
- `src/Repository/EmailTemplateRepository.php`

### Nové Enumy

- `src/Enum/RelationshipStatus.php` - vztah uživatele k firmě
- `src/Enum/CrawlFrequency.php` - frekvence crawlování
- `src/Enum/SubscriptionStatus.php` - stav odběru signálu

### Upravené soubory

- `src/Entity/User.php` - přidány vztahy k novým entitám
- `src/Entity/Company.php` - odstraněn user_id, změněn constraint na (ico)
- `src/Entity/CompetitorSnapshot.php` - změněn lead_id na monitored_domain_id
- `src/Entity/DemandSignal.php` - nullable user_id, přidáno is_shared
- `src/Repository/CompanyRepository.php` - odstraněny user-specific metody
- `src/Repository/CompetitorSnapshotRepository.php` - změněno na MonitoredDomain
- `src/Service/Company/CompanyService.php` - odstranění user dependency
- `src/Command/CompanySyncAresCommand.php` - odstranění user dependency

### Migrace

- `migrations/Version20260121143139.php` - kompletní migrace všech změn

## Klíčové implementační detaily

### 1. Sdílená Company

Company entity už není per-user, ale sdílená (jeden záznam per IČO):
- Unique constraint pouze na `ico`
- Per-user metadata přes `UserCompanyNote` (poznámky, tagy, relationship_status)

### 2. MonitoredDomain pro competitor tracking

Namísto vazby CompetitorSnapshot → Lead je teď:
- `MonitoredDomain` - sdílená doména ke sledování
- `MonitoredDomainSubscription` - per-user odběr
- `CompetitorSnapshot` - vazba na MonitoredDomain

### 3. Sdílené DemandSignal

- `user_id` je nullable (signály mohou být crawlované bez přiřazení)
- `is_shared` flag pro sdílení
- `DemandSignalSubscription` - per-user view/stav
- `MarketWatchFilter` - automatické matchování signálů

### 4. UserAnalyzerConfig

- Per-user konfigurace které analyzátory běží
- Priorita (1-10)
- Custom config JSON (thresholds, ignore_codes)

### 5. EmailTemplate hierarchie

Resolution order:
1. User-specific template (user_id NOT NULL)
2. Industry-specific global (user_id NULL, industry NOT NULL)
3. Default global (user_id NULL, industry NULL)

## Diagram vztahů

```
User (tenant root)
├── UserAnalyzerConfig (1:N)
├── UserCompanyNote (1:N) → Company
├── MonitoredDomainSubscription (1:N) → MonitoredDomain
├── DemandSignalSubscription (1:N) → DemandSignal
├── MarketWatchFilter (1:N)
├── EmailTemplate (1:N, nullable)
├── Lead (1:N) → Company
└── IndustryBenchmark (1:N)

Sdílená data:
├── Company (UNIQUE ico)
├── MonitoredDomain (UNIQUE domain)
│   └── CompetitorSnapshot (1:N)
├── DemandSignal (shared)
└── EmailTemplate (user_id NULL)
```

## Migrace

### Migrace `Version20260121143139.php`

Provádí:
1. Vytvoření nových tabulek (7 tabulek)
2. Odstranění user_id z companies
3. Přejmenování lead_id na monitored_domain_id v competitor_snapshots
4. Úprava FK constraints
5. Přidání is_shared do demand_signals, nullable user_id

### Spuštění migrace (2026-01-21)

```bash
# Migrace úspěšně spuštěna
bin/console doctrine:migrations:migrate
# [OK] Successfully migrated to version: DoctrineMigrations\Version20260121143139
```

### Data fix: Leads bez uživatele

Před migrací existovalo 176 leadů s NULL user_id. Řešení:

```sql
-- Vytvoření default uživatele
INSERT INTO users (id, code, name, email, active, settings, created_at, updated_at)
VALUES (gen_random_uuid(), 'default', 'Default User', 'default@example.com', true, '{}', NOW(), NOW());

-- Přiřazení leadů k default uživateli
UPDATE leads SET user_id = '42287890-25bf-40a4-b525-334410ba5ea7' WHERE user_id IS NULL;
-- 176 rows affected

-- Nastavení NOT NULL constraint
ALTER TABLE leads ALTER user_id SET NOT NULL;
```

**Default User:**
- ID: `42287890-25bf-40a4-b525-334410ba5ea7`
- Code: `default`
- Name: `Default User`

### Stav po migraci

```bash
bin/console doctrine:schema:validate
# [OK] The mapping files are correct.
# [OK] The database schema is in sync with the mapping files.
```

## Nové tabulky v databázi

| Tabulka | Popis |
|---------|-------|
| `user_analyzer_configs` | Per-user konfigurace analyzátorů |
| `user_company_notes` | Per-user poznámky ke sdíleným firmám |
| `monitored_domains` | Sdílené domény pro competitor tracking |
| `monitored_domain_subscriptions` | Per-user odběry sledovaných domén |
| `demand_signal_subscriptions` | Per-user odběry tržních signálů |
| `market_watch_filters` | Per-user filtry pro automatické matchování |
| `email_templates` | Hierarchické email šablony |

## Poznámky pro produkci

**POZOR:** Před spuštěním migrace na produkci je nutné:
1. Data deduplikace pro Company (zachovat nejstarší per IČO)
2. Vytvoření MonitoredDomain z existujících Lead.domain
3. Přesměrování existujících CompetitorSnapshot
4. Vyřešit leads bez user_id (přiřadit nebo smazat)

## Verifikace

```bash
# Validace schema
bin/console doctrine:schema:validate

# Test API
curl http://localhost:7270/api/user_analyzer_configs
curl http://localhost:7270/api/monitored_domains
curl http://localhost:7270/api/demand_signal_subscriptions
curl http://localhost:7270/api/email_templates
curl http://localhost:7270/api/market_watch_filters
```
