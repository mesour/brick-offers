# Návrh aplikace – Web Analyzer & Outreach System

Technická specifikace interního systému pro automatizovanou analýzu webů a generování nabídek.

---

## Technologický stack

| Komponenta | Technologie |
|------------|-------------|
| **Backend** | PHP 8.x, Nette Framework |
| **Databáze** | PostgreSQL |
| **Queue/Jobs** | RabbitMQ nebo Redis (volitelně) |
| **Storage** | S3-compatible (abstraktní interface) |
| **Email** | AWS SES (abstraktní interface) |
| **Browser automation** | Headless Chromium (Puppeteer/Playwright) |
| **Containerization** | Docker |
| **Orchestration** | Kubernetes |

---

## Architektura systému

```
┌─────────────────────────────────────────────────────────────────┐
│                         KUBERNETES CLUSTER                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐       │
│  │   Web App    │    │   Workers    │    │   Scheduler  │       │
│  │  (Admin UI)  │    │  (Analysis)  │    │   (Cron)     │       │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘       │
│         │                   │                   │                │
│         └───────────────────┼───────────────────┘                │
│                             │                                    │
│                    ┌────────▼────────┐                          │
│                    │   PostgreSQL    │                          │
│                    └─────────────────┘                          │
│                                                                  │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐       │
│  │   Chromium   │    │  S3 Storage  │    │   AWS SES    │       │
│  │  (Headless)  │    │  (Interface) │    │  (Interface) │       │
│  └──────────────┘    └──────────────┘    └──────────────┘       │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Moduly systému

### 1. Discovery Module (Získávání URL)

Zdroje pro získávání potenciálních klientů:

| Zdroj | Popis | Priorita |
|-------|-------|----------|
| **Ruční zadání** | URL zadané zaměstnanci (nejvyšší priorita) | 1 |
| **Google Search API** | Vyhledávání dle klíčových slov | 2 |
| **Seznam Search** | Český trh | 2 |
| **Firmy.cz, Google Maps** | Katalogy firem | 2 |
| **Vlastní crawler** | Procházení odkazů z nalezených webů | 3 |

**Entita: `Lead`**
```
- id: UUID
- url: string (doména)
- source: enum (manual, google, seznam, firmy_cz, crawler)
- affiliate_hash: string|null (pro ruční zadání)
- affiliate_user_id: FK|null
- status: enum (new, queued, analyzing, analyzed, approved, sent, responded, converted)
- priority: int (1-10)
- created_at: timestamp
- updated_at: timestamp
```

### 2. Analysis Module (Analýza webů)

#### 2.1 Analyzované problémy

**HTTP & Server:**
- Status kódy (404→200 problém)
- SSL certifikáty (platnost, expirace)
- TTFB (Time To First Byte)
- Mixed content

**Security hlavičky:**
- Content-Security-Policy
- X-Frame-Options
- X-Content-Type-Options
- Strict-Transport-Security
- Referrer-Policy

**Knihovny & technologie:**
- Verze jQuery a ostatních JS knihoven
- Deprecated funkce (console warnings)
- Outdated dependencies

**SEO:**
- Meta tagy (title, description, OG)
- Alt texty obrázků
- Struktura nadpisů (H1-H6)
- sitemap.xml, robots.txt
- Core Web Vitals (LCP, FID, CLS)

**W3C & HTML:**
- HTML validace
- Doctype, charset
- Sémantické značky

**Responzivita:**
- Viewport meta tag
- Testování v různých rozlišeních
- Touch target sizes

**Vizuální konzistence (AI-assisted):**
- Padding/margin konzistence
- Typografie
- Kvalita obrázků

**Přístupnost:**
- Barevný kontrast
- ARIA atributy
- Keyboard navigation

**Entita: `Analysis`**
```
- id: UUID
- lead_id: FK
- started_at: timestamp
- completed_at: timestamp
- status: enum (pending, running, completed, failed)
- raw_data: jsonb (všechna naměřená data)
- issues: jsonb (strukturovaný seznam problémů)
- scores: jsonb (skóre podle kategorií)
```

**Entita: `Issue`**
```
- id: UUID
- analysis_id: FK
- category: enum (http, security, libraries, seo, w3c, responsive, visual, accessibility)
- severity: enum (critical, recommended, optimization)
- code: string (např. "HTTP_404_RETURNS_200")
- title: string
- description: text
- evidence: jsonb (důkazy - screenshoty, data)
- impact: text (dopad pro laika)
```

#### 2.2 Evidence (Důkazy)

Pro každý problém se ukládá:
- Screenshot (pokud relevantní)
- Raw data (hlavičky, response, DOM)
- Metadata (čas, viewport, user-agent)

**Storage interface:**
```php
interface StorageInterface
{
    public function upload(string $path, string $content, string $contentType): string;
    public function download(string $path): string;
    public function delete(string $path): void;
    public function getUrl(string $path, int $expiration = 3600): string;
}
```

### 3. Design Generation Module

#### 3.1 Proces generování návrhu

1. **Stažení webu** (wget s limitem hloubky)
2. **Analýza struktury** (rozpoznání sekcí, obsahu)
3. **Generování nového designu** (HTML + CSS)
4. **Screenshot návrhu** (headless Chrome)
5. **Uložení pro budoucí použití**

**Entita: `DesignProposal`**
```
- id: UUID
- lead_id: FK|null (null = volný pro reuse)
- original_url: string
- html_content: text
- css_content: text
- screenshot_path: string (S3)
- status: enum (generated, proposed, accepted, rejected, reused)
- created_at: timestamp
- reused_from_id: FK|null (pokud je recyklovaný)
```

#### 3.2 Recyklace návrhů

- Pokud klient nepřijme návrh → `status = rejected`
- Systém může návrh přiřadit jinému leadovi s podobným profilem
- Při přiřazení se nastaví `reused_from_id`

### 4. Offer Module (Nabídky)

**Entita: `Offer`**
```
- id: UUID
- lead_id: FK
- analysis_id: FK
- design_proposal_id: FK|null
- email_content: text (vygenerovaný email)
- status: enum (draft, pending_approval, approved, sent, opened, clicked, responded)
- approved_by: FK|null (user_id)
- approved_at: timestamp|null
- sent_at: timestamp|null
- tracking_token: string (unique)
- created_at: timestamp
```

**Rate limiting:**
- Konfigurovatelný limit emailů za den/hodinu
- Samostatný limit pro každou doménu (aby se nezahlcoval jeden provider)

### 5. Email Module

**Interface pro abstrakci:**
```php
interface EmailServiceInterface
{
    public function send(Email $email): SendResult;
    public function getStatus(string $messageId): EmailStatus;
}

