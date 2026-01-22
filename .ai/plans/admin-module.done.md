# Role & Permission Model - Design Document

## Status: TODO

## Přehled

Systém používá hierarchický model rolí s dědičností oprávnění. Každý uživatel má právě jednu roli a volitelně patří pod master účet.

---

## Role Hierarchy

```
ROLE_ADMIN (superadmin)
    └── ROLE_MASTER (master účet)
            └── ROLE_USER (běžný uživatel / sub-účet)
```

### ROLE_USER (Sub-account)
- Základní přístup do adminu
- Vidí **pouze svá data**
- Nemůže měnit systémová nastavení
- Nemůže spravovat jiné uživatele

### ROLE_MASTER (Master account)
- Všechna oprávnění ROLE_USER
- Vidí **svá data + data svých sub-účtů**
- Může vytvářet a spravovat sub-účty
- Může měnit sdílená nastavení (templates, analyzer config)
- Přístup k discovery konfiguraci

### ROLE_ADMIN (Superadmin)
- Všechna oprávnění ROLE_MASTER
- Vidí **všechna data v systému**
- Může spravovat master účty
- Přístup k systémovým nastavením
- Debug/monitoring funkce

---

## User Entity Extensions

```php
// src/Entity/User.php

#[ORM\Column(type: Types::STRING, nullable: true)]
private ?string $password = null;

#[ORM\Column(type: Types::JSON)]
private array $roles = ['ROLE_USER'];

#[ORM\ManyToOne(targetEntity: self::class)]
#[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
private ?User $masterAccount = null;

#[ORM\OneToMany(targetEntity: self::class, mappedBy: 'masterAccount')]
private Collection $subAccounts;

public function isMaster(): bool
{
    return $this->masterAccount === null && in_array('ROLE_MASTER', $this->roles, true);
}

public function isAdmin(): bool
{
    return in_array('ROLE_ADMIN', $this->roles, true);
}

public function getMasterOrSelf(): User
{
    return $this->masterAccount ?? $this;
}
```

---

## Data Visibility Rules

### Entity Ownership

Většina entit má `user` field:

| Entity | Ownership Field | Poznámka |
|--------|-----------------|----------|
| Lead | `user_id` | Primární vlastník |
| Offer | `user_id` | Vlastník nabídky |
| Proposal | `user_id` | Vlastník návrhu |
| Analysis | via Lead | Dědí z leadu |
| EmailLog | `user_id` | Kdo odeslal |
| MonitoredDomain | `user_id` | Kdo monitoruje |
| DemandSignal | `user_id` | Komu patří |
| Company | Shared | Sdíleno (ARES data) |
| IndustryBenchmark | `user_id` / null | Per-user nebo globální |

### Visibility Matrix

| Role | Vlastní data | Sub-účty data | Všechna data |
|------|--------------|---------------|--------------|
| ROLE_USER | ✅ | ❌ | ❌ |
| ROLE_MASTER | ✅ | ✅ | ❌ |
| ROLE_ADMIN | ✅ | ✅ | ✅ |

### Query Builder Pattern

```php
// src/Service/Security/DataVisibilityService.php

public function applyVisibilityFilter(QueryBuilder $qb, User $user, string $alias = 'e'): void
{
    if ($user->isAdmin()) {
        // Admin vidí vše
        return;
    }

    if ($user->isMaster()) {
        // Master vidí svoje + sub-účty
        $qb->andWhere(sprintf(
            '%s.user = :currentUser OR %s.user IN (:subAccounts)',
            $alias, $alias
        ))
        ->setParameter('currentUser', $user)
        ->setParameter('subAccounts', $user->getSubAccounts());
    } else {
        // User vidí jen svoje
        $qb->andWhere(sprintf('%s.user = :currentUser', $alias))
           ->setParameter('currentUser', $user);
    }
}
```

---

## Permission Categories

### 1. Entity-Level Permissions

| Permission | USER | MASTER | ADMIN |
|------------|------|--------|-------|
| Lead: View own | ✅ | ✅ | ✅ |
| Lead: View sub-accounts | ❌ | ✅ | ✅ |
| Lead: Create | ✅ | ✅ | ✅ |
| Lead: Edit own | ✅ | ✅ | ✅ |
| Lead: Delete own | ✅ | ✅ | ✅ |
| Lead: Trigger analysis | ✅ | ✅ | ✅ |
| --- | --- | --- | --- |
| Offer: View own | ✅ | ✅ | ✅ |
| Offer: Edit email text | ✅ | ✅ | ✅ |
| Offer: Approve | ✅ | ✅ | ✅ |
| Offer: Send | ✅ | ✅ | ✅ |
| --- | --- | --- | --- |
| User: View self | ✅ | ✅ | ✅ |
| User: Edit self | ✅ | ✅ | ✅ |
| User: View sub-accounts | ❌ | ✅ | ✅ |
| User: Create sub-account | ❌ | ✅ | ✅ |
| User: Create master | ❌ | ❌ | ✅ |

### 2. Feature-Level Permissions

| Permission | USER | MASTER | ADMIN |
|------------|------|--------|-------|
| Batch: Approve offers | ✅ | ✅ | ✅ |
| Batch: Analyze leads | ✅ | ✅ | ✅ |
| Batch: Export CSV | ✅ | ✅ | ✅ |
| Batch: Cross-account ops | ❌ | ✅ | ✅ |

### 3. Settings-Level Permissions

