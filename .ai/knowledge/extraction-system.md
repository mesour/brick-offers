# Extraction System - Knowledge Base

## Přehled

Systém pro extrakci kontaktních údajů a detekci technologií z webových stránek.

## Architektura

```
src/Service/Extractor/
├── ContactExtractorInterface.php   # Interface pro extraktory
├── PageData.php                    # DTO pro extrahovaná data
├── PageDataExtractor.php           # Orchestrátor všech extraktorů
├── EmailExtractor.php              # Extrakce emailů s prioritizací
├── PhoneExtractor.php              # České telefonní formáty
├── IcoExtractor.php                # Extrakce a validace IČO
├── TechnologyDetector.php          # CMS a tech stack detection
└── SocialMediaExtractor.php        # Sociální sítě
```

## Komponenty

### EmailExtractor

**Funkce:** Extrahuje emaily z HTML, prioritizuje podle typu.

**Prioritizace:**
1. `info@`, `kontakt@`, `contact@`, `objednavky@`, `obchod@`, `office@`, `podpora@`, `support@`
2. `sales@`, `marketing@`, `fakturace@`, `uctarna@`, `hr@`, `servis@`
3. Ostatní (osobní emaily)

**Ignorované domény:**
- example.com, domain.tld, wixpress.com, sentry.io, placeholder.com, test.com, localhost

**Patterns:**
```php
'/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/i'
'/mailto:([^"\'>\s?]+)/i'
```

### PhoneExtractor

**Funkce:** Extrahuje české telefony, normalizuje na +420 formát.

**Podporované formáty:**
- `+420 123 456 789`
- `00420 123 456 789`
- `123 456 789`
- `123456789`
- `tel:+420123456789`

**Validace:**
- Prefix 6, 7 = mobil
- Prefix 2-5 = pevná linka
- Odmítá fake čísla (111111111, 123456789)

**Output:** `+420XXXXXXXXX`

### IcoExtractor

**Funkce:** Extrahuje a validuje české IČO (8 číslic).

**Patterns:**
```php
'/IČO?\s*[:\s]\s*(\d{8})/iu'
'/identifikační číslo\s*[:\s]\s*(\d{8})/iu'
'/company\s*id\s*[:\s]\s*(\d{8})/iu'
```

**Validace:** Modulo 11 kontrolní součet
```php
// Weights: 8, 7, 6, 5, 4, 3, 2
$sum = d1*8 + d2*7 + d3*6 + d4*5 + d5*4 + d6*3 + d7*2
$check = (11 - ($sum % 11)) % 11  // Speciální pravidla pro 0, 1
```

### TechnologyDetector

**Funkce:** Detekuje CMS a technologie z HTML a HTTP headers.

**Podporované CMS:**
| CMS | Detection patterns |
|-----|-------------------|
| WordPress | `/wp-content/`, `/wp-includes/`, meta generator |
| Shoptet | `cdn.myshoptet.com`, `/user/documents/` |
| Wix | `static.wixstatic.com`, `_wix_` |
| Squarespace | `sqsp.net`, `squarespace.com` |
| PrestaShop | `/modules/`, meta generator |
| Webnode | `webnode.cz`, `webnode.com` |
| Shopify | `cdn.shopify.com`, `myshopify.com` |
| Joomla | `/media/jui/`, meta generator |
| Drupal | `/sites/default/`, x-drupal-cache header |
| Magento | `/static/frontend/`, x-magento headers |
| OpenCart | `catalog/view/javascript` |
| Eshop-rychle | `eshop-rychle.cz` |
| Webareal | `webareal.cz` |
| Webgarden | `webgarden.cz` |
| Estranky | `estranky.cz` |
| Webzdarma | `webzdarma.cz` |

**Detekované technologie:**
- Frontend: jQuery, Bootstrap, React, Vue, Angular, Tailwind
- Analytics: Google Analytics, GTM, Facebook Pixel, Hotjar, Matomo
- Tools: reCAPTCHA, Cloudflare, Font Awesome, Google Fonts
- Frameworks: Next.js, Nuxt, Gatsby, Laravel, Symfony
- Libraries: Swiper, Slick, Lightbox, Fancybox, GSAP, Three.js

### SocialMediaExtractor

**Funkce:** Extrahuje odkazy na sociální sítě.

**Podporované platformy:**
- Facebook
- Instagram
- LinkedIn (company, profile)
- Twitter/X
- YouTube (channel, @handle)
- TikTok
- Pinterest

**Filtrování:** Ignoruje share buttons, dialog linky, generické stránky.

### PageDataExtractor

**Funkce:** Orchestrátor - kombinuje všechny extraktory.

**Metody:**
```php
// Z HTML
extract(string $html, array $headers = []): PageData

// Z URL (HTTP fetch)
extractFromUrl(string $url): ?PageData
```

### PageData DTO

```php
readonly class PageData
{
    public function __construct(
        public array $emails = [],
        public array $phones = [],
        public ?string $ico = null,
        public ?string $cms = null,
        public array $technologies = [],
        public array $socialMedia = [],
        public ?string $companyName = null,
        public ?string $address = null,
    ) {}

    public function getPrimaryEmail(): ?string;
    public function getPrimaryPhone(): ?string;
    public function hasContactData(): bool;
    public function hasTechnologyData(): bool;
    public function toMetadata(): array;
}
```

## Integrace s Discovery

### AbstractDiscoverySource

Všechny discovery sources dědí z `AbstractDiscoverySource` a mohou používat extrakci:

```php
// V discovery source
$this->setPageDataExtractor($extractor);
$this->setExtractionEnabled(true);

// Při vytváření výsledku
$result = $this->createResultWithExtraction($url, $metadata);
```

### LeadDiscoverCommand

```bash
# Bez extrakce (výchozí)
bin/console app:lead:discover manual --url=https://example.cz

# S extrakcí
bin/console app:lead:discover manual --url=https://example.cz --extract
bin/console app:lead:discover firmy_cz --query="restaurace" --limit=10 --extract
```

## Lead Entity - Extrahovaná data

| Pole | Zdroj | Popis |
|------|-------|-------|
| `email` | EmailExtractor | Primární email (nejvyšší priorita) |
| `phone` | PhoneExtractor | Primární telefon |
| `ico` | IcoExtractor | IČO firmy (validované) |
| `companyName` | metadata/business_name | Název firmy |
| `address` | metadata | Adresa |
| `detectedCms` | TechnologyDetector | CMS (wordpress, shoptet, ...) |
| `detectedTechnologies` | TechnologyDetector | Array technologií |
| `socialMedia` | SocialMediaExtractor | {facebook: url, instagram: url, ...} |

## Metadata struktura

Extrakce ukládá do Lead.metadata:
```json
{
  "extracted_emails": ["info@example.cz", "sales@example.cz"],
  "extracted_phones": ["+420123456789"],
  "extracted_ico": "12345678",
  "detected_cms": "wordpress",
  "detected_technologies": ["jquery", "bootstrap", "google_analytics"],
  "social_media": {
    "facebook": "https://facebook.com/example",
    "instagram": "https://instagram.com/example"
  }
}
```

## Budoucí rozšíření (nerealizováno)

- ARES API integrace pro ověření IČO a získání dalších dat
- GoogleLinkCrawlerSource pro víceúrovňové crawlování
- MapyCzSource pro lokální firmy
- `--crawl-results` option pro crawlování odkazů z Google výsledků
- `--without-web-only` option pro filtrování firem bez webu
