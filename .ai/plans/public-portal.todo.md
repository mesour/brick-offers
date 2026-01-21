# Public Portal - Implementation Plan

## Status: TODO (otázky k zodpovězení)

## Shrnutí

Veřejné landing pages pro klienty - zobrazení analýzy, návrhu a kontaktního formuláře.

---

## Předpokládané URL

```
/p/{tracking_token}           - Hlavní landing page
/p/{tracking_token}/analysis  - Detailní analýza
/p/{tracking_token}/proposal  - Návrh/design
/p/{tracking_token}/contact   - Kontaktní formulář
```

---

## Otázky k zodpovězení

### 1. Design a branding

- [ ] **Jednotný design** - jedna šablona pro všechny?
- [ ] **Per-user branding** - logo, barvy z User.settings?
- [ ] **Per-industry** - různé šablony podle odvětví?
- [ ] **White-label** - kompletně customizovatelné?

### 2. Obsah stránky

Co zobrazit na landing page?
- [ ] Shrnutí analýzy (issues, skóre)
- [ ] Screenshot návrhu (pokud existuje)
- [ ] Seznam problémů (srozumitelně pro laika)
- [ ] CTA / kontaktní formulář
- [ ] Cena/nabídka?

### 3. Kontaktní formulář

- [ ] Jaká pole? (jméno, email, telefon, zpráva)
- [ ] Kam posílat? (email, webhook, CRM?)
- [ ] Notifikace vlastníkovi leadu?
- [ ] Spam protection (reCAPTCHA, honeypot)?

### 4. Tracking

- [ ] Logovat návštěvy?
- [ ] A/B testování variant?
- [ ] Heatmapy? (externí služba)

### 5. SEO a indexace

- [ ] **noindex** - nechceme v Google?
- [ ] **robots.txt** block?
- [ ] Expirační doba stránky?

### 6. Multi-tenancy

- Stránka patří uživateli přes Offer → Lead → User
- Branding z User.settings?

---

## Předběžný implementační plán

### Fáze 1: Controller

- [ ] `src/Controller/PublicPortalController.php`
  - `show(string $token)` - hlavní stránka
  - `analysis(string $token)` - detail analýzy
  - `proposal(string $token)` - návrh
  - `contact(string $token, Request $request)` - formulář

### Fáze 2: Templates

- [ ] `templates/portal/base.html.twig`
- [ ] `templates/portal/show.html.twig`
- [ ] `templates/portal/analysis.html.twig`
- [ ] `templates/portal/proposal.html.twig`
- [ ] `templates/portal/contact.html.twig`

### Fáze 3: Assets

- [ ] `assets/styles/portal.css`
- [ ] `assets/js/portal.js`

### Fáze 4: Contact Form

- [ ] `src/Form/ContactFormType.php`
- [ ] `src/Service/ContactFormHandler.php`
- [ ] Email notifikace

### Fáze 5: Visit Tracking (volitelné)

- [ ] `src/Entity/PortalVisit.php` (?)
- [ ] Logování v controlleru

---

## Závislosti

- **Offer Module** - tracking token
- **Analysis** - data pro zobrazení
- **Proposal** - návrh pro zobrazení

---

## Mockup stránky (předběžný)

```
┌─────────────────────────────────────────┐
│  [Logo]                                  │
├─────────────────────────────────────────┤
│                                         │
│  Analýza vašeho webu: example.cz        │
│                                         │
│  ┌─────────────────────────────────┐    │
│  │ Celkové skóre: 65/100           │    │
│  │ Kritické problémy: 3            │    │
│  │ Doporučení: 12                  │    │
│  └─────────────────────────────────┘    │
│                                         │
│  Nalezené problémy:                     │
│  • SSL certifikát expiruje za 14 dní    │
│  • Chybí security headers              │
│  • Pomalé načítání (4.2s)              │
│                                         │
│  ┌─────────────────────────────────┐    │
│  │ [Screenshot návrhu]              │    │
│  │                                  │    │
│  └─────────────────────────────────┘    │
│                                         │
│  ┌─────────────────────────────────┐    │
│  │ Kontaktujte nás                 │    │
│  │ Jméno: [____________]           │    │
│  │ Email: [____________]           │    │
│  │ Telefon: [____________]         │    │
│  │ [Odeslat]                       │    │
│  └─────────────────────────────────┘    │
│                                         │
├─────────────────────────────────────────┤
│  © 2026 | Unsubscribe                   │
└─────────────────────────────────────────┘
```

---

## Verifikace (předběžná)

```bash
# Vytvoření test offeru s tokenem
curl http://localhost:7270/p/test-token-123
```
