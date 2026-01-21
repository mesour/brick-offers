# Lead Discovery Command - Implementation Plan

## Shrnutí požadavků

- Vytvořit Symfony console command pro vyhledávání potenciálních leadů
- **Víceúrovňové vyhledávání:**
  1. Google Search výsledky
  2. Prohledávání odkazů z výsledků
  3. Prohledávání firem (i bez webu)
- **Extrakce kontaktů:** email, telefon, název firmy přímo ze stránek
- **IČO jako primární identifikátor** pro deduplikaci českých firem
- **Detekce technologií webu** (WordPress, Shoptet, vlastní CMS)
- **Rozlišení:** firma má/nemá web
- **Sociální sítě:** ukládat do metadata (Facebook, Instagram, LinkedIn)
- Ukládání do PostgreSQL přes Doctrine ORM
- Synchronní zpracování (paralelizace později)
- Deduplikace na základě IČO > domény > názvu firmy
- API endpointy přes API Platform

## Analýza současného stavu

- Fresh Symfony 7.3 + API Platform 4.2 projekt
- PostgreSQL 15.6 v Dockeru
- `src/Entity/` je prázdný - žádné entity
- `src/Command/` je prázdný - žádné commandy
- Dokumentace v `docs/navrh-aplikace.md` definuje strukturu

---

## Implementační plán

### Fáze 1: Foundation - Entity a Enumy

- [ ] Vytvořit `src/Enum/LeadSource.php` (manual, google, google_link, firmy_cz, mapy_cz, crawler)
- [ ] Vytvořit `src/Enum/LeadStatus.php` (new, queued, analyzing, analyzed, approved, sent, responded, converted)
- [ ] Vytvořit `src/Enum/LeadType.php` (website, business_without_web)
- [ ] Vytvořit `src/Entity/Affiliate.php`
- [ ] Vytvořit `src/Entity/Lead.php` s rozšířenými poli
- [ ] Vytvořit `src/Repository/LeadRepository.php`
- [ ] Vytvořit `src/Repository/AffiliateRepository.php`
- [ ] Vygenerovat a spustit migraci

### Fáze 2: Extraction Services

- [ ] Vytvořit `src/Service/Extractor/ContactExtractorInterface.php`
- [ ] Vytvořit `src/Service/Extractor/EmailExtractor.php`
- [ ] Vytvořit `src/Service/Extractor/PhoneExtractor.php`
- [ ] Vytvořit `src/Service/Extractor/CompanyNameExtractor.php`
- [ ] Vytvořit `src/Service/Extractor/IcoExtractor.php` (extrakce IČO z webu/ARES)
- [ ] Vytvořit `src/Service/Extractor/SocialMediaExtractor.php` (FB, IG, LinkedIn)
- [ ] Vytvořit `src/Service/Extractor/TechnologyDetector.php` (CMS/tech stack)
- [ ] Vytvořit `src/Service/Extractor/PageDataExtractor.php` (kombinuje všechny)

### Fáze 3: Discovery Command

- [ ] Vytvořit `src/Service/Discovery/DiscoveryResult.php` DTO
- [ ] Vytvořit `src/Service/Discovery/DiscoverySourceInterface.php`
- [ ] Vytvořit `src/Service/Discovery/AbstractDiscoverySource.php`
- [ ] Vytvořit `src/Command/LeadDiscoverCommand.php`

### Fáze 4: Source Integrace

- [ ] Vytvořit `src/Service/Discovery/ManualDiscoverySource.php`
- [ ] Vytvořit `src/Service/Discovery/GoogleSearchSource.php` (Google výsledky)
- [ ] Vytvořit `src/Service/Discovery/GoogleLinkCrawlerSource.php` (prohledávání odkazů z Google)
- [ ] Vytvořit `src/Service/Discovery/FirmyCzSource.php` (firmy s i bez webu)
- [ ] Vytvořit `src/Service/Discovery/MapyCzSource.php`

### Fáze 5: API Layer

- [ ] Přidat API Platform atributy na Lead entity
- [ ] Přidat API Platform atributy na Affiliate entity
- [ ] Vytvořit `src/Controller/LeadImportController.php` pro bulk import

### Fáze 6: Konfigurace a Testování

- [ ] Přidat env variables do `.env`
- [ ] Aktualizovat `config/services.yaml`
- [ ] Napsat testy

---

## Lead Entity - Rozšířená struktura

