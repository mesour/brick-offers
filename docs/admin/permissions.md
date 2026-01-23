# Oprávnění a role

Přístup k funkcím admin panelu je řízen systémem rolí a oprávnění.

---

## Role

### ROLE_ADMIN

Administrátor má plný přístup ke všem funkcím systému bez omezení.

### ROLE_USER

Běžný uživatel má přístup pouze k funkcím povoleným jeho oprávněními.

---

## Přehled oprávnění

Systém obsahuje 20 oprávnění rozdělených do kategorií:

### Leady

| Oprávnění | Popis |
|-----------|-------|
| `leads:read` | Zobrazení leadů a jejich detailů |
| `leads:write` | Vytváření a úprava leadů |
| `leads:delete` | Mazání leadů |
| `leads:analyze` | Spouštění analýz leadů |

### Nabídky (Offers)

| Oprávnění | Popis |
|-----------|-------|
| `offers:read` | Zobrazení nabídek |
| `offers:write` | Vytváření a úprava nabídek |
| `offers:approve` | Schvalování nabídek k odeslání |
| `offers:send` | Odesílání nabídek klientům |

### Návrhy (Proposals)

| Oprávnění | Popis |
|-----------|-------|
| `proposals:read` | Zobrazení návrhů |
| `proposals:approve` | Schvalování návrhů |
| `proposals:reject` | Zamítání návrhů |

### Analýzy

| Oprávnění | Popis |
|-----------|-------|
| `analysis:read` | Zobrazení výsledků analýz |
| `analysis:trigger` | Ruční spouštění analýz |

### Monitoring konkurence

| Oprávnění | Popis |
|-----------|-------|
| `competitors:read` | Zobrazení dat o konkurenci |
| `competitors:manage` | Správa sledovaných domén a nastavení |

### Statistiky

| Oprávnění | Popis |
|-----------|-------|
| `stats:read` | Zobrazení statistik a reportů |

### Nastavení

| Oprávnění | Popis |
|-----------|-------|
| `settings:read` | Zobrazení konfigurace systému |
| `settings:write` | Úprava konfigurace systému |

### Uživatelé

| Oprávnění | Popis |
|-----------|-------|
| `users:read` | Zobrazení seznamu uživatelů |
| `users:manage` | Vytváření, úprava a mazání uživatelů |

---

## Typické kombinace oprávnění

### Obchodník (Sales)

```
leads:read, leads:write, leads:analyze
offers:read, offers:write
proposals:read, proposals:approve, proposals:reject
analysis:read
```

Může pracovat s leady, vytvářet nabídky a schvalovat návrhy. Nemůže odesílat emaily ani spravovat uživatele.

### Marketing

```
leads:read
offers:read
analysis:read
competitors:read
stats:read
```

Pouze čtení dat pro analýzu a reporty. Nemůže měnit záznamy.

### Odesílatel (Sender)

```
leads:read
offers:read, offers:send
analysis:read
```

Může odesílat schválené nabídky, ale nemůže je vytvářet ani schvalovat.

### Team Lead

```
leads:read, leads:write, leads:delete, leads:analyze
offers:read, offers:write, offers:approve, offers:send
proposals:read, proposals:approve, proposals:reject
analysis:read, analysis:trigger
competitors:read, competitors:manage
stats:read
settings:read
```

Plný přístup k operativní práci, ale bez správy uživatelů.

### Administrátor

Všechna oprávnění, nebo použití role `ROLE_ADMIN`.

---

## Multi-tenancy

Systém podporuje oddělení dat mezi uživateli (tenants):

- Každý uživatel vidí pouze své leady, nabídky a další záznamy
- Administrátor vidí data všech uživatelů
- Data se filtrují automaticky podle přihlášeného uživatele

### Sdílení dat

Některé entity jsou sdílené mezi všemi uživateli:
- Systémové emailové šablony
- Oborové benchmarky
- Firmy (z ARES)

---

## Bezpečnostní doporučení

1. **Princip nejmenších oprávnění** - Přidělujte pouze nezbytně nutná oprávnění
2. **Pravidelná revize** - Kontrolujte oprávnění při změně rolí v týmu
3. **Oddělení schvalování a odesílání** - Různí lidé by měli schvalovat a odesílat
4. **Audit** - Sledujte, kdo co v systému dělá

---

## Přiřazení oprávnění

Oprávnění se přiřazují v sekci **Nastavení → Uživatelé**:

1. Otevřete detail uživatele
2. V sekci "Oprávnění" zaškrtněte požadovaná oprávnění
3. Uložte změny

Změny oprávnění se projeví okamžitě při dalším požadavku uživatele.
