# Offer Module - Implementation Plan

## Status: TODO (otázky k zodpovězení)

## Shrnutí

Modul pro správu nabídek - spojuje Proposal + Analysis do finálního emailu pro odeslání klientovi.

---

## Předpokládaná struktura

### Offer Entity

```
Offer
├── id: UUID (PK)
├── user_id: FK → User (NOT NULL) - vlastník (via Lead)
├── lead_id: FK → Lead (NOT NULL)
├── proposal_id: FK → Proposal (nullable)
├── analysis_id: FK → Analysis (nullable)
├── email_template_id: FK → EmailTemplate (nullable)
├── status: OfferStatus enum
├── subject: VARCHAR(500) - finální předmět
├── body: TEXT - finální tělo emailu
├── tracking_token: VARCHAR(100) UNIQUE
├── approved_by: FK → User (nullable)
├── approved_at: DATETIME (nullable)
├── sent_at: DATETIME (nullable)
├── created_at, updated_at
```

### OfferStatus Enum

```
enum OfferStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SENT = 'sent';
    case OPENED = 'opened';
    case CLICKED = 'clicked';
    case RESPONDED = 'responded';
    case CONVERTED = 'converted';
}
```

---

## Otázky k zodpovězení

### 1. Approval Workflow

- [ ] **Jednoduchý approval** - jeden člověk schvaluje
- [ ] **Víceúrovňový** - junior → senior → odesláno
- [ ] **Auto-approve** - po splnění podmínek automaticky schválit

### 2. Email generování

- [ ] **Z šablony** - EmailTemplate + proměnné z Analysis/Proposal
- [ ] **AI generování** - Claude vygeneruje personalizovaný email
- [ ] **Kombinace** - šablona jako základ, AI dopiluje

### 3. Rate Limiting

- [ ] **Per-user limity** - v User.settings nebo samostatná entita?
- [ ] **Per-domain limity** - max emailů na jednu doménu/den?
- [ ] **Globální limity** - celkový denní/hodinový limit?

### 4. Proměnné v šablonách

Které proměnné budou dostupné?
```
{{company_name}}
{{domain}}
{{contact_name}}
{{issues_count}}
{{critical_issues_count}}
{{total_score}}
{{proposal_link}}
{{tracking_pixel}}
{{unsubscribe_link}}
...?
```

### 5. Multi-tenancy

- Offer je vždy per-user (přes Lead)
- Rate limits per-user v settings nebo nová entita UserRateLimit?

---

## Předběžný implementační plán

### Fáze 1: Entity
- [ ] `src/Enum/OfferStatus.php`
- [ ] `src/Entity/Offer.php`
- [ ] `src/Repository/OfferRepository.php`

### Fáze 2: Services
- [ ] `src/Service/Offer/OfferService.php`
- [ ] `src/Service/Offer/OfferGenerator.php` - generování z template
- [ ] `src/Service/Offer/RateLimiter.php`

### Fáze 3: API & Commands
- [ ] API Platform endpoints
- [ ] Custom actions (approve, reject, send)
- [ ] `src/Command/OfferGenerateCommand.php`

### Fáze 4: Migration
- [ ] `migrations/VersionXXX_offer.php`

---

## Závislosti

- **Proposal Generator Module** - pro proposal_id
- **Email Module** - pro skutečné odesílání
- **EmailTemplate** - již existuje

---

## Verifikace (předběžná)

```bash
bin/console doctrine:schema:validate
bin/console app:offer:generate --lead=<uuid>
curl http://localhost:7270/api/offers
curl -X POST http://localhost:7270/api/offers/{id}/approve
```
