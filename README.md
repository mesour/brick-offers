# brick-offers

RealEstate Data source

Staging: https://real-estate-data-source.k8stage.ulovdomov.cz/

Link to [DOCUMENTATION](docs/index.md)

## First run

1. If you are not logged in, log in to the DO registry using:
   `docker login registry.digitalocean.com/ulovdomov-be -u doctl`
    - To get the token, you need to contact the DO registry administrator
2. Run `make init`

## Development

### First setup

1. Run for initialization
```shell
make init
```
2. Run composer install
```shell
make composer
```

Use tasks in Makefile:

- To log into container
```shell
make docker
```
- To run code sniffer fix
```shell
make cs-fix
```
- To run PhpStan
```shell
make phpstan
```
- To run tests
```shell
make phpunit
```

---

## Lead Discovery Module

Modul pro vyhledávání a import leadů z různých zdrojů.

### Architektura

```
src/
├── Enum/
│   ├── LeadSource.php      # manual, google, seznam, firmy_cz, zive_firmy, najisto, zlatestranky, crawler, reference_crawler
│   ├── LeadStatus.php      # new → queued → analyzing → ... → converted
│   └── LeadType.php        # website, business_without_web
├── Entity/
│   ├── Lead.php            # Hlavní entita leadu (s kontakty a technologiemi)
│   └── Affiliate.php       # Affiliate pro tracking
├── Repository/
│   ├── LeadRepository.php  # Bulk deduplikace, query by status
│   └── AffiliateRepository.php
├── Command/
│   └── LeadDiscoverCommand.php  # CLI příkaz pro discovery
├── Controller/
│   └── LeadImportController.php # API endpoint pro bulk import
├── Service/
│   ├── Discovery/
│   │   ├── DiscoverySourceInterface.php
│   │   ├── DiscoveryResult.php         # DTO s auto-extrakcí domény
│   │   ├── AbstractDiscoverySource.php # Rate limiting, URL normalizace, extraction
│   │   ├── ManualDiscoverySource.php   # Přímé URL
│   │   ├── GoogleDiscoverySource.php   # Google Custom Search API
│   │   ├── SeznamDiscoverySource.php   # Seznam Search
│   │   ├── FirmyCzDiscoverySource.php  # Firmy.cz katalog
│   │   ├── ZiveFirmyDiscoverySource.php  # Živéfirmy.cz katalog
│   │   ├── NajistoDiscoverySource.php    # Najisto.cz katalog
│   │   ├── ZlatestrankyDiscoverySource.php # ZlatéStránky.cz katalog
│   │   ├── CrawlerDiscoverySource.php    # Crawl existujících leadů
│   │   └── ReferenceDiscoverySource.php  # Crawl agency portfolios
│   └── Extractor/                        # Contact/Technology extraction
│       ├── ContactExtractorInterface.php
│       ├── PageData.php                  # DTO pro extrahovaná data
│       ├── PageDataExtractor.php         # Orchestrátor
│       ├── EmailExtractor.php            # Email s prioritizací
│       ├── PhoneExtractor.php            # České telefony
│       ├── IcoExtractor.php              # IČO validace (modulo 11)
│       ├── TechnologyDetector.php        # CMS a tech stack
│       └── SocialMediaExtractor.php      # FB, IG, LinkedIn, ...
```

### CLI Command: `app:lead:discover`

```bash
# Syntaxe
bin/console app:lead:discover <source> [options]

# Zdroje (source)
#   manual       - Přímé URL
#   google       - Google Custom Search API
#   seznam       - Seznam Search
#   firmy_cz     - Firmy.cz katalog
#   zive_firmy   - Živéfirmy.cz katalog
#   najisto      - Najisto.cz katalog (Centrum.cz)
#   zlatestranky - ZlatéStránky.cz katalog
#   crawler      - Crawl analyzovaných leadů
```

#### Rychlá nápověda - jak spustit každý zdroj

| Zdroj | Příkaz | Popis |
|-------|--------|-------|
| **manual** | `--url=https://example.com` | Přímé URL (lze opakovat) |
| **google** | `--query="webdesign Praha"` | Google Custom Search API |
| **seznam** | `--query="restaurace Brno"` | Seznam Search |
| **firmy_cz** | `--query="kavárna"` | Firmy.cz katalog |
| **zive_firmy** | `--query="stavebnictvi"` | Kategorie |
| | `--query="stavebnictvi:loc=10000019"` | Kategorie + kraj (Praha) |
| | `--query="q=autoservis"` | Textové vyhledávání |
| | `--query="q=autoservis:loc=10000019"` | Vyhledávání + kraj |
| **najisto** | `--query="what=autoservis"` | Textové vyhledávání |
| | `--query="what=autoservis:where=brno"` | Vyhledávání + lokalita |
| | `--query="sport"` | Kategorie |
| **zlatestranky** | `--query="autoservis"` | Textové vyhledávání |
| | `--query="autoservis:brno"` | Vyhledávání + lokalita |
| | `--query="rubrika:Autoservis"` | Kategorie |
| | `--query="kraj:Jihočeský kraj"` | Dle kraje |
| **crawler** | (bez query) | Crawluje existující leady |
| **reference_crawler** | `--query="webdesign"` | Crawluje portfolia agentur |

> **Tip:** Všechny zdroje podporují `--limit=N`, `--dry-run`, `--priority=N`, `--affiliate=HASH` a `--extract` pro extrakci kontaktů

#### Příklady použití

