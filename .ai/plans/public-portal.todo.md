# Public Portal - Implementation Plan

## Status: TODO

## Shrnutí

Veřejné landing pages pro klienty - kompletní prezentace na jedné stránce: analýza + návrh + kontaktní formulář. Per-user branding s volitelným benchmark widgetem.

**Technologie:** Twig templates + Bootstrap 5 + vanilla JS (bez jQuery)

---

## Klíčová rozhodnutí

| Otázka | Odpověď |
|--------|---------|
| Účel portálu | Kompletní prezentace (analýza + návrh + formulář) |
| Technologie | Twig templates + Bootstrap 5 + vanilla JS (bez jQuery) |
| Benchmark | Volitelně per-user (nastavení v User.settings) |
| Industry templates | Jedna šablona s CSS variacemi |

---

## URL Struktura (zjednodušeno)

```
/p/{tracking_token}           - Hlavní landing page (vše na jedné stránce)
/p/{tracking_token}/contact   - Kontaktní formulář (volitelně separátně)
```

---

## Obsah landing page

1. **Header** - Logo klienta (z User.settings), název analyzovaného webu
2. **Score sekce** - Celkové skóre + volitelný benchmark text
3. **Problémy** - Seznam issues srozumitelně pro laika
4. **Návrh** - Screenshot/preview proposalu (pokud existuje)
5. **Kontaktní formulář** - Inline na stránce
6. **Footer** - Unsubscribe link, powered by

---

## Implementační plán

### Fáze 1: Controller + Base Template

- [ ] `src/Controller/PublicPortalController.php`
  - `show(string $token)` - hlavní stránka
  - `contact(string $token, Request $request)` - form handling
- [ ] Token validation (load Offer by tracking_token)
- [ ] noindex/nofollow meta tags
- [ ] `templates/portal/base.html.twig` - layout s per-user brandingem
- [ ] `templates/portal/show.html.twig` - landing page

---

### Fáze 2: Zobrazení analýzy

#### 2.1 Score Display
- [ ] `templates/portal/partials/score.html.twig`
- [ ] Celkové skóre vizualizace (kruhový progress bar)
- [ ] Score breakdown by category (progress bars)
- [ ] Barevné kódování podle skóre

#### 2.2 Issues Display
- [ ] `templates/portal/partials/issues.html.twig`
- [ ] Seznam issues srozumitelně pro laika
- [ ] IssueCategory ikony a barvy:
  - HTTP - modrá
  - SECURITY - červená
  - SEO - zelená
  - PERFORMANCE - oranžová
  - ACCESSIBILITY - fialová
  - BEST_PRACTICES - šedá