class SesEmailService implements EmailServiceInterface
{
    // AWS SES implementace
}
```

**Tracking:**
- Tracking pixel pro otevření (vede na veřejnou část)
- Tracking URL pro kliknutí
- Webhook pro bounces a complaints (SES)

**Entita: `EmailLog`**
```
- id: UUID
- offer_id: FK
- message_id: string (SES message ID)
- to_email: string
- subject: string
- sent_at: timestamp
- opened_at: timestamp|null
- clicked_at: timestamp|null
- bounced: boolean
- bounce_type: string|null
```

### 6. Admin Module

#### 6.1 Dashboard
- Přehled leadů podle statusu
- Pipeline visualization
- Statistiky (sent, opened, converted)
- Rate limit status

#### 6.2 Lead Management
- Seznam leadů s filtrováním
- Detail leadu s kompletní historií
- Ruční přidání URL (s možností affiliate hash)
- Bulk import z CSV

#### 6.3 Approval Workflow
- Fronta nabídek čekajících na schválení
- Preview emailu před odesláním
- Možnost editace před schválením
- Batch approve/reject

#### 6.4 Analysis Browser
- Procházení analýz
- Detail s všemi issues
- Porovnání před/po (pokud existuje)

#### 6.5 Design Gallery
- Přehled všech designových návrhů
- Filtr: volné pro reuse / přiřazené
- Ruční přiřazení designu k leadovi

#### 6.6 Settings
- Rate limits
- Email templates
- Crawler settings
- API keys (Google, Seznam)

---

## Affiliate systém

Pro sledování, kdo přivedl potenciálního zákazníka:

**Entita: `Affiliate`**
```
- id: UUID
- user_id: FK|null (pokud je to interní zaměstnanec)
- name: string
- hash: string (unique, pro URL)
- email: string
- commission_rate: decimal|null
- created_at: timestamp
- active: boolean
```

Při ručním zadání URL lze přiřadit affiliate hash. Pokud lead konvertuje, affiliate dostane kredit.

---

## Job Queue & Workers

### Typy jobů

| Job | Popis | Priorita |
|-----|-------|----------|
| `AnalyzeWebsiteJob` | Kompletní analýza webu | Normal |
| `GenerateDesignJob` | Generování design návrhu | Low |
| `GenerateScreenshotJob` | Screenshot (analýza i design) | Normal |
| `SendEmailJob` | Odeslání schváleného emailu | High |
| `CrawlWebsiteJob` | Procházení webu crawlerem | Low |
| `SearchGoogleJob` | Vyhledávání přes Google API | Low |

### Worker konfigurace

```yaml
workers:
  analysis:
    replicas: 2
    memory: 1Gi
    jobs: [AnalyzeWebsiteJob, GenerateScreenshotJob]
  
  design:
    replicas: 1
    memory: 2Gi
    jobs: [GenerateDesignJob]
  
  email:
    replicas: 1
    memory: 256Mi
    jobs: [SendEmailJob]
  
  discovery:
    replicas: 1
    memory: 512Mi
    jobs: [CrawlWebsiteJob, SearchGoogleJob]