```php
#[ORM\Entity]
#[ORM\Table(name: 'leads')]
#[ORM\UniqueConstraint(name: 'uniq_leads_ico', columns: ['ico'])]
#[ORM\UniqueConstraint(name: 'uniq_leads_domain', columns: ['domain'])]
class Lead
{
    // Identifikace
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $id = null;

    // === FIREMNÍ IDENTIFIKACE ===

    // IČO - primární identifikátor pro deduplikaci (8 číslic)
    #[ORM\Column(length: 8, nullable: true, unique: true)]
    private ?string $ico = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $companyName = null;

    // === TYP A WEB ===

    #[ORM\Column(enumType: LeadType::class)]
    private LeadType $type = LeadType::WEBSITE;

    #[ORM\Column]
    private bool $hasWebsite = true;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domain = null;

    // === DETEKCE TECHNOLOGIÍ ===

    // Detekovaný CMS/platforma (wordpress, shoptet, wix, squarespace, custom, null)
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $detectedCms = null;

    // Detekované technologie (array: ["php", "jquery", "bootstrap", ...])
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $detectedTechnologies = null;

    // === KONTAKTNÍ ÚDAJE ===

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $address = null;

    // === ZDROJ A STAV ===

    #[ORM\Column(enumType: LeadSource::class)]
    private LeadSource $source;

    #[ORM\Column(enumType: LeadStatus::class)]
    private LeadStatus $status = LeadStatus::NEW;

    #[ORM\Column(type: 'smallint')]
    private int $priority = 5;

    #[ORM\ManyToOne(targetEntity: Affiliate::class)]
    private ?Affiliate $affiliate = null;

    // === FLEXIBILNÍ METADATA ===

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;
    // metadata obsahuje:
    // - search_query: vyhledávací dotaz
    // - search_position: pozice ve výsledcích
    // - discovered_from_url: URL ze které byl objeven
    // - firmy_cz_id: ID na Firmy.cz
    // - category: kategorie firmy
    // - all_emails: array všech nalezených emailů
    // - all_phones: array všech nalezených telefonů
    // - social_facebook: URL Facebook stránky
    // - social_instagram: URL Instagram profilu
    // - social_linkedin: URL LinkedIn profilu
    // - ssl_valid: bool - má platný SSL certifikát
    // - page_title: title tag webu

    // === TIMESTAMPS ===

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;
}
```

---

## LeadSource Enum

```php
enum LeadSource: string
{
    case MANUAL = 'manual';           // Ruční zadání
    case GOOGLE = 'google';           // Google Search API výsledky
    case GOOGLE_LINK = 'google_link'; // Odkaz nalezený na Google výsledku
    case FIRMY_CZ = 'firmy_cz';       // Firmy.cz katalog
    case MAPY_CZ = 'mapy_cz';         // Mapy.cz / Google Maps
    case CRAWLER = 'crawler';         // Web crawler z existujících leadů
}
```

---

## LeadType Enum

```php
enum LeadType: string
{
    case WEBSITE = 'website';                      // Firma s webem
    case BUSINESS_WITHOUT_WEB = 'business_without_web'; // Firma bez webu
}
```

---

## Víceúrovňové vyhledávání - Flow

```
┌─────────────────────────────────────────────────────────────────┐
│  Level 1: Google Search                                         │
│  Query: "restaurace Praha"                                      │
│                                                                 │
│  → Výsledek 1: restaurace-ukocoura.cz                          │
│  → Výsledek 2: tripadvisor.cz/restaurace-praha                 │
│  → Výsledek 3: firmy.cz/restaurace/praha                       │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Level 2A: Crawl Google Results                                 │
│                                                                 │
│  restaurace-ukocoura.cz:                                       │
│    → Extrahuj: email, telefon, název firmy                     │
│    → Ulož jako Lead (type: WEBSITE, source: GOOGLE)            │
│                                                                 │
│  tripadvisor.cz stránka:                                       │
│    → Najdi odkazy na restaurace                                │
│    → Pro každý odkaz: ulož jako Lead (source: GOOGLE_LINK)     │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Level 2B: Firmy.cz / Katalogy                                 │
│                                                                 │
│  Firma s webem:                                                │
│    → Ulož jako Lead (type: WEBSITE, source: FIRMY_CZ)         │
│    → hasWebsite = true                                         │
│                                                                 │
│  Firma BEZ webu:                                               │
│    → Ulož jako Lead (type: BUSINESS_WITHOUT_WEB)              │
│    → hasWebsite = false                                        │
│    → url = null, domain = null                                │
│    → companyName, email, phone, address vyplněno               │
└─────────────────────────────────────────────────────────────────┘
```

---

## Contact Extraction Service

```php
interface ContactExtractorInterface
{
    public function extract(string $html): array;
}

class PageContactExtractor
{
    public function extractAll(string $html): ContactInfo
    {
        return new ContactInfo(
            emails: $this->emailExtractor->extract($html),
            phones: $this->phoneExtractor->extract($html),
            companyName: $this->companyNameExtractor->extract($html),
        );
    }
}

// ContactInfo DTO
readonly class ContactInfo
{
    public function __construct(
        public array $emails,      // Všechny nalezené emaily
        public array $phones,      // Všechny nalezené telefony
        public ?string $companyName,
        public ?string $primaryEmail = null,   // Nejlepší email
        public ?string $primaryPhone = null,   // Nejlepší telefon
    ) {}
}
```

