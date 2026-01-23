# Workflow

Sekce pro správu návrhů a nabídek - klíčová část procesu oslovování klientů.

---

## Kompletní workflow

```
Lead → Analýza → Proposal (DRAFT)
                      │
          ┌───────────┼───────────┐
          ▼           ▼           ▼
      Schválit    Odmítnout   Odmítnout +
          │           │        zamítnout lead
          ▼           │           │
      APPROVED        │           │
          │           │           ▼
          ▼           │       Lead → DISMISSED
    "Vytvořit         │
     nabídku"         │
          │           │
          ▼           ▼
    Offer (DRAFT)   (konec)
          │
          ▼
    Odeslat ke schválení
          │
          ▼
    PENDING_APPROVAL
          │
          ▼
      Schválit
          │
          ▼
      APPROVED
          │
          ▼
      Odeslat
          │
          ▼
       SENT → OPENED → CLICKED → RESPONDED → CONVERTED
```

---

## Proposals (Návrhy)

**K čemu slouží:** AI-generované návrhy na základě výsledků analýzy. Návrhy slouží jako podklad pro vytvoření emailové nabídky.

### Stavy návrhu

| Stav | Popis |
|------|-------|
| `generating` | Generuje se pomocí AI |
| `draft` | Nově vygenerovaný návrh |
| `approved` | Schválen - připraven k vytvoření nabídky |
| `rejected` | Zamítnut - nepoužije se |
| `used` | Použit pro vytvoření nabídky |
| `recycled` | Recyklován pro jiného uživatele |
| `expired` | Vypršela platnost |

### Dostupné akce

| Akce | Ikona | Popis | Kdy je viditelná |
|------|-------|-------|------------------|
| **Zobrazit detail** | 👁 | Kompletní text návrhu | Vždy |
| **Schválit** | ✓ | Označit návrh jako vhodný pro nabídku | Stav `draft` |
| **Odmítnout** | ✗ | Označit návrh jako nevhodný (lead zůstává) | Stav `draft` |
| **Odmítnout + zamítnout lead** | ⊘ | Zamítnout návrh a zároveň nastavit lead na DISMISSED | Stav `draft`, lead není zamítnutý |
| **Náhled** | 👁 | Náhled HTML mockupu v novém okně | Existuje HTML výstup |
| **Vytvořit nabídku** | ✉ | Vytvořit Offer z návrhu | Stav `approved` |

### Pole v seznamu

- Lead (URL webu)
- Stav
- Typ návrhu
- Odvětví
- AI generováno
- Datum vytvoření

### Filtry

- Stav návrhu
- Typ návrhu
- Odvětví
- AI generováno
- Recyklovatelné
- Datum vytvoření

### Co obsahuje návrh

- Shrnutí nalezených problémů
- Doporučená řešení
- Odhadovaný přínos pro klienta
- Navrhované služby
- HTML mockup (pro webdesign návrhy)

### Tipy

- Před schválením zkontrolujte relevanci nalezených problémů
- Použijte "Odmítnout + zamítnout lead" pokud lead není vhodný pro oslovení
- Použijte "Odmítnout" pokud chcete pouze zamítnout návrh, ale lead ponechat pro případné budoucí oslovení
- Po schválení klikněte na "Vytvořit nabídku" pro automatické vygenerování emailu
- Schválený návrh lze použít pro více nabídek

---

## Offers (Nabídky)

**K čemu slouží:** Emailové nabídky vytvořené na základě schválených návrhů. Nabídky procházejí workflow od konceptu po odeslání.

### Stavy nabídky

| Stav | Popis | Následující akce |
|------|-------|------------------|
| `draft` | Koncept nabídky | Upravit, Schválit |
| `pending_approval` | Čeká na schválení | Schválit, Vrátit k úpravě |
| `approved` | Schváleno k odeslání | Odeslat |
| `sent` | Odesláno klientovi | - |
| `delivered` | Doručeno | - |
| `opened` | Klient otevřel | - |
| `clicked` | Klient klikl na odkaz | - |
| `responded` | Klient odpověděl | - |

### Dostupné akce

- **Vytvořit** - Nová nabídka z návrhu
- **Upravit** - Editovat text nabídky
- **Schválit** - Posunout do stavu "Schváleno"
- **Odeslat** - Odeslat email klientovi
- **Preview** - Náhled emailu

### Pole v seznamu

- Lead
- Předmět emailu
- Stav
- Datum vytvoření
- Datum odeslání

### Filtry

- Stav nabídky
- Lead
- Datum vytvoření
- Datum odeslání

### Tipy

- Před odesláním vždy použijte Preview pro kontrolu
- Sledujte stavy "opened" a "clicked" pro follow-up
- Nabídky můžete duplikovat pro podobné leady
- Personalizace zvyšuje úspěšnost - upravte text pro konkrétního klienta
