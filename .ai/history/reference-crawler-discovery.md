# Reference Crawler Discovery Source - Implementation History

## Zadání

Vytvořit nový discovery source `REFERENCE_CRAWLER`, který:
1. Hledá firmy v daném oboru (např. "webdesign agentura") pomocí existujících sources
2. Prochází jejich weby a hledá stránky s referencemi/portfoliem
3. Extrahuje URL klientských webů z těchto stránek
4. Vrací je jako nové leady

**Use case:** Když zadám "webdesign", najde webdesign agentury, projde jejich reference a získá weby jejich klientů - ti jsou ideální cíl (už investovali do webu, mohou potřebovat vylepšení).

## Vytvořené soubory

### Nové soubory
- `src/Service/Discovery/ReferenceDiscoverySource.php` - Hlavní logika crawleru (~500 řádků)

### Upravené soubory
- `src/Enum/LeadSource.php` - Přidána hodnota `REFERENCE_CRAWLER = 'reference_crawler'`
- `src/Command/LeadDiscoverCommand.php` - Přidána `--inner-source` option

## Klíčové implementační detaily

### Architektura

```
Query: "webdesign agentura"
         │
         ▼
┌─────────────────────────────────┐
│  ReferenceDiscoverySource       │
│  1. Použije existující source   │
│     (Google/Seznam/Firmy.cz)    │
│  2. Získá URL agentur           │
└─────────────────────────────────┘
         │
         ▼ Pro každou agenturu:
┌─────────────────────────────────┐
│  Crawl hlavní stránky           │
│  - Hledá odkazy na:             │
│    /reference, /portfolio,      │
│    /nase-prace, /klienti, atd.  │
└─────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│  Crawl referenční stránky       │
│  - Extrahuje externí linky      │
│  - Filtruje: sociální sítě,     │
│    stock foto, frameworky       │
└─────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────┐
│  DiscoveryResult[]              │
│  - URL klientského webu         │
│  - Metadata: odkud, agentura    │
└─────────────────────────────────┘
```

### Klíčové konstanty

**REFERENCE_PATTERNS** - URL paths pro detekci referenčních stránek:
- `reference`, `portfolio`, `nase-prace`, `our-work`, `klienti`, `clients`
- `projects`, `projekty`, `case-study`, `realizace`, `showcases`

**SKIP_DOMAINS** - Domény k ignorování při extrakci klientů:
- Sociální sítě (Facebook, Twitter, Reddit, LinkedIn, etc.)
- Stock foto (Unsplash, Pexels, Shutterstock)
- CMS/Frameworky (WordPress, Wix, Squarespace, Webnode)
- CDN a služby (Cloudflare, Google APIs)
- Review/job portály (Trustpilot, Jobs.cz)

### Metadata pro leady

Každý lead obsahuje:
```json
{
  "source_type": "reference_crawler",
  "inner_source": "google",
  "agency_url": "https://webdesign-agentura.cz",
  "agency_domain": "webdesign-agentura.cz",
  "found_on_page": "https://webdesign-agentura.cz/reference",
  "query": "webdesign agentura"
}
```

### Rate limiting
- 2000ms mezi requesty (konzervativní)
- Max 5 stránek na agenturu
- Max 20 agentur z inner source

## Použití

```bash
# Výchozí (Google)
bin/console app:lead:discover reference_crawler \
  --query="webdesign agentura" --limit=50

# S jiným inner source
bin/console app:lead:discover reference_crawler \
  --query="webdesign" --inner-source=firmy_cz --limit=30

# Dry run
bin/console app:lead:discover reference_crawler \
  --query="webdesign" --dry-run -v
```

### Podporované inner sources
- `google` (výchozí)
- `seznam`
- `firmy_cz`
- `zive_firmy`
- `najisto`
- `zlatestranky`

## Známé problémy a řešení

### URL příliš dlouhé pro databázi (VARCHAR 500)

**Problém:** Některé extrahované URL překračovaly 500 znaků.

**Řešení:** Přidána metoda `truncateUrl()` která:
1. Nejprve odstraní fragment (#)
2. Pak query string (?)
3. Jako poslední resort vrátí jen base URL

### Nerelevantní domény v extrakci

**Problém:** Extrahované URL obsahovaly Reddit, Trustpilot, atd.

**Řešení:** Rozšířen SKIP_DOMAINS o:
- Reddit domény
- Review portály (Trustpilot)
- Webnode subdomény
- Accessibility widgety (Accessiway)

## Checklist pro podobnou implementaci

1. [ ] Přidat enum hodnotu do `LeadSource.php`
2. [ ] Vytvořit třídu extending `AbstractDiscoverySource`
3. [ ] Přidat `#[AutoconfigureTag('app.discovery_source')]`
4. [ ] Implementovat `supports()`, `getSource()`, `discover()`
5. [ ] Pokud potřeba custom options, upravit `LeadDiscoverCommand`
6. [ ] Přidat relevantní domény do SKIP_DOMAINS
7. [ ] Testovat s `--dry-run -v`
8. [ ] Ověřit rate limiting
