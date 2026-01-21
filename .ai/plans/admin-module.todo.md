# Admin Module - Implementation Plan

## Status: TODO (otázky k zodpovězení)

## Shrnutí

Web UI dashboard pro správu leadů, nabídek, schvalování a statistik.

---

## Otázky k zodpovězení

### 1. Technologie

- [ ] **Samostatná SPA** - React/Vue/Svelte v separátním repo?
- [ ] **Symfony UX** - Twig + Turbo + Stimulus (v tomto repo)?
- [ ] **EasyAdmin** - rychlý CRUD admin bundle?
- [ ] **API Admin** - čistě API, frontend jinde?

### 2. Autentizace

- [ ] **Session-based** - klasické Symfony security?
- [ ] **JWT tokens** - pro SPA?
- [ ] **OAuth** - Google/GitHub login?
- [ ] **API keys** - pro programový přístup?

### 3. Role a oprávnění

Jaké role potřebujeme?
- [ ] **Admin** - plný přístup
- [ ] **Manager** - schvalování, statistiky
- [ ] **User** - základní operace
- [ ] **Viewer** - pouze read-only?

Jaká oprávnění per role?
- Leads: create, read, update, delete?
- Offers: create, approve, send?
- Proposals: generate, approve?
- Settings: edit?

### 4. Funkce dashboardu

Priority funkcí:
- [ ] **Lead pipeline** - kanban/list view?
- [ ] **Approval queue** - nabídky ke schválení
- [ ] **Statistiky** - sent, opened, converted
- [ ] **Bulk operace** - hromadné schvalování?
- [ ] **Notifikace** - nové leady, konverze?

### 5. Multi-tenancy v UI

- Filtrování podle user?
- Přepínání mezi uživateli (pro admina)?
- Izolace dat v UI?

---

## Předběžný implementační plán (závisí na odpovědích)

### Varianta A: EasyAdmin (rychlé)

- [ ] `composer require easycorp/easyadmin-bundle`
- [ ] `src/Controller/Admin/DashboardController.php`
- [ ] `src/Controller/Admin/LeadCrudController.php`
- [ ] `src/Controller/Admin/OfferCrudController.php`
- [ ] ...

### Varianta B: Symfony UX (střední)

- [ ] `composer require symfony/ux-turbo symfony/ux-live-component`
- [ ] `templates/admin/` - Twig templates
- [ ] `assets/controllers/` - Stimulus controllers
- [ ] `src/Controller/AdminController.php`

### Varianta C: Separátní SPA (komplexní)

- [ ] Separátní repo (např. admin-frontend)
- [ ] API-only backend
- [ ] CORS konfigurace
- [ ] JWT autentizace

---

## UI Sekce (předběžné)

### Dashboard
- Statistiky (leads, offers, emails)
- Pipeline overview
- Recent activity

### Leads
- Seznam s filtrováním
- Detail leadu
- Bulk import

### Offers
- Approval queue
- Seznam nabídek
- Preview před odesláním

### Proposals
- Gallery návrhů
- Recyklace management

### Analyses
- Procházení analýz
- Porovnání

### Settings
- Rate limits
- Email templates
- API keys

---

## Závislosti

- **Všechny ostatní moduly** - zobrazuje jejich data
- **Autentizace** - User entity již existuje

---

## Konfigurace (předběžná)

```env
# Admin
ADMIN_PATH=/admin
ADMIN_LOCALE=cs
```
