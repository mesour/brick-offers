# Domain Blacklist & Catalog Sources - Implementation History

## Zadání
- Umožnit uživatelům definovat wildcard patterny pro vyloučení domén z discovery výsledků (např. `*.firmy.cz`, `katalog.*`)
- Automaticky excludovat domény katalogových sources (firmy.cz, seznam) z výsledků discovery
- Globální scope - nastavení na úrovni User entity s dědičností od admin účtu

## Vytvořené soubory

### Nové soubory
- `src/Service/Discovery/DomainMatcher.php` - Service pro pattern matching pomocí fnmatch()
- `migrations/Version20260123100000.php` - Migrace pro přidání `excluded_domains` sloupce
- `tests/Unit/Service/Discovery/DomainMatcherTest.php` - Unit testy pro DomainMatcher

### Upravené soubory
- `src/Enum/LeadSource.php` - Přidány metody `isCatalogSource()` a `getCatalogDomain()`
- `src/Entity/User.php` - Přidáno pole `excludedDomains` s tenant inheritance
- `src/MessageHandler/DiscoverLeadsMessageHandler.php` - Přidán filtering pomocí DomainMatcher
- `src/Controller/Admin/UserCrudController.php` - Přidáno TextareaField pro excluded domains

## Klíčové implementační detaily

### DomainMatcher service
- Používá PHP `fnmatch()` s FNM_CASEFOLD pro case-insensitive matching
- Podporuje wildcard patterny: `*.example.com` (subdomény), `example.*` (TLD), `*example*` (obsahuje)
- Automaticky stripuje `www.` prefix a testuje obě varianty

### User entity - excludedDomains
```php
// Tenant inheritance - sub-users dědí patterns od admin účtu
public function getExcludedDomains(): array
{
    if ($this->adminAccount !== null) {
        return array_values(array_unique(array_merge(
            $this->adminAccount->getExcludedDomains(),
            $this->excludedDomains
        )));
    }
    return $this->excludedDomains;
}

// Text helpers pro admin UI
public function getExcludedDomainsText(): string
public function setExcludedDomainsText(?string $text): static
```

### LeadSource catalog domains
```php
public function getCatalogDomain(): ?string
{
    return match ($this) {
        self::FIRMY_CZ => 'firmy.cz',
        self::SEZNAM => 'firmy.seznam.cz',
        self::ZIVE_FIRMY => 'zivefirmy.cz',
        self::NAJISTO => 'najisto.centrum.cz',
        self::ZLATESTRANKY => 'zlatestranky.cz',
        default => null,
    };
}
```

### Discovery filtering flow
1. Načti User.excludedDomains (včetně zděděných od admina)
2. Přidej domény z LeadSource.getCatalogDomain() pro použitý source
3. Filtruj výsledky pomocí DomainMatcher před ukládáním

## Checklist pro podobnou implementaci
1. [ ] Vytvořit migrace pro nový sloupec
2. [ ] Přidat field do entity s helper metodami pro text I/O
3. [ ] Implementovat tenant inheritance pokud potřeba
4. [ ] Vytvořit service pro business logic
5. [ ] Přidat field do Admin CRUD controlleru
6. [ ] Napsat unit testy

## Verifikace
1. Přidat excluded domain pattern v User admin (např. `*.testovaci.cz`)
2. Spustit discovery (batch nebo manuální)
3. Ověřit v logu že domény byly vyfiltrovány
4. Ověřit že domény katalogových sources nejsou ve výsledcích
5. Otestovat wildcards: `*.test.cz`, `test.*`, `*test*`