### Email Extraction Patterns

```php
// Regex patterns pro české emaily
$patterns = [
    '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
    '/mailto:([^"\'>\s]+)/',
];

// Priorita emailů (od nejvyšší):
// 1. info@, kontakt@, objednavky@
// 2. jmeno.prijmeni@
// 3. ostatní
```

### Phone Extraction Patterns

```php
// Regex patterns pro české telefony
$patterns = [
    '/\+420[\s]?[0-9]{3}[\s]?[0-9]{3}[\s]?[0-9]{3}/',  // +420 123 456 789
    '/[0-9]{3}[\s]?[0-9]{3}[\s]?[0-9]{3}/',            // 123 456 789
    '/tel[:\s]+([+0-9\s-]+)/',                          // tel: ...
];
```

---

## Technology Detection Service

```php
class TechnologyDetector
{
    // CMS Detection patterns
    private const CMS_PATTERNS = [
        'wordpress' => [
            'meta' => 'generator.*WordPress',
            'url' => '/wp-content/',
            'header' => 'X-Powered-By.*PHP',
        ],
        'shoptet' => [
            'meta' => 'generator.*Shoptet',
            'url' => '/user/documents/',
            'script' => 'shoptet',
        ],
        'wix' => [
            'meta' => 'generator.*Wix',
            'url' => 'static.wixstatic.com',
        ],
        'squarespace' => [
            'meta' => 'generator.*Squarespace',
            'url' => 'squarespace.com',
        ],
        'prestashop' => [
            'meta' => 'generator.*PrestaShop',
            'url' => '/modules/',
        ],
    ];

    public function detect(string $html, array $headers): TechnologyInfo
    {
        return new TechnologyInfo(
            cms: $this->detectCms($html, $headers),
            technologies: $this->detectTechnologies($html, $headers),
        );
    }
}

readonly class TechnologyInfo
{
    public function __construct(
        public ?string $cms,           // wordpress, shoptet, wix, ...
        public array $technologies,    // ["php", "jquery", "bootstrap", ...]
    ) {}
}
```

---

## IČO Extraction & Validation

```php
class IcoExtractor
{
    // Regex pro IČO (8 číslic)
    private const ICO_PATTERN = '/IČO?[:\s]*(\d{8})/i';
    private const ICO_PATTERN_ALT = '/(?:IČ|ICO|IC)[:\s]*(\d{8})/i';

    // ARES API pro ověření
    private const ARES_API = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/';

    public function extract(string $html): ?string
    {
        // 1. Hledej IČO na stránce
        if (preg_match(self::ICO_PATTERN, $html, $matches)) {
            return $this->validate($matches[1]) ? $matches[1] : null;
        }
        return null;
    }

    public function fetchFromAres(string $ico): ?array
    {
        // Vrací: name, address, legal_form, ...
    }
}
```

---

## Command Usage - Rozšířené

```bash
# === LEVEL 1: Google Search ===
# Vyhledá na Google a uloží přímé výsledky
php bin/console app:lead:discover google --query="restaurace Praha" --limit=50

# === LEVEL 2A: Crawl Google výsledky ===
# Pro každý Google výsledek: navštíví stránku, extrahuje kontakty a odkazy
php bin/console app:lead:discover google --query="restaurace Praha" --crawl-results

# === LEVEL 2B: Firmy.cz ===
# Vyhledá firmy v katalogu (včetně těch bez webu)
php bin/console app:lead:discover firmy_cz --query="restaurace" --location="Praha"

# === Kombinované ===
# Všechny úrovně najednou
php bin/console app:lead:discover google --query="restaurace Praha" --crawl-results --include-directories

# === Filtry ===
# Pouze firmy bez webu (pro nabídku tvorby webu)
php bin/console app:lead:discover firmy_cz --query="restaurace" --without-web-only

# === Dry run ===
php bin/console app:lead:discover google --query="test" --dry-run
```

---

## Klíčové soubory k vytvoření

