# Job Queue Module - Implementation Plan

## Status: TODO (otázky k zodpovězení)

## Shrnutí

Background processing pro dlouhotrvající úlohy (analýzy, generování, odesílání).

---

## Otázky k zodpovězení

### 1. Technologie

- [ ] **Symfony Messenger** + transport?
- [ ] **Která transport?**
  - [ ] Redis (jednoduché, rychlé)
  - [ ] RabbitMQ (robustnější, komplexnější)
  - [ ] Doctrine (nejjednodušší, databáze)
  - [ ] Amazon SQS (cloud-native)

### 2. Priority a fronty

Kolik front potřebujeme?
- [ ] **Jedna fronta** - FIFO, jednodušší
- [ ] **Více front s prioritami:**
  - `high` - emaily, urgentní
  - `normal` - analýzy, proposals
  - `low` - crawling, batch operace

### 3. Workers

- [ ] **Kolik workerů?** - 1, 2, více?
- [ ] **Memory limits?**
- [ ] **Timeout per job?**
- [ ] **Retry strategie?** - kolikrát, s jakou pauzou

### 4. Monitoring

- [ ] **Failed jobs** - kam logovat?
- [ ] **Metrics** - Prometheus/Grafana?
- [ ] **Alerting** - notifikace při selhání?

### 5. Scheduler (cron jobs)

- [ ] **Symfony Scheduler** component?
- [ ] **Klasický cron** + commands?

Jaké scheduled joby?
- [ ] Kontrola expirace (proposals, SSL)
- [ ] Batch crawling
- [ ] Statistiky/reporty
- [ ] Cleanup starých dat

---

## Předpokládané Job typy

### Vysoká priorita
- `SendEmailMessage` - odeslání schváleného emailu
- `ProcessWebhookMessage` - zpracování SES webhook

### Normální priorita
- `AnalyzeWebsiteMessage` - analýza webu
- `GenerateProposalMessage` - AI generování návrhu
- `GenerateScreenshotMessage` - screenshot capture
- `SyncAresDataMessage` - ARES synchronizace

### Nízká priorita
- `CrawlWebsiteMessage` - crawling odkazů
- `DiscoverLeadsMessage` - batch discovery
- `CalculateBenchmarksMessage` - statistiky
- `CleanupExpiredMessage` - mazání starých dat

---

## Předběžný implementační plán

### Fáze 1: Messenger setup

- [ ] `composer require symfony/messenger`
- [ ] `config/packages/messenger.yaml`
- [ ] Transport konfigurace

### Fáze 2: Messages

- [ ] `src/Message/SendEmailMessage.php`
- [ ] `src/Message/AnalyzeWebsiteMessage.php`
- [ ] `src/Message/GenerateProposalMessage.php`
- [ ] ...

### Fáze 3: Handlers

- [ ] `src/MessageHandler/SendEmailMessageHandler.php`
- [ ] `src/MessageHandler/AnalyzeWebsiteMessageHandler.php`
- [ ] `src/MessageHandler/GenerateProposalMessageHandler.php`
- [ ] ...

### Fáze 4: Scheduler (volitelné)

- [ ] `composer require symfony/scheduler`
- [ ] `src/Scheduler/MainSchedule.php`

### Fáze 5: Worker commands

- [ ] Supervisor konfigurace
- [ ] Docker compose pro workers

---

## Konfigurace (předběžná)

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            high_priority:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: high
            normal:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: normal
            low_priority:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    queue_name: low

        routing:
            'App\Message\SendEmailMessage': high_priority
            'App\Message\AnalyzeWebsiteMessage': normal
            'App\Message\CrawlWebsiteMessage': low_priority
```

```env
# .env
MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages
# nebo
MESSENGER_TRANSPORT_DSN=doctrine://default
# nebo
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@localhost:5672/%2f/messages
```

---

## Worker spouštění

```bash
# Development
bin/console messenger:consume high_priority normal low_priority -vv

# Production (supervisor)
[program:messenger-worker]
command=php /var/www/html/bin/console messenger:consume high_priority normal low_priority --time-limit=3600
numprocs=2
autostart=true
autorestart=true
```

---

## Závislosti

- **Redis** nebo **RabbitMQ** nebo **PostgreSQL** (pro Doctrine transport)
- Všechny ostatní moduly (analýza, email, proposal...)

---

## Verifikace (předběžná)

```bash
# Test message dispatch
bin/console debug:messenger

# Spuštění workeru
bin/console messenger:consume -vv

# Kontrola fronty
bin/console messenger:stats
```
