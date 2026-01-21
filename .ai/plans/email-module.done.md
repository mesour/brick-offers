# Email Module - Implementation Plan

## Status: TODO (otázky k zodpovězení)

## Shrnutí

Modul pro odesílání emailů s trackingem a správou bounces/complaints.

---

## Předpokládaná struktura

### EmailLog Entity

```
EmailLog
├── id: UUID (PK)
├── offer_id: FK → Offer (NOT NULL)
├── message_id: VARCHAR(255) - provider message ID
├── to_email: VARCHAR(255)
├── from_email: VARCHAR(255)
├── subject: VARCHAR(500)
├── status: EmailStatus enum
├── sent_at: DATETIME (nullable)
├── delivered_at: DATETIME (nullable)
├── opened_at: DATETIME (nullable)
├── clicked_at: DATETIME (nullable)
├── bounced_at: DATETIME (nullable)
├── bounce_type: VARCHAR(50) (nullable)
├── bounce_reason: TEXT (nullable)
├── complained_at: DATETIME (nullable)
├── metadata: JSON
├── created_at
```

### EmailStatus Enum

```
enum EmailStatus: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case OPENED = 'opened';
    case CLICKED = 'clicked';
    case BOUNCED = 'bounced';
    case COMPLAINED = 'complained';
    case FAILED = 'failed';
}
```

---

## Otázky k zodpovězení

### 1. Email Provider

- [ ] **AWS SES** jako primární?
- [ ] **Abstrakce** pro více providerů? (Mailgun, SendGrid jako fallback)
- [ ] **Vlastní SMTP** jako alternativa?

### 2. Tracking

- [ ] **Open tracking** - pixel v emailu?
- [ ] **Click tracking** - redirect přes náš server?
- [ ] **Reply detection** - parsování inbox? (složité)

### 3. Webhooks

- [ ] **SES webhooks** pro bounce/complaint?
- [ ] Endpoint URL a autentizace?
- [ ] SNS vs HTTP callback?

### 4. Unsubscribe

- [ ] **List-Unsubscribe header**?
- [ ] **Unsubscribe link** v patičce?
- [ ] **Blacklist entita** pro unsubscribed adresy?

### 5. GDPR/Compliance

- [ ] Ukládání souhlasu?
- [ ] Retence dat (jak dlouho uchovávat logy)?
- [ ] Right to deletion?

### 6. Multi-tenancy

- EmailLog je per-user (via Offer → Lead → User)
- Blacklist per-user nebo globální?

---

## Předběžný implementační plán

### Fáze 1: Entity
- [ ] `src/Enum/EmailStatus.php`
- [ ] `src/Entity/EmailLog.php`
- [ ] `src/Entity/EmailBlacklist.php` (?)
- [ ] `src/Repository/EmailLogRepository.php`

### Fáze 2: Services
- [ ] `src/Service/Email/EmailServiceInterface.php`
- [ ] `src/Service/Email/SesEmailService.php`
- [ ] `src/Service/Email/EmailTracker.php`

### Fáze 3: Tracking Endpoints
- [ ] `src/Controller/TrackingController.php`
  - GET /t/{token}/open - tracking pixel
  - GET /t/{token}/click/{url} - click redirect
  - GET /unsubscribe/{token} - unsubscribe

### Fáze 4: Webhooks
- [ ] `src/Controller/WebhookController.php`
  - POST /webhook/ses - SES notifications

### Fáze 5: Commands
- [ ] `src/Command/EmailSendCommand.php`
- [ ] `src/Command/EmailProcessBouncesCommand.php`

### Fáze 6: Migration
- [ ] `migrations/VersionXXX_email.php`

---

## Závislosti

- **Offer Module** - EmailLog → Offer
- **AWS SDK** - pro SES

---

## Konfigurace (předběžná)

```env
# Email
EMAIL_DRIVER=ses
EMAIL_FROM=noreply@example.com
EMAIL_FROM_NAME="Web Analyzer"

# AWS SES
AWS_SES_REGION=eu-west-1
AWS_SES_KEY=...
AWS_SES_SECRET=...

# Tracking
TRACKING_BASE_URL=https://track.example.com
```

---

## Verifikace (předběžná)

```bash
bin/console doctrine:schema:validate
bin/console app:email:send --offer=<uuid> --dry-run
curl http://localhost:7270/api/email_logs
```