```
src/
├── Enum/
│   ├── LeadSource.php           # VYTVOŘIT
│   ├── LeadStatus.php           # VYTVOŘIT
│   └── LeadType.php             # VYTVOŘIT
├── Entity/
│   ├── Lead.php                 # VYTVOŘIT (s IČO, detectedCms, detectedTechnologies)
│   └── Affiliate.php            # VYTVOŘIT
├── Repository/
│   ├── LeadRepository.php       # VYTVOŘIT
│   └── AffiliateRepository.php  # VYTVOŘIT
├── Command/
│   └── LeadDiscoverCommand.php  # VYTVOŘIT
├── Controller/
│   └── LeadImportController.php # VYTVOŘIT
└── Service/
    ├── Discovery/
    │   ├── DiscoverySourceInterface.php  # VYTVOŘIT
    │   ├── DiscoveryResult.php           # VYTVOŘIT
    │   ├── AbstractDiscoverySource.php   # VYTVOŘIT
    │   ├── ManualDiscoverySource.php     # VYTVOŘIT
    │   ├── GoogleSearchSource.php        # VYTVOŘIT
    │   ├── GoogleLinkCrawlerSource.php   # VYTVOŘIT
    │   ├── FirmyCzSource.php             # VYTVOŘIT
    │   └── MapyCzSource.php              # VYTVOŘIT
    └── Extractor/
        ├── ContactExtractorInterface.php # VYTVOŘIT
        ├── EmailExtractor.php            # VYTVOŘIT
        ├── PhoneExtractor.php            # VYTVOŘIT
        ├── CompanyNameExtractor.php      # VYTVOŘIT
        ├── IcoExtractor.php              # VYTVOŘIT (IČO z webu/ARES)
        ├── SocialMediaExtractor.php      # VYTVOŘIT (FB, IG, LinkedIn)
        ├── TechnologyDetector.php        # VYTVOŘIT (CMS/tech detection)
        └── PageDataExtractor.php         # VYTVOŘIT (kombinuje všechny)
```

---

## Verifikace

1. **Migrace:**
   ```bash
   bin/console doctrine:migrations:migrate
   bin/console doctrine:schema:validate
   ```

2. **Command Test - Základní:**
   ```bash
   # Dry run s Google
   bin/console app:lead:discover google --query="test" --limit=5 --dry-run
   ```

3. **Command Test - S crawlingem:**
   ```bash
   # Crawl výsledky a extrahuj kontakty
   bin/console app:lead:discover google --query="restaurace Praha" --limit=10 --crawl-results --dry-run
   ```

4. **Command Test - Firmy bez webu:**
   ```bash
   # Najdi firmy bez webu
   bin/console app:lead:discover firmy_cz --query="restaurace" --without-web-only --dry-run
   ```

5. **Ověření v DB:**
   ```sql
   -- Firmy s webem a IČO
   SELECT ico, company_name, domain, detected_cms, email, phone
   FROM leads WHERE has_website = true;

   -- Firmy bez webu
   SELECT ico, company_name, email, phone, address
   FROM leads WHERE has_website = false;

   -- Statistiky CMS
   SELECT detected_cms, COUNT(*) FROM leads
   WHERE detected_cms IS NOT NULL GROUP BY detected_cms;

   -- Statistiky zdrojů
   SELECT source, COUNT(*) FROM leads GROUP BY source;
   ```

6. **Test Technology Detection:**
   ```bash
   # Test detekce CMS
   bin/console app:lead:discover manual --url=https://wordpress-site.cz --dry-run
   # Očekávaný výstup: detectedCms: wordpress
   ```

7. **Test IČO extrakce:**
   ```bash
   # Test extrakce IČO
   bin/console app:lead:discover manual --url=https://firma-s-ico.cz --dry-run
   # Očekávaný výstup: ico: 12345678
   ```

---

## Klíčová rozhodnutí

1. **Deduplikace (priorita):**
   - 1. IČO (pokud existuje) - unique constraint
   - 2. Doména (pokud má web) - unique constraint
   - 3. company_name + address (fallback pro firmy bez webu a IČO)

2. **IČO:** 8-místný string, primární identifikátor českých firem, možnost ověření přes ARES API

3. **LeadType:** Rozlišení `website` vs `business_without_web` pro různé sales strategie

4. **Technology Detection:**
   - `detectedCms`: wordpress, shoptet, wix, squarespace, prestashop, custom, null
   - `detectedTechnologies`: ["php", "jquery", "bootstrap", "react", ...]
   - Detekce přes HTTP headers, meta tagy, známé URL patterns

5. **Sociální sítě:** Ukládány do metadata (social_facebook, social_instagram, social_linkedin)

6. **Synchronní zpracování:** Jednoduchý sync command, paralelizace přes Symfony Messenger později

7. **Contact Extraction:** Extrakce emailů/telefonů přímo při discovery, ne až při analysis

8. **Multi-level crawling:** Google → Crawl results → Extract links

9. **Metadata:** JSONB pro flexibilní ukládání (all_emails, all_phones, social_*, ssl_valid, ...)

10. **Priority emailů:** info@, kontakt@ > jmeno.prijmeni@ > ostatní
