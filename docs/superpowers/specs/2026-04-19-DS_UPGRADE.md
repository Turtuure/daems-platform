# DS_UPGRADE — Multi-Tenant Database Isolation + PHPStan Level 9 Baseline

**Status:** Draft — pending user + implementation approval
**Owner:** Dev Team
**Date:** 2026-04-19
**Supersedes:** N/A
**Related:** ADR-014 (to be drafted), follow-up dashboard-migration spec

---

## 1. Yhteenveto

Daems-platform muuttuu single-tenant järjestelmästä **multi-tenant-alustaksi**:
- Käyttäjät ovat globaaleja identiteettejä, joilla voi olla rooli useissa tenanteissa
- Jäsenyydet, roolit ja sisältö (projects, events, applications, forum, insights) ovat tenant-rajoitteita
- Uusi `users.is_platform_admin` -flagi korvaa aiemman `global_system_administrator`-roolin ja on ainoa tapa ylittää tenant-rajat
- Host → tenant -resoluutio tapahtuu Host-headerin perusteella (DB `tenant_domains` -taulu ensisijaisesti, `config/tenant-fallback.php` dev-fallbackina)
- GSA voi ylittää tenant-rajan API-pyynnöllä käyttäen `X-Daems-Tenant`-headeria

Samassa spekissä nostetaan koko koodipohja **PHPStan level 9 -puhtaaksi** (nykyinen baseline: level 6).

## 2. Tavoitteet

- Tenant-data-isolaatio taattu kantatasolla (NOT NULL + FK), domain-tasolla (repository-metodit vaativat `TenantId`:n) ja middleware-tasolla (`TenantContextMiddleware` injektoi kontekstin jokaiseen pyyntöön)
- GSA voi hallita kaikkia tenantteja yhdestä accountista ilman erillisiä sisäänkirjautumisia
- Uusi tenant voidaan ottaa käyttöön kolmella askeleella: (1) rivi `tenants`-tauluun, (2) rivit `tenant_domains`-tauluun, (3) web-serverin konfigurointi pointtaamaan platformiin
- PHPStan level 9 -puhtaus kaikissa tiedostoissa — 0 virhettä ilman baseline-tiedostoa

## 3. Ei-tavoitteet (scope-rajat)

Seuraavat asiat **eivät** kuulu tähän spekkiin ja tehdään omina töinä myöhemmin:
- Dashboard-migraatio (`daem-society/public/pages/backstage/` → `daems-platform/public/dashboard/`) — oma spec heti tämän jälkeen
- Admin UI uusien tenanttien luomiseen — uudet tenantit lisätään SQL:llä nyt
- Cross-tenant content-jakaminen (esim. projekti omistettuna kahdelle tenantille) — YAGNI
- Tenant-branding, teemat, per-tenant i18n — myöhemmät speckit
- SSO muille protokolaille (OAuth, OIDC) — platform on ainoa identity source

## 4. Arkkitehtuuri

### 4.1 Korkea taso

```
┌─ HTTP ──────────────────────────────────────────────────┐
│  Request: daems.fi / sahegroup.com / daems.local / ...  │
│  Authorization: Bearer <token>                          │
│  X-Daems-Tenant: <slug>  (vain GSA)                     │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─ TenantContextMiddleware (UUSI) ────────────────────────┐
│  Host → tenant (DB ensisijainen, config fallback)       │
│  Tuntematon host → 404 unknown_tenant                   │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─ AuthMiddleware (MUUTOS) ───────────────────────────────┐
│  Validoi Bearer → UserId + isPlatformAdmin              │
│  X-Daems-Tenant + isPlatformAdmin → override            │
│  X-Daems-Tenant ilman isPlatformAdmin → 403             │
│  Lataa user_tenants.role (current tenant)               │
│  Luo ActingUser request-scopeen                         │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─ Application-kerros ────────────────────────────────────┐
│  Use caset käyttävät ActingUser::isAdminIn(tenantId)    │
│  tai ActingUser::roleIn(tenantId) auktorisointiin       │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─ Domain + Infrastructure ───────────────────────────────┐
│  Repositoryt vaativat TenantId-parametrin               │
│  Cross-tenant-metodit nimetty eksplisiittisesti         │
│  SQL: WHERE tenant_id = ? kaikissa per-tenant-queryissä │
└─────────────────────┬───────────────────────────────────┘
                      ▼
┌─ Database ──────────────────────────────────────────────┐
│  GLOBAL: users, auth_tokens, auth_login_attempts,       │
│          tenants, tenant_domains, platform_admin_audit  │
│  PER-TENANT: user_tenants, events, projects,            │
│          member_applications, supporter_applications,   │
│          forum_*, insights, event_registrations,        │
│          project_extras, project_proposals,             │
│          member_register_audit                          │
└─────────────────────────────────────────────────────────┘
```