```

---

## Headless Chromium

### Použití

1. **Screenshoty stránek** (různé viewporty)
2. **Analýza s JS** (spuštění JS, console log capture)
3. **Core Web Vitals měření**
4. **Screenshot designových návrhů**

### Generované obrázky (bez Chromium)

Pro některé "screenshoty" není potřeba skutečný browser:
- Console log výstup → HTML template → obrázek
- Tabulky s daty → HTML template → obrázek
- Hlavičky response → HTML template → obrázek

**Možnosti:**
- `wkhtmltoimage`
- Puppeteer s vlastním HTML
- Canvas rendering v Node.js

---

## API Endpoints (interní)

### Leads
```
GET    /api/leads                    # Seznam leadů
POST   /api/leads                    # Vytvořit lead (ruční)
GET    /api/leads/{id}               # Detail leadu
PATCH  /api/leads/{id}               # Update leadu
DELETE /api/leads/{id}               # Smazat lead

POST   /api/leads/import             # Bulk import z CSV
```

### Analyses
```
GET    /api/analyses                 # Seznam analýz
GET    /api/analyses/{id}            # Detail analýzy
POST   /api/leads/{id}/analyze       # Spustit analýzu
```

### Offers
```
GET    /api/offers                   # Seznam nabídek
GET    /api/offers/{id}              # Detail nabídky
POST   /api/offers                   # Vytvořit nabídku
PATCH  /api/offers/{id}              # Upravit nabídku
POST   /api/offers/{id}/approve      # Schválit nabídku
POST   /api/offers/{id}/send         # Odeslat nabídku
```

### Tracking (veřejné)
```
GET    /t/{token}/open               # Tracking pixel
GET    /t/{token}/click              # Tracking redirect
GET    /p/{token}                    # Veřejná stránka s detailem
```

---

## Veřejná část (Public Portal)

Minimalistická stránka pro klienty:

- **URL:** `/p/{tracking_token}`
- **Obsah:**
  - Shrnutí analýzy
  - Seznam issues (strukturovaně)
  - Screenshot návrhu designu
  - Kontaktní formulář / CTA

Tato stránka slouží jako:
- Cíl pro tracking kliknutí v emailu
- Místo pro detailnější informace
- Landing page pro konverzi

---

## Docker & Kubernetes

### Docker images

```dockerfile
# Base image pro PHP workers
FROM php:8.2-cli-alpine
# ... dependencies, Nette, atd.

# Image s Chromium pro screenshoty
FROM base-php
RUN apk add chromium chromium-chromedriver
```

### Kubernetes resources

```yaml
# Deployment pro web app
apiVersion: apps/v1
kind: Deployment
metadata:
  name: web-analyzer-app
spec:
  replicas: 2
  # ...

# Deployment pro workers
apiVersion: apps/v1
kind: Deployment
metadata:
  name: web-analyzer-workers
spec:
  replicas: 3
  # ...

# CronJob pro scheduler
apiVersion: batch/v1
kind: CronJob
metadata:
  name: web-analyzer-scheduler
