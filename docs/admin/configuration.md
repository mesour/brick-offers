# Nastavení

Sekce pro konfiguraci systému, správu uživatelů a personalizaci.

---

## Users (Uživatelé)

**K čemu slouží:** Správa uživatelských účtů a jejich oprávnění. Vyžaduje oprávnění `users:read`.

### Role uživatelů

| Role | Popis |
|------|-------|
| `ROLE_ADMIN` | Plný přístup ke všem funkcím |
| `ROLE_USER` | Omezený přístup podle oprávnění |

### Dostupné akce

- **Vytvořit** - Nový uživatelský účet (vyžaduje `users:manage`)
- **Upravit** - Změnit údaje a oprávnění (vyžaduje `users:manage`)
- **Deaktivovat** - Zablokovat přístup
- **Smazat** - Odstranit účet

### Pole v seznamu

- Jméno
- Email
- Role
- Stav (aktivní/neaktivní)
- Poslední přihlášení
- Počet přiřazených leadů

### Nastavení uživatele

- Základní údaje (jméno, email, heslo)
- Role a oprávnění
- Přiřazené odvětví (industry)
- Limity (počet analýz, emailů...)
- Notifikační preference

### Tipy

- Pro nové členy týmu vytvořte účet s rolí USER a přidělte konkrétní oprávnění
- Pravidelně kontrolujte neaktivní účty
- Viz [Oprávnění a role](permissions.md) pro detailní popis oprávnění

---

## Analyzer Config (Konfigurace analyzátorů)

**K čemu slouží:** Nastavení, které kategorie analýz se mají provádět a jejich váhy pro výpočet celkového skóre.

### Kategorie analýz

| Kategorie | Výchozí váha | Popis |
|-----------|--------------|-------|
| SSL | 15% | Certifikát a HTTPS konfigurace |
| Security | 20% | Bezpečnostní hlavičky |
| SEO | 25% | Optimalizace pro vyhledávače |
| Performance | 25% | Rychlost a optimalizace |
| Accessibility | 15% | Přístupnost webu |

### Dostupné akce

- **Upravit** - Změnit váhy kategorií
- **Aktivovat/Deaktivovat** - Zapnout/vypnout kategorii

### Pole v seznamu

- Kategorie
- Váha (%)
- Stav (aktivní/neaktivní)
- Počet kontrol v kategorii

### Tipy

- Upravte váhy podle vašeho zaměření (např. zvýšit SEO pro marketingové služby)
- Deaktivujte kategorie, které nejsou pro vaše služby relevantní
- Součet vah aktivních kategorií by měl být 100%

---

## Company Notes (Poznámky k firmám)

**K čemu slouží:** CRM poznámky a tagy k jednotlivým firmám. Slouží pro interní komunikaci a sledování historie vztahu.

### Dostupné akce

- **Přidat** - Nová poznámka k firmě
- **Upravit** - Editovat poznámku
- **Smazat** - Odstranit poznámku

### Typy poznámek

| Typ | Popis |
|-----|-------|
| `note` | Obecná poznámka |
| `call` | Záznam z telefonátu |
| `meeting` | Záznam ze schůzky |
| `email` | Poznámka k emailové komunikaci |
| `task` | Úkol k firmě |

### Pole v seznamu

- Firma
- Typ poznámky
- Obsah (zkráceno)
- Autor
- Datum vytvoření

### Tagy

Můžete přidávat tagy pro rychlou kategorizaci:
- `hot-lead` - Velmi zajímavý lead
- `follow-up` - Vyžaduje follow-up
- `vip` - VIP klient
- `not-interested` - Nemá zájem
- Vlastní tagy...

### Tipy

- Zaznamenávejte všechny interakce pro kontinuitu
- Používejte tagy pro rychlé filtrování
- Poznámky vidí všichni členové týmu

---

## Industry Benchmarks (Oborové benchmarky)

**K čemu slouží:** Oborové průměry skóre pro porovnání výsledků analýz. Pomáhá ukázat klientovi, jak je na tom oproti konkurenci.

### Dostupné akce

- **Vytvořit** - Nový benchmark pro obor
- **Upravit** - Aktualizovat hodnoty benchmarku
- **Smazat** - Odstranit benchmark

### Pole v seznamu

- Obor/Odvětví
- Průměrné skóre celkové
- Skóre podle kategorií (SSL, Security, SEO, Performance, Accessibility)
- Počet analyzovaných webů v oboru
- Datum poslední aktualizace

### Použití v nabídkách

V emailových šablonách můžete použít:
```
Váš web má skóre {{score}}, zatímco průměr ve vašem oboru je {{industry_avg}}.
```

### Tipy

- Benchmarky se aktualizují automaticky z provedených analýz
- Nízké skóre oproti benchmarku = silný argument v nabídce
- Vytvořte benchmarky pro obory, ve kterých nejčastěji pracujete
