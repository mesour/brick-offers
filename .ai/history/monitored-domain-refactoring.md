# MonitoredDomain Architecture Refactoring - Implementation History

## Zadani

Refaktoring MonitoredDomain architektury pro odstraneni nekonzistence:
- Puvodne: `MonitoredDomain` mel `user_id` (per-tenant) ale take `unique: true` na `domain` (globalni)
- Problem: Dve entity (`MonitoredDomain` a `MonitoredDomainSubscription`) delaly podobnou vec

## Cilovy stav

| Entita | Ucel | Spravce | Rozhrani |
|--------|------|---------|----------|
| `MonitoredDomain` | Globalni seznam domen ke crawlovani | Server admin | CLI |
| `MonitoredDomainSubscription` | Uzivatelske prihlaseni k domene | Uzivatel | Admin panel |

## Vytvorene/upravene soubory

### Nove soubory
- `src/Command/MonitorDomainCommand.php` - CLI prikaz pro spravu globalnich domen
- `migrations/Version20260123120000.php` - Migrace pro odstraneni user_id

### Upravene soubory
- `src/Entity/MonitoredDomain.php` - Odstraneno user pole a relace
- `src/Controller/Admin/MonitoredDomainCrudController.php` - Zmena na AbstractCrudController, ROLE_SUPER_ADMIN
- `src/Controller/Admin/MonitoredDomainSubscriptionCrudController.php` - Query builder pro globalni domeny
- `src/Controller/Admin/DashboardController.php` - Pridani ROLE_SUPER_ADMIN permission na menu item

## Klicove implementacni detaily

### Entity zmeny
- Odstranen `user` ManyToOne vztah
- Odstranen UniqueConstraint `monitored_domains_user_domain_unique`
- Odstranen Index `monitored_domains_user_idx`
- Ponechan `unique: true` na `domain` sloupci

### CLI prikaz
```bash
# Pridat domenu
bin/console app:monitor:domain add example.com --frequency=weekly

# Seznam domen
bin/console app:monitor:domain list
bin/console app:monitor:domain list --needs-crawl

# Aktualizovat domenu
bin/console app:monitor:domain update example.com --frequency=daily
bin/console app:monitor:domain update example.com --active=false

# Odstranit domenu
bin/console app:monitor:domain remove example.com
```

### Admin pristup
- `MonitoredDomainCrudController` - pouze pro ROLE_SUPER_ADMIN
- `MonitoredDomainSubscriptionCrudController` - pro bezne uzivatele (tenant filtered)

### Migrace
Migrace resi:
1. Odstraneni duplicit (ponechava nejstarsi zaznam pro kazdy unikatni domain)
2. Drop constraints a indexu
3. Drop user_id sloupce
4. Vytvoreni unikatniho indexu na domain

## Verifikace

1. Spustit migraci: `bin/console doctrine:migrations:migrate`
2. Pridat domenu pres CLI: `bin/console app:monitor:domain add test.cz`
3. V adminu vytvorit subscription k teto domene
4. Overit ze crawl funguje: `bin/console app:monitor:crawl`

## Poznamky

- Stavajici data: migrace musi osetrrit existujici zaznamy (smazat duplicity)
- MonitoredDomainCrudController ponechan pro ROLE_SUPER_ADMIN pro webove rozhrani
- MonitoredDomainSubscription zustava tenant-filtered (kazdy uzivatel vidi sve odbery)