```bash
# Manuální import jedné URL
bin/console app:lead:discover manual --url=https://example.com

# Manuální import více URL
bin/console app:lead:discover manual \
  --url=https://example1.com \
  --url=https://example2.com \
  --url=https://example3.com

# Google search s dotazem
bin/console app:lead:discover google --query="webdesign Praha" --limit=100

# Seznam search
bin/console app:lead:discover seznam --query="restaurace Brno" --limit=50

# Firmy.cz katalog
bin/console app:lead:discover firmy_cz --query="kavárna" --limit=200

# Živéfirmy.cz - kategorie
bin/console app:lead:discover zive_firmy --query="stavebnictvi" --limit=50

# Živéfirmy.cz - kategorie + lokalita (Vysočina)
bin/console app:lead:discover zive_firmy --query="stavebnictvi:loc=10000108" --limit=50

# Živéfirmy.cz - textové vyhledávání
bin/console app:lead:discover zive_firmy --query="q=autoservis" --limit=50

# Živéfirmy.cz - vyhledávání + lokalita (Praha)
bin/console app:lead:discover zive_firmy --query="q=autoservis:loc=10000019" --limit=50

# Najisto.cz - textové vyhledávání
bin/console app:lead:discover najisto --query="what=autoservis" --limit=50

# Najisto.cz - vyhledávání + lokalita
bin/console app:lead:discover najisto --query="what=autoservis:where=brno" --limit=50

# Najisto.cz - procházení kategorie
bin/console app:lead:discover najisto --query="sport" --limit=50

# ZlatéStránky.cz - textové vyhledávání
bin/console app:lead:discover zlatestranky --query="autoservis" --limit=50

# ZlatéStránky.cz - vyhledávání + lokalita
bin/console app:lead:discover zlatestranky --query="autoservis:brno" --limit=50

# ZlatéStránky.cz - procházení kategorie (rubrika)
bin/console app:lead:discover zlatestranky --query="rubrika:Autoservis, opravy silničních vozidel" --limit=50

# ZlatéStránky.cz - procházení dle kraje
bin/console app:lead:discover zlatestranky --query="kraj:Jihočeský kraj" --limit=50

# Crawler - crawluje weby již analyzovaných leadů
bin/console app:lead:discover crawler --limit=100

# Více dotazů najednou
bin/console app:lead:discover google \
  --query="webdesign Praha" \
  --query="tvorba webů Brno" \
  --limit=200

# S affiliate tracking
bin/console app:lead:discover manual --url=https://example.com --affiliate=ABC123

# S vlastní prioritou (1-10)
bin/console app:lead:discover google --query="eshop" --priority=8

# Dry run - pouze simulace bez ukládání
bin/console app:lead:discover manual --url=https://example.com --dry-run

# Vlastní batch size pro DB operace
bin/console app:lead:discover google --query="webdesign" --batch-size=50
```

#### Živéfirmy.cz - Query formáty

Živéfirmy.cz podporuje dva způsoby vyhledávání:

**1. Procházení kategorií:**
```bash
# Pouze kategorie
--query="{category}"

# Kategorie + lokalita
--query="{category}:loc={location_id}"
```

**2. Textové vyhledávání:**
```bash
# Pouze hledaný výraz
--query="q={search_term}"

# Hledaný výraz + lokalita
--query="q={search_term}:loc={location_id}"
```

**Dostupné kategorie:**

| Slug | Popis |
|------|-------|
| `auto-moto` | Auto, moto |
| `stavebnictvi` | Stavebnictví |
| `vypocetni-technika-internet` | IT, internet |
| `finance-ekonomika-pravo` | Finance, právo |
| `zdravotnictvi-zdravotni-sluzby-a-technika` | Zdravotnictví |
| `restaurace-ubytovani` | Restaurace, ubytování |
| `sluzby-obchod-prodej` | Služby, obchod |
| `sport` | Sport |
| `reality` | Reality |
| `vzdelani-jazyky` | Vzdělání, jazyky |
| `zabava-kultura` | Zábava, kultura |
| `doprava-dopravni-technika` | Doprava |
| `energetika-topeni` | Energetika, topení |
| ... | (32 kategorií celkem) |

**Location IDs (kraje):**

> **Poznámka:** Výchozí hodnota je `loc=1` (celá Česká republika). Pokud neuvedete `:loc=`, prohledává se celá ČR.

| ID | Kraj |
|----|------|
| `1` | Celá ČR (výchozí) |
| `10000019` | Praha |
| `10000027` | Středočeský |
| `10000035` | Jihočeský |
| `10000043` | Plzeňský |
| `10000051` | Karlovarský |
| `10000060` | Ústecký |
| `10000078` | Liberecký |
| `10000086` | Královéhradecký |
| `10000094` | Pardubický |
| `10000108` | Vysočina |
| `10000116` | Jihomoravský |
| `10000124` | Olomoucký |
| `10000132` | Moravskoslezský |
| `10000141` | Zlínský |

**Metadata:**

Živéfirmy.cz discovery ukládá do metadata:
- `has_own_website` - zda má firma vlastní web (true/false)
- `business_name` - název firmy
- `catalog_profile_url` - URL profilu na Živéfirmy.cz
- `phone` - telefon
- `email` - email
- `address` - adresa
- `ico` - IČO
- `category` - kategorie/vyhledávací dotaz
- `source_type` - `zive_firmy_direct` (má web) nebo `zive_firmy_catalog` (nemá web)

#### Najisto.cz - Query formáty

Najisto.cz (Centrum.cz) podporuje dva způsoby vyhledávání:

**1. Textové vyhledávání:**
```bash
# Pouze hledaný výraz
--query="what={search_term}"

# Hledaný výraz + lokalita
--query="what={search_term}:where={location}"
```

**2. Procházení kategorií:**
```bash
# Kategorie (slug z URL)
--query="{category}"
```

**Příklady kategorií:**
- `sport` - Sport
- `auto-moto` - Auto, moto
- `restaurace-a-stravovani` - Restaurace a stravování
- `zdravi` - Zdraví
- `kultura-a-zabava` - Kultura a zábava
- `cestovani-a-ubytovani` - Cestování a ubytování
- `sluzby-a-remesla` - Služby a řemesla

**Metadata:**

