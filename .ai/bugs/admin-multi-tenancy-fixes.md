# Admin Multi-tenancy & CRUD Fixes

## UUID toBinary() PostgreSQL Encoding Error

### Popis problému
Při přístupu na `/admin/lead/admin` aplikace padala s chybou:
```
SQLSTATE[22021]: Character not in repertoire: 7 ERROR: invalid byte sequence for encoding "UTF8": 0x9b
CONTEXT: unnamed portal parameter $1
```

### Příčina
V `AbstractTenantCrudController` a `UserCrudController` se používalo `->toBinary()` na UUID objekty při vytváření parametrů pro Doctrine query. PostgreSQL driver toto interpretoval jako UTF-8 string a některé binární sekvence byly nevalidní UTF-8.

### Řešení
Odstraněno volání `->toBinary()` - Doctrine samo správně konvertuje UUID objekty:
```php
// Před (špatně)
->setParameter('tenantUsers', array_map(fn ($u) => $u->getId()?->toBinary(), $users));

// Po (správně)
->setParameter('tenantUsers', array_map(fn ($u) => $u->getId(), $users));
```

### Soubory
- `src/Controller/Admin/AbstractTenantCrudController.php`
- `src/Controller/Admin/UserCrudController.php`

---

## Chybějící PermissionVoter

### Popis problému
Admin nemohl editovat svůj profil přes "My Profile" - dostával chybu o nedostatečných oprávněních i když měl roli ROLE_ADMIN.

### Příčina
EasyAdmin používá Symfony `is_granted()` pro kontrolu oprávnění nastavených přes `setPermission()`. Ale custom permission stringy jako `users:manage` nebyly zpracovávány žádným Voter.

### Řešení
Vytvořen `PermissionVoter`, který propojuje EasyAdmin permissions s metodou `User::hasPermission()`:

```php
class PermissionVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_contains($attribute, ':');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        return $user instanceof User && $user->hasPermission($attribute);
    }
}
```

### Soubory
- `src/Security/Voter/PermissionVoter.php` (nový)

---

## Chybějící __toString() na entitách

### Popis problému
Při vytváření Proposal (a dalších entit s AssociationField) aplikace padala:
```
Object of class App\Entity\Lead could not be converted to string
```

### Příčina
EasyAdmin potřebuje `__toString()` metodu na entitách používaných v AssociationField pro zobrazení v select boxech.

### Řešení
Přidána metoda `__toString()` do všech entit používaných jako asociace:

| Entita | Výstup |
|--------|--------|
| Lead | `$domain ?? $url ?? $id` |
| Analysis | `"{$lead->getDomain()} #{$sequenceNumber}"` |
| Company | `$name ?? $ico` |
| EmailTemplate | `$name` |
| MonitoredDomain | `$domain` |
| Offer | `$subject ?? "Offer #{$id}"` |
| Proposal | `"{$lead->getDomain()} - {$type}"` |

### Soubory
- `src/Entity/Lead.php`
- `src/Entity/Analysis.php`
- `src/Entity/Company.php`
- `src/Entity/EmailTemplate.php`
- `src/Entity/MonitoredDomain.php`
- `src/Entity/Offer.php`
- `src/Entity/Proposal.php`

---

## Špatné názvy polí v EmailTemplate CRUD

### Popis problému
Při přidávání email šablony:
```
Can't get a way to read the property "subject" in class "App\Entity\EmailTemplate"
```

### Příčina
CRUD controllery používaly neexistující názvy polí (`subject`, `bodyHtml`, `bodyText`, `active`) místo skutečných (`subjectTemplate`, `bodyTemplate`, `isActive`).

### Řešení
Opraveny názvy polí v obou controllerech:
- `subject` → `subjectTemplate`
- `bodyHtml` → `bodyTemplate`
- `active` → `isActive`

### Soubory
- `src/Controller/Admin/EmailTemplateCrudController.php`
- `src/Controller/Admin/UserEmailTemplateCrudController.php`

---

## Nginx Unit Port Mismatch

### Popis problému
Admin panel nebyl dostupný na `localhost:7270/admin`.

### Příčina
- `docker-compose.yml` mapuje `7270:8080`
- `unit.json` měl listener na portu `80`
- Cesta k index.php byla `www/index.php` místo `public/index.php`

### Řešení
Aktualizován `.infrastructure/docker/php/unit.json`:
```json
{
    "listeners": {
        "*:8080": { "pass": "routes/main" }
    },
    "applications": {
        "php-app": {
            "script": "public/index.php"
        }
    }
}
```

### Soubory
- `.infrastructure/docker/php/unit.json`

---

## Špatné názvy polí v MarketWatchFilter CRUD

### Popis problému
Při přidávání/editaci Market Watch filtru aplikace padala:
```
Can't get a way to read the property "minScore" in class "App\Entity\MarketWatchFilter"
```

### Příčina
CRUD controller používal neexistující názvy polí (`minScore`, `maxScore`) a špatný typ (`IntegerField`) místo skutečných (`minValue`, `maxValue` typu DECIMAL).

### Řešení
Opraveny názvy polí a typ:
- `IntegerField::new('minScore')` → `NumberField::new('minValue')`
- `IntegerField::new('maxScore')` → `NumberField::new('maxValue')`

### Soubory
- `src/Controller/Admin/MarketWatchFilterCrudController.php`

---

## Prevence

1. **UUID v Doctrine queries** - Vždy používat UUID objekty přímo, nikdy `toBinary()`
2. **Custom permissions** - Vytvořit Voter pro jakékoliv custom permission atributy
3. **Entity s AssociationField** - Vždy implementovat `__toString()`
4. **CRUD field names** - Vždy ověřit existenci property v entitě před použitím v CRUD
5. **Docker port mapping** - Zkontrolovat shodu mezi docker-compose a interní konfigurací služeb
