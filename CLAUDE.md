# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Session Workflow

**Na začátku každé session:**

1. Přečti `.ai/knowledge/todo-plan.md` pro aktuální stav projektu
2. Pokud není aktivní úkol, vezmi další z backlogu
3. Označ úkol jako `[>]` IN PROGRESS

**Před přechodem na další fázi (i desetinkovou):**

1. **VŽDY** projdi `.ai/knowledge/architecture-proposal.md` a ověř, že todo obsahuje vše potřebné
2. Zkontroluj i další relevantní knowledge dokumenty
3. Ověř, že plán odpovídá architektuře (Strapi, Figma export, integrace, atd.)

**Během práce:**

1. Pracuj na aktuálním úkolu
2. Po dokončení implementace počkej na testování od uživatele
3. Pokud uživatel nic dalšího nepožaduje, označ úkol jako `[x]` DONE
4. Přesuň do sekce Completed s datem
5. Vezmi další úkol z backlogu

**Na konci každé session:**

1. Zapiš do `SESSIONS.md` co bylo uděláno (datum, zaměření, dokončeno, blokery)
2. Aktualizuj `CHANGELOG.md` s novými změnami (Added, Changed, Fixed, atd.)

**Struktura TODO plánu** (`.ai/knowledge/todo-plan.md`):

- `[ ]` TODO - `[>]` IN PROGRESS - `[x]` DONE - `[!]` BLOCKED - `[?]` NEEDS CLARIFICATION

---

## Dokumentace implementací

Při implementaci nové featury (modul, dropdown, modal, form control, plugin, atd.) **vždy vytvoř dokumentační soubor** v `.ai/history/`:

### Struktura .ai/ složky

```
# Root soubory
SESSIONS.md                     # ⭐ Session log (co bylo uděláno v každé session)
CHANGELOG.md                    # ⭐ Changelog (Keep a Changelog formát)
CLAUDE.md                       # Tento soubor - hlavní context

.ai/
├── plans/                      # Plány budoucích implementací
│   ├── feature-xyz.todo.md     # Plánovaný úkol
│   ├── feature-abc.in-progress.md  # Rozpracovaný úkol
│   └── feature-def.done.md     # Dokončený plán (před přesunem do history)
├── history/                    # Historie dokončených implementací
│   ├── layout-module-tabs.md   # Příklad: TabsModule
│   ├── dropdown-color.md       # Příklad: ColorDropdown
│   └── form-control-spacing.md # Příklad: SpacingInput
├── bugs/                       # Řešené bugy (nesouvisející s novou featurou)
│   ├── nav-drag-out-fix.md
│   └── sortable-timing-issue.md
└── knowledge/                  # Detailní dokumentace (viz níže)
```

---

## Plány (.ai/plans/)

Při plánování (plan mode) **vytvoř plán** v `.ai/plans/` se suffixem podle stavu:

| Suffix | Stav | Popis |
|--------|------|-------|
| `.todo.md` | Naplánováno | Plán čeká na implementaci |
| `.in-progress.md` | Rozpracováno | Aktivně se implementuje |
| `.done.md` | Dokončeno | Implementace hotová, připraveno k přesunu do history |

### Životní cyklus plánu

1. **Plan mode** → vytvoř `feature-name.todo.md`
2. **Začátek implementace** → přejmenuj na `feature-name.in-progress.md`
3. **Dokončení** → přejmenuj na `feature-name.done.md`
4. **Po review** → přesuň obsah do `.ai/history/feature-name.md`

### Struktura plánu (.ai/plans/)

```markdown
# {Název} - Implementation Plan

## Shrnutí požadavků
- Co uživatel požaduje
- Klíčové požadavky a omezení

## Analýza současného stavu
- Relevantní existující kód
- Co je potřeba změnit/rozšířit

## Implementační plán
### Fáze 1: {název}
- [ ] Úkol 1
- [ ] Úkol 2

### Fáze 2: {název}
- [ ] Úkol 3

## Klíčové soubory k úpravě
- `path/to/file.ts` - popis změny

## Verifikace
- Jak ověřit že implementace funguje
```

---

## Historie implementací (.ai/history/)

Po dokončení implementace **vytvoř dokumentační soubor** v `.ai/history/`:

### Struktura dokumentačního souboru (.ai/history/)

