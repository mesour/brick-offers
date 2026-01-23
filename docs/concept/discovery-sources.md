# Discovery Sources

Přehled zdrojů pro získávání leadů (potenciálních klientů).

## Implementované zdroje (8)

| # | Source | Třída | Popis | Rate Limit |
|---|--------|-------|-------|------------|
| 1 | MANUAL | `ManualDiscoverySource` | Ruční zadání URL | N/A |
| 2 | GOOGLE | `GoogleDiscoverySource` | Google Custom Search API | 100 ms |
| 3 | SEZNAM | `SeznamDiscoverySource` | Seznam.cz vyhledávání (HTML scraping) | 1000 ms |
| 4 | FIRMY_CZ | `FirmyCzDiscoverySource` | Firmy.cz katalog (JSON-LD + fallback) | 2000 ms |
| 5 | ZIVE_FIRMY | `ZiveFirmyDiscoverySource` | Živéfirmy.cz s kategoriemi | 2500 ms |
| 6 | NAJISTO | `NajistoDiscoverySource` | Najisto.centrum.cz | 2000 ms |
| 7 | ZLATESTRANKY | `ZlatestrankyDiscoverySource` | Zlatéstránky.cz | 2000 ms |
| 8 | CRAWLER | `CrawlerDiscoverySource` | Web crawler - extrahuje linky z analyzovaných leadů | 2000 ms |

## Návrhy dalších zdrojů

### Vysoká priorita (české B2B katalogy)

| Zdroj | Popis | Výhoda |
|-------|-------|--------|
| **Mapy.cz** | Mapy.cz firmy | Lokální firmy s kontakty |
| **Google Places API** | Business listings | Reviews, otevírací doba, kvalitní data |
| **ARES** | Registr ekonomických subjektů | IČO → web, spolehlivá státní data |
| **Heureka.cz** | Katalog e-shopů | Cílení na e-commerce segment |

### Střední priorita (specializované katalogy)

| Zdroj | Popis | Vhodné pro |
|-------|-------|------------|
| **Sreality.cz** | Realitní kanceláře | RK weby |
| **Booking/Airbnb** | Ubytování | Hotely, penziony |
| **TripAdvisor** | Turistické služby | Restaurace, atrakce |
| **Horeca.cz** | Gastronomie a hotely | HoReCa segment |
| **StartupJobs.cz** | Startupy | Tech firmy |

### Technické zdroje (proaktivní discovery)

| Zdroj | Popis | Jak využít |
|-------|-------|------------|
| **Nově registrované domény** | CZ.NIC data / Whois | Firmy s novým webem (potřebují služby) |
| **Certificate Transparency** | Nové SSL certifikáty | Detekce nových webů |
| **BuiltWith/Wappalyzer** | Weby podle technologie | Např. "všechny WordPress weby v ČR" |

### Doplňkové zdroje

| Zdroj | Popis |
|-------|-------|
| **Europages.cz** | B2B mezinárodní katalog |
| **Kompass** | B2B databáze |
| **Oborové asociace** | HK ČR, profesní komory (členské seznamy) |
| **LinkedIn Company Search** | Firemní profily (API omezení) |

## Doporučená priorita implementace

Pro use case (outreach pro webdesign/IT služby):

1. **ARES** - spolehlivý státní zdroj s IČO, lze napojit na další registry
2. **Nově registrované domény** - firmy s čerstvým webem jsou ideální cíl
3. **Heureka.cz** - e-shopy často potřebují vylepšení UX/designu
4. **Mapy.cz** - již plánované, doplní Google

## Technické poznámky

### Architektura

Všechny discovery sources implementují `DiscoverySourceInterface` a jsou registrovány přes Symfony DI tag `app.discovery_source`.

### Přidání nového zdroje

1. Vytvořit třídu v `src/Service/Discovery/`
2. Implementovat `DiscoverySourceInterface`
3. Přidat case do `src/Enum/LeadSource.php`
4. Třída se automaticky zaregistruje díky `#[AutoconfigureTag]`

### Společné metody (AbstractDiscoverySource)

- `rateLimit()` - delay mezi requesty
- `normalizeUrl()` - přidá https://
- `extractUrlsFromHtml()` - regex extrakce odkazů
- `isValidWebsiteUrl()` - filtruje sociální sítě a vyhledávače
- `extractPageData()` - extrakce emailů, telefonů, technologií z webu

### Contact Page Crawling

Při extrakci dat z webu se automaticky prohledávají **kontaktní stránky**:

1. Stáhne se hlavní stránka
2. Pokud na ní není email, hledají se odkazy na kontaktní stránky:
   - CZ: `/kontakt`, `/kontakty`, `/o-nas`, `/napiste-nam`
   - EN: `/contact`, `/about`, `/contact-us`, `/about-us`
   - DE: `/kontakt`, `/impressum`
3. Navštíví se až 3 kontaktní stránky a extrahují se emaily/telefony
4. Výsledky se sloučí

Toto chování lze vypnout:
```php
$source->setContactPageCrawlingEnabled(false);
```
