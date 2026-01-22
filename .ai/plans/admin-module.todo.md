# Admin Module - Implementation Plan

## Status: TODO

## Shrnutí

EasyAdmin-based dashboard pro správu všech entit, workflow, statistik a multi-tenant data isolation.

---

## Rozhodnutí (potvrzeno)

| Otázka | Odpověď |
|--------|---------|
| Uživatelé | Já + budoucí klienti (multi-tenant) |
| Autentizace | Email + heslo (Symfony Security) |
| Technologie | EasyAdmin Bundle + Bootstrap 5 (pro custom widgety) |
| Scope | Kompletní admin od začátku |

---

## Implementační plán

### Fáze 1: Setup EasyAdmin + Autentizace

#### 1.1 EasyAdmin instalace
- [ ] `composer require easycorp/easyadmin-bundle`
- [ ] `src/Controller/Admin/DashboardController.php`
- [ ] Základní konfigurace a routing

#### 1.2 User entity rozšíření
- [ ] Přidat `password` field do User entity
- [ ] Přidat `roles` field (array)
- [ ] Migrace pro nová pole

#### 1.3 Symfony Security
- [ ] `config/packages/security.yaml` - firewall + provider
- [ ] Form login na `/admin/login`
- [ ] Password hashing přes `UserPasswordHasherInterface`
- [ ] Remember me cookie (volitelně)
- [ ] Logout route

---

### Fáze 2: Core Entity CRUD Controllers

#### 2.1 Lead Management
- [ ] `src/Controller/Admin/LeadCrudController.php`
  - Všechna pole: url, status, ico, cms, technologies, social links
  - Filtry: status, created_at, has_analysis
  - Custom actions: Spustit analýzu, Spustit discovery

#### 2.2 Company Management
- [ ] `src/Controller/Admin/CompanyCrudController.php`
  - ARES data zobrazení
  - Custom action: Refresh ARES data

#### 2.3 Analysis Management
- [ ] `src/Controller/Admin/AnalysisCrudController.php`
  - Zobrazení score, issues count
  - Link na AnalysisResult

- [ ] `src/Controller/Admin/AnalysisResultCrudController.php`
  - Výsledky analyzátorů

- [ ] `src/Controller/Admin/AnalysisSnapshotCrudController.php`
  - Agregované snapshoty

---

### Fáze 3: Proposal & Offer CRUD

#### 3.1 Proposal Management
- [ ] `src/Controller/Admin/ProposalCrudController.php`
  - 7 typů návrhů
  - Preview content
  - Custom actions: Approve, Reject, Recycle

#### 3.2 Offer Management
- [ ] `src/Controller/Admin/OfferCrudController.php`
  - 9 workflow stavů s barevným zobrazením
  - Custom actions: Submit for approval, Approve, Reject, Send
  - Preview emailu

#### 3.3 Email Templates
- [ ] `src/Controller/Admin/EmailTemplateCrudController.php`
  - Globální šablony

- [ ] `src/Controller/Admin/UserEmailTemplateCrudController.php`
  - Per-user šablony

---

### Fáze 4: Email System CRUD

#### 4.1 Email Log
- [ ] `src/Controller/Admin/EmailLogCrudController.php`
  - Zobrazení: status, opens, clicks, bounces
  - Filtry: status, date range
  - Read-only (no create/edit)

#### 4.2 Email Blacklist
- [ ] `src/Controller/Admin/EmailBlacklistCrudController.php`
  - Global + per-user blacklist
  - Custom action: Add/Remove email

---

### Fáze 5: Multi-tenancy Configs

#### 5.1 User Config
- [ ] `src/Controller/Admin/UserCrudController.php`
  - Správa uživatelů (pro superadmin)
  - Password field s hasherem

- [ ] `src/Controller/Admin/UserAnalyzerConfigCrudController.php`
  - Per-user konfigurace analyzátorů

- [ ] `src/Controller/Admin/UserCompanyNoteCrudController.php`
  - Per-user poznámky k firmám

---

### Fáze 6: Competitor & Signals CRUD

#### 6.1 Monitored Domains
- [ ] `src/Controller/Admin/MonitoredDomainCrudController.php`
- [ ] `src/Controller/Admin/MonitoredDomainSubscriptionCrudController.php`
- [ ] `src/Controller/Admin/CompetitorSnapshotCrudController.php`

