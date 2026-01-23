# Intuitivní konfigurace analyzérů - Implementation History

## Zadání

Implementovat intuitivní UI pro konfiguraci analyzérů v Discovery Profile místo JSON textarea:

1. Přejmenovat tab "Analýza nastavení" na "Nastavení analýzy"
2. Nahradit JSON textarea intuitivním UI
3. Načíst analyzery relevantní pro dané industry
4. Zobrazit checkbox pro každý analyzér + jeho nastavení
5. Rozšířit interface analyzérů o deklaraci konfigurovatelných parametrů

## Vytvořené soubory

### Backend

- `src/Service/Analyzer/LeadAnalyzerInterface.php` - přidány metody `getName()`, `getDescription()`, `getConfigurableSettings()`
- `src/Service/Analyzer/AbstractLeadAnalyzer.php` - defaultní implementace nových metod
- `src/Service/AnalyzerConfigService.php` - **NOVÝ** - service pro správu schémat analyzérů
- `src/Admin/Field/AnalyzerConfigField.php` - **NOVÝ** - custom EasyAdmin field

### Form Types

- `src/Form/AnalyzerConfigType.php` - **NOVÝ** - hlavní form type pro konfiguraci
- `src/Form/AnalyzerItemType.php` - **NOVÝ** - form type pro jeden analyzér
- `src/Form/ThresholdsType.php` - **NOVÝ** - form type pro dynamické thresholds

### Templates

- `templates/admin/field/analyzer_config.html.twig` - **NOVÝ** - EasyAdmin field template
- `templates/admin/form/analyzer_config_theme.html.twig` - **NOVÝ** - Symfony form theme

### Config

- `config/packages/twig.yaml` - přidán form theme

### Upravené analyzéry (přidán `getDescription()`)

- `HttpAnalyzer.php`
- `SecurityAnalyzer.php`
- `SeoAnalyzer.php`
- `LibraryAnalyzer.php`
- `PerformanceAnalyzer.php` - přidán i `getConfigurableSettings()` pro thresholds
- `ResponsivenessAnalyzer.php`
- `VisualAnalyzer.php`
- `AccessibilityAnalyzer.php`
- `EshopDetectionAnalyzer.php`
- `OutdatedWebAnalyzer.php`
- `DesignModernityAnalyzer.php`
- `EshopAnalyzer.php`
- `WebdesignCompetitorAnalyzer.php`
- `RealEstateAnalyzer.php`
- `AutomobileAnalyzer.php`
- `RestaurantAnalyzer.php`
- `MedicalAnalyzer.php`

## Klíčové implementační detaily

### LeadAnalyzerInterface rozšíření

```php
public function getName(): string;
public function getDescription(): string;
public function getConfigurableSettings(): array;
```

### AnalyzerConfigService

- Získává seznam analyzérů pomocí tagged iterator (`app.lead_analyzer`)
- Filtruje analyzéry podle industry
- Poskytuje schémata pro UI (jméno, popis, settings, issue codes)
- Merguje uživatelskou konfiguraci s defaulty

### PerformanceAnalyzer configurable settings

```php
public function getConfigurableSettings(): array
{
    return [
        'lcp_good' => [
            'type' => 'integer',
            'label' => 'LCP dobrý (ms)',
            'default' => 2500,
            'min' => 1000,
            'max' => 10000,
            'step' => 100,
        ],
        // ... další thresholds
    ];
}
```

### Form structure

- `AnalyzerConfigType` - container pro všechny analyzéry
- `AnalyzerItemType` - jeden analyzér (enabled, priority, ignoreCodes, thresholds)
- `ThresholdsType` - dynamicky generované thresholds podle settings

### UI features

- Checkbox pro enable/disable analyzéru
- Priority slider (1-10)
- Multi-select pro ignorování issue kódů
- Dynamické thresholds (pouze pro analyzéry které je mají)
- Collapsible settings panel pro každý analyzér
- Badge pro označení univerzální/industry-specific

## Checklist pro podobnou implementaci

1. Definovat interface metody pro metadata
2. Implementovat defaulty v abstract třídě
3. Vytvořit service pro agregaci dat
4. Vytvořit custom EasyAdmin field
5. Vytvořit form types (container, item, sub-types)
6. Vytvořit form theme pro Twig
7. Registrovat form theme v twig.yaml
8. Aktualizovat controller pro použití nového fieldu

## Testy

### Unit testy (`tests/Form/AnalyzerConfigTypeTest.php`)

1. `testGetAnalyzerSchemasForNullIndustry` - ověřuje, že pro null industry vrací pouze univerzální analyzéry
2. `testGetAnalyzerSchemasForEshopIndustry` - ověřuje, že pro e-shop industry vrací universal + e-shop specific
3. `testAnalyzerSchemaContainsRequiredFields` - ověřuje strukturu schémat
4. `testPerformanceAnalyzerHasConfigurableSettings` - ověřuje konfigurovatelné thresholds
5. `testMergeWithDefaultsCreatesCompleteConfig` - ověřuje mergování user config s defaults
6. `testFormCreation` - ověřuje vytvoření formu s children pro analyzéry
7. `testFormSubmission` - ověřuje správnou transformaci dat při submit

Všech 7 testů prochází s 106 assertions.

## Známé problémy a řešení

### Line length warnings v PHPDoc

PHPStan/PHPCS hlásí warnings pro dlouhé řádky v type annotations. Toto je akceptovatelné pro komplexní typy.

### Industry dynamická změna

Aktuálně se industry bere z entity při editaci nebo z uživatele při vytváření nového profilu. Pokud uživatel změní industry v prvním tabu, analyzéry se neaktualizují dynamicky - vyžaduje uložení a znovu otevření.

Možné řešení: JavaScript listener na změnu industry pole, který přenačte form pomocí AJAX.

### Checkbox submission

Při form submission, prázdný string není false - musí úplně chybět klíč v submitted data, aby checkbox byl false. To je standardní HTML behavior.
