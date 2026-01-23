# User Industry Configuration - Implementation History

## Zadání
- Přidat `industry` - odvětví uživatele (konfigurovatelné přes CLI)
- Přidat `data-industry="..."` - atribut na `<body>` pro budoucí stylování
- Vytvořit CLI command `app:user:set-industry` pro nastavení odvětví
- Zobrazit indikátor odvětví v admin sidebaru

## Vytvořené soubory

### Nové soubory
- `src/Command/UserSetIndustryCommand.php` - CLI command pro nastavení industry
- `src/Service/CurrentIndustryService.php` - Service pro přístup k industry uživatele
- `src/Twig/IndustryExtension.php` - Twig extension pro přístup k industry v šablonách
- `templates/bundles/EasyAdminBundle/layout.html.twig` - Override EasyAdmin layoutu
- `migrations/Version20260122185459.php` - Migrace pro allowed_industries (původní)
- `migrations/Version20260122192956.php` - Migrace pro přechod na single industry

### Upravené soubory
- `src/Entity/User.php` - Přidáno `industry` pole (Industry enum, nullable)
- `public/css/admin.css` - CSS pro industry indikátor v sidebaru

## Klíčové implementační detaily

### User Entity
```php
#[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: Industry::class)]
private ?Industry $industry = null;

public function getIndustry(): ?Industry
public function setIndustry(?Industry $industry): static
public function hasIndustry(): bool
```

Sub-users automaticky dědí industry z admin účtu.

### CLI Command
```bash
# Nastavit industry
bin/console app:user:set-industry admin eshop

# Zobrazit aktuální
bin/console app:user:set-industry admin

# Odstranit industry
bin/console app:user:set-industry admin none

# Seznam dostupných
bin/console app:user:set-industry admin --list
```

### Twig globals
V šablonách jsou dostupné:
- `current_industry` - aktuální Industry enum nebo null
- `current_industry_value` - hodnota jako string
- `has_industry` - boolean zda má user industry

### Data attribute na body
```html
<body data-industry="eshop" ...>
```

### Indikátor v sidebaru
- Zobrazuje se pod logem "Web Analyzer"
- Barevný accent na levé straně podle odvětví
- Zobrazuje se pouze pokud má user nastavené odvětví

## Checklist pro podobnou implementaci
1. [ ] Přidat enum pole do entity s getter/setter
2. [ ] Vytvořit CLI command pro správu
3. [ ] Vytvořit service pro přístup k hodnotě
4. [ ] Vytvořit Twig extension pro globální proměnné
5. [ ] Override EasyAdmin layout pro UI
6. [ ] Vytvořit migraci
7. [ ] Přidat CSS styly

## Známé problémy a řešení
- Žádné
