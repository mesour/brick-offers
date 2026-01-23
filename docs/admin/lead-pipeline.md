# Lead Pipeline

Sekce pro správu potenciálních klientů a jejich analýz.

---

## Leads (Leady)

**K čemu slouží:** Správa potenciálních klientů - webů, které chcete oslovit s nabídkou služeb.

### Stavy leadu

| Stav | Popis |
|------|-------|
| `new` | Nově přidaný lead |
| `queued` | Zařazen do fronty na analýzu |
| `analyzing` | Probíhá analýza |
| `analyzed` | Analýza dokončena |
| `approved` | Schválen k oslovení |
| `sent` | Nabídka odeslána |
| `responded` | Klient odpověděl |
| `converted` | Klient objednán |

### Dostupné akce

- **Vytvořit** - Přidat nový lead (URL webu)
- **Analyzovat** - Spustit analýzu webu
- **Upravit** - Změnit údaje leadu
- **Smazat** - Odstranit lead

### Pole v seznamu

- URL webu
- Stav
- Firma (pokud je přiřazena)
- Skóre analýzy
- Datum vytvoření
- Poslední aktivita

### Filtry

- Stav leadu
- Rozsah data vytvoření
- Přiřazená firma
- Skóre analýzy

### Tipy

- Před analýzou ověřte, že URL je platná a web je dostupný
- Leady bez odpovědi můžete po čase znovu oslovit

---

## Companies (Firmy)

**K čemu slouží:** Zobrazení firemních údajů získaných z ARES a dalších zdrojů. Data jsou read-only.

### Dostupné akce

- **Zobrazit detail** - Podrobné informace o firmě
- Data jsou automaticky načítána z ARES podle IČO

### Pole v seznamu

- Název firmy
- IČO
- Adresa
- Právní forma
- Počet přiřazených leadů

### Tipy

- Údaje se aktualizují automaticky při přiřazení leadu k firmě
- Pro CRM poznámky použijte sekci "Company Notes" v Nastavení

---

## Analyses (Analýzy)

**K čemu slouží:** Přehled výsledků analýz webů se souhrnným skóre a stavem.

### Stavy analýzy

| Stav | Popis |
|------|-------|
| `pending` | Čeká na zpracování |
| `running` | Probíhá analýza |
| `completed` | Analýza dokončena |
| `failed` | Analýza selhala |

### Dostupné akce

- **Zobrazit detail** - Detailní výsledky analýzy
- **Spustit znovu** - Opakovat analýzu

### Pole v seznamu

- Lead (URL)
- Celkové skóre (0-100)
- Stav
- Počet nalezených problémů
- Datum analýzy

### Filtry

- Stav analýzy
- Rozsah skóre
- Datum analýzy

### Tipy

- Nízké skóre = více problémů = větší příležitost pro nabídku
- Detaily problémů najdete ve "Výsledky analýz"

---

## Analysis Results (Výsledky analýz)

**K čemu slouží:** Detailní výsledky analýz rozdělené podle kategorií.

### Kategorie analýz

| Kategorie | Co analyzuje |
|-----------|--------------|
| SSL | Certifikát, platnost, konfigurace |
| Security | Bezpečnostní hlavičky, zranitelnosti |
| SEO | Meta tagy, struktura, obsah |
| Performance | Rychlost načítání, optimalizace |
| Accessibility | Přístupnost pro hendikepované |

### Pole v seznamu

- Analýza
- Kategorie
- Skóre kategorie
- Počet problémů
- Závažnost (critical, high, medium, low)

### Tipy

- Kritické problémy by měly být zmíněny v nabídce jako první
- Srovnejte výsledky s benchmarky v dané kategorii

---

## Analysis Snapshots (Snapshoty analýz)

**K čemu slouží:** Historické záznamy analýz pro sledování vývoje v čase.

### Dostupné akce

- **Zobrazit detail** - Snapshot analýzy k danému datu
- Snapshoty se vytvářejí automaticky při každé analýze

### Pole v seznamu

- Lead
- Datum snapshotu
- Celkové skóre
- Změna oproti předchozímu

### Tipy

- Porovnejte snapshoty pro zjištění, zda klient řeší problémy sám
- Pokles skóre může být příležitost k dalšímu oslovení