| Permission | USER | MASTER | ADMIN |
|------------|------|--------|-------|
| Discovery: View config | ❌ | ✅ | ✅ |
| Discovery: Edit config | ❌ | ✅ | ✅ |
| Templates: View global | ✅ | ✅ | ✅ |
| Templates: Edit global | ❌ | ✅ | ✅ |
| Templates: Create user | ✅ | ✅ | ✅ |
| Analyzer config: Edit | ❌ | ✅ | ✅ |
| System settings | ❌ | ❌ | ✅ |

---

## UI Restrictions

### Menu Visibility

```php
// src/Controller/Admin/DashboardController.php

public function configureMenuItems(): iterable
{
    yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

    // Všechny role
    yield MenuItem::section('Pipeline');
    yield MenuItem::linkToCrud('Leads', 'fa fa-bullseye', Lead::class);
    yield MenuItem::linkToCrud('Offers', 'fa fa-envelope', Offer::class);
    yield MenuItem::linkToCrud('Proposals', 'fa fa-lightbulb', Proposal::class);

    // Všechny role - read-only pro USER
    yield MenuItem::section('Monitoring');
    yield MenuItem::linkToCrud('Email Log', 'fa fa-paper-plane', EmailLog::class);
    yield MenuItem::linkToCrud('Competitors', 'fa fa-binoculars', MonitoredDomain::class);

    // MASTER+ only
    if ($this->isGranted('ROLE_MASTER')) {
        yield MenuItem::section('Configuration');
        yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Templates', 'fa fa-file-alt', EmailTemplate::class);
        yield MenuItem::linkToCrud('Discovery', 'fa fa-search', DiscoveryConfig::class);
    }

    // ADMIN only
    if ($this->isGranted('ROLE_ADMIN')) {
        yield MenuItem::section('System');
        yield MenuItem::linkToCrud('All Users', 'fa fa-users-cog', User::class)
            ->setController(AllUsersCrudController::class);
        yield MenuItem::linkToRoute('Queue Monitor', 'fa fa-tasks', 'admin_queue');
    }
}
```

### Action Visibility

```php
// src/Controller/Admin/UserCrudController.php

public function configureActions(Actions $actions): Actions
{
    $createSubAccount = Action::new('createSubAccount', 'Create Sub-Account')
        ->linkToCrudAction('createSubAccount')
        ->displayIf(fn () => $this->isGranted('ROLE_MASTER'));

    return $actions
        ->add(Crud::PAGE_INDEX, $createSubAccount)
        ->setPermission(Action::DELETE, 'ROLE_MASTER')
        ->setPermission(Action::NEW, 'ROLE_MASTER');
}
```

---

## Symfony Security Configuration

```yaml
# config/packages/security.yaml
security:
    enable_authenticator_manager: true

    password_hashers:
        App\Entity\User:
            algorithm: auto

    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false

        admin:
            pattern: ^/admin
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: admin_login
                check_path: admin_login
                default_target_path: /admin
                enable_csrf: true
            logout:
                path: admin_logout
                target: admin_login
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 604800 # 1 week

    role_hierarchy:
        ROLE_MASTER: ROLE_USER
        ROLE_ADMIN: ROLE_MASTER

    access_control:
        - { path: ^/admin/login$, roles: PUBLIC_ACCESS }
        - { path: ^/admin, roles: ROLE_USER }
```

---

## Voter Implementation

```php
// src/Security/Voter/EntityVoter.php

class EntityVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE])
            && $subject instanceof OwnedEntityInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Admin může vše
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $entityOwner = $subject->getUser();

        // Vlastní data
        if ($entityOwner === $user) {
            return true;
        }

        // Master může přistupovat k datům sub-účtů
        if (in_array('ROLE_MASTER', $user->getRoles(), true)) {
            return $entityOwner->getMasterAccount() === $user;
        }

        return false;
    }
}
```

---

## CLI Commands

```bash
# Vytvoření master účtu (pouze CLI)
bin/console app:user:create admin@example.com --master --password=secret

# Vytvoření sub-účtu (CLI nebo admin UI)
bin/console app:user:create employee@example.com --master-code=admin --password=secret

# Povýšení na admina (pouze CLI)
bin/console app:user:promote admin@example.com ROLE_ADMIN
```

---

## Future Considerations

### Custom Roles (v2)
- Možnost definovat custom role per master účet
- Granular permissions per role

### API Tokens (v2)
```php
// Scoped API tokens
$token = $user->createApiToken([
    'scopes' => ['leads:read', 'leads:write', 'offers:read'],
    'expires_at' => new DateTimeImmutable('+30 days'),
]);
```

### Audit Log (v2)
- Logování všech akcí
- Kdo, co, kdy, na jakém záznamu

### Team Features (v3)
- Sdílení leadů mezi sub-účty
- Přiřazení leadů konkrétnímu sub-účtu
- Team dashboards

---

## Implementační poznámky

1. **Migrace**: Přidat `password`, `roles`, `master_account_id` do users tabulky
2. **Interface**: Vytvořit `OwnedEntityInterface` pro entity s `user` fieldem
3. **Base CRUD**: Vytvořit `AbstractOwnedCrudController` s visibility filtrem
4. **Tests**: Unit testy pro Voter, integration testy pro visibility

---

## Checklist pro implementaci

- [ ] User entity rozšíření (password, roles, masterAccount)
- [ ] Migrace databáze
- [ ] Security.yaml konfigurace
- [ ] Login controller a template
- [ ] OwnedEntityInterface
- [ ] EntityVoter
- [ ] DataVisibilityService
- [ ] AbstractOwnedCrudController
- [ ] CLI commands (user:create, user:promote)
- [ ] Tests
