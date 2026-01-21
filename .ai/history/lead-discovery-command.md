# Lead Discovery Command - Implementation History

## Datum implementace

2026-01 (postupně během vývoje)

## Zadání

Vytvořit Symfony console command pro vyhledávání potenciálních leadů s:
- Víceúrovňovým vyhledáváním (Google, katalogy, crawling)
- Extrakcí kontaktů (email, telefon, název firmy, IČO)
- Detekcí technologií webu (CMS, tech stack)
- Rozlišením firma má/nemá web
- Extrakcí sociálních sítí
- Deduplikací na základě IČO > domény > názvu

## Vytvořené soubory

### Enumy

- `src/Enum/LeadSource.php` - zdroje leadů (manual, google, firmy_cz, seznam, crawler, reference...)
- `src/Enum/LeadStatus.php` - workflow stavy (new, queued, analyzing, analyzed, approved, sent...)
- `src/Enum/LeadType.php` - typ leadu (website, business_without_web)

### Entity

- `src/Entity/Lead.php` - hlavní entita s IČO, CMS detekcí, kontakty, metadata
- `src/Entity/Affiliate.php` - affiliate partneři

### Repositories

- `src/Repository/LeadRepository.php`
- `src/Repository/AffiliateRepository.php`

### Extraction Services

- `src/Service/Extractor/ContactExtractorInterface.php` - interface pro extraktory
- `src/Service/Extractor/EmailExtractor.php` - extrakce emailů z HTML
- `src/Service/Extractor/PhoneExtractor.php` - extrakce českých telefonů
- `src/Service/Extractor/CompanyNameExtractor.php` - extrakce názvu firmy
- `src/Service/Extractor/IcoExtractor.php` - extrakce IČO z webu
- `src/Service/Extractor/SocialMediaExtractor.php` - extrakce FB, IG, LinkedIn
- `src/Service/Extractor/TechnologyDetector.php` - detekce CMS a technologií
- `src/Service/Extractor/PageDataExtractor.php` - kombinuje všechny extraktory
- `src/Service/Extractor/PageData.php` - DTO pro extrahovaná data

### Discovery Sources

- `src/Service/Discovery/DiscoverySourceInterface.php` - interface pro zdroje
- `src/Service/Discovery/AbstractDiscoverySource.php` - base class
- `src/Service/Discovery/DiscoveryResult.php` - DTO pro výsledky
- `src/Service/Discovery/ManualDiscoverySource.php` - ruční zadání URL
- `src/Service/Discovery/GoogleDiscoverySource.php` - Google Search
- `src/Service/Discovery/SeznamDiscoverySource.php` - Seznam.cz
- `src/Service/Discovery/FirmyCzDiscoverySource.php` - Firmy.cz katalog
- `src/Service/Discovery/ZiveFirmyDiscoverySource.php` - ZiveFirmy.cz
- `src/Service/Discovery/NajistoDiscoverySource.php` - Najisto.cz
- `src/Service/Discovery/ZlatestrankyDiscoverySource.php` - Zlatestranky.cz
- `src/Service/Discovery/CrawlerDiscoverySource.php` - crawling odkazů
- `src/Service/Discovery/ReferenceDiscoverySource.php` - reference z existujících leadů

### Commands

- `src/Command/LeadDiscoverCommand.php` - hlavní discovery command

## Klíčové implementační detaily

### Lead Entity struktura

```php
Lead
├── id: UUID (PK)
├── user_id: FK → User (NOT NULL)
├── company_id: FK → Company (nullable)
├── ico: VARCHAR(8) (nullable)
├── companyName: VARCHAR(255)
├── type: LeadType enum
├── hasWebsite: BOOLEAN
├── url: VARCHAR(500)
├── domain: VARCHAR(255) - UNIQUE per user
├── detectedCms: VARCHAR(50) - wordpress, shoptet, wix...
├── detectedTechnologies: JSON array
├── email, phone, address
├── source: LeadSource enum
├── status: LeadStatus enum
├── priority: SMALLINT (1-10)
├── metadata: JSON (all_emails, social_*, search_query...)
└── timestamps
```

### Deduplikace (priorita)

1. IČO (pokud existuje) - unique per user
2. Doména (pokud má web) - unique per user
3. company_name + address (fallback)

### Technology Detection patterns

- WordPress: `/wp-content/`, `generator.*WordPress`
- Shoptet: `/user/documents/`, `generator.*Shoptet`
- Wix: `static.wixstatic.com`
- Squarespace: `generator.*Squarespace`
- PrestaShop: `/modules/`, `generator.*PrestaShop`

### Contact Extraction priorita

Emaily: `info@`, `kontakt@` > `jmeno.prijmeni@` > ostatní

## Usage

```bash
# Google Search
bin/console app:lead:discover google --query="restaurace Praha" --limit=50

# Firmy.cz
bin/console app:lead:discover firmy_cz --query="restaurace" --location="Praha"

# Manual URL
bin/console app:lead:discover manual --url=https://example.com

# Crawler (z existujících leadů)
bin/console app:lead:discover crawler --lead-id=<uuid>

# Dry run
bin/console app:lead:discover google --query="test" --dry-run
```

## Rozšíření oproti původnímu plánu

Implementováno více discovery sources než bylo v plánu:
- SeznamDiscoverySource (nebylo v plánu)
- ZiveFirmyDiscoverySource (nebylo v plánu)
- NajistoDiscoverySource (nebylo v plánu)
- ZlatestrankyDiscoverySource (nebylo v plánu)
- ReferenceDiscoverySource (nebylo v plánu)

## Neimplementováno

- `MapyCzSource` - nízká priorita, Google pokrývá mapy
- `GoogleLinkCrawlerSource` - nahrazeno obecným `CrawlerDiscoverySource`

## Verifikace

```bash
# Schema validace
bin/console doctrine:schema:validate

# Test discovery
bin/console app:lead:discover google --query="test" --limit=5 --dry-run

# API endpoints
curl http://localhost:7270/api/leads
curl http://localhost:7270/api/affiliates
```