### 4.2 Host → tenant -resoluutio

```php
final class HostTenantResolver
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        /** @var array<string,string> */
        private array $fallbackMap,
    ) {}

    public function resolve(string $host): ?Tenant
    {
        $host = strtolower(trim($host));

        // 1) DB primary
        $tenant = $this->tenants->findByDomain($host);
        if ($tenant !== null) {
            return $tenant;
        }

        // 2) Config fallback (dev + bootstrap)
        if (isset($this->fallbackMap[$host])) {
            return $this->tenants->findBySlug($this->fallbackMap[$host]);
        }

        return null;
    }
}
```

### 4.3 Invariantit

1. Jokainen tenant-scoped rivi kantaa `tenant_id NOT NULL` — ei NULL-riviä joka menisi "kaikkien nähtäville" vahingossa
2. Repository-metodit vaativat `TenantId`-parametrin, ellei metodin nimessä lue eksplisiittisesti `CrossTenant` tai `AllTenants`
3. Käyttäjäidentiteetti (`users`) ja tenant-jäsenyys (`user_tenants`) ovat eri asioita — user ilman pivot-rivejä on "kirjautumaan pystyvä identiteetti ilman jäsenyyttä"
4. `is_platform_admin`-muutokset loggataan kantatasolla `platform_admin_audit`-tauluun triggerillä
5. Cross-tenant-operaatiot vaativat `ActingUser::isPlatformAdmin() === true`

## 5. Skeema ja migraatiot

### 5.1 Uudet taulut