#### 6.2 Demand Signals
- [ ] `src/Controller/Admin/DemandSignalCrudController.php`
- [ ] `src/Controller/Admin/DemandSignalSubscriptionCrudController.php`
- [ ] `src/Controller/Admin/MarketWatchFilterCrudController.php`

---

### Fáze 7: Benchmarks

- [ ] `src/Controller/Admin/IndustryBenchmarkCrudController.php`
  - Benchmarky podle odvětví
  - Import/export dat

---

### Fáze 8: Dashboard Widgets

> **Poznámka:** EasyAdmin používá vlastní CSS (založené na Bootstrap), pro custom widgety použijeme Bootstrap 5 komponenty (cards, badges, progress bars) - konzistentní s Public Portal.

- [ ] **Lead Statistics Widget**
  - Počty: new, analyzed, sent, converted
  - Trend graf

- [ ] **Pipeline Overview Widget**
  - Kanban nebo funnel vizualizace
  - Klikatelné statusy

- [ ] **Email Delivery Stats Widget**
  - Sent, opened, clicked, bounced
  - Procentuální success rate

- [ ] **Recent Activity Feed Widget**
  - Poslední akce v systému
  - Real-time updates (volitelně)

- [ ] **Job Queue Status Widget**
  - Pending, processing, failed jobs
  - Link na messenger:failed queue

---

### Fáze 9: Batch Operations

- [ ] Hromadné schválení nabídek
- [ ] Hromadná analýza leadů
- [ ] Hromadný export dat
- [ ] Hromadné přesunutí statusu

---

### Fáze 10: Multi-tenancy Security

#### 10.1 Data Isolation
- [ ] Override `createIndexQueryBuilder()` ve všech CRUD
- [ ] Filtrování podle přihlášeného usera
- [ ] Automatické nastavení `user_id` při vytváření

#### 10.2 Role System
- [ ] ROLE_USER - vidí jen svá data
- [ ] ROLE_ADMIN - vidí vše (superadmin)
- [ ] Voter pro entity-level permissions

---

## Klíčové soubory

```
src/Controller/Admin/
├── DashboardController.php        # Hlavní dashboard
├── LeadCrudController.php         # Leady
├── CompanyCrudController.php      # Firmy
├── AnalysisCrudController.php     # Analýzy
├── AnalysisResultCrudController.php
├── AnalysisSnapshotCrudController.php
├── ProposalCrudController.php     # Návrhy
├── OfferCrudController.php        # Nabídky
├── EmailTemplateCrudController.php
├── UserEmailTemplateCrudController.php
├── EmailLogCrudController.php     # Email log
├── EmailBlacklistCrudController.php
├── UserCrudController.php         # Uživatelé
├── UserAnalyzerConfigCrudController.php
├── UserCompanyNoteCrudController.php
├── MonitoredDomainCrudController.php
├── MonitoredDomainSubscriptionCrudController.php
├── CompetitorSnapshotCrudController.php
├── DemandSignalCrudController.php
├── DemandSignalSubscriptionCrudController.php
├── MarketWatchFilterCrudController.php
└── IndustryBenchmarkCrudController.php

config/packages/
├── security.yaml                  # Autentizace
└── easy_admin.yaml               # EasyAdmin config

templates/admin/
├── login.html.twig               # Login stránka
└── dashboard.html.twig           # Custom dashboard
```

---

## Konfigurace

```yaml
# config/packages/security.yaml
security:
    password_hashers:
        App\Entity\User:
            algorithm: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        admin:
            pattern: ^/admin
            form_login:
                login_path: admin_login
                check_path: admin_login
                default_target_path: /admin
            logout:
                path: admin_logout
            remember_me:
                secret: '%kernel.secret%'

    access_control:
        - { path: ^/admin/login, roles: PUBLIC_ACCESS }
        - { path: ^/admin, roles: ROLE_USER }
```

---

## Závislosti

- **Všechny entity moduly** - zobrazuje jejich data
- **User entity** - rozšíření o password a roles
- **Bootstrap 5** - sdílený s Public Portal (via Webpack Encore)

---

## Verifikace

```bash
# EasyAdmin instalace
composer require easycorp/easyadmin-bundle

# Vytvoření admin uživatele
bin/console app:user:create admin@example.com password123

# Přístup na admin
http://localhost:7270/admin
```