Najisto.cz discovery ukládá do metadata:
- `has_own_website` - zda má firma vlastní web (true/false)
- `business_name` - název firmy
- `catalog_profile_url` - URL profilu na Najisto.cz
- `phone` - telefon
- `email` - email
- `address` - adresa
- `query` - vyhledávací dotaz/kategorie
- `source_type` - `najisto_direct` (má web) nebo `najisto_catalog` (nemá web)

#### ZlatéStránky.cz - Query formáty

ZlatéStránky.cz podporuje několik způsobů vyhledávání:

**1. Textové vyhledávání:**
```bash
# Pouze hledaný výraz
--query="{search_term}"

# Hledaný výraz + lokalita
--query="{search_term}:{location}"
```

**2. Procházení kategorií:**
```bash
# Kategorie (název rubriky z URL)
--query="rubrika:{category}"
```

**3. Procházení dle kraje:**
```bash
# Kraj
--query="kraj:{region}"
```

**Příklady použití:**
```bash
# Textové vyhledávání
bin/console app:lead:discover zlatestranky --query="autoservis" --limit=50

# Vyhledávání s lokalitou
bin/console app:lead:discover zlatestranky --query="autoservis:brno" --limit=50

# Vyhledávání s více slovy
bin/console app:lead:discover zlatestranky --query="webdesign praha" --limit=50

# Procházení kategorie
bin/console app:lead:discover zlatestranky --query="rubrika:Autoservis, opravy silničních vozidel" --limit=50

# Procházení dle kraje
bin/console app:lead:discover zlatestranky --query="kraj:Jihočeský kraj" --limit=50
```

**Dostupné kraje:**
- `Hlavní město Praha`
- `Středočeský kraj`
- `Jihočeský kraj`
- `Plzeňský kraj`
- `Karlovarský kraj`
- `Ústecký kraj`
- `Liberecký kraj`
- `Královéhradecký kraj`
- `Pardubický kraj`
- `Kraj Vysočina`
- `Jihomoravský kraj`
- `Olomoucký kraj`
- `Moravskoslezský kraj`
- `Zlínský kraj`

**Metadata:**

ZlatéStránky.cz discovery ukládá do metadata:
- `has_own_website` - zda má firma vlastní web (true/false)
- `business_name` - název firmy
- `catalog_profile_url` - URL profilu na ZlatéStránky.cz
- `phone` - telefon
- `address` - adresa
- `rating` - hodnocení (0-100)
- `query` - vyhledávací dotaz
- `source_type` - `zlatestranky_direct` (má web) nebo `zlatestranky_catalog` (nemá web)

#### Volby (Options)

| Volba | Zkratka | Popis | Default |
|-------|---------|-------|---------|
| `--query` | | Vyhledávací dotaz (lze opakovat) | - |
| `--url` | `-u` | Přímá URL pro manual source (lze opakovat) | - |
| `--limit` | `-l` | Max počet leadů | 50 |
| `--affiliate` | | Affiliate hash pro tracking | - |
| `--priority` | `-p` | Priorita 1-10 | 5 |
| `--dry-run` | | Simulace bez ukládání | false |
| `--batch-size` | | Velikost DB dávky | 100 |
| `--extract` | `-x` | Extrahovat kontakty a detekovat technologie | false |
| `--inner-source` | | Inner source pro reference_crawler | google |

### API Endpoints

#### REST API (API Platform)

```bash
# Seznam leadů
GET /api/leads

# Filtrování
GET /api/leads?domain=example
GET /api/leads?source=google
GET /api/leads?status=new

# Detail leadu
GET /api/leads/{id}

# Vytvoření leadu
POST /api/leads
Content-Type: application/json

{
  "url": "https://example.com",
  "domain": "example.com",
  "source": "manual",
  "status": "new",
  "priority": 5,
  "metadata": {}
}

# Aktualizace leadu
PATCH /api/leads/{id}
Content-Type: application/merge-patch+json

{
  "status": "queued",
  "priority": 8
}

# Smazání leadu
DELETE /api/leads/{id}
```

#### Bulk Import API

```bash
POST /api/leads/import
Content-Type: application/json

{
  "urls": [
    "https://example1.com",
    "https://example2.com",
    "example3.com"
  ],
  "source": "manual",
  "priority": 5,
  "affiliate": "ABC123"
}
```

**Response:**
```json
{
  "imported": 2,
  "skipped": 1,
  "errors": 0,
  "details": {
    "processed": [
      {"url": "https://example1.com", "domain": "example1.com"},
      {"url": "https://example2.com", "domain": "example2.com"}
    ],
    "skipped": [
      {"url": "https://example3.com", "domain": "example3.com", "reason": "domain_exists"}
    ],
    "errors": []
  }
}
```

### Konfigurace

#### Environment Variables

```env
# Google Custom Search API
# Získat na: https://programmablesearchengine.google.com/
GOOGLE_SEARCH_API_KEY=your-api-key
GOOGLE_SEARCH_ENGINE_ID=your-search-engine-id

# Seznam Search API (volitelné)
SEZNAM_SEARCH_API_KEY=your-seznam-key
```

### Database

#### Migrace

```bash
# Spustit migrace
bin/console doctrine:migrations:migrate

# Validovat schema
bin/console doctrine:schema:validate
```

#### Lead Entity

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | UUID | Primární klíč |
| `url` | VARCHAR(500) | Cílová URL |
| `domain` | VARCHAR(255) | Extrahovaná doména (UNIQUE) |
| `source` | ENUM | Zdroj leadu |
| `status` | ENUM | Status workflow |
| `priority` | INT | Priorita 1-10 |
| `type` | ENUM | Typ leadu (website, business_without_web) |
| `has_website` | BOOL | Má firma web? |
| `ico` | VARCHAR(8) | IČO firmy (validované) |
| `company_name` | VARCHAR(255) | Název firmy |
| `email` | VARCHAR(255) | Primární email |
| `phone` | VARCHAR(50) | Primární telefon |
| `address` | VARCHAR(500) | Adresa |
| `detected_cms` | VARCHAR(50) | Detekovaný CMS (wordpress, shoptet, ...) |
| `detected_technologies` | JSON | Detekované technologie |
| `social_media` | JSON | Sociální sítě {facebook, instagram, ...} |
| `metadata` | JSONB | Dodatečná data (title, snippet, query...) |
| `affiliate_id` | UUID | FK na Affiliate (nullable) |
| `created_at` | TIMESTAMP | Čas vytvoření |
| `updated_at` | TIMESTAMP | Čas poslední změny |

