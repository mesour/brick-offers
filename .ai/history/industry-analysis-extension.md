# Industry Analysis Extension - Implementation History

## Zadání

Rozšíření Web Analyzer systému o:
1. **Industry enum** - oblasti podnikání (webdesign, real-estate, automobile, eshop...)
2. **Opakované analýzy** - možnost spouštět analýzy vícekrát v čase
3. **Historie reportů** - ukládání a porovnávání analýz v čase
4. **Industry-specific analyzátory** - různé odvětví analyzují různé věci
5. **Benchmarking** - porovnání s odvětvím
6. **Retention/archivace** - úspora místa v databázi
7. **REST API** - přístup k datům analýz, trendům a benchmarkům

**Use cases:**
- Nabídkový systém pro vyhledávání potenciálních klientů
- Monitoring konkurence a jejich snah v čase

## Architektonické rozhodnutí

Zvolen **Hybrid přístup**:
- Normalizované entity (Analysis, AnalysisResult) - plná data
- Agregované snapshoty (AnalysisSnapshot) - týdenní/měsíční souhrny
- Industry benchmarky (IndustryBenchmark) - projekce pro porovnání
- Retention policy - komprese/mazání starých detailních dat

**Výhody:**
- Zůstává v čistém PostgreSQL
- Podporuje všechny query patterns (historie, trending, benchmarking)
- Snadná migrace na TimescaleDB v budoucnu
- Efektivní storage s retention policy

## Vytvořené soubory

### Enumy
- `src/Enum/Industry.php` - 10 odvětví (webdesign, eshop, real_estate, automobile, restaurant, medical, legal, finance, education, other)
- `src/Enum/SnapshotPeriod.php` - day, week, month

### Entity
- `src/Entity/AnalysisSnapshot.php` - agregovaná data pro trending
- `src/Entity/IndustryBenchmark.php` - benchmark projekce pro odvětví

### Rozšířené entity
- `src/Entity/Lead.php` - přidáno: industry, latestAnalysis, analysisCount, snapshotPeriod
- `src/Entity/Analysis.php` - přidáno: industry, sequenceNumber, previousAnalysis, scoreDelta, issueDelta, isImproved

### Služby
- `src/Service/Snapshot/SnapshotService.php` - generování snapshotů
- `src/Service/Benchmark/BenchmarkCalculator.php` - výpočet benchmarků
- `src/Service/Archive/ArchiveService.php` - archivace starých dat
- `src/Service/Archive/ArchiveStats.php` - DTO pro statistiky archivace

### Industry-specific analyzátory
- `src/Service/Analyzer/EshopAnalyzer.php` - plná implementace (8 kontrol)
- `src/Service/Analyzer/WebdesignCompetitorAnalyzer.php` - plná implementace (9 kontrol)
- `src/Service/Analyzer/RealEstateAnalyzer.php` - skeleton (3 kontroly)
- `src/Service/Analyzer/AutomobileAnalyzer.php` - skeleton (3 kontroly)
- `src/Service/Analyzer/RestaurantAnalyzer.php` - skeleton (2 kontroly)
- `src/Service/Analyzer/MedicalAnalyzer.php` - skeleton (3 kontroly)

### CLI příkazy
- `src/Command/AnalysisSnapshotCommand.php` - `app:analysis:snapshot`
- `src/Command/BenchmarkCalculateCommand.php` - `app:benchmark:calculate`
- `src/Command/AnalysisArchiveCommand.php` - `app:analysis:archive`

### REST API controllery
- `src/Controller/LeadAnalysisController.php`:
  - `GET /api/leads/{id}/analyses` - historie analýz
  - `GET /api/leads/{id}/trend` - trending data
  - `GET /api/leads/{id}/benchmark` - porovnání s benchmarkem
- `src/Controller/IndustryBenchmarkController.php`:
  - `GET /api/industries` - seznam odvětví
  - `GET /api/industries/{industry}/benchmark` - detail benchmarku
  - `GET /api/industries/{industry}/benchmark/history` - historie

### Migrace
- `migrations/Version20260120204402.php` - hlavní migrace pro všechny nové entity a sloupce