spec:
  schedule: "*/5 * * * *"
  # ...
```

---

## Konfigurace

### Environment variables

```env
# Database
DATABASE_URL=postgresql://user:pass@host:5432/db

# Storage (S3)
STORAGE_DRIVER=s3
S3_BUCKET=web-analyzer
S3_REGION=eu-central-1
S3_KEY=...
S3_SECRET=...

# Email (SES)
EMAIL_DRIVER=ses
SES_REGION=eu-west-1
SES_KEY=...
SES_SECRET=...
EMAIL_FROM=noreply@example.com

# APIs
GOOGLE_SEARCH_API_KEY=...
SEZNAM_SEARCH_API_KEY=...

# Rate limits
RATE_LIMIT_EMAILS_PER_HOUR=50
RATE_LIMIT_EMAILS_PER_DAY=200
RATE_LIMIT_ANALYSES_PER_HOUR=100

# Chromium
CHROMIUM_PATH=/usr/bin/chromium
```

---

## Databázové schéma (PostgreSQL)

```sql
-- Leads
CREATE TABLE leads (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    url VARCHAR(500) NOT NULL,
    domain VARCHAR(255) NOT NULL,
    source VARCHAR(50) NOT NULL,
    affiliate_id UUID REFERENCES affiliates(id),
    status VARCHAR(50) NOT NULL DEFAULT 'new',
    priority INT NOT NULL DEFAULT 5,
    metadata JSONB,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Analyses
CREATE TABLE analyses (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lead_id UUID NOT NULL REFERENCES leads(id),
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    raw_data JSONB,
    scores JSONB,
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Issues
CREATE TABLE issues (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    analysis_id UUID NOT NULL REFERENCES analyses(id),
    category VARCHAR(50) NOT NULL,
    severity VARCHAR(50) NOT NULL,
    code VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    evidence JSONB,
    impact TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Design Proposals
CREATE TABLE design_proposals (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lead_id UUID REFERENCES leads(id),
    original_url VARCHAR(500),
    html_content TEXT,
    css_content TEXT,
    screenshot_path VARCHAR(500),
    status VARCHAR(50) NOT NULL DEFAULT 'generated',
    reused_from_id UUID REFERENCES design_proposals(id),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Offers
CREATE TABLE offers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    lead_id UUID NOT NULL REFERENCES leads(id),
    analysis_id UUID NOT NULL REFERENCES analyses(id),
    design_proposal_id UUID REFERENCES design_proposals(id),
    email_content TEXT NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'draft',
    approved_by UUID REFERENCES users(id),
    approved_at TIMESTAMP,
    sent_at TIMESTAMP,
    tracking_token VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Email Logs
CREATE TABLE email_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    offer_id UUID NOT NULL REFERENCES offers(id),
    message_id VARCHAR(255),
    to_email VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    sent_at TIMESTAMP,
    opened_at TIMESTAMP,
    clicked_at TIMESTAMP,
    bounced BOOLEAN DEFAULT FALSE,
    bounce_type VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Affiliates
CREATE TABLE affiliates (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID REFERENCES users(id),
    name VARCHAR(255) NOT NULL,
    hash VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255),
    commission_rate DECIMAL(5,2),
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT NOW()
);

-- Users (admin)
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    role VARCHAR(50) NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT NOW()
);

-- Indexy
CREATE INDEX idx_leads_status ON leads(status);
CREATE INDEX idx_leads_domain ON leads(domain);
CREATE INDEX idx_analyses_lead_id ON analyses(lead_id);
CREATE INDEX idx_issues_analysis_id ON issues(analysis_id);
CREATE INDEX idx_offers_tracking_token ON offers(tracking_token);
CREATE INDEX idx_email_logs_offer_id ON email_logs(offer_id);
```

---

## Další kroky k upřesnění

1. **Email templates** – konkrétní šablony pro různé typy klientů?
2. **Scoring algoritmus** – jak přesně počítat skóre z issues?
3. **AI integrace** – jaký model pro vizuální analýzu? GPT-4 Vision? Claude?
4. **Notifikace** – Slack/email pro nové konverze?
5. **Multi-tenancy** – bude systém jen pro vás, nebo i pro další uživatele?
6. **Billing/Invoicing** – integrace s fakturačním systémem?
