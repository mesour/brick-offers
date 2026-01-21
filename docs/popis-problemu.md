# Detailní popis problémů webů

Tento dokument obsahuje přehled všech typů problémů, které detekční systém analyzuje, včetně způsobu jejich prezentace klientům.

---

## Klasifikace závažnosti

Každý zjištěný problém je zařazen do jedné ze tří kategorií:

| Úroveň | Označení | Popis |
|--------|----------|-------|
| 🔴 | **Kritické** | Technické chyby, rozpad layoutu, SEO problémy, bezpečnost, přístupnost |
| 🟠 | **Doporučené** | Konzistence, čitelnost, responzivita, zastaralé knihovny |
| 🔵 | **Optimalizace** | Estetika, detaily, UX flow, drobné nekonzistence |

**Proč tři úrovně:**
- Bere vítr z plachet argumentu "to není důležité"
- Dává majiteli pocit kontroly
- Působí rozumně a profesionálně

---

## Struktura každého problému

Každý detekovaný problém musí obsahovat:

1. **Co** – konkrétní problém (jednoznačný popis)
2. **Kde** – URL / sekce webu
3. **Jak zjištěno** – metoda detekce
4. **Dopad** – proč na tom záleží (v lidské řeči)
5. **Důkaz** – screenshot, výpis, data

---

## Kategorie problémů

### 1. HTTP a serverové problémy

#### 1.1 Chybná 404 stránka (vrací 200)
- **Závažnost:** 🔴 Kritické
- **Detekce:** HTTP request na neexistující URL (např. `/neexistuje-xyz-test`)
- **Důkaz:** Response status code, screenshot hlaviček
- **Dopad pro laika:** "Vyhledávače i analytické nástroje si myslí, že stránka existuje, což zkresluje data a může snižovat důvěryhodnost webu v očích Googlu."

#### 1.2 Chybějící nebo neplatný SSL certifikát
- **Závažnost:** 🔴 Kritické
- **Detekce:** SSL check, datum expirace
- **Důkaz:** Informace o certifikátu, screenshot varování prohlížeče
- **Dopad pro laika:** "Prohlížeče označují web jako nezabezpečený, což odrazuje návštěvníky a snižuje důvěryhodnost."

#### 1.3 Mixed content (HTTP na HTTPS stránce)
- **Závažnost:** 🔴 Kritické
- **Detekce:** Analýza zdrojů načítaných přes HTTP
- **Důkaz:** Seznam problematických URL, screenshot konzole
- **Dopad pro laika:** "Některé části webu se nenačítají správně nebo prohlížeč zobrazuje varování o nezabezpečeném obsahu."

#### 1.4 Pomalá odezva serveru (TTFB)
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Měření Time To First Byte
- **Důkaz:** Čas odezvy v ms, porovnání s benchmarkem
- **Dopad pro laika:** "Web se načítá pomaleji, což zhoršuje uživatelský zážitek a může negativně ovlivnit pozici ve vyhledávačích."

---

### 2. Bezpečnostní hlavičky

#### 2.1 Chybějící Content-Security-Policy (CSP)
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Kontrola HTTP hlaviček
- **Důkaz:** Výpis hlaviček (nebo jejich absence)
- **Dopad pro laika:** "Web nemá ochranu proti určitým typům útoků, které mohou ohrozit návštěvníky."

#### 2.2 Chybějící X-Frame-Options
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Kontrola HTTP hlaviček
- **Důkaz:** Výpis hlaviček
- **Dopad pro laika:** "Web může být zneužit vložením do cizí stránky (clickjacking)."

#### 2.3 Chybějící X-Content-Type-Options
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** Kontrola HTTP hlaviček
- **Důkaz:** Výpis hlaviček
- **Dopad pro laika:** "Starší prohlížeče mohou špatně interpretovat typ obsahu."

#### 2.4 Chybějící Strict-Transport-Security (HSTS)
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Kontrola HTTP hlaviček
- **Důkaz:** Výpis hlaviček
- **Dopad pro laika:** "Web nenutí prohlížeče používat zabezpečené připojení."

**Prezentace security hlaviček:**
Jednoduchá tabulka ✔ / ✖ pro rychlý přehled:

| Hlavička | Stav |
|----------|------|
| Content-Security-Policy | ✖ chybí |
| X-Frame-Options | ✔ |
| X-Content-Type-Options | ✖ chybí |
| Strict-Transport-Security | ✖ chybí |

