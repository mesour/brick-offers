# Discovery Profiles - Implementation History

## Zadání
Vytvořit systém "Discovery Profiles" - pojmenované profily které spojují:
- Discovery nastavení (zdroje, dotazy, limity)
- Analyzer nastavení (které kategorie analyzovat, priority, thresholds)
- Odvětví (industry) pro industry-specific analyzery
- Auto-analyze flag pro automatické spuštění analýzy po discovery

## Vytvořené soubory

### Nové soubory
- `src/Entity/DiscoveryProfile.php` - hlavní entita profilu
- `src/Repository/DiscoveryProfileRepository.php` - repository s helper metodami
- `src/Controller/Admin/DiscoveryProfileCrudController.php` - Admin CRUD controller
- `migrations/Version20260122215543.php` - DB migrace

### Aktualizované soubory
- `src/Entity/Lead.php` - přidán vztah `discoveryProfile`
- `src/Entity/User.php` - přidán vztah `discoveryProfiles`
- `src/Message/DiscoverLeadsMessage.php` - přidáno `profileId`, `industryFilter`, `autoAnalyze`
- `src/Message/AnalyzeLeadMessage.php` - přidáno `profileId`
- `src/Message/BatchDiscoveryMessage.php` - přidáno `profileName`
- `src/MessageHandler/DiscoverLeadsMessageHandler.php` - podpora profilů, auto-analyze
- `src/MessageHandler/BatchDiscoveryMessageHandler.php` - profile-based discovery
- `src/MessageHandler/AnalyzeLeadMessageHandler.php` - profile analyzer configs
- `src/Command/DiscoveryBatchCommand.php` - profile options
- `src/Controller/Admin/DashboardController.php` - přidáno menu pro Discovery Profiles

## Klíčové implementační detaily

### DiscoveryProfile entita
```php
DiscoveryProfile:
  id: UUID
  user: User (ManyToOne)
  name: string
  description: string|null
  industry: Industry|null
  isDefault: bool

  // Discovery settings
  discoveryEnabled: bool
  discoverySources: array<string>
  discoveryQueries: array<string>
  discoveryLimit: int
  extractData: bool
  linkCompany: bool
  priority: int

  // Analysis settings
  autoAnalyze: bool
  analyzerConfigs: JSON {category: {enabled, priority, thresholds, ignoreCodes}}
```

### Message flow s profilem
1. `BatchDiscoveryMessage` → `BatchDiscoveryMessageHandler`
   - Iteruje přes aktivní profily
   - Pro každý profil a source dispatchne `DiscoverLeadsMessage`

2. `DiscoverLeadsMessage` → `DiscoverLeadsMessageHandler`
   - Použije settings z profilu
   - Nastaví `discoveryProfile` na vytvořených leadech
   - Pokud `autoAnalyze=true`, dispatchne `AnalyzeLeadMessage`

3. `AnalyzeLeadMessage` → `AnalyzeLeadMessageHandler`
   - Načte profil z leadu nebo parametru
   - Filtruje analyzery podle `analyzerConfigs`
   - Aplikuje `ignoreCodes` na issues

## Checklist pro podobnou implementaci

1. **Entita:**
   - [ ] Vytvořit entitu s UUID, timestamps, user relationship
   - [ ] Přidat repository s helper metodami
   - [ ] Přidat vztah do User entity (OneToMany)

2. **Admin UI:**
   - [ ] Vytvořit CRUD controller extending AbstractTenantCrudController
   - [ ] Přidat do menu v DashboardController
   - [ ] Implementovat custom actions (duplicate, setDefault)

3. **Integrace do pipeline:**
   - [ ] Aktualizovat Message třídy s novými parametry
   - [ ] Aktualizovat MessageHandlery pro podporu nové entity

4. **CLI:**
   - [ ] Aktualizovat existující commands

5. **Database:**
   - [ ] Vytvořit migraci
   - [ ] Přidat indexy

## Verifikace

```bash
# Vytvořit profil v Admin UI
# Admin → Configuration → Discovery Profiles → New

# Spustit discovery s profilem
bin/console app:discovery:batch --user=admin --profile="E-shopy Praha" --dry-run

# Spustit discovery pro všechny profily
bin/console app:discovery:batch --all-users --dry-run

# Zobrazit konfiguraci
bin/console app:discovery:batch --all-users --show-config
```

## Známé problémy a řešení

### Problém: PHPStan warning o unused ID type
**Příčina:** UUID ID je auto-generated Doctrine
**Řešení:** Ignorovat warning - je to standard pattern pro UUID entities

### Problém: Textarea pro queries v Admin formuláři
**Příčina:** EasyAdmin neumí přímo array fields z textarea
**Řešení:** Transform v `persistEntity`/`updateEntity` - konverze string→array
