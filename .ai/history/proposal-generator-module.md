# Proposal Generator Module - Implementation History

## Zadání

Implementace abstraktního rozhraní pro generování návrhů (proposals) podle odvětví:
- **Per-user ownership** s možností recyklace
- **Claude CLI/API** integrace pro AI generování
- **Web Design** jako první implementace
- **Storage abstrakce** (local + S3)
- **Chrome headless** screenshots

## Vytvořené soubory

### Entity & Enumy
- `src/Enum/ProposalStatus.php` - stavy návrhu (generating, draft, approved, rejected, used, recycled, expired)
- `src/Enum/ProposalType.php` - typy návrhů (design_mockup, marketing_audit, conversion_report, security_report, compliance_check, market_analysis, generic_report)
- `src/Entity/Proposal.php` - hlavní entita s recyklací
- `src/Repository/ProposalRepository.php` - repository s findRecyclable, findPendingGeneration

### AI Services
- `src/Service/AI/ClaudeService.php` - Claude integrace (CLI + API mode)
- `src/Service/AI/ClaudeResponse.php` - DTO pro AI odpovědi

### Screenshot Service
- `src/Service/Screenshot/ScreenshotService.php` - Chrome headless screenshots

### Proposal Services
- `src/Service/Proposal/ProposalGeneratorInterface.php` - interface pro generátory
- `src/Service/Proposal/ProposalResult.php` - DTO výsledku generování
- `src/Service/Proposal/CostEstimate.php` - DTO odhadu nákladů
- `src/Service/Proposal/AbstractProposalGenerator.php` - base class pro generátory
- `src/Service/Proposal/DesignProposalGenerator.php` - webdesign generátor
- `src/Service/Proposal/ProposalService.php` - orchestrační service

### Commands
- `src/Command/ProposalGenerateCommand.php` - CLI příkaz pro generování

### Controllers
- `src/Controller/ProposalController.php` - REST API custom endpoints

### Templates
- `templates/prompts/design_mockup.prompt.md` - prompt šablona pro design

### Config
- `config/services.yaml` - DI konfigurace
- `.env` - environment variables (CLAUDE_*, CHROME_SCREENSHOT_URL)

### Migration
- `migrations/Version20260121154144.php` - proposals tabulka

## Klíčové implementační detaily

### Recyklace flow
1. User A vytvoří Proposal (status: GENERATING)
2. AI vygeneruje obsah (status: DRAFT)
3. User A zamítne (status: REJECTED, recyclable: true)
4. User B požaduje návrh pro stejné odvětví
5. Systém najde recyklovatelný a přiřadí User B
6. User A ztrácí přístup

### Tagged iterator pattern
```php
#[AutoconfigureTag('app.proposal_generator')]
class DesignProposalGenerator extends AbstractProposalGenerator
```

### Claude dual-mode
```php
if ($this->useCli) {
    return $this->generateViaCli($prompt, $options);
}
return $this->generateViaApi($prompt, $options);
```

## REST API Endpoints

### API Platform (CRUD)
- `GET /api/proposals` - seznam proposals s filtry
- `GET /api/proposals/{id}` - detail proposal
- `POST /api/proposals` - vytvoření (bez generování)
- `PATCH /api/proposals/{id}` - úprava
- `DELETE /api/proposals/{id}` - smazání

### Custom Endpoints (ProposalController)

```bash
# Generate new proposal
POST /api/proposals/generate
Body: {"leadId": "uuid", "userCode": "default", "type": "design_mockup", "recycle": true}

# Approve proposal
POST /api/proposals/{id}/approve

# Reject proposal
POST /api/proposals/{id}/reject

# Recycle proposal to another user
POST /api/proposals/{id}/recycle
Body: {"userCode": "new_user", "leadId": "uuid"}

# Estimate generation cost
GET /api/proposals/estimate?leadId={uuid}

# Check if recyclable proposal available
GET /api/proposals/recyclable?industry=webdesign&type=design_mockup
```

### Filtry (API Platform)
- `user.id`, `user.code` - filtr podle uživatele
- `lead.id` - filtr podle leadu
- `status` - filtr podle stavu
- `type` - filtr podle typu
- `industry` - filtr podle odvětví
- `isAiGenerated`, `isCustomized`, `recyclable` - boolean filtry
- `createdAt`, `expiresAt` - date filtry

## CLI Commands

```bash
# Single proposal generation
bin/console app:proposal:generate --lead=<uuid> --user=<code>

# With specific type
bin/console app:proposal:generate --lead=<uuid> --user=<code> --type=design_mockup

# Batch processing
bin/console app:proposal:generate --batch --limit=10

# Dry run
bin/console app:proposal:generate --lead=<uuid> --user=<code> --dry-run

# Force regeneration
bin/console app:proposal:generate --lead=<uuid> --user=<code> --force

# Try recycling first
bin/console app:proposal:generate --lead=<uuid> --user=<code> --recycle
```

## Checklist pro přidání nového generátoru

1. Vytvořit novou třídu v `src/Service/Proposal/`
2. Extends `AbstractProposalGenerator`
3. Přidat `#[AutoconfigureTag('app.proposal_generator')]`
4. Implementovat `supports()` pro správné Industry
5. Implementovat `getProposalType()` pro typ návrhu
6. Implementovat `getName()` pro identifikaci
7. Implementovat `generate()` a `processResponse()`
8. Volitelně vytvořit prompt template v `templates/prompts/`

## Známé problémy a řešení

### ScreenshotService timeout
- Timeout je hardcoded na 60 sekund v service
- Pro změnu je nutné upravit service, ne env variable
