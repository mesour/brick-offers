# Admin Module - Implementation History

## Zadání
Implementovat kompletní EasyAdmin dashboard pro správu všech entit s:
- Multi-tenant data isolation (ADMIN vidí celý tenant, USER jen svoje)
- Role-based access control (ROLE_ADMIN, ROLE_USER)
- Granulární permissions pro sub-users
- CLI nástroje pro správu uživatelů

## Vytvořené soubory

### Backend - Controllers
- `src/Controller/Admin/DashboardController.php` - Hlavní dashboard s menu
- `src/Controller/Admin/SecurityController.php` - Login/logout
- `src/Controller/Admin/AbstractTenantCrudController.php` - Base controller s multi-tenancy

### CRUD Controllers
- `src/Controller/Admin/LeadCrudController.php` - Správa leadů
- `src/Controller/Admin/OfferCrudController.php` - Správa nabídek s workflow akcemi
- `src/Controller/Admin/ProposalCrudController.php` - Správa návrhů s approve/reject
- `src/Controller/Admin/AnalysisCrudController.php` - Zobrazení analýz (read-only)
- `src/Controller/Admin/AnalysisResultCrudController.php` - Detail výsledků
- `src/Controller/Admin/AnalysisSnapshotCrudController.php` - Snapshoty
- `src/Controller/Admin/CompanyCrudController.php` - Správa firem
- `src/Controller/Admin/EmailLogCrudController.php` - Log odeslaných emailů
- `src/Controller/Admin/EmailTemplateCrudController.php` - Globální šablony
- `src/Controller/Admin/UserEmailTemplateCrudController.php` - Per-user šablony
- `src/Controller/Admin/EmailBlacklistCrudController.php` - Blacklist
- `src/Controller/Admin/UserCrudController.php` - Správa uživatelů
- `src/Controller/Admin/UserAnalyzerConfigCrudController.php` - Konfigurace analyzátorů
- `src/Controller/Admin/UserCompanyNoteCrudController.php` - Poznámky k firmám
- `src/Controller/Admin/IndustryBenchmarkCrudController.php` - Industry benchmarky
- `src/Controller/Admin/MonitoredDomainCrudController.php` - Monitorované domény
- `src/Controller/Admin/MonitoredDomainSubscriptionCrudController.php` - Odběry
- `src/Controller/Admin/CompetitorSnapshotCrudController.php` - Snapshoty konkurence
- `src/Controller/Admin/DemandSignalCrudController.php` - Signály poptávky
- `src/Controller/Admin/DemandSignalSubscriptionCrudController.php` - Odběry signálů
- `src/Controller/Admin/MarketWatchFilterCrudController.php` - Filtry sledování trhu

### CLI Commands
- `src/Command/UserCreateCommand.php` - Vytvoření uživatele
- `src/Command/UserSetLimitsCommand.php` - Nastavení limitů pro admin účty

### Entity Updates
- `src/Entity/User.php` - Přidána autentizace, role, permissions, multi-tenancy

### Templates
- `templates/admin/login.html.twig` - Login stránka
- `templates/admin/dashboard.html.twig` - Dashboard homepage
- `templates/admin/field/score.html.twig` - Score badge rendering

### Config
- `config/packages/security.yaml` - Security firewall a providers
- `public/css/admin.css` - Custom admin styly

### Migrations
- `migrations/Version20260122165020.php` - User entity changes

## Klíčové implementační detaily

### Role System
```
ROLE_ADMIN - Tenant owner (plný přístup v rámci limitů)
ROLE_USER  - Sub-user s konfigurovatelými permissions
```

### Permission Templates
- `manager` - read-only přístup ke statistikám
- `approver` - schvalování emailů/návrhů
- `analyst` - práce s leady a analýzami
- `full` - vše kromě správy uživatelů

### Multi-tenancy Data Isolation
`AbstractTenantCrudController` automaticky filtruje data:
- ADMIN vidí svoje data + všech sub-users
- USER vidí jen svoje data

### CLI Usage
```bash
# Vytvořit admin účet
bin/console app:user:create admin@example.com password123 --admin

# Vytvořit sub-účet s template
bin/console app:user:create employee@example.com password123 \
    --admin-code=admin --template=analyst

# Nastavit limity pro admin
bin/console app:user:set-limits admin \
    '{"maxLeadsPerMonth": 1000, "maxEmailsPerDay": 100}'
```

## Checklist pro podobnou implementaci

1. [ ] Nainstalovat EasyAdmin: `composer require easycorp/easyadmin-bundle`
2. [ ] Vytvořit DashboardController s menu items
3. [ ] Nastavit security.yaml s firewally a providers
4. [ ] Vytvořit login template
5. [ ] Implementovat base controller pro multi-tenancy
6. [ ] Vytvořit CRUD controllery pro každou entitu
7. [ ] Přidat workflow akce (approve, reject, send)
8. [ ] Nastavit permissions pro akce
9. [ ] Vytvořit CLI příkazy pro správu uživatelů
10. [ ] Vygenerovat a spustit migraci

## Známé problémy a řešení

### APP_SECRET prázdné
**Problém:** Migration diff selhává s "A non-empty secret is required"
**Řešení:** Nastavit APP_SECRET v .env nebo předat jako env var: `APP_SECRET=xxx bin/console ...`

### PHPStan memory limit
**Problém:** PHPStan přeteče paměť na velkých souborech
**Řešení:** Použít `php -d memory_limit=512M ./vendor/bin/phpstan ...`

## Verifikace

```bash
# Spustit migraci
bin/console doctrine:migrations:migrate

# Vytvořit test admin účet
bin/console app:user:create admin@test.com password123 --admin

# Přístup na /admin
```