- [ ] IssueSeverity barevné kódování:
  - CRITICAL - červená (#dc3545)
  - HIGH - oranžová (#fd7e14)
  - MEDIUM - žlutá (#ffc107)
  - LOW - modrá (#17a2b8)
  - INFO - šedá (#6c757d)

#### 2.3 Benchmark Widget (volitelný)
- [ ] Jednoduchá věta "Váš web je lepší/horší než průměr odvětví"
- [ ] Zobrazení řízeno přes `User.settings.portal.showBenchmark: boolean`
- [ ] Žádná samostatná stránka - pouze inline text

---

### Fáze 3: Zobrazení návrhu (proposal)

- [ ] `templates/portal/partials/proposal.html.twig`
- [ ] Screenshot preview (pokud existuje proposal)
- [ ] Link na full-size nebo interaktivní verzi
- [ ] Podpora různých typů:
  - design_mockup - screenshot preview
  - report typy - PDF ke stažení nebo inline preview
- [ ] Graceful handling když proposal neexistuje

---

### Fáze 4: Kontaktní formulář

#### 4.1 Form Setup
- [ ] `src/Form/PortalContactFormType.php`
- [ ] `src/Service/PortalContactHandler.php`
- [ ] `templates/portal/partials/contact.html.twig`

#### 4.2 Form Fields
- [ ] Jméno (předvyplněno z Lead)
- [ ] Email (předvyplněno z Lead)
- [ ] Telefon (volitelný)
- [ ] Typ zájmu (dropdown):
  - Nový design webu
  - SEO audit
  - Marketing konzultace
  - Bezpečnostní audit
  - Jiné
- [ ] Zpráva (textarea)

#### 4.3 GDPR & Security
- [ ] GDPR souhlas checkbox (povinný)
- [ ] Link na privacy policy
- [ ] Honeypot field (spam protection)
- [ ] Consent logging

#### 4.4 Submission Handling
- [ ] Email notifikace vlastníkovi leadu (User)
- [ ] Lead status update
- [ ] Confirmation message

---

### Fáze 5: Styling + JS

#### 5.1 Bootstrap 5 Setup
- [ ] Instalace Bootstrap 5 via npm/Webpack Encore
- [ ] `assets/styles/portal.scss` - Bootstrap import + customizace
- [ ] CSS variables pro theming (primaryColor z User.settings)
- [ ] Využití Bootstrap komponent (cards, alerts, buttons, forms, progress bars)
- [ ] Responsivní grid system (mobile-first)
- [ ] Print styles

#### 5.2 Vanilla JS
- [ ] `assets/js/portal.js`
- [ ] Bootstrap JS komponenty (bez jQuery - Bootstrap 5 je jQuery-free)
- [ ] Form validation (Bootstrap validation styles + vanilla JS)
- [ ] Smooth scroll navigace

#### 5.3 Grafy (volitelně)
- [ ] Chart.js pro score breakdown
- [ ] Alternativně: Bootstrap progress bars pro jednodušší vizualizaci

#### 5.4 Visit Tracking
- [ ] Track page view při načtení
- [ ] Update Offer.viewedAt

---

## User.settings rozšíření

Přidat do User entity settings JSON:

```json
{
  "portal": {
    "showBenchmark": true,
    "logo": "https://...",
    "primaryColor": "#3498db",
    "companyName": "Example s.r.o."
  }
}
```

---

## Klíčové soubory

```
src/Controller/
└── PublicPortalController.php

src/Form/
└── PortalContactFormType.php

src/Service/
└── PortalContactHandler.php

templates/portal/
├── base.html.twig
├── show.html.twig
└── partials/
    ├── score.html.twig
    ├── issues.html.twig
    ├── proposal.html.twig
    └── contact.html.twig

assets/
├── styles/
│   ├── _bootstrap-custom.scss  # Bootstrap variables override
│   └── portal.scss             # Portal styles
└── js/portal.js
```

---

## Mockup - Landing Page

```
┌─────────────────────────────────────────────────────────┐
│  [User Logo]                    [Company Name s.r.o.]   │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  Analýza webu: example.cz                              │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ CELKOVÉ SKÓRE                                   │   │
│  │      ┌───┐                                      │   │
│  │      │65 │  Váš web je lepší než průměr        │   │
│  │      └───┘  ve vašem odvětví.                   │   │
│  │   /100 bodů                                     │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  Score breakdown:                                       │
│  Performance  ████████░░ 78%                           │
│  Security     ██████░░░░ 55%                           │
│  SEO          █████████░ 85%                           │
│  Accessibility ███████░░░ 68%                          │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ NALEZENÉ PROBLÉMY                        🔴 3   │   │
│  │ • SSL certifikát expiruje za 14 dní             │   │
│  │ • Chybí důležitá bezpečnostní nastavení         │   │
│  │ • Formuláře nejsou dostatečně zabezpečené       │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ NÁVRH NOVÉHO DESIGNU                            │   │
│  │ ┌───────────────────────────────────────────┐   │   │
│  │ │                                           │   │   │
│  │ │         [Design Screenshot]               │   │   │
│  │ │                                           │   │   │
│  │ └───────────────────────────────────────────┘   │   │
│  │ [Zobrazit detail]                              │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
│  ┌─────────────────────────────────────────────────┐   │
│  │ MÁTE ZÁJEM O ZLEPŠENÍ?                          │   │
│  │                                                 │   │
│  │ Jméno:  [Jan Novák___________]                  │   │
│  │ Email:  [jan@example.cz______]                  │   │
│  │ Telefon: [+420 _____________]                   │   │
│  │ Zájem o: [Nový design webu     ▼]               │   │
│  │ Zpráva: [____________________]                  │   │
│  │          [____________________]                  │   │
│  │                                                 │   │
│  │ [x] Souhlasím se zpracováním osobních údajů    │   │
│  │                                                 │   │
│  │ [        ODESLAT POPTÁVKU        ]              │   │
│  └─────────────────────────────────────────────────┘   │
│                                                         │
├─────────────────────────────────────────────────────────┤
│  © 2026 | Powered by WebAnalyzer | Unsubscribe         │
└─────────────────────────────────────────────────────────┘
```

---

## SEO & Indexace

- [ ] `<meta name="robots" content="noindex, nofollow">`
- [ ] `robots.txt` - block `/p/*`
- [ ] No canonical URL

---

## Závislosti

- **Offer** - tracking token, offer data
- **Analysis** - analýza a issues
- **Proposal** - návrhy pro zobrazení (volitelně)
- **Lead** - kontaktní údaje pro předvyplnění
- **User** - settings pro branding
- **IndustryBenchmark** - benchmark data (pro volitelný widget)
- **Bootstrap 5** - sdílený s Admin Module (via Webpack Encore)

---

## Verifikace

```bash
# Po implementaci
curl http://localhost:7270/p/{tracking_token}

# Ověřit:
# 1. Zobrazení analýzy a skóre
# 2. Zobrazení návrhu (pokud existuje)
# 3. Funkčnost kontaktního formuláře
# 4. Per-user branding (logo, barvy)
# 5. Benchmark text (pokud zapnutý v User.settings)
# 6. Responsivní design na mobilu
```
