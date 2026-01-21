# Email Module - Implementation History

## Zadání
- Implementace Email Module pro odesílání a tracking emailů
- Symfony Mailer abstrakce s podporou více providerů (SMTP, SES, Null)
- Duální blacklist: globální pro hard bounces, per-user pro unsubscribes
- Retence dat: 365 dní

## Vytvořené soubory

### Enumy
- `src/Enum/EmailStatus.php` - PENDING, SENT, DELIVERED, OPENED, CLICKED, BOUNCED, COMPLAINED, FAILED
- `src/Enum/EmailBounceType.php` - HARD_BOUNCE, SOFT_BOUNCE, COMPLAINT, UNSUBSCRIBE
- `src/Enum/EmailProvider.php` - SMTP, SES, NULL

### Entity
- `src/Entity/EmailLog.php` - Log odeslaných emailů s tracking timestamps
- `src/Entity/EmailBlacklist.php` - Blacklist (global: user_id=NULL, per-user: user_id set)

### Repository
- `src/Repository/EmailLogRepository.php` - findByMessageId, countSentLastHour, countSentToday, findOlderThan, getStatistics
- `src/Repository/EmailBlacklistRepository.php` - isBlacklisted, findEntry, findGlobalBounces, findUserUnsubscribes

### Services
- `src/Service/Email/EmailSenderInterface.php` - Interface pro email sendery
- `src/Service/Email/EmailMessage.php` - DTO pro email message
- `src/Service/Email/EmailSendResult.php` - DTO pro výsledek odeslání
- `src/Service/Email/AbstractEmailSender.php` - Base class s buildSymfonyEmail()
- `src/Service/Email/SmtpEmailSender.php` - SMTP sender via Symfony Mailer
- `src/Service/Email/SesEmailSender.php` - AWS SES sender
- `src/Service/Email/NullEmailSender.php` - Testing sender (ukládá do paměti)
- `src/Service/Email/EmailBlacklistService.php` - Správa blacklistu
- `src/Service/Email/EmailService.php` - Hlavní orchestrace (send, processBounce, processDelivery)

### Controllers
- `src/Controller/SesWebhookController.php` - POST /api/webhook/ses pro SNS notifications
- `src/Controller/TrackingController.php` - Aktualizován unsubscribe() endpoint

### Commands
- `src/Command/EmailSendCommand.php` - `app:email:send` - odesílání approved offers
- `src/Command/EmailCleanupCommand.php` - `app:email:cleanup` - čištění starých logů
- `src/Command/EmailBlacklistCommand.php` - `app:email:blacklist` - správa blacklistu

### Konfigurace
- `config/services.yaml` - Přidána konfigurace email senderů a EmailService
- `config/packages/mailer.yaml` - Symfony Mailer konfigurace
- `.env.template` - Přidány EMAIL_*, AWS_SES_*, MAILER_DSN proměnné

### Migration
- `migrations/Version20260121170000.php` - email_logs a email_blacklist tabulky

### Aktualizované soubory
- `src/Service/Offer/OfferService.php` - Přidán EmailService inject a integrace v send()

## Klíčové implementační detaily

### Flow odesílání
1. OfferService.send(offer) voláno
2. Rate limit check via RateLimitChecker
3. Blacklist check via EmailBlacklistService
4. Create EmailLog (status: PENDING)
5. Send via provider (SmtpEmailSender / SesEmailSender)
6. Update EmailLog (SENT + messageId, nebo FAILED)
7. Mark Offer as sent

### Webhook handling (SES)
- POST /api/webhook/ses přijímá SNS notifications
- Bounce → processBounce() → add to global blacklist (hard bounce)
- Complaint → processComplaint() → add to global blacklist
- Delivery → processDelivery() → update EmailLog status

### Unsubscribe handling
- GET /unsubscribe/{token} → show confirmation form
- POST /unsubscribe/{token} → add to per-user blacklist

### Blacklist logic
- Global (user_id = NULL): Hard bounces, complaints - blokuje pro všechny
- Per-user (user_id set): Unsubscribes - blokuje pouze pro daného usera

## CLI příkazy

```bash
# Odeslat approved offers
bin/console app:email:send --limit=50 --provider=smtp
bin/console app:email:send --offer=<uuid> --provider=ses
bin/console app:email:send --user=admin --dry-run

# Cleanup starých logů
bin/console app:email:cleanup --retention-days=365 --dry-run

# Blacklist management
bin/console app:email:blacklist add test@example.com --type=hard --reason="Manual block"
bin/console app:email:blacklist remove test@example.com
bin/console app:email:blacklist check test@example.com --user=admin
bin/console app:email:blacklist list --global
bin/console app:email:blacklist list --user=admin
```

## Checklist pro podobnou implementaci

1. Vytvořit enumy pro status/type hodnoty
2. Vytvořit entity s proper indexy a FK
3. Vytvořit repository s query metodami
4. Vytvořit service interface a DTOs
5. Implementovat concrete services
6. Přidat controller endpointy
7. Vytvořit CLI commands
8. Nakonfigurovat services.yaml (tagging, arguments)
9. Vytvořit migration
10. Aktualizovat .env.template

## Známé problémy a řešení

### AWS SES SDK
- Pro production je potřeba `composer require aws/aws-sdk-php`
- SesEmailSender zkontroluje isConfigured() před odesláním

### Symfony Mailer
- Je potřeba `composer require symfony/mailer`
- SmtpEmailSender používá MailerInterface

### Webhook security
- SES SNS webhooks by měly být ověřeny (SNS signature validation)
- Momentálně auto-confirm subscription bez validace

## Závislosti

```bash
composer require symfony/mailer
composer require aws/aws-sdk-php  # pro SES v production
```
