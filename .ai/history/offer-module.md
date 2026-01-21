# Offer Module - Implementation History

## Zadání
Implementace modulu pro generování a správu email nabídek s následujícími požadavky:

- **Approval workflow:** Jednoduchý (jeden člověk schvaluje)
- **Email generování:** Kombinace (šablona jako základ, AI dopiluje personalizaci)
- **Rate limits:** Per-user konfigurovatelné v User.settings JSON
- **Šablony:** UserEmailTemplate entita pro per-user overrides

## Vytvořené soubory

### Enumy
- `src/Enum/OfferStatus.php` - 9 workflow stavů (draft → converted)

### Entity
- `src/Entity/Offer.php` - Hlavní entita pro nabídky
- `src/Entity/UserEmailTemplate.php` - Per-user template customization

### Repository
- `src/Repository/OfferRepository.php` - Query metody pro offers
- `src/Repository/UserEmailTemplateRepository.php` - Template resolution

### Services
- `src/Service/Offer/OfferContent.php` - DTO pro výsledek generování
- `src/Service/Offer/RateLimitResult.php` - DTO pro rate limit check
- `src/Service/Offer/RateLimitChecker.php` - Rate limiting logic
- `src/Service/Offer/OfferGenerator.php` - Email content generation
- `src/Service/Offer/OfferService.php` - Orchestration service

### Controllers
- `src/Controller/OfferController.php` - REST API endpoints
- `src/Controller/TrackingController.php` - Email tracking endpoints

### Commands
- `src/Command/OfferGenerateCommand.php` - CLI command

### Migrations
- `migrations/Version20260121161630.php` - DB schema

## Klíčové implementační detaily

### OfferStatus enum
```
draft → pending_approval → approved → sent → opened → clicked → responded → converted
                        ↘ rejected
```

State machine metody:
- `canApprove()` - lze schválit (PENDING_APPROVAL)
- `canReject()` - lze zamítnout (PENDING_APPROVAL)
- `canSend()` - lze odeslat (APPROVED)
- `isSent()` - byl odeslán
- `isFinal()` - konečný stav

### Offer entity
- UUID primary key
- Vztahy: User, Lead, Proposal (nullable), Analysis (nullable), EmailTemplate (nullable)
- Tracking: unique `trackingToken` pro pixel/click tracking
- Workflow timestamps: approvedAt, sentAt, openedAt, clickedAt, respondedAt, convertedAt
- AI metadata JSON pro personalizaci info

### UserEmailTemplate
- Per-user override globálních šablon
- `baseTemplate` → EmailTemplate (nullable)
- AI personalization settings (enabled, custom prompt)
- Industry-specific templates

### OfferGenerator
Template hierarchy:
1. UserEmailTemplate (per-user, industry-specific)
2. EmailTemplate (global)
3. Default fallback

Dostupné proměnné:
```
{{domain}}, {{company_name}}, {{contact_name}}, {{email}}, {{phone}}
{{total_score}}, {{issues_count}}, {{critical_issues_count}}, {{top_issues}}
{{proposal_title}}, {{proposal_summary}}, {{proposal_link}}
{{tracking_pixel}}, {{unsubscribe_link}}
{{sender_name}}, {{sender_email}}, {{sender_signature}}
```

### RateLimitChecker
Default limits:
- 10 emails/hour
- 50 emails/day
- 3 emails/domain/day

Konfigurovatelné přes User.settings:
```json
{
  "rate_limits": {
    "emails_per_hour": 10,
    "emails_per_day": 50,
    "emails_per_domain_day": 3
  }
}
```

### TrackingController
- `/api/track/open/{token}` - Returns 1x1 transparent GIF
- `/api/track/click/{token}?url=` - Redirects after tracking
- `/unsubscribe/{token}` - Unsubscribe placeholder

## REST API Endpoints

| Method | Endpoint | Popis |
|--------|----------|-------|
| GET | /api/offers | List offers (API Platform) |
| GET | /api/offers/{id} | Detail offer |
| POST | /api/offers/generate | Create + generate offer |
| POST | /api/offers/{id}/submit | Submit for approval |
| POST | /api/offers/{id}/approve | Approve offer |
| POST | /api/offers/{id}/reject | Reject with reason |
| POST | /api/offers/{id}/send | Send email (rate limited) |
| GET | /api/offers/{id}/preview | Preview email content |
| GET | /api/offers/rate-limits | Current rate limit status |
| POST | /api/offers/{id}/responded | Mark as responded |
| POST | /api/offers/{id}/converted | Mark as converted |

## CLI Command

```bash
# Single offer
bin/console app:offer:generate --lead=<uuid> --user=<code>

# With proposal
bin/console app:offer:generate --lead=<uuid> --user=<code> --proposal=<uuid>

# Batch processing
bin/console app:offer:generate --batch --user=<code> --limit=10

# Options
--email=<email>      Override recipient email
--template=<name>    Use specific template
--dry-run            Show what would be generated
--send               Send immediately after generation
--skip-ai            Skip AI personalization
```

## Checklist pro podobnou implementaci

1. [ ] Vytvoř Status enum se state machine metodami
2. [ ] Vytvoř hlavní entitu s workflow timestamps
3. [ ] Vytvoř repository s query metodami pro reporting
4. [ ] Vytvoř DTOs pro service results
5. [ ] Vytvoř service pro business logic
6. [ ] Vytvoř controller s REST API
7. [ ] Vytvoř CLI command
8. [ ] Vytvoř migraci a spusť

## Známé problémy a řešení

### Email odesílání
Actual email sending je zatím mock - bude delegováno na Email Module (AWS SES integrace).

### Unsubscribe
`/unsubscribe/{token}` endpoint je placeholder - potřebuje implementaci unsubscribe listu.

### Tracking pixel caching
Tracking pixel response má správné no-cache headers, ale některé email klienty mohou cachovat.