#### Lead Status Workflow

```
NEW → QUEUED → ANALYZING → ANALYZED → APPROVED → SENT → RESPONDED → CONVERTED
```

### Contact Extraction System

Systém pro automatickou extrakci kontaktních údajů a detekci technologií z webových stránek.

#### Použití

```bash
# Discovery s extrakcí kontaktů a technologií
bin/console app:lead:discover manual --url=https://example.cz --extract

# S katalogem
bin/console app:lead:discover firmy_cz --query="restaurace" --limit=10 --extract
```

#### Podporované CMS

| CMS | Detekce |
|-----|---------|
| WordPress | `/wp-content/`, meta generator |
| Shoptet | `cdn.myshoptet.com`, `/user/documents/` |
| Wix | `static.wixstatic.com` |
| Squarespace | `sqsp.net` |
| PrestaShop | `/modules/`, meta generator |
| Shopify | `cdn.shopify.com` |
| Webnode | `webnode.cz` |
| Joomla | `/media/jui/` |
| Drupal | `/sites/default/`, headers |
| Magento | `/static/frontend/` |

#### Extrahovaná data

| Typ | Popis |
|-----|-------|
| **Email** | Prioritizace: info@, kontakt@ > sales@ > ostatní |
| **Telefon** | České formáty, normalizace na +420XXXXXXXXX |
| **IČO** | 8 číslic, validace modulo 11 |
| **Sociální sítě** | Facebook, Instagram, LinkedIn, Twitter, YouTube |
| **Technologie** | jQuery, Bootstrap, React, Vue, Angular, Google Analytics, ... |

### Deduplikace

