# Admin Panel - Přehled

Web Analyzer je automatizovaná platforma pro analýzu webů a generování obchodních nabídek. Admin panel slouží ke správě celého workflow od získání leadů až po odeslání personalizovaných nabídek.

## Navigace

Admin panel je rozdělen do 5 hlavních sekcí:

| Sekce | Popis |
|-------|-------|
| [Lead Pipeline](lead-pipeline.md) | Správa leadů, firem a analýz |
| [Workflow](workflow.md) | Návrhy a nabídky |
| [Email](email.md) | Šablony a odesílání emailů |
| [Monitoring](monitoring.md) | Sledování domén a tržních signálů |
| [Nastavení](configuration.md) | Uživatelé a konfigurace |

## Základní workflow

```
Lead → Analýza → Návrh (Proposal) → Nabídka (Offer) → Email
```

1. **Lead** - Přidáte URL webu jako potenciálního klienta
2. **Analýza** - Systém analyzuje web (SEO, bezpečnost, výkon, přístupnost)
3. **Návrh** - AI vygeneruje návrh na základě nalezených problémů
4. **Schválení** - Zkontrolujete a schválíte návrh (nebo zamítnete s/bez zamítnutí leadu)
5. **Nabídka** - Kliknutím na "Vytvořit nabídku" vygenerujete personalizovaný email
6. **Email** - Po schválení odešlete nabídku klientovi

### Detailní workflow

```
Lead → Analýza → Proposal (DRAFT)
                      │
        ┌─────────────┼─────────────┐
        ▼             ▼             ▼
    Schválit      Odmítnout    Odmítnout +
        │             │         zamítnout lead
        ▼             │             │
    APPROVED          │             ▼
        │             │         Lead DISMISSED
        ▼             │
  "Vytvořit           │
   nabídku"           │
        │             │
        ▼             ▼
  Offer (DRAFT)    (konec)
        │
        ▼
  Odeslat ke schválení → Schválit → Odeslat → SENT
```

## Oprávnění

Přístup k jednotlivým funkcím je řízen oprávněními. Viz [Oprávnění a role](permissions.md).

## Dashboard

Dashboard zobrazuje přehled aktuálního stavu:

- Počet leadů v jednotlivých stavech
- Čekající návrhy ke schválení
- Nabídky připravené k odeslání
- Poslední aktivity

## Tipy pro efektivní práci

- Používejte filtry pro rychlé vyhledávání
- Hromadné akce šetří čas při práci s více záznamy
- Pravidelně kontrolujte Dashboard pro přehled o stavu
- Nastavte si notifikace pro důležité události
