# Lead Discovery Extension - Implementation History

## Zadání

Rozšíření Lead Discovery systému o:
1. **Extrakce kontaktů** - email, telefon, IČO přímo ze stránek
2. **Detekce technologií** - CMS, tech stack
3. **Rozlišení firem s/bez webu** - LeadType enum
4. **Rozšířená Lead entita** - nová pole pro kontakty a technologie

## Vytvořené soubory

### Nové soubory

**Enums:**
- `src/Enum/LeadType.php` - LeadType enum (WEBSITE, BUSINESS_WITHOUT_WEB)

**Extraction Services:**
- `src/Service/Extractor/ContactExtractorInterface.php` - Interface pro extraktory
- `src/Service/Extractor/PageData.php` - DTO pro extrahovaná data
- `src/Service/Extractor/PageDataExtractor.php` - Orchestrátor všech extraktorů
- `src/Service/Extractor/EmailExtractor.php` - Extrakce a prioritizace emailů
- `src/Service/Extractor/PhoneExtractor.php` - Extrakce českých telefonů
- `src/Service/Extractor/IcoExtractor.php` - Extrakce a validace IČO (modulo 11)
- `src/Service/Extractor/TechnologyDetector.php` - Detekce CMS a technologií
- `src/Service/Extractor/SocialMediaExtractor.php` - Extrakce sociálních sítí

**Migrations:**
- `migrations/Version20260121013200.php` - Lead Discovery Extension fields

### Aktualizované soubory

- `src/Entity/Lead.php` - Nová pole: type, hasWebsite, ico, companyName, email, phone, address, detectedCms, detectedTechnologies, socialMedia
- `src/Service/Discovery/AbstractDiscoverySource.php` - Podpora PageDataExtractor
- `src/Command/LeadDiscoverCommand.php` - Nová `--extract` / `-x` option

## Klíčové implementační detaily

### LeadType Enum

```php
enum LeadType: string
{
    case WEBSITE = 'website';
    case BUSINESS_WITHOUT_WEB = 'business_without_web';
}
```

### Lead Entity - Nová pole

| Pole | Typ | Popis |
|------|-----|-------|
| `type` | LeadType | Typ leadu (website/business_without_web) |
| `hasWebsite` | bool | Má web? (default: true) |
| `ico` | string(8) | IČO firmy |
| `companyName` | string(255) | Název firmy |
| `email` | string(255) | Primární email |
| `phone` | string(50) | Primární telefon |
| `address` | string(500) | Adresa |
| `detectedCms` | string(50) | Detekovaný CMS |
| `detectedTechnologies` | array | Detekované technologie |
| `socialMedia` | array | Sociální sítě |

### EmailExtractor - Prioritizace

1. `info@`, `kontakt@`, `objednavky@` - nejvyšší priorita
2. `sales@`, `marketing@` - střední priorita
3. Ostatní - nejnižší priorita

Ignorované domény: example.com, wixpress.com, sentry.io, atd.

### PhoneExtractor - České formáty

- `+420 123 456 789`
- `123 456 789`
- Normalizace na `+420XXXXXXXXX`
- Validace prefixů (6, 7 = mobil, 2-5 = pevná)

### IcoExtractor - Validace

- 8 číslic
- Modulo 11 kontrolní součet

### TechnologyDetector - Podporované CMS

WordPress, Shoptet, Wix, Squarespace, PrestaShop, Webnode, Shopify, Joomla, Drupal, Magento, OpenCart, Eshop-rychle, Webareal, Webgarden, Estranky, Webzdarma

### Použití

```bash
# Discovery bez extrakce (výchozí)
bin/console app:lead:discover manual --url=https://example.cz

# Discovery s extrakcí
bin/console app:lead:discover manual --url=https://example.cz --extract

# Katalogový zdroj s extrakcí
bin/console app:lead:discover firmy_cz --query="restaurace" --limit=10 --extract
```

## Checklist pro podobnou implementaci

1. [ ] Vytvořit enum pro nový typ
2. [ ] Přidat pole do entity
3. [ ] Vytvořit extractor interface
4. [ ] Implementovat jednotlivé extraktory
5. [ ] Vytvořit orchestrátor (PageDataExtractor)
6. [ ] Integrovat do discovery sources
7. [ ] Aktualizovat command
8. [ ] Vytvořit migraci
9. [ ] Otestovat syntaxi a schema

## Známé problémy a řešení

### 1. Shortcut konflikt `-e`

**Problém:** Option shortcut `-e` koliduje se Symfony env option.

**Řešení:** Změna na `-x` (extract).

### 2. Auto-generated migration obsahovala duplicity

**Problém:** `doctrine:migrations:diff` vygenerovalo migraci s existujícími tabulkami.

**Řešení:** Manuální vytvoření migrace pouze s novými poli.
