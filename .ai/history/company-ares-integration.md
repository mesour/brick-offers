# Company Entity & ARES Integration - Implementation History

## Zadání

Rozšíření systému o Company entitu pro správu firem identifikovaných IČO. Jedna firma může mít více webů (Lead). Integrace s ARES API pro automatické doplnění firemních údajů z veřejného registru.

### Klíčový koncept

```
Company (1 IČO)
    ├── Lead (web1.cz)
    ├── Lead (web2.cz)
    └── Lead (eshop.cz)
```

## Vytvořené soubory

### Entity & Repository
- `src/Entity/Company.php` - Nová entita s ARES daty (IČO, DIČ, název, adresa, právní forma, stav)
- `src/Repository/CompanyRepository.php` - Repository s metodami pro vyhledávání a statistiky

### ARES Services
- `src/Service/Ares/AresData.php` - DTO pro data z ARES API
- `src/Service/Ares/AresClient.php` - HTTP client pro ARES API s rate limiting (200ms delay)

### Company Service
- `src/Service/Company/CompanyService.php` - Orchestrátor pro práci s Company a ARES

### Extractor
- `src/Service/Extractor/CompanyNameExtractor.php` - Extrakce názvu firmy z HTML (Schema.org, meta tags, copyright, title)

### Command
- `src/Command/CompanySyncAresCommand.php` - CLI pro synchronizaci ARES dat

### Migration
- `migrations/Version20260121100000.php` - Creates companies table, adds company_id to leads

## Aktualizované soubory

- `src/Entity/Lead.php` - Přidán ManyToOne vztah na Company
- `src/Service/Extractor/PageDataExtractor.php` - Integrován CompanyNameExtractor
- `src/Command/LeadDiscoverCommand.php` - Přidána `--link-company` option

## Klíčové implementační detaily

### Company Entity

```php
class Company
{
    // ARES data
    private string $ico;           // 8 digits, unique
    private ?string $dic;          // DIČ
    private string $name;          // Obchodní jméno
    private ?string $legalForm;    // s.r.o., a.s., ...

    // Address
    private ?string $street;
    private ?string $city;
    private ?string $cityPart;
    private ?string $postalCode;

    // Status
    private ?string $businessStatus;  // Aktivní, Zaniklý, ...
    private ?array $aresData;         // Raw ARES response
    private ?\DateTimeImmutable $aresUpdatedAt;

    // Relations
    private Collection $leads;  // OneToMany
}
```

### ARES API

- Endpoint: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ico}`
- Rate limit: 200ms delay between requests (max 500/min allowed)
- Response: JSON with company data (obchodniJmeno, dic, sidlo, pravniForma, stavSubjektu)
- Caching: Stored in `ares_data` JSONB, refreshed after 30 days

### CompanyNameExtractor Patterns

1. Schema.org JSON-LD (Organization, LocalBusiness)
2. Open Graph `og:site_name` meta tag
3. Company name with legal form (s.r.o., a.s.)
4. Copyright notice (© 2024 Company Name)
5. Title tag fallback

### Lead Discovery Integration

```bash
# Discovery with company linking
app:lead:discover manual --url=https://example.cz --extract --link-company

# Sync specific IČO
app:company:sync-ares --ico=27082440

# Batch sync outdated companies
app:company:sync-ares --limit=100
```

## Database Schema

```sql
CREATE TABLE companies (
    id UUID PRIMARY KEY,
    ico VARCHAR(8) UNIQUE NOT NULL,
    dic VARCHAR(20),
    name VARCHAR(255) NOT NULL,
    legal_form VARCHAR(100),
    street VARCHAR(255),
    city VARCHAR(100),
    city_part VARCHAR(100),
    postal_code VARCHAR(10),
    business_status VARCHAR(50),
    ares_data JSONB,
    ares_updated_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
);

-- Lead relation
ALTER TABLE leads ADD company_id UUID REFERENCES companies(id) ON DELETE SET NULL;
CREATE INDEX leads_company_idx ON leads(company_id);
```

## Checklist pro podobnou implementaci

1. [ ] Vytvoř entitu s potřebnými poli
2. [ ] Vytvoř repository s helper metodami
3. [ ] Vytvoř DTO pro externí API response
4. [ ] Implementuj API client s rate limiting
5. [ ] Vytvoř service pro orchestraci
6. [ ] Přidej vztah do souvisejících entit
7. [ ] Integruj do existujících commands
8. [ ] Vytvoř nový command pro správu
9. [ ] Vytvoř databázovou migraci
10. [ ] Aktualizuj dokumentaci

## Známé problémy a řešení

### ARES API Rate Limiting
- **Problém:** ARES má limit 500 req/min
- **Řešení:** 200ms delay mezi requesty v `AresClient`

### Company Name Extraction Priority
- **Problém:** Různé zdroje mají různou spolehlivost
- **Řešení:** Priority scoring v `CompanyNameExtractor` (Schema.org > meta > copyright > title)

### Denormalizace IČO na Lead
- **Problém:** Potřeba rychlého přístupu k IČO bez JOIN
- **Řešení:** IČO zůstává na Lead i po propojení s Company (denormalizace)