---

### 3. Zastaralé knihovny a technologie

#### 3.1 Zastaralá verze jQuery
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Analýza načtených skriptů, verze knihovny
- **Důkaz:** Verze knihovny, datum poslední aktualizace, známá rizika
- **Dopad pro laika:** "Starší knihovny jsou častým cílem útoků a komplikují další rozvoj webu."

#### 3.2 Zastaralé JavaScript knihovny obecně
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Porovnání verzí s databází aktuálních verzí
- **Důkaz:** Seznam knihoven s verzemi, datum vydání
- **Dopad pro laika:** "Zastaralé knihovny mohou obsahovat bezpečnostní chyby a zpomalovat web."

#### 3.3 Použití deprecated funkcí
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** Statická analýza kódu, console warnings
- **Důkaz:** Seznam varování z konzole
- **Dopad pro laika:** "Některé funkce webu mohou v budoucnu přestat fungovat."

---

### 4. SEO problémy

#### 4.1 Chybějící nebo duplicitní meta tagy
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Analýza `<head>` sekce
- **Důkaz:** Výpis meta tagů, chybějící položky
- **Dopad pro laika:** "Web se může zobrazovat špatně ve výsledcích vyhledávání."

#### 4.2 Chybějící alt texty u obrázků
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Procházení `<img>` tagů
- **Důkaz:** Počet obrázků bez alt textu, příklady
- **Dopad pro laika:** "Vyhledávače nerozumí obsahu obrázků a web je méně přístupný pro zrakově postižené."

#### 4.3 Špatná struktura nadpisů (H1, H2, ...)
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Analýza hierarchie nadpisů
- **Důkaz:** Vizualizace struktury nadpisů
- **Dopad pro laika:** "Nelogická struktura stránky zhoršuje čitelnost pro vyhledávače i uživatele."

#### 4.4 Chybějící sitemap.xml nebo robots.txt
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** HTTP request na standardní cesty
- **Důkaz:** Response status
- **Dopad pro laika:** "Vyhledávače nemusí najít všechny stránky webu."

#### 4.5 Pomalé načítání (Core Web Vitals)
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Lighthouse/PageSpeed měření
- **Důkaz:** Skóre, konkrétní metriky (LCP, FID, CLS)
- **Dopad pro laika:** "Google zvýhodňuje rychlé weby. Pomalý web může mít horší pozici ve vyhledávání."

---

### 5. W3C validace a HTML kvalita

#### 5.1 Nevalidní HTML
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** W3C Validator
- **Důkaz:** Seznam chyb a varování
- **Dopad pro laika:** "Některé prohlížeče mohou web zobrazit jinak, než je zamýšleno."

#### 5.2 Chybějící doctype nebo charset
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Analýza hlavičky dokumentu
- **Důkaz:** Výpis začátku HTML
- **Dopad pro laika:** "Web se může zobrazovat nekonzistentně v různých prohlížečích."

---

### 6. Responzivita a mobilní zobrazení

#### 6.1 Nefunkční responzivní design
- **Závažnost:** 🔴 Kritické
- **Detekce:** Testování v různých viewportech (headless Chrome)
- **Důkaz:** Screenshoty v různých rozlišeních
- **Dopad pro laika:** "Web se špatně zobrazuje na mobilech, kde dnes probíhá většina návštěv."

#### 6.2 Příliš malé klikatelné prvky na mobilu
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Lighthouse accessibility audit
- **Důkaz:** Screenshot problematických prvků
- **Dopad pro laika:** "Návštěvníci na mobilu mají problém kliknout na tlačítka a odkazy."

#### 6.3 Chybějící viewport meta tag
- **Závažnost:** 🔴 Kritické
- **Detekce:** Analýza `<head>` sekce
- **Důkaz:** Absence tagu
- **Dopad pro laika:** "Web není optimalizovaný pro mobilní zařízení."

---

### 7. Vizuální konzistence a design

#### 7.1 Nekonzistentní odsazení (padding/margin)
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** Analýza computed styles, AI vyhodnocení
- **Důkaz:** Screenshot s označením, hodnoty v px
- **Dopad pro laika:** "Design působí neprofesionálně a neuceleně."

**Příklad formulace:**
> "Na stránce /akce má kontejner rozdílné levé a pravé odsazení (24 px vs. 40 px). Zjištěno automatickou analýzou DOM."