```markdown
# {Název} - Implementation History

## Zadání
- Co uživatel požadoval
- Klíčové požadavky a rozhodnutí

## Vytvořené soubory
- Seznam všech nových souborů (FE + BE)
- Seznam aktualizovaných souborů

## Klíčové implementační detaily
- Architektura, data struktury
- Důležité patterns a konvence

## Checklist pro podobnou implementaci
- Kroky které je potřeba udělat

## Známé problémy a řešení
- Bugy které vznikly během implementace
```

> **Poznámka:** TODO položky patří do `.ai/plans/`, ne do history. History obsahuje pouze dokumentaci dokončených implementací.

### Struktura bug reportu (.ai/bugs/)

```markdown
# {Název bugu}

## Popis problému
- Co nefungovalo

## Příčina
- Proč to nefungovalo

## Řešení
- Jak bylo opraveno
- Které soubory byly změněny

## Prevence
- Jak se vyhnout podobnému problému
```

---

## Project Overview

Web Analyzer & Outreach System - an automated web analysis and lead generation platform that:
- Discovers potential clients from multiple sources (manual entry, Google Search, Seznam, Firmy.cz, crawling)
- Analyzes websites for technical issues (SSL, security headers, SEO, performance, accessibility)
- Generates professional outreach emails highlighting found issues
- Tracks leads through a workflow: new → queued → analyzing → analyzed → approved → sent → responded → converted

**Stack:** PHP 8.2+, Symfony 7.3, API Platform 4.2, Doctrine ORM, PostgreSQL 15.6

**Staging:** https://real-estate-data-source.k8stage.ulovdomov.cz/

## Development Commands

```bash
# First-time setup
make init                    # Start Docker containers
make composer                # Install PHP dependencies

# Daily development
make docker                  # Enter PHP container shell
make info                    # Show service URLs and ports

# Code quality
make cs                      # Run PHP CodeSniffer
make cs-fix                  # Auto-fix code style
make phpstan                 # Run static analysis
make phpunit                 # Run tests

# Rebuild
make rebuild                 # Rebuild containers with latest code
```

## Local Services

| Service | URL | Notes |
|---------|-----|-------|
| API | http://localhost:7270 | Main application |
| API Docs | http://localhost:7270/api/docs | Swagger UI |
| Adminer | http://localhost:7279 | Database UI (user: postgres, pass: password) |
| XDebug | localhost:50570 | For PhpStorm debugging |

## Architecture

### Symfony Microkernel
- Entry point: `public/index.php`
- Kernel: `src/Kernel.php` (uses MicroKernelTrait)
- Services auto-discovered from `src/` directory

### API Platform REST API
- All API endpoints prefixed with `/api`
- Resources defined in `src/ApiResource/`
- Auto-generated OpenAPI documentation

### Planned Modules (see docs/navrh-aplikace.md)
1. **Discovery Module** - Lead acquisition from various sources
2. **Analysis Module** - Website quality/security analysis with headless Chromium
3. **Design Generation Module** - New design proposals for analyzed sites
4. **Offer Module** - Email generation and approval workflow
5. **Email Module** - AWS SES integration with tracking
6. **Admin Module** - Dashboard and management UI

### Key Entities (planned)
- `Lead` - Potential client with URL and status workflow
- `Analysis` - Website analysis results with issues and scores
- `Issue` - Individual problems found (category, severity, evidence)
- `DesignProposal` - Generated design with reuse capability
- `Offer` - Email content with approval workflow
- `EmailLog` - Delivery tracking with opens/clicks

## Configuration

Environment variables in `.env` (copy from `.env.template`):
- `DATABASE_URL` - PostgreSQL connection string
- `APP_ENV` - Environment (dev/test/prod)
- `APP_SECRET` - Symfony secret key
- `CORS_ALLOW_ORIGIN` - CORS regex pattern

## Database

PostgreSQL accessed via Doctrine ORM:
- Migrations in `migrations/` directory
- Run migrations: `bin/console doctrine:migrations:migrate`
- Create migration: `bin/console doctrine:migrations:diff`

## Documentation

Project documentation in Czech:
- `docs/navrh-aplikace.md` - Complete system architecture and entity definitions
- `docs/popis-problemu.md` - Problem detection specifications and severity levels
- `docs/email-navrh.md` - Email template guidelines
