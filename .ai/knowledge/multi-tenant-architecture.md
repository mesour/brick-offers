# Multi-Tenant Architecture

## Overview

Systém podporuje multi-tenant architekturu pomocí `User` entity. Všechna hlavní data (leads, companies, industry_benchmarks) jsou navázána na konkrétního uživatele.

## Architektura

```
User
├── Lead (website1.cz)
│   └── Company (IČO 12345678)
├── Lead (website2.cz)
│   └── Company (IČO 12345678)  # Stejná firma, různé weby
├── Lead (website3.cz)
│   └── Company (IČO 87654321)
└── IndustryBenchmark (e-commerce, 2026-01)
```

## User Entity

```php
class User
{
    private string $code;        // Unikátní kód (lowercase, a-z0-9_-)
    private string $name;        // Zobrazované jméno
    private ?string $email;      // Volitelný email (unique)
    private bool $active;        // Aktivní/neaktivní
    private array $settings;     // JSONB pro uživatelská nastavení

    // Vztahy
    private Collection $leads;
    private Collection $companies;
    private Collection $industryBenchmarks;
}
```

### Příklady kódů uživatelů

```
admin          - Hlavní administrátor
webdesign-team - Tým webdesignu
sales          - Obchodní oddělení
client-xyz     - Konkrétní klient
```

## Data Isolation

### Leads

- Každý lead patří právě jednomu uživateli
- Doména je unikátní **v rámci uživatele** (ne globálně)
- Stejná doména může existovat u různých uživatelů

```sql
-- Unique constraint
UNIQUE (user_id, domain)
```

### Companies

- Každá firma patří právě jednomu uživateli
- IČO je unikátní **v rámci uživatele**
- Stejné IČO může existovat u různých uživatelů (každý má vlastní ARES data)

```sql
-- Unique constraint
UNIQUE (user_id, ico)
```

### Industry Benchmarks

- Benchmarky jsou počítány **per user**
- Každý uživatel má vlastní statistiky odvětví

```sql
-- Unique constraint
UNIQUE (user_id, industry, period_start)
```

## CLI Commands

Všechny příkazy pro práci s daty **vyžadují** `--user` parametr:

### Lead Discovery

```bash
# Discover leads pro uživatele "webdesign"
bin/console app:lead:discover manual --user=webdesign --url=https://example.cz

# S extrakcí kontaktů
bin/console app:lead:discover google --user=webdesign --query="webdesign Praha" --extract

# S propojením na firmy
bin/console app:lead:discover manual --user=webdesign --url=https://example.cz --extract --link-company
```

### Company ARES Sync

```bash
# Sync konkrétního IČO
bin/console app:company:sync-ares --user=webdesign --ico=27082440

# Batch sync
bin/console app:company:sync-ares --user=webdesign --limit=100
```

### Benchmark Calculation

```bash
# Vypočítat benchmarky pro všechna odvětví
bin/console app:benchmark:calculate --user=webdesign

# Pouze pro konkrétní odvětví
bin/console app:benchmark:calculate --user=webdesign --industry=e-commerce

# Zobrazit statistiky
bin/console app:benchmark:calculate --user=webdesign --show-stats
```

## API Filtering

V REST API lze filtrovat podle user ID:

```
GET /api/leads?user.code=webdesign
GET /api/companies?user.code=webdesign
GET /api/industry_benchmarks?user.code=webdesign
```

## Migrace existujících dat

Při migraci se automaticky vytvoří `default` user a všechna existující data se mu přiřadí:

```sql
INSERT INTO users (code, name) VALUES ('default', 'Default User');
UPDATE leads SET user_id = (SELECT id FROM users WHERE code = 'default');
UPDATE companies SET user_id = (SELECT id FROM users WHERE code = 'default');
UPDATE industry_benchmarks SET user_id = (SELECT id FROM users WHERE code = 'default');
```

## Vytvoření nového uživatele

Nový uživatel se vytvoří přes API nebo přímo v DB:

```sql
INSERT INTO users (id, code, name, email, active, settings, created_at, updated_at)
VALUES (gen_random_uuid(), 'new-user', 'New User', 'user@example.com', true, '{}', NOW(), NOW());
```

Nebo přes API:

```bash
curl -X POST http://localhost:7270/api/users \
  -H "Content-Type: application/json" \
  -d '{"code": "new-user", "name": "New User", "email": "user@example.com"}'
```

## Best Practices

1. **Vždy specifikujte user** v CLI příkazech
2. **Používejte popisné kódy** - `sales-team` je lepší než `user1`
3. **Jeden uživatel = jeden dataset** - nepoužívejte jednoho uživatele pro více nezávislých datasetů
4. **Archivujte neaktivní uživatele** - nastavte `active = false` místo mazání