**`tenants`**
```sql
CREATE TABLE tenants (
    id         CHAR(36)     NOT NULL,
    slug       VARCHAR(64)  NOT NULL,
    name       VARCHAR(255) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY tenants_slug_unique (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`tenant_domains`**
```sql
CREATE TABLE tenant_domains (
    domain     VARCHAR(255) NOT NULL,
    tenant_id  CHAR(36)     NOT NULL,
    is_primary BOOLEAN      NOT NULL DEFAULT FALSE,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (domain),
    KEY tenant_domains_tenant_idx (tenant_id),
    CONSTRAINT fk_tenant_domains_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`user_tenants`** (pivot)
```sql
CREATE TABLE user_tenants (
    user_id    CHAR(36)     NOT NULL,
    tenant_id  CHAR(36)     NOT NULL,
    role       ENUM('admin','moderator','member','supporter','registered')
                            NOT NULL DEFAULT 'registered',
    joined_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at    DATETIME     NULL,
    PRIMARY KEY (user_id, tenant_id),
    KEY user_tenants_tenant_role_idx (tenant_id, role),
    CONSTRAINT fk_user_tenants_user
        FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_user_tenants_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**`platform_admin_audit`**
```sql
CREATE TABLE platform_admin_audit (
    id          CHAR(36)     NOT NULL,
    user_id     CHAR(36)     NOT NULL,
    action      ENUM('granted','revoked') NOT NULL,
    changed_by  CHAR(36)     NULL,
    reason      VARCHAR(500) NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY pa_audit_user_idx (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE TRIGGER trg_users_platform_admin_audit
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF NEW.is_platform_admin <> OLD.is_platform_admin THEN
        INSERT INTO platform_admin_audit (id, user_id, action, changed_by, created_at)
        VALUES (
            UUID(),
            NEW.id,
            IF(NEW.is_platform_admin = TRUE, 'granted', 'revoked'),
            @app_actor_user_id,
            CURRENT_TIMESTAMP
        );
    END IF;
END //
DELIMITER ;
```

Application-kerros asettaa `@app_actor_user_id`-session-muuttujan ennen UPDATE-komentoa.

### 5.2 Muutokset olemassa oleviin tauluihin

**`users`**
- Lisää `is_platform_admin BOOLEAN NOT NULL DEFAULT FALSE`
- Backfill: `UPDATE users SET is_platform_admin = TRUE WHERE role = 'global_system_administrator'`
- Poista `role`-sarake (migraatio 024, vasta kun `user_tenants` on täytetty ja koodi ei enää tarvitse saraketta)

**Per-tenant-taulut** (migraatiot 025–033) — jokaiseen lisätään `tenant_id CHAR(36)` kolmella vaiheella samassa tiedostossa:
1. `ALTER TABLE ... ADD COLUMN tenant_id CHAR(36) NULL`
2. `UPDATE <tbl> SET tenant_id = (SELECT id FROM tenants WHERE slug = 'daems')`
3. `ALTER TABLE ... MODIFY tenant_id CHAR(36) NOT NULL, ADD CONSTRAINT fk_<tbl>_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT, ADD INDEX <tbl>_tenant_idx (tenant_id, ...)`

### 5.3 Migraatioiden järjestys

```
019  create_tenants_and_tenant_domains.sql          (+ seed daems, sahegroup, dev-domainit)
020  add_is_platform_admin_to_users.sql             (+ platform_admin_audit, + trigger)
021  backfill_is_platform_admin_from_role.sql
022  create_user_tenants_pivot.sql
023  backfill_user_tenants_from_users_role.sql      (kaikki → tenant='daems')
024  drop_users_role_column.sql
025  add_tenant_id_to_events.sql
026  add_tenant_id_to_insights.sql
027  add_tenant_id_to_projects.sql
028  add_tenant_id_to_member_applications.sql
029  add_tenant_id_to_supporter_applications.sql
030  add_tenant_id_to_forum_tables.sql
031  add_tenant_id_to_project_extras.sql
032  add_tenant_id_to_event_registrations.sql
033  add_tenant_id_to_member_register_audit.sql
```

### 5.4 Seed-data (migraatio 019)

```sql
INSERT INTO tenants (id, slug, name) VALUES
    ('01958000-0000-7000-8000-000000000001', 'daems',     'Daems Society'),
    ('01958000-0000-7000-8000-000000000002', 'sahegroup', 'Sahe Group');

INSERT INTO tenant_domains (domain, tenant_id, is_primary) VALUES
    ('daems.fi',          '01958000-0000-7000-8000-000000000001', TRUE),
    ('www.daems.fi',      '01958000-0000-7000-8000-000000000001', FALSE),
    ('sahegroup.com',     '01958000-0000-7000-8000-000000000002', TRUE),
    ('www.sahegroup.com', '01958000-0000-7000-8000-000000000002', FALSE);
```

`config/tenant-fallback.php`:
```php
<?php
return [
    'daem-society.local'   => 'daems',
    'daems-platform.local' => 'daems',
    'sahegroup.local'      => 'sahegroup',
    'localhost'            => 'daems',
];
```

## 6. Domain, Application ja Middleware -muutokset

### 6.1 Uudet Domain-luokat

```
src/Domain/Tenant/
    Tenant.php                          // entiteetti
    TenantId.php                        // VO (extends Uuid7Id)
    TenantSlug.php                      // VO (a-z 0-9 -, 3..64 merkkiä)
    TenantDomain.php                    // VO (domain-syntaksi)
    UserTenantRole.php                  // enum: admin, moderator, member, supporter, registered
    TenantRepositoryInterface.php
    UserTenantRepositoryInterface.php
    TenantNotFoundException.php
```

### 6.2 Muutokset olemassa oleviin Domain-luokkiin

- `src/Domain/Auth/ActingUser.php` — lisää kentät `isPlatformAdmin`, `activeTenant`, `roleInActiveTenant`, lisää metodit `isPlatformAdmin()`, `roleIn(TenantId)`, `isAdminIn(TenantId)`
- `src/Domain/User/Role.php` — **poistetaan** (siirtyy `UserTenantRole`:ksi Tenant-alueelle); `global_system_administrator` poistuu enumista ja edustetaan `users.is_platform_admin`-lipulla
- Kaikki `Role`-viittaukset korvataan `UserTenantRole`-viittauksilla tai `ActingUser::isPlatformAdmin()`-tarkistuksilla

### 6.3 ActingUser (uusi muoto)

```php
final readonly class ActingUser
{
    public function __construct(
        public UserId $id,
        public string $email,
        public bool $isPlatformAdmin,
        public TenantId $activeTenant,
        public ?UserTenantRole $roleInActiveTenant,
    ) {}

    public function isPlatformAdmin(): bool
    {
        return $this->isPlatformAdmin;
    }

    public function roleIn(TenantId $tenant): ?UserTenantRole
    {
        if (!$tenant->equals($this->activeTenant)) {
            return null;
        }
        return $this->roleInActiveTenant;
    }

    public function isAdminIn(TenantId $tenant): bool
    {
        if ($this->isPlatformAdmin) {
            return true;
        }
        return $this->roleIn($tenant) === UserTenantRole::Admin;
    }
}
```

### 6.4 Middleware

**`TenantContextMiddleware` (UUSI)** — ajetaan **kaikilla** reiteillä (myös public):
```php
final class TenantContextMiddleware implements Middleware
{
    public function __construct(private HostTenantResolver $resolver) {}

    public function handle(Request $req, Closure $next): Response
    {
        $host = strtolower($req->header('Host') ?? '');
        $tenant = $this->resolver->resolve($host);

        if ($tenant === null) {
            throw new NotFoundException('unknown_tenant');
        }

        return $next($req->withAttribute('tenant', $tenant));
    }
}
```

**`AuthMiddleware` (MUUTOS)** — lataa `user_tenants.role`, käsittelee `X-Daems-Tenant`-overriden:
```php
final class AuthMiddleware implements Middleware
{
    public function handle(Request $req, Closure $next): Response
    {
        $token = $this->extractBearerToken($req);
        $user  = $this->authService->validateToken($token);

        $tenant = $req->attribute('tenant');

        $override = $req->header('X-Daems-Tenant');
        if ($override !== null) {
            if (!$user->isPlatformAdmin) {
                throw new ForbiddenException('tenant_override_forbidden');
            }
            $tenant = $this->tenantRepo->findBySlug($override);
            if ($tenant === null) {
                throw new NotFoundException('unknown_tenant');
            }
            $req = $req->withAttribute('tenant', $tenant);
        }

        $role = $this->userTenantRepo->findRole($user->id, $tenant->id);

        $actingUser = new ActingUser(
            id:                 $user->id,
            email:              $user->email,
            isPlatformAdmin:    $user->isPlatformAdmin,
            activeTenant:       $tenant->id,
            roleInActiveTenant: $role,
        );

        return $next($req->withAttribute('actingUser', $actingUser));
    }
}
```

### 6.5 Repository-patterni

Nykyinen:
```php
public function list(int $limit, int $offset): array;
```

Uusi:
```php
public function listForTenant(TenantId $tenantId, int $limit, int $offset): array;

// Cross-tenant, harvinainen:
public function listAllTenantsForPlatformAdmin(int $limit, int $offset): array;
```

SQL:
```sql
-- ennen:
SELECT * FROM projects WHERE slug = ?;
-- jälkeen:
SELECT * FROM projects WHERE slug = ? AND tenant_id = ?;
```

### 6.6 Use case -patterni

```php
public function execute(AddProjectCommentInput $input, ActingUser $actor): AddProjectCommentOutput
{
    $project = $this->projectRepo
        ->findBySlugForTenant($input->slug, $actor->activeTenant)
        ?? throw new ProjectNotFoundException();

    if (!$actor->isPlatformAdmin && $actor->roleInActiveTenant === null) {
        throw new ForbiddenException('not_a_member');
    }

    // ... use case -logiikka
}
```

Cross-tenant-tapaus:
```php
final class GetGlobalPlatformStats
{
    public function execute(ActingUser $actor): Output
    {
        if (!$actor->isPlatformAdmin) {
            throw new ForbiddenException('platform_admin_required');
        }
        return $this->statsRepo->aggregateAllTenants();
    }
}
```

### 6.7 Uusi endpoint: `GET /api/v1/auth/me`

Dashboard ja muut UI:t validoivat tokenin tällä joka sivulatauksen alussa.

Request headers: `Authorization: Bearer <token>`, optional `X-Daems-Tenant: <slug>` (GSA-only)

Response 200:
```json
{
    "data": {
        "user": {
            "id": "01958000-...",
            "name": "Sam",
            "email": "sam@...",
            "is_platform_admin": false
        },
        "tenant": {
            "slug": "daems",
            "name": "Daems Society"
        },
        "role_in_tenant": "admin",
        "token_expires_at": "2026-04-26T10:00:00Z"
    }
}
```

Response 401: `{ "error": "invalid_token" }`
Response 404: `{ "error": "unknown_tenant" }`

### 6.8 Session-sopimus frontendeille

Frontendit (society ja tulevat) tallentavat loginin jälkeen sessioon:
```php
$_SESSION['user']       = [id, name, email, is_platform_admin];  // ilman roolia
$_SESSION['tenant']     = ['slug' => ..., 'name' => ..., 'role_in_tenant' => ...];
$_SESSION['token']      = '<bearer>';
$_SESSION['expires_at'] = '<ISO-8601>';
```

Society-puolen `login.php` päivitetään samassa PR:ssä jotta se tallentaa uuden muodon.

## 7. API enforcement ja virhekäsittely

### 7.1 Reittikohtainen middleware-soveltaminen

**Kaikki reitit** saavat `TenantContextMiddleware`:n (myös public).
**Suojatut reitit** saavat lisäksi `AuthMiddleware`:n.

```php
// Public
$router->post('/api/v1/auth/login',    ..., [TenantContextMiddleware::class, RateLimitLoginMiddleware::class]);
$router->post('/api/v1/auth/register', ..., [TenantContextMiddleware::class]);
$router->get('/api/v1/events',         ..., [TenantContextMiddleware::class]);

// Protected
$router->get('/api/v1/projects',       ..., [TenantContextMiddleware::class, AuthMiddleware::class]);
$router->get('/api/v1/auth/me',        ..., [TenantContextMiddleware::class, AuthMiddleware::class]);
$router->get('/api/v1/backstage/stats',..., [TenantContextMiddleware::class, AuthMiddleware::class]);
```

### 7.2 Virhetilakoodit ja -avaimet

| Tilanne                                                         | HTTP | Body-avain                    |
|-----------------------------------------------------------------|------|-------------------------------|
| Host ei löydy (DB + fallback)                                   | 404  | `unknown_tenant`              |
| Bearer puuttuu                                                  | 401  | `missing_token`               |
| Bearer virheellinen / vanhentunut                               | 401  | `invalid_token`               |
| X-Daems-Tenant ilman platform-admin -oikeuksia                  | 403  | `tenant_override_forbidden`   |
| X-Daems-Tenant -headerissa tuntematon slug                      | 404  | `unknown_tenant`              |
| Käyttäjä ei ole jäsen current-tenantissa, yrittää tenant-scoped | 403  | `not_a_member`                |
| Rooli ei riitä (esim. admin vaaditaan)                          | 403  | `insufficient_role`           |

Kaikki virheet sanitoituna ADR-011:n mukaisesti — ei vuoteta SQL-virheitä, sisäisiä ID:tä eikä enumerointiin johtavia viestejä.

### 7.3 Siirtymävaihe ja deploy

Tuotanto ei tällä hetkellä ole live (v1.0.0 on suunnitteilla), joten migraatiot ja koodi voidaan ottaa käyttöön yhdessä erässä dev-ympäristössä. Jos tuotanto olisi live, vaiheistettu deploy olisi:

1. Migraatiot 019–023 (uudet taulut + backfill, vanha rakenne pystyssä)
2. Koodi jossa käytetään uutta `user_tenants`-rakennetta; `users.role` lukee edelleen vanhan sarakkeen (käsittelee molempia)
3. Migraatiot 024+ (poista `users.role`, lisää `tenant_id`)
4. Koodi joka vaatii `tenant_id`:n kaikkialla

Devi-ympäristössä siirrytään suoraan lopulliseen tilaan.

## 8. PHPStan level 9 baseline

### 8.1 Nykyinen tila ja tavoite

- Nykyinen: level 6, 0 virhettä
- Tavoite: level 9, 0 virhettä (ilman baseline-tiedostoa)

### 8.2 Vaiheistus

**Vaihe A — Level 7:** Union-tyyppien + array-offset-pääsyjen korjaukset. Arvio: 30–60 virhettä.

**Vaihe B — Level 8:** Null-turva. Suurin työruutu; nykyisessä koodissa paljon `?Entity`-chainauksia. Työkalu-pattern:
- Early-return: `if ($x === null) return ...;`
- `?? throw new NotFoundException()` -pattern
- `assert($x !== null)` (PHPStan ymmärtää assertit)

Arvio: 80–150 virhettä.

**Vaihe C — Level 9:** `mixed`-tyypin strict-käsittely. Vaatii tyypitettyjä accessor-metodeja superglobaaleille ja Request-objektille.

**Olemassa olevaan `src/Infrastructure/Framework/Http/Request.php` -luokkaan lisätään** tyypitetyt accessorit:
```php
public function string(string $key, ?string $default = null): ?string;
public function int(string $key, ?int $default = null): ?int;
public function bool(string $key, ?bool $default = null): ?bool;
public function arrayValue(string $key): ?array;
```

**Uusi `src/Infrastructure/Framework/Session/Session.php`-luokka** (tuleville frontend-tarpeille ja platformin omalle sessiolle dashboard-spekissä):
```php
final class Session
{
    public function string(string $key, ?string $default = null): ?string;
    public function array(string $key): ?array;
    // ...
}
```

Kaikki `$_POST['x']`, `$_SESSION['x']`, `$request->body()['x']` -pääsyt migroidaan näihin tyypitettyihin metodeihin.

Arvio: 100–200+ virhettä.

### 8.3 Toteutusjärjestys

Level 9 -baseline toteutetaan **ennen** tenant-isolaatiota (PR 1), jotta uusi tenant-koodi voidaan kirjoittaa level 9 -puhtaana alusta asti ja sillä on jo käytössä tyypitetyt helperit.

## 9. PR-jako

| PR  | Scope                                                     | Rivimäärä (arvio) | Riski |
|-----|-----------------------------------------------------------|-------------------|-------|
| 1   | PHPStan level 7+8+9 cleanup koko projektille + typed helperit | 500–1500      | Matala (ei behavior change) |
| 2   | Migraatiot 019–024 + Domain/Tenant + middlewaret + /auth/me + testit | 1500–2500 | Keskitaso |
| 3   | Migraatiot 025–033 + repository- ja use case -muutokset + isolaatio-testit | 3000–5000 | Korkea |

PR:t mergetään järjestyksessä. Kukin on itsessään vihreä (composer test + analyse + test:mutation).

## 10. Testausstrategia

### 10.1 Tenant-isolaatio-testit (turvallisuuskriittiset)

Jokainen per-tenant-taulu saa oman `*TenantIsolationTest`-luokan:
- `ProjectTenantIsolationTest`
- `EventTenantIsolationTest`
- `MemberApplicationTenantIsolationTest`
- `SupporterApplicationTenantIsolationTest`
- `ForumTenantIsolationTest`
- `InsightTenantIsolationTest`
- `UserTenantIsolationTest`

Kukin testaa vähintään:
1. Admin tenantista A ei näe tenantin B dataa
2. Repository-metodit joihin puuttuu `tenant_id`-parametri eivät ole olemassa (staattinen takuu)
3. GSA voi ylittää tenantin `X-Daems-Tenant`-headerilla
4. Non-GSA + override → 403
5. Tuntematon override-slug → 404

### 10.2 Middleware-testit

**`TenantContextMiddlewareTest`** (uusi):
- Tunnettu DB-domain → tenant requestissa
- Tuntematon domain → 404 `unknown_tenant`
- Fallback-config toimii dev-domainille
- DB ensisijainen configin yli

**`AuthMiddlewareTest`** (päivitys):
- Bearer → user + tenant-rooli ladataan
- GSA + override → menee läpi, tenant vaihtuu
- Non-GSA + override → 403
- Override → tuntematon slug → 404
- Ei jäsen current-tenantissa → ActingUser.role=null, pyyntö menee läpi (rooli-validointi use case -tasolla)

### 10.3 Repository-testit (integration, oikea MySQL)

- `TenantRepositoryTest` (uusi): findById, findBySlug, findByDomain, findAll
- `UserTenantRepositoryTest` (uusi): findRole, attach, detach (soft via left_at)
- Olemassa olevat repository-testit päivitetään käyttämään `TenantId`-parametria; jokaiseen lisätään testi joka luo datan kahteen tenanttiin ja varmistaa että query palauttaa vain oikean tenantin rivit

### 10.4 Migraatio-testit

```
tests/Migration/
    TenantBackfillTest.php
        test_migration_023_assigns_all_existing_users_to_daems_tenant
        test_migration_021_flags_former_gsas_as_platform_admin
        test_migration_024_drops_users_role_column
        test_migration_025_033_tenant_id_not_null_after_each_migration
```

### 10.5 Audit-trigger-testit

`PlatformAdminAuditTest`:
- Muutos `is_platform_admin = TRUE` → `platform_admin_audit.action = 'granted'`
- Muutos `is_platform_admin = FALSE` → `action = 'revoked'`
- Muu UPDATE users-tauluun → ei audit-riviä
- `@app_actor_user_id` välittyy `changed_by`-kenttään

### 10.6 E2E-testit (Playwright)

```
tests/E2E/TenantIsolation/
    daems_admin_cannot_see_sahegroup_data.spec.ts
    gsa_can_switch_tenants_via_api_header.spec.ts
    registered_user_without_membership_gets_403.spec.ts
    login_from_unknown_host_returns_404.spec.ts
```

### 10.7 Mutation testing

Infection MSI ≥ 85 % — sama kynnys kuin nykyisin. Tenant-alueen kriittiset luokat:
- `src/Domain/Tenant/*`
- `src/Infrastructure/Framework/Http/Middleware/TenantContextMiddleware.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlTenantRepository.php`

### 10.8 Lopullinen merge-kriteeri

- `composer test` → 0 virhettä
- `composer analyse` → level **9**, 0 virhettä (ei baselinea)
- `composer test:mutation` → MSI ≥ 85 %
- `npm run test:e2e` (relevant subset) → kaikki ohi
- Manuaalinen verifiointi: GSA näkee molempien tenanttien datan yhdestä accountista; admin-rooli ei

## 11. Muutosten kokoluokka-yhteenveto

| Alue                              | Tehtäviä (arvio) |
|-----------------------------------|------------------|
| PHPStan cleanup (level 7→8→9)     | 200–400 fix-paikkaa |
| Tyypitetyt helperit (Request/Session) | 3–4 luokkaa  |
| Uudet Domain-luokat               | 8                |
| Uudet repositoryt (iface + SQL)   | 2                |
| Muutetut repositoryt              | 10–12            |
| Uudet middlewaret                 | 1                |
| Muutetut middlewaret              | 1                |
| Muutetut use caset                | ~50              |
| Migraatioita                      | 14               |
| Uusia testiluokkia                | 40+              |

## 12. Dokumentaatiomuutokset

- `docs/api.md` — lisää `X-Daems-Tenant`-header, `GET /api/v1/auth/me`, uudet virhekoodit
- `docs/architecture.md` — päivittää kuvan multi-tenant-arkkitehtuurille
- `docs/database.md` — uudet taulut + muutokset olemassa oleviin
- `docs/decisions.md` — **ADR-014: Multi-Tenant Data Isolation via Domain-Derived Tenant Context + Pivot Membership**
- `docs/decisions.md` — **ADR-015: PHPStan Level 9 Baseline**

## 13. Avoimet kysymykset ja riskit

### 13.1 Login tenantista, jossa käyttäjä ei ole jäsen

**Päätös:** Login onnistuu, Bearer-token myönnetään, mutta `$_SESSION['tenant']['role_in_tenant']` on `null`. Tenant-scoped use caset heittävät `ForbiddenException('not_a_member')` kaikille paitsi platform-adminille. Rekisteröinti luo eksplisiittisesti `user_tenants`-rivin `role='registered'`.

### 13.2 Riski: SQL-queryn unohtama tenant_id

**Mitigaatio:** (1) Repository-interfacet eivät salli tenant-scoped queryä ilman `TenantId`-parametria — kääntäjä (ja PHPStan) estää unohduksen. (2) Jokainen repository-muutos saa isolaatio-testin joka luo datan kahteen tenanttiin. (3) Code review -checklistiin lisätään kohta.

### 13.3 Riski: Triggerin `@app_actor_user_id` jää asettamatta

**Mitigaatio:** (1) Kaikki `UPDATE users SET is_platform_admin=...` -queryt kulkevat yhden `PlatformAdminService::setFlag()`-metodin kautta joka asettaa session-muuttujan ensin. (2) Integration-testi varmistaa että audit-rivi syntyy ja `changed_by` on oikea.

### 13.4 Riski: PHPStan level 9 cleanup paljastaa piilotettuja nullahavaintoja jotka ovat behaviorbugeja

**Mitigaatio:** Level 9 -cleanup on ensimmäinen PR ja erillinen tenant-työstä. Jos paljastuu oikeita bugeja, ne korjataan osana cleanup-PR:ää ja dokumentoidaan git-commiteissa; tenant-työ ei häiriinny.

### 13.5 Avoin: Tenant-specifinen branding/teemat

Ei kuulu tähän speckiin. Todetaan että `tenants`-taulu on se paikka johon brandingisarakkeet lisätään myöhemmin (esim. `primary_color`, `logo_url`, `name_display`).

---

## Appendix A: Nimeämiskonventiot

- **Taulut**: `snake_case`, monikko: `tenants`, `user_tenants`, `tenant_domains`
- **Sarakkeet**: `snake_case`, tenant_id aina `CHAR(36) NOT NULL` + FK
- **Indeksit**: `<tbl>_<cols>_idx`
- **FK-rajoitteet**: `fk_<tbl>_<fk-name>`
- **PHP-luokat**: `PascalCase`, nimiavaruus `Daems\Domain\Tenant\...`
- **Virheavaimet**: `snake_case`, lyhyt, englanninkielinen (`not_a_member`, `unknown_tenant`)
