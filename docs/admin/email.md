# Email

Sekce pro správu emailových šablon a sledování odeslaných zpráv.

---

## Moje šablony (User Email Templates)

**K čemu slouží:** Vlastní emailové šablony s podporou AI personalizace. Můžete si vytvořit šablony pro různé typy nabídek a situací.

### Dostupné akce

- **Vytvořit** - Nová šablona
- **Upravit** - Editovat existující šablonu
- **Duplikovat** - Vytvořit kopii šablony
- **Smazat** - Odstranit šablonu

### Pole v seznamu

- Název šablony
- Předmět
- Typ (nabídka, follow-up, děkovný...)
- Datum vytvoření
- Počet použití

### Proměnné v šablonách

V šablonách můžete používat tyto proměnné:

| Proměnná | Popis |
|----------|-------|
| `{{company_name}}` | Název firmy |
| `{{contact_name}}` | Jméno kontaktu |
| `{{website_url}}` | URL analyzovaného webu |
| `{{issues_summary}}` | Shrnutí nalezených problémů |
| `{{score}}` | Celkové skóre analýzy |
| `{{top_issues}}` | Top 3 nejzávažnější problémy |

### Tipy

- Vytvořte šablony pro různé obory klientů
- Používejte jasný a stručný předmět
- Testujte různé verze šablon pro lepší úspěšnost

---

## Systémové šablony (Email Templates)

**K čemu slouží:** Předdefinované systémové šablony v češtině. Automaticky se vybírají podle odvětví leadu. Slouží jako základ - můžete si vytvořit vlastní verze.

### Dostupné akce

- **Zobrazit detail** - Podívat se na obsah šablony
- **Kopírovat do mých** - Vytvořit vlastní kopii pro úpravu

### Předinstalované šablony

| Šablona | Odvětví | Popis |
|---------|---------|-------|
| **Výchozí šablona** | (všechna) | Generická šablona použitá, pokud neexistuje odvětvová |
| **Webdesign - redesign nabídka** | webdesign | Nabídka redesignu s odkazem na mockup |
| **E-shop - optimalizace** | eshop | Zaměřeno na konverze a prodeje |
| **Reality - prezentace nemovitostí** | real_estate | Prezentace nemovitostí, mobilní optimalizace |
| **Restaurace - online rezervace** | restaurant | Mobilní web, rezervace, menu |
| **Zdravotnictví - důvěryhodná prezentace** | medical | Důvěryhodnost, GDPR, online objednávky |

### Proměnné v šablonách

| Proměnná | Popis |
|----------|-------|
| `{{domain}}` | Doména webu |
| `{{company_name}}` | Název firmy |
| `{{contact_name}}` | Jméno kontaktu |
| `{{total_score}}` | Celkové skóre analýzy (0-100) |
| `{{issues_count}}` | Počet nalezených problémů |
| `{{top_issues}}` | Seznam nejzávažnějších problémů |
| `{{proposal_title}}` | Název návrhu/proposalu |
| `{{proposal_summary}}` | Shrnutí návrhu |
| `{{proposal_link}}` | Odkaz na návrh |
| `{{sender_name}}` | Jméno odesílatele |
| `{{tracking_pixel}}` | Sledovací pixel (automaticky) |

### Jak funguje výběr šablony

1. Systém zjistí odvětví leadu (webdesign, eshop, real_estate, ...)
2. Hledá **uživatelskou šablonu** pro dané odvětví
3. Pokud neexistuje, použije **systémovou šablonu** pro odvětví
4. Pokud ani ta neexistuje, použije **Výchozí šablonu**

### AI personalizace

Šablony podporují volitelnou AI personalizaci. Při generování nabídky AI upraví text tak, aby byl osobnější a relevantnější pro konkrétního klienta. AI:

- Zachová strukturu a klíčové informace
- Přidá relevantní personalizaci na základě firmy
- Udržuje profesionální tón
- Nepřidává nepravdivá tvrzení

### Tipy

- Systémové šablony slouží jako základ - vytvořte si vlastní verze pro lepší výsledky
- Nelze je měnit, ale můžete je kopírovat do "Mých šablon"
- Pro každé odvětví doporučujeme mít vlastní optimalizovanou šablonu

---

## Email Log (Email logy)

**K čemu slouží:** Sledování všech odeslaných emailů a jejich stavů. Zde vidíte, co bylo odesláno a jak příjemci reagovali.

### Stavy emailu

| Stav | Popis |
|------|-------|
| `queued` | Zařazeno k odeslání |
| `sent` | Odesláno |
| `delivered` | Doručeno |
| `opened` | Otevřeno příjemcem |
| `clicked` | Kliknuto na odkaz |
| `bounced` | Nedoručitelné |
| `complained` | Označeno jako spam |
| `unsubscribed` | Příjemce se odhlásil |

### Pole v seznamu

- Příjemce
- Předmět
- Stav
- Datum odeslání
- Datum otevření
- Počet kliknutí

### Filtry

- Stav emailu
- Příjemce
- Datum odeslání

### Tipy

- Sledujte míru otevření pro optimalizaci předmětů
- Bounced adresy se automaticky přidávají do blacklistu
- Pro follow-up čekejte na stav "opened" nebo "clicked"

---

## Blacklist

**K čemu slouží:** Seznam blokovaných emailových adres. Na tyto adresy se nebudou odesílat žádné emaily.

### Důvody blokace

| Důvod | Popis |
|-------|-------|
| `bounce` | Email nedoručitelný (neexistující adresa) |
| `complaint` | Příjemce označil jako spam |
| `unsubscribe` | Příjemce se odhlásil |
| `manual` | Ručně přidáno administrátorem |

### Dostupné akce

- **Přidat** - Ručně přidat adresu do blacklistu
- **Odebrat** - Odstranit adresu z blacklistu (pozor u complaint!)
- **Zobrazit detail** - Důvod a datum blokace

### Pole v seznamu

- Email adresa
- Důvod blokace
- Datum přidání
- Přidáno automaticky/ručně

### Tipy

- Adresy s důvodem "complaint" nikdy neodstraňujte - riskujete penalizaci
- Bounce adresy se přidávají automaticky z AWS SES
- Před odesláním velkého množství emailů zkontrolujte blacklist