#### 7.2 Nekonzistentní typografie
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** Analýza použitých fontů a velikostí
- **Důkaz:** Seznam použitých kombinací font/velikost
- **Dopad pro laika:** "Různé části webu vypadají, jako by patřily k jinému projektu."

#### 7.3 Nekvalitní nebo rozmazané obrázky
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Porovnání rozlišení vs. zobrazované velikosti
- **Důkaz:** Screenshot, rozměry
- **Dopad pro laika:** "Obrázky působí neprofesionálně a snižují důvěryhodnost webu."

#### 7.4 Neoptimalizované obrázky (velká velikost)
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Analýza velikosti souborů
- **Důkaz:** Seznam obrázků s velikostmi
- **Dopad pro laika:** "Web se zbytečně pomalu načítá kvůli velkým obrázkům."

---

### 8. Přístupnost (Accessibility)

#### 8.1 Nedostatečný barevný kontrast
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Lighthouse accessibility, WCAG analýza
- **Důkaz:** Problematické kombinace barev, kontrastní poměr
- **Dopad pro laika:** "Některý text je špatně čitelný, zejména pro lidi se zhoršeným zrakem."

#### 8.2 Chybějící ARIA atributy
- **Závažnost:** 🔵 Optimalizace
- **Detekce:** Analýza interaktivních prvků
- **Důkaz:** Seznam prvků bez správných atributů
- **Dopad pro laika:** "Web není přístupný pro uživatele čteček obrazovky."

#### 8.3 Špatná navigace klávesnicí
- **Závažnost:** 🟠 Doporučené
- **Detekce:** Automatizovaný test focus order
- **Důkaz:** Popis problémů
- **Dopad pro laika:** "Uživatelé, kteří nemohou používat myš, mají problém web ovládat."

---

## Jak prezentovat důkazy laikům

### Princip: "Neříkáme názor. Ukazujeme důkaz."

Laik nebude věřit tvrzení, ale:
- Screenshotu
- Konkrétnímu URL
- Reprodukovatelnému testu
- Porovnání před/po

### Formát důkazu

```
📍 Problém: [popis]
📍 Místo: [URL nebo sekce]
📍 Metoda zjištění: [jak bylo zjištěno]
📍 Důkaz: [screenshot / výpis / data]
📍 Dopad: [proč na tom záleží]
```

### Věta pro ověřitelnost

> "Všechny uvedené body lze ověřit běžnými nástroji (DevTools, Lighthouse, PageSpeed, W3C Validator)."

Nebo konkrétněji:
> "Část zjištění lze ověřit běžně v prohlížeči, část vyžaduje technickou analýzu serverové komunikace. U každého bodu uvádíme, jakým způsobem byl zjištěn."

### Jak zapojit AI

> "Pokud chcete, můžete si nechat web posoudit i nezávislým AI nástrojem (např. zadáním URL do ChatGPT)."

S dodatkem:
- AI uvidí jen veřejnou část
- Nebude mít přístup k hlavičkám/serveru
- Může potvrdit vizuální a strukturální problémy

---

## Reakce na typické námitky

### "To tam nemáme."
→ Ukázat konkrétní důkaz (screenshot, URL, data)

### "To není chyba, to je záměr."
→ "Chápeme, že jde o záměr. Uvádíme ho proto, že je v rozporu s běžnými standardy a může mít tyto důsledky…"

### "To není kritické."
→ "Souhlasíme, že to není kritická chyba. Proto je bod označen jako doporučený. Přesto má měřitelný dopad na [čitelnost / důvěryhodnost / použitelnost]."

### "Proč bych vám měl věřit?"
→ Umožnit ověření třetí stranou, odkazy na standardní nástroje

---

## Generování screenshotů a důkazů

### Skutečné screenshoty (headless Chrome)
- Celá stránka v různých viewportech
- Konkrétní sekce s problémem
- DevTools panel (Network, Console, Security)
- Response hlavičky

### Generované "screenshoty" (z HTML/textu)
- Console log výstup (nepotřebuje skutečnou konzoli)
- Tabulky s daty (hlavičky, metriky)
- Srovnávací přehledy

### Co ukládat
- Původní screenshot jako důkaz
- Metadata (datum, URL, viewport, ...)
- Strukturovaná data pro případnou regeneraci