### Rozšířené soubory
- `src/Enum/IssueCategory.php` - přidány industry-specific kategorie
- `src/Service/Analyzer/IssueRegistry.php` - 39 nových industry-specific issues
- `src/Service/Analyzer/LeadAnalyzerInterface.php` - getSupportedIndustries(), isUniversal(), supportsIndustry()
- `src/Service/Analyzer/AbstractLeadAnalyzer.php` - default implementace industry metod
- `src/Command/LeadAnalyzeCommand.php` - --industry parametr, --reanalyze
- `src/Repository/AnalysisRepository.php` - findHistoryByLead, findByIndustry, getDeltaStats
- `src/Repository/AnalysisResultRepository.php` - metody pro archivaci

## Klíčové implementační detaily

### Retention Policy

| Stáří dat | Akce | Úspora |
|-----------|------|--------|
| 0-30 dní | Plná data | 0% |
| 30-90 dní | Komprese rawData (gzip + base64) | ~70% |
| 90-365 dní | Smazání rawData, zachovat issues | ~85% |
| 365+ dní | Smazání AnalysisResult, pouze snapshoty | ~95% |

### Komprese rawData

```php
// Komprese
$compressed = base64_encode(gzencode(json_encode($rawData)));
$result->setRawData(['gz:' . $compressed]);

// Dekomprese (při čtení)
if (str_starts_with($data[0], 'gz:')) {
    $decoded = gzdecode(base64_decode(substr($data[0], 3)));
    $rawData = json_decode($decoded, true);
}
```

### Industry-specific analyzátory

Analyzátory implementují metody:
- `getSupportedIndustries(): array` - prázdné = všechna odvětví
- `isUniversal(): bool` - true = běží vždy
- `supportsIndustry(?Industry $industry): bool` - rozhoduje zda běžet

### Benchmark porovnání

```php
// Ranking kategorie
$ranking = match(true) {
    $percentile >= 90 => 'top10',
    $percentile >= 75 => 'top25',
    $percentile >= 50 => 'above_average',
    $percentile >= 25 => 'below_average',
    default => 'bottom25',
};
```

## Checklist pro podobnou implementaci

1. **Enumy a entity:**
   - [ ] Vytvořit potřebné enumy
   - [ ] Rozšířit existující entity o nové sloupce
   - [ ] Vytvořit nové entity pro agregace
   - [ ] Vytvořit migraci

2. **Služby:**
   - [ ] Implementovat hlavní service logiku
   - [ ] Přidat repository metody
   - [ ] Ošetřit edge cases (prázdná data, chybějící vztahy)

3. **CLI příkazy:**
   - [ ] Implementovat command s --dry-run
   - [ ] Přidat batch processing pro velká data
   - [ ] Logovat průběh operací

4. **REST API:**
   - [ ] Vytvořit controller s validací vstupů
   - [ ] Ošetřit error responses
   - [ ] Dokumentovat v README.md

5. **Dokumentace:**
   - [ ] Aktualizovat README.md
   - [ ] Aktualizovat CHANGELOG.md
   - [ ] Zapsat do SESSIONS.md

## Známé problémy a řešení

### PostgreSQL JSONB NOT LIKE

**Problém:** `operator does not exist: jsonb !~~ unknown`

**Řešení:** Cast JSONB na text:
```sql
-- Špatně
WHERE raw_data NOT LIKE '"gz:%'

-- Správně
WHERE raw_data::text NOT LIKE '"gz:%'
```

### VARCHAR(500) limit pro URL

**Problém:** Některé URL z crawleru jsou delší než 500 znaků.

**Řešení:** Truncation metoda:
```php
private function truncateUrl(string $url, int $maxLength = 500): string
{
    if (strlen($url) <= $maxLength) {
        return $url;
    }
    // Odstranit query string a fragment
    $parsed = parse_url($url);
    return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
}
```

## Doporučené cron joby

```bash
# Týdenní snapshoty (každé pondělí v 2:00)
0 2 * * 1 bin/console app:analysis:snapshot --period=week

# Měsíční snapshoty (první den v měsíci v 3:00)
0 3 1 * * bin/console app:analysis:snapshot --period=month

# Přepočet benchmarků (každé pondělí v 6:00)
0 6 * * 1 bin/console app:benchmark:calculate

# Měsíční archivace (první den v měsíci v 4:00)
0 4 1 * * bin/console app:analysis:archive

# Cleanup starých snapshotů (první den v měsíci v 5:00)
0 5 1 * * bin/console app:analysis:snapshot --cleanup --period=day --retention=90
0 5 1 * * bin/console app:analysis:snapshot --cleanup --period=week --retention=52
```

## Datum implementace

2026-01-20 až 2026-01-21