- Každá doména může být v databázi pouze jednou (UNIQUE constraint)
- Při importu se automaticky přeskakují existující domény
- URL se normalizuje (přidání https://, odstranění www.)
- Bulk import používá efektivní `findExistingDomains()` pro kontrolu

---

## Analysis Module

Modul pro analýzu webových stránek a detekci technických problémů.

### CLI Command: `app:lead:analyze`

Spustí analýzu leadů pro detekci technických problémů (SSL, SEO, security, performance, atd.).

```bash
# Syntaxe
bin/console app:lead:analyze [options]

# Základní použití - analyzuje NEW leady
bin/console app:lead:analyze --limit=50

# Analyzovat s industry filtrem (spustí i industry-specific analyzátory)
bin/console app:lead:analyze --industry=eshop --limit=10

# Analyzovat konkrétní lead
bin/console app:lead:analyze --lead-id=uuid

# Re-analyzovat existující lead (vytvoří novou analýzu s historií)
bin/console app:lead:analyze --lead-id=uuid --reanalyze --industry=webdesign

# Spustit pouze konkrétní kategorii analyzátorů
bin/console app:lead:analyze --category=security

# Dry run - simulace bez ukládání
bin/console app:lead:analyze --dry-run

# Zobrazit detailní issues
bin/console app:lead:analyze --verbose-issues
```

#### Volby (Options)

| Volba | Zkratka | Popis | Default |
|-------|---------|-------|---------|
| `--limit` | `-l` | Max počet leadů k analýze | 50 |
| `--offset` | `-o` | Offset pro paginaci | 0 |
| `--lead-id` | | UUID konkrétního leadu | - |
| `--category` | `-c` | Pouze konkrétní kategorie (http, security, seo, ...) | - |
| `--industry` | `-i` | Odvětví pro industry-specific analyzátory | - |
| `--dry-run` | | Simulace bez ukládání | false |
| `--reanalyze` | | Re-analyzovat i již analyzované leady | false |
| `--verbose-issues` | | Zobrazit detailní issues | false |

#### Dostupná odvětví (Industry)

| Hodnota | Popis |
|---------|-------|
| `webdesign` | Web Design & Development |
| `eshop` | E-commerce / E-shop |
| `real_estate` | Real Estate |
| `automobile` | Automobile |
| `restaurant` | Restaurant & Food |
| `medical` | Healthcare & Medical |
| `legal` | Legal Services |
| `finance` | Finance & Insurance |
| `education` | Education |
| `other` | Other |

#### Kategorie analyzátorů

**Univerzální (běží vždy):**
- `http` - SSL, HTTPS, TTFB, mixed content
- `security` - Security headers, CSP, HSTS
- `seo` - Title, description, H1, OG tags, sitemap
- `libraries` - jQuery, Bootstrap, outdated versions
- `performance` - Core Web Vitals (LCP, FCP, CLS)
- `responsiveness` - Viewport, mobile overflow
- `visual` - Padding, fonts, image quality
- `accessibility` - Color contrast, ARIA, labels
- `outdated_code` - Table layout, Flash, deprecated tags
- `design_modernity` - CSS Grid, Flexbox, modern features
- `eshop_detection` - Detekce e-shopu

**Industry-specific (běží pouze s --industry):**
- `industry_eshop` - Product pages, cart, payments (pro --industry=eshop)
- `industry_webdesign` - Portfolio, case studies, pricing (pro --industry=webdesign)
- `industry_real_estate` - Listings, virtual tours (pro --industry=real_estate)
- `industry_automobile` - Inventory, test drive booking (pro --industry=automobile)
- `industry_restaurant` - Menu, reservations (pro --industry=restaurant)
- `industry_medical` - Appointment booking (pro --industry=medical)

#### Historie analýz

Při opakované analýze (--reanalyze) systém automaticky:
- Propojí novou analýzu s předchozí (`previousAnalysis`)
- Vypočítá změnu skóre (`scoreDelta`)
- Porovná issues (`issueDelta` - added, removed, unchanged)
- Aktualizuje `Lead.latestAnalysis` a `Lead.analysisCount`

---

### CLI Command: `app:analysis:snapshot`

Generuje snapshoty analýz pro trending a benchmarking.

```bash
# Syntaxe
bin/console app:analysis:snapshot [options]

# Generovat týdenní snapshoty pro všechny leady
bin/console app:analysis:snapshot --period=week

# Generovat snapshot pro konkrétní lead
bin/console app:analysis:snapshot --lead-id=uuid --period=week

# Zobrazit statistiky snapshotů
bin/console app:analysis:snapshot --show-stats

# Vyčistit staré snapshoty (zachovat posledních 52 týdnů)
bin/console app:analysis:snapshot --cleanup --retention=52

# Dry run
bin/console app:analysis:snapshot --period=week --dry-run
```

#### Volby (Options)

| Volba | Zkratka | Popis | Default |
|-------|---------|-------|---------|
| `--period` | `-p` | Typ období (day, week, month) | week |
| `--lead-id` | | UUID konkrétního leadu | - |
| `--cleanup` | | Vyčistit staré snapshoty | false |
| `--retention` | `-r` | Počet období k zachování (pro cleanup) | 52 |
| `--dry-run` | | Simulace bez ukládání | false |
| `--show-stats` | | Zobrazit statistiky | false |

#### Snapshot periody

| Období | Popis | Doporučené použití |
|--------|-------|-------------------|
| `day` | Denní snapshoty | E-shopy, často se měnící weby |
| `week` | Týdenní snapshoty | Standardní monitoring |
| `month` | Měsíční snapshoty | Dlouhodobé trendy |

#### Doporučené cron joby

```bash
# Týdenní snapshoty (každé pondělí)
0 2 * * 1 bin/console app:analysis:snapshot --period=week

# Měsíční snapshoty (první den v měsíci)
0 3 1 * * bin/console app:analysis:snapshot --period=month

# Cleanup starých snapshotů (jednou měsíčně)
0 4 1 * * bin/console app:analysis:snapshot --cleanup --period=day --retention=90
0 5 1 * * bin/console app:analysis:snapshot --cleanup --period=week --retention=52
```

---

### CLI Command: `app:benchmark:calculate`

Vypočítá industry benchmarky z dat analýz pro porovnávání leadů v rámci odvětví.

```bash
# Syntaxe
bin/console app:benchmark:calculate [options]

# Vypočítat benchmarky pro všechna odvětví
bin/console app:benchmark:calculate

# Vypočítat benchmark pro konkrétní odvětví
bin/console app:benchmark:calculate --industry=eshop

# Zobrazit aktuální statistiky benchmarků
bin/console app:benchmark:calculate --show-stats

# Zobrazit top issues per industry
bin/console app:benchmark:calculate --show-stats --show-top-issues

# Porovnat konkrétní analýzu s benchmarkem
bin/console app:benchmark:calculate --compare=analysis-uuid

# Dry run
bin/console app:benchmark:calculate --dry-run
```

#### Volby (Options)

| Volba | Zkratka | Popis | Default |
|-------|---------|-------|---------|
| `--industry` | `-i` | Pouze konkrétní odvětví | - |
| `--compare` | | UUID analýzy pro porovnání s benchmarkem | - |
| `--dry-run` | | Simulace bez ukládání | false |
| `--show-stats` | | Zobrazit aktuální statistiky | false |
| `--show-top-issues` | | Zobrazit top issues (s --show-stats) | false |

#### Výstup porovnání

Při použití `--compare` zobrazí:
- **Ranking**: top10, top25, above_average, below_average, bottom25
- **Percentile**: Kde se analýza nachází v rámci odvětví (0-100%)
- **Score Comparison**: Porovnání skóre s průměrem odvětví

#### Doporučené cron joby

```bash
# Přepočítat benchmarky jednou týdně (po snapshotech)
0 6 * * 1 bin/console app:benchmark:calculate
```

---

### Database Schema (Analysis)

#### Analysis Entity

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | UUID | Primární klíč |
| `lead_id` | UUID | FK na Lead |
| `status` | ENUM | PENDING, RUNNING, COMPLETED, FAILED |
| `industry` | ENUM | Odvětví (nullable) |
| `sequence_number` | INT | Pořadí analýzy pro lead (1, 2, 3...) |
| `previous_analysis_id` | UUID | FK na předchozí analýzu (nullable) |
| `total_score` | INT | Celkové skóre |
| `score_delta` | INT | Změna skóre oproti předchozí (nullable) |
| `is_improved` | BOOL | Zda došlo ke zlepšení |
| `issue_delta` | JSON | {added: [], removed: [], unchanged_count: int} |
| `is_eshop` | BOOL | Detekce e-shopu |
| `started_at` | TIMESTAMP | Začátek analýzy |
| `completed_at` | TIMESTAMP | Dokončení analýzy |
| `created_at` | TIMESTAMP | Čas vytvoření |

#### AnalysisSnapshot Entity

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | UUID | Primární klíč |
| `lead_id` | UUID | FK na Lead |
| `analysis_id` | UUID | FK na Analysis (nullable) |
| `period_type` | ENUM | day, week, month |
| `period_start` | DATE | Začátek období |
| `total_score` | INT | Celkové skóre |
| `category_scores` | JSON | {http: 85, security: 70, ...} |
| `issue_count` | INT | Počet issues |
| `critical_issue_count` | INT | Počet kritických issues |
| `top_issues` | JSON | Top 5 issue kódů |
| `score_delta` | INT | Změna oproti předchozímu období |
| `industry` | ENUM | Odvětví (denormalizováno) |
| `created_at` | TIMESTAMP | Čas vytvoření |

#### IndustryBenchmark Entity

| Sloupec | Typ | Popis |
|---------|-----|-------|
| `id` | UUID | Primární klíč |
| `industry` | ENUM | Odvětví |
| `period_start` | DATE | Začátek období |
| `avg_score` | FLOAT | Průměrné skóre |
| `median_score` | FLOAT | Medián skóre |
| `percentiles` | JSON | {p10, p25, p50, p75, p90} |
| `avg_category_scores` | JSON | Průměr per kategorie |
| `top_issues` | JSON | Nejčastější issues |
| `sample_size` | INT | Počet vzorků |
| `created_at` | TIMESTAMP | Čas vytvoření |

---

### CLI Command: `app:analysis:archive`

Archivuje staré analýzy podle retention policy pro úsporu místa v databázi.

```bash
# Syntaxe
bin/console app:analysis:archive [options]

# Spustit archivaci s výchozími hodnotami
bin/console app:analysis:archive

# Zobrazit pouze počty bez provedení archivace
bin/console app:analysis:archive --show-counts

# Dry run - simulace bez ukládání
bin/console app:analysis:archive --dry-run

# Custom retention periods
bin/console app:analysis:archive --compress-after=30 --clear-after=90 --delete-after=365
```

#### Retention Policy

| Stáří dat | Akce | Úspora |
|-----------|------|--------|
| 0-30 dní | Plná data | 0% |
| 30-90 dní | Komprese rawData (gzip + base64) | ~70% |
| 90-365 dní | Smazání rawData, zachovat issues | ~85% |
| 365+ dní | Smazání AnalysisResult, pouze snapshoty | ~95% |

#### Volby (Options)

| Volba | Zkratka | Popis | Default |
|-------|---------|-------|---------|
| `--compress-after` | | Dní pro kompresi rawData | 30 |
| `--clear-after` | | Dní pro smazání rawData | 90 |
| `--delete-after` | | Dní pro smazání AnalysisResult | 365 |
| `--batch-size` | `-b` | Velikost dávky pro zpracování | 100 |
| `--dry-run` | | Simulace bez ukládání | false |
| `--show-counts` | | Pouze zobrazit počty | false |

#### Doporučené cron joby

```bash
# Měsíční archivace (první den v měsíci ve 3:00)
0 3 1 * * bin/console app:analysis:archive
```

---

### Analysis REST API

Endpoints pro přístup k datům analýz, trendům a benchmarkům.

#### Lead Analysis Endpoints

```bash
# Historie analýz pro lead
GET /api/leads/{id}/analyses?limit=10&offset=0

# Response:
{
  "lead": {
    "id": "uuid",
    "domain": "example.com",
    "analysisCount": 5
  },
  "analyses": [
    {
      "id": "uuid",
      "sequenceNumber": 5,
      "status": "completed",
      "industry": "eshop",
      "totalScore": 72,
      "scoreDelta": 3,
      "isImproved": true,
      "issueCount": 12,
      "criticalIssueCount": 2,
      "issueDelta": {"added": ["SEO_001"], "removed": ["SSL_002"], "unchanged_count": 10},
      "scores": {"http": 85, "security": 70, "seo": 65},
      "createdAt": "2026-01-21T10:00:00+00:00",
      "completedAt": "2026-01-21T10:05:00+00:00"
    }
  ],
  "pagination": {
    "total": 5,
    "limit": 10,
    "offset": 0
  }
}
```

```bash
# Trending data (snapshoty) pro lead
GET /api/leads/{id}/trend?period=week&limit=52

# Parametry:
# - period: day, week, month (default: week)
# - limit: max 365 (default: 52)

# Response:
{
  "lead": {
    "id": "uuid",
    "domain": "example.com",
    "industry": "eshop"
  },
  "period": "week",
  "current": {
    "periodStart": "2026-01-20",
    "totalScore": 72,
    "scoreDelta": 3,
    "issueCount": 12,
    "criticalIssueCount": 2,
    "categoryScores": {"http": 85, "security": 70},
    "topIssues": ["SEO_001", "SSL_002"]
  },
  "trend": [
    {"periodStart": "2026-01-20", "totalScore": 72, "issueCount": 12},
    {"periodStart": "2026-01-13", "totalScore": 69, "issueCount": 14}
  ],
  "count": 2
}
```

```bash
# Porovnat lead s industry benchmarkem
GET /api/leads/{id}/benchmark

# Response:
{
  "lead": {
    "id": "uuid",
    "domain": "example.com",
    "industry": "eshop"
  },
  "analysis": {
    "id": "uuid",
    "totalScore": 72,
    "issueCount": 12,
    "completedAt": "2026-01-21T10:05:00+00:00"
  },
  "benchmark": {
    "ranking": "above_average",
    "percentile": 65.5,
    "comparison": {
      "score": 72,
      "industryAvg": 68.5,
      "industryMedian": 67.0,
      "diffFromAvg": 3.5,
      "issueCount": 12,
      "industryAvgIssues": 15.2,
      "sampleSize": 150
    }
  }
}
```

#### Industry Benchmark Endpoints

```bash
# Seznam všech odvětví s jejich benchmark statusem
GET /api/industries

# Response:
{
  "industries": [
    {
      "code": "eshop",
      "label": "E-commerce / E-shop",
      "defaultSnapshotPeriod": "day",
      "hasBenchmark": true,
      "benchmark": {
        "periodStart": "2026-01-20",
        "sampleSize": 150,
        "avgScore": 68.5
      }
    },
    {
      "code": "webdesign",
      "label": "Web Design & Development",
      "defaultSnapshotPeriod": "week",
      "hasBenchmark": false,
      "benchmark": null
    }
  ],
  "count": 10
}
```

```bash
# Detail benchmarku pro konkrétní odvětví
GET /api/industries/{industry}/benchmark

# industry: webdesign, eshop, real_estate, automobile, restaurant, medical, legal, finance, education, other

# Response:
{
  "industry": {
    "code": "eshop",
    "label": "E-commerce / E-shop"
  },
  "benchmark": {
    "periodStart": "2026-01-20",
    "sampleSize": 150,
    "avgScore": 68.5,
    "medianScore": 67.0,
    "avgIssueCount": 15.2,
    "avgCriticalIssueCount": 2.3,
    "percentiles": {
      "p10": 45.0,
      "p25": 55.0,
      "p50": 67.0,
      "p75": 78.0,
      "p90": 88.0
    },
    "avgCategoryScores": {
      "http": 82.5,
      "security": 65.0,
      "seo": 70.0,
      "performance": 60.5
    },
    "topIssues": [
      {"code": "SEO_001", "count": 120, "percentage": 80.0},
      {"code": "SSL_002", "count": 75, "percentage": 50.0}
    ]
  },
  "updatedAt": "2026-01-21T06:00:00+00:00"
}
```

```bash
# Historie benchmarků pro odvětví
GET /api/industries/{industry}/benchmark/history?periods=12

# Parametry:
# - periods: max 52 (default: 12)

# Response:
{
  "industry": {
    "code": "eshop",
    "label": "E-commerce / E-shop"
  },
  "history": [
    {
      "periodStart": "2026-01-20",
      "sampleSize": 150,
      "avgScore": 68.5,
      "medianScore": 67.0,
      "avgIssueCount": 15.2
    },
    {
      "periodStart": "2026-01-13",
      "sampleSize": 145,
      "avgScore": 67.8,
      "medianScore": 66.5,
      "avgIssueCount": 15.8
    }
  ],
  "count": 2
}
```

#### Error Responses

```bash
# Lead not found
HTTP 404
{
  "error": "Lead not found"
}

# Invalid industry
HTTP 400
{
  "error": "Invalid industry \"invalid\"",
  "available": ["webdesign", "eshop", "real_estate", ...]
}

# No benchmark data
HTTP 404
{
  "error": "No benchmark data available for industry \"webdesign\"",
  "hint": "Run `bin/console app:benchmark:calculate` to generate benchmarks"
}

# No analysis for lead
HTTP 404
{
  "error": "Lead has no analysis"
}
```

---

## Přehled CLI příkazů

### Discovery (vyhledávání leadů)

```bash
# Manuální import URL
bin/console app:lead:discover manual --url=https://example.com

# S extrakcí kontaktů a technologií
bin/console app:lead:discover manual --url=https://example.com --extract

# Google search
bin/console app:lead:discover google --query="webdesign Praha" --limit=100

# Seznam search
bin/console app:lead:discover seznam --query="restaurace Brno" --limit=50

# Firmy.cz katalog s extrakcí
bin/console app:lead:discover firmy_cz --query="kavárna" --limit=200 --extract

# Reference crawler (portfolia agentur)
bin/console app:lead:discover reference_crawler --query="webdesign" --inner-source=google --limit=50

# Crawl existujících leadů
bin/console app:lead:discover crawler --limit=100
```

### Analýza

```bash
# Analyzovat NEW leady
bin/console app:lead:analyze --limit=50

# Analyzovat s industry filtrem
bin/console app:lead:analyze --industry=eshop --limit=10

# Re-analyzovat konkrétní lead
bin/console app:lead:analyze --lead-id=uuid --reanalyze --industry=webdesign

# Dry run
bin/console app:lead:analyze --dry-run
```

### Snapshoty a benchmarky

```bash
# Generovat týdenní snapshoty
bin/console app:analysis:snapshot --period=week

# Generovat měsíční snapshoty
bin/console app:analysis:snapshot --period=month

# Zobrazit statistiky snapshotů
bin/console app:analysis:snapshot --show-stats

# Cleanup starých snapshotů
bin/console app:analysis:snapshot --cleanup --retention=52

# Přepočítat benchmarky pro všechna odvětví
bin/console app:benchmark:calculate

# Přepočítat benchmark pro konkrétní odvětví
bin/console app:benchmark:calculate --industry=eshop

# Porovnat analýzu s benchmarkem
bin/console app:benchmark:calculate --compare=analysis-uuid
```

### Archivace

```bash
# Spustit archivaci s výchozími hodnotami
bin/console app:analysis:archive

# Zobrazit pouze počty
bin/console app:analysis:archive --show-counts

# Dry run
bin/console app:analysis:archive --dry-run

# Custom retention
bin/console app:analysis:archive --compress-after=30 --clear-after=90 --delete-after=365
```

---

## Doporučené Cron Joby

```bash
# === DENNÍ ===
# Analyzovat nové leady (každý den v 1:00)
0 1 * * * bin/console app:lead:analyze --limit=100

# === TÝDENNÍ ===
# Týdenní snapshoty (každé pondělí v 2:00)
0 2 * * 1 bin/console app:analysis:snapshot --period=week

# Přepočet benchmarků (každé pondělí v 6:00)
0 6 * * 1 bin/console app:benchmark:calculate

# === MĚSÍČNÍ ===
# Měsíční snapshoty (první den v měsíci v 3:00)
0 3 1 * * bin/console app:analysis:snapshot --period=month

# Archivace starých dat (první den v měsíci v 4:00)
0 4 1 * * bin/console app:analysis:archive

# Cleanup denních snapshotů starších 90 dní
0 5 1 * * bin/console app:analysis:snapshot --cleanup --period=day --retention=90

# Cleanup týdenních snapshotů starších 52 týdnů
0 5 1 * * bin/console app:analysis:snapshot --cleanup --period=week --retention=52
```

---

## Workflow - jak systém používat

### 1. Discovery (získání leadů)

```bash
# Najít potenciální klienty přes Google
bin/console app:lead:discover google --query="webdesign Praha" --limit=100

# Nebo přes katalogy
bin/console app:lead:discover firmy_cz --query="eshop" --limit=200
```

### 2. Analýza

```bash
# Analyzovat nové leady (universal analyzátory)
bin/console app:lead:analyze --limit=50

# Nebo s industry-specific analyzátory
bin/console app:lead:analyze --industry=eshop --limit=50
```

### 3. Monitoring v čase

```bash
# Re-analyzovat lead pro sledování změn
bin/console app:lead:analyze --lead-id=uuid --reanalyze

# Generovat snapshoty pro trending
bin/console app:analysis:snapshot --period=week
```

### 4. Benchmarking

```bash
# Přepočítat industry benchmarky
bin/console app:benchmark:calculate

# Porovnat konkrétní analýzu
bin/console app:benchmark:calculate --compare=analysis-uuid
```

### 5. API přístup

```bash
# Historie analýz
curl http://localhost:7270/api/leads/{id}/analyses

# Trending data
curl http://localhost:7270/api/leads/{id}/trend?period=week

# Porovnání s benchmarkem
curl http://localhost:7270/api/leads/{id}/benchmark

# Industry benchmark
curl http://localhost:7270/api/industries/eshop/benchmark
```

---

## Multi-Tenant Architecture

Systém podporuje multi-tenant architekturu pomocí `User` entity. Všechna hlavní data (leads, companies, industry_benchmarks) jsou navázána na konkrétního uživatele.

### User Entity

```php
class User
{
    private string $code;        // Unikátní kód (lowercase, a-z0-9_-)
    private string $name;        // Zobrazované jméno
    private ?string $email;      // Volitelný email (unique)
    private bool $active;        // Aktivní/neaktivní
    private array $settings;     // JSONB pro uživatelská nastavení
}
```

### Data Isolation

- **Leads**: Doména je unikátní **v rámci uživatele** (ne globálně)
- **Companies**: IČO je unikátní **v rámci uživatele**
- **Industry Benchmarks**: Benchmarky jsou počítány **per user**

### CLI Commands s --user

Všechny příkazy pro práci s daty **vyžadují** `--user` parametr:

```bash
# Lead Discovery
bin/console app:lead:discover manual --user=webdesign --url=https://example.cz
bin/console app:lead:discover google --user=webdesign --query="webdesign Praha" --extract

# Company ARES Sync
bin/console app:company:sync-ares --user=webdesign --ico=27082440
bin/console app:company:sync-ares --user=webdesign --limit=100

# Benchmark Calculation
bin/console app:benchmark:calculate --user=webdesign
bin/console app:benchmark:calculate --user=webdesign --industry=e-commerce
```

### API Filtering

V REST API lze filtrovat podle user code:

```bash
GET /api/leads?user.code=webdesign
GET /api/companies?user.code=webdesign
GET /api/industry_benchmarks?user.code=webdesign
```

### Vytvoření nového uživatele

```bash
# Via API
curl -X POST http://localhost:7270/api/users \
  -H "Content-Type: application/json" \
  -d '{"code": "new-user", "name": "New User", "email": "user@example.com"}'
```

---

## Company & ARES Integration

Systém podporuje propojení leadů s firmami pomocí IČO a automatické doplnění firemních údajů z registru ARES.

### Koncept

```
Company (1 IČO)
├── Lead (web1.cz)
├── Lead (web2.cz)
└── Lead (eshop.cz)
```

Jedna firma může mít více webů. ARES data se načtou jednou pro firmu, ne pro každý lead.

### Company Entity

| Pole | Typ | Popis |
|------|-----|-------|
| `ico` | VARCHAR(8) | IČO firmy (unikátní per user) |
| `dic` | VARCHAR(20) | DIČ (z ARES) |
| `name` | VARCHAR(255) | Obchodní jméno |
| `legalForm` | VARCHAR(100) | Právní forma (s.r.o., a.s., ...) |
| `street`, `city`, `postalCode` | VARCHAR | Adresa sídla |
| `businessStatus` | VARCHAR(50) | Status (Aktivní, Zrušená, ...) |
| `aresData` | JSONB | Kompletní ARES response |
| `aresUpdatedAt` | TIMESTAMP | Datum poslední synchronizace |

### CLI: `app:company:sync-ares`

```bash
# Sync konkrétního IČO
bin/console app:company:sync-ares --user=webdesign --ico=27082440

# Batch sync - firmy bez ARES dat nebo starší než 30 dní
bin/console app:company:sync-ares --user=webdesign --limit=100

# Force refresh všech firem
bin/console app:company:sync-ares --user=webdesign --force-refresh --limit=50

# Dry run
bin/console app:company:sync-ares --user=webdesign --dry-run
```

### Lead Discovery s propojením na firmu

```bash
# Discovery s extrakcí kontaktů a propojením na firmy
bin/console app:lead:discover manual --user=webdesign --url=https://example.cz --extract --link-company

# Automaticky:
# 1. Extrahuje IČO z webové stránky
# 2. Vytvoří/najde Company pro toto IČO
# 3. Načte ARES data
# 4. Propojí Lead s Company
```

### ARES API Rate Limiting

- Max 500 requests/min
- Implementován delay 200ms mezi requesty
- ARES data se cachují v `ares_data` JSONB
- Refresh pouze pokud `ares_updated_at` > 30 dní

---

## Dostupná odvětví (Industry)

| Kód | Popis | Snapshot perioda |
|-----|-------|-----------------|
| `webdesign` | Web Design & Development | week |
| `eshop` | E-commerce / E-shop | day |
| `real_estate` | Real Estate | week |
| `automobile` | Automobile | week |
| `restaurant` | Restaurant & Food | week |
| `medical` | Healthcare & Medical | month |
| `legal` | Legal Services | month |
| `finance` | Finance & Insurance | week |
| `education` | Education | month |
| `other` | Other | week |
