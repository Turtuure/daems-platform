# Backstage Applications & Members API — Design Spec

**Date:** 2026-04-20
**Status:** Approved (pending user review of this spec)
**Depends on:** PR 3 (tenant-data-migration) merged to `dev`

---

## 1. Yhteenveto

Tämä spec lisää platform-APIin admin-puolen endpointit joita `sites/daem-society/public/pages/backstage/` on jo odottamassa — ne tekevät API-kutsuja osoitteisiin joita ei vielä ole olemassa. Kaksi toimintoaluetta:

1. **Applications-hallinta** — GSA/admin näkee odottavat jäsen- ja tukija-hakemukset (member/supporter) ja voi hyväksyä tai hylätä ne perustelun kera.
2. **Members-hallinta** — GSA/admin näkee suodatettavan, lajiteltavan ja sivutettavan rekisteröidyn jäsenlistan, voi vaihtaa jäsenen statusta (**vain GSA**) sekä katsoa jäsenkohtaisen audit-lokin.

Molemmat endpointit toimivat tenant-scopessa: kutsu nähden tenantista A ei koskaan palauta tenantin B dataa. GSA voi vaihtaa tenanttia `X-Daems-Tenant: <slug>` -headerilla (jo toteutettu PR 2).

## 2. Tavoitteet

- Tarjota puuttuvat backend-endpointit jotka frontend on jo oikeilla poluilla kutsumassa → backstage-sivut alkavat toimia
- Säilyttää Clean Architecture -kerrokset: Domain/Application/Infrastructure-separaatio
- Noudattaa PHPStan level 9 -tasoa (0 virhettä)
- Vahvistaa tenant-eristys — admin tenantista A ei koskaan näe tenantin B applications/members
- GSA-vain-operaatio: member statuksen muuttaminen (väärinkäyttöherkkä, vaatii korkeimman oikeustason)
- Tuottaa audit-loki sekä hakemuspäätöksistä että jäsenstatus-muutoksista

## 3. Ei-tavoitteet

- Frontend-muutokset (frontend on jo valmis ja odottaa näitä endpointeja)
- Jäsennumeron automaattinen generointi (oletetaan että manuaalisesti nyt; omaksi speciksi myöhemmin)
- Sähköpostilähetykset hakemuksen päätöksestä (oma spec myöhemmin, `daems-mail` -projekti)
- Members-export muuna muotona kuin CSV (esim. Excel/PDF → myöhemmin jos tarve)

## 4. Arkkitehtuuri

### 4.1 Korkea taso

Uusi kontrolleri `BackstageController` (erillään `AdminController`ista joka hoitaa pelkästään stats/growth). Miksi uusi: `AdminController` on jo tulkittu "dashboard stats" -kontrolleriksi, sekoittaminen hallinta-toimintojen kanssa tekisi siitä 400+ rivisen monoliitin.

```
HTTP  → TenantContextMiddleware → AuthMiddleware → BackstageController
       → (use case) → Repository → MySQL
```

### 4.2 Reittikartta

```
GET  /api/v1/backstage/applications/pending
POST /api/v1/backstage/applications/{type}/{id}/decision
GET  /api/v1/backstage/members
POST /api/v1/backstage/members/{id}/status
GET  /api/v1/backstage/members/{id}/audit
```

Kaikki reitit: `[TenantContextMiddleware::class, AuthMiddleware::class]`.

## 5. Skeema ja migraatiot

### 5.1 Migraatio 034 — Hakemuspäätös-sarakkeet

Lisätään päätösmetadata sekä `member_applications`- että `supporter_applications`-tauluihin.

```sql
-- Migration 034: add decision metadata to applications

ALTER TABLE member_applications
    ADD COLUMN decided_at     DATETIME    NULL AFTER status,
    ADD COLUMN decided_by     CHAR(36)    NULL AFTER decided_at,
    ADD COLUMN decision_note  TEXT        NULL AFTER decided_by,
    ADD CONSTRAINT fk_member_applications_decider
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE supporter_applications
    ADD COLUMN decided_at     DATETIME    NULL AFTER status,
    ADD COLUMN decided_by     CHAR(36)    NULL AFTER decided_at,
    ADD COLUMN decision_note  TEXT        NULL AFTER decided_by,
    ADD CONSTRAINT fk_supporter_applications_decider
        FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL;
```

Nullaable koska vanha data (ennen migraatiota) on käsittelemättä; vastaa "decision_pending".

### 5.2 Migraatio 035 — Member status audit -taulu

Olemassa oleva `member_register_audit` (migraatio 033) on sidottu `application_id`:ään eli seuraa hakemusten elinkaarta. Jäsenen statuksen muutos on erillinen tapahtumatyyppi (jäsen on jo rekisteröity käyttäjänä) — omaa loki-taulua varten.

```sql
-- Migration 035: create member_status_audit

CREATE TABLE member_status_audit (
    id              CHAR(36)     NOT NULL,
    tenant_id       CHAR(36)     NOT NULL,
    user_id         CHAR(36)     NOT NULL,
    previous_status VARCHAR(30)  NULL,
    new_status      VARCHAR(30)  NOT NULL,
    reason          TEXT         NOT NULL,
    performed_by    CHAR(36)     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY member_status_audit_tenant_idx (tenant_id, created_at),
    KEY member_status_audit_user_idx (user_id, created_at),
    CONSTRAINT fk_member_status_audit_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_member_status_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_member_status_audit_performer
        FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 6. Domain ja Application -kerrokset

### 6.1 Uudet Domain-rakenteet

`src/Domain/Backstage/`:

- `PendingApplication` — read-model (value-object), sisältää yhdistetyn näkymän member+supporter-hakemuksiin
  - fields: `id`, `type: 'member'|'supporter'`, `displayName`, `email`, `submittedAt`, `motivation`
- `ApplicationDecision` — enum: `Approved`, `Rejected`
- `MemberDirectoryEntry` — read-model admin-members-listaa varten
  - fields: `userId`, `name`, `email`, `membershipType`, `membershipStatus`, `memberNumber`, `roleInTenant`, `joinedAt`
- `MemberStatusAuditEntry` — read-model audit-lokia varten
  - fields: `id`, `previousStatus`, `newStatus`, `reason`, `performedByName`, `createdAt`

Miksi read-modelleja eikä aggregaatteja: nämä ovat pelkkiä admin-näkymiä, ei liiketoimintalogiikkaa. Aggregaatit ovat jo `User`, `MemberApplication`, `SupporterApplication` — näitä muokataan oikean aggregaatin kautta.

### 6.2 Repositoriomuutokset

**`MemberApplicationRepositoryInterface`** — lisätään:
```php
/** @return MemberApplication[] */
public function listPendingForTenant(TenantId $tenantId, int $limit): array;

public function findByIdForTenant(string $id, TenantId $tenantId): ?MemberApplication;

public function recordDecision(
    string $id,
    string $status,       // 'approved' | 'rejected'
    UserId $decidedBy,
    ?string $note,
    DateTimeImmutable $decidedAt,
): void;
```

Sama rakenne `SupporterApplicationRepositoryInterface`:ssa.

**Uusi `MemberDirectoryRepositoryInterface`** (`src/Domain/Backstage/`):

```php
interface MemberDirectoryRepositoryInterface
{
    /**
     * @param array{status?:string, type?:string, q?:string} $filters
     * @return array{entries: MemberDirectoryEntry[], total: int}
     */
    public function listMembersForTenant(
        TenantId $tenantId,
        array $filters,
        string $sort,     // 'member_number' | 'name' | 'joined_at' | 'status'
        string $dir,      // 'ASC' | 'DESC'
        int $page,
        int $perPage,
    ): array;

    public function changeStatus(
        UserId $userId,
        TenantId $tenantId,
        string $newStatus,
        string $reason,
        UserId $performedBy,
        DateTimeImmutable $at,
    ): void;

    /** @return MemberStatusAuditEntry[] */
    public function getAuditEntriesForMember(
        UserId $userId,
        TenantId $tenantId,
        int $limit,
    ): array;
}
```

Rajapinta on kapea ja fokusoitunut admin-näkymiin — ei laajenna `UserRepositoryInterface`:ä joka on identity-keskeinen.

Implementaatio: `SqlMemberDirectoryRepository` (`src/Infrastructure/Adapter/Persistence/Sql/`).

### 6.3 Use caset (`src/Application/Backstage/`)

Jokainen use case ottaa vastuunsa: (1) authorization-tarkastus, (2) input-validointi, (3) repository-kutsu. Kieltoon → `ForbiddenException`.

| Use case | Valtuudet | Throws |
|----------|-----------|--------|
| `ListPendingApplications` | `$acting->isAdminIn($tenantId)` | `ForbiddenException` |
| `DecideApplication` | `$acting->isAdminIn($tenantId)` | `ForbiddenException`, `NotFoundException`, `ValidationException` |
| `ListMembers` | `$acting->isAdminIn($tenantId)` | `ForbiddenException` |
| `ChangeMemberStatus` | **`$acting->isPlatformAdmin`** (GSA-only) | `ForbiddenException`, `NotFoundException`, `ValidationException` |
| `GetMemberAudit` | `$acting->isAdminIn($tenantId)` | `ForbiddenException`, `NotFoundException` |

Input DTO:t ottavat aina `ActingUser`:n ensimmäisenä parametrina (paitsi jos tenantin joku muu — ei tässä speciss̈a).

#### Esimerkki: `DecideApplication`

```php
public function execute(DecideApplicationInput $input): DecideApplicationOutput
{
    $tenantId = $input->acting->activeTenant;

    if (!$input->acting->isAdminIn($tenantId)) {
        throw new ForbiddenException('not_tenant_admin');
    }

    if (!in_array($input->decision, ['approved', 'rejected'], true)) {
        throw new ValidationException(['decision' => 'invalid_value']);
    }

    $repo = match ($input->type) {
        'member'    => $this->memberApps,
        'supporter' => $this->supporterApps,
        default     => throw new ValidationException(['type' => 'invalid_value']),
    };

    $app = $repo->findByIdForTenant($input->id, $tenantId)
        ?? throw new NotFoundException('application_not_found');

    $repo->recordDecision(
        $input->id,
        $input->decision,
        $input->acting->id,
        $input->note,
        $this->clock->now(),
    );

    // Audit entry for member_register_audit (jos tyyppi=member)
    if ($input->type === 'member') {
        $this->auditRepo->appendApplicationAudit(
            applicationId: $input->id,
            tenantId: $tenantId,
            action: $input->decision,
            performedBy: $input->acting->id,
            note: $input->note,
            at: $this->clock->now(),
        );
    }

    return new DecideApplicationOutput(success: true);
}
```

**Huom:** Hyväksynnän yhteydessä EI tässä vaiheessa vielä luoda `users`-riviä eikä kiinnitetä user_tenants-taulun kautta. Se on oma use casensa (`CreateMemberFromApplication` tms.) joka saattaa kuulua myöhemmin spekisti tai jäsennumeron generoinnista. Tässä specissä päätös on pelkkä statusmuutos + audit-merkintä.

### 6.4 BackstageController

Ohut HTTP-adapter joka muuntaa `Request`:in `Input`-DTO:iksi, kutsuu use casen ja muuntaa poikkeukset `Response`:ksi. Ei business logiikkaa.

```php
final class BackstageController
{
    public function __construct(
        private readonly ListPendingApplications $listPending,
        private readonly DecideApplication $decide,
        private readonly ListMembers $listMembers,
        private readonly ChangeMemberStatus $changeStatus,
        private readonly GetMemberAudit $getAudit,
    ) {}

    public function pendingApplications(Request $request): Response { /* ... */ }
    public function decideApplication(Request $request, array $params): Response { /* ... */ }
    public function members(Request $request): Response { /* ... CSV branch hoidettu täällä ... */ }
    public function changeMemberStatus(Request $request, array $params): Response { /* ... */ }
    public function memberAudit(Request $request, array $params): Response { /* ... */ }
}
```

## 7. API enforcement ja virhekäsittely

### 7.1 Vastausmuoto

**Onnistuminen:**
```json
{"data": {...}}
```

**Virhe:**
```json
{"error": "<code>", "message": "optional human-readable"}
```

### 7.2 Virhekoodit

| HTTP | error code | Milloin |
|------|------------|---------|
| 401  | `unauthorized` | Ei Bearer-tokenia tai rikkinäinen (AuthMiddleware) |
| 403  | `forbidden` | `ForbiddenException` — käyttäjä kirjautunut mutta ei valtuuksia |
| 404  | `not_found` | `NotFoundException` — application tai member ei löydy tenantissa |
| 404  | `unknown_tenant` | Host ei resolvaudu → TenantContextMiddleware (jo olemassa) |
| 422  | `validation_failed` | `ValidationException` — input-validointi epäonnistui, vastauksessa `fields: {...}` |

### 7.3 CSV-export

Endpoint: `GET /api/v1/backstage/members?export=csv` (muut filtterit vaikuttavat riveihin).

```
Content-Type: text/csv; charset=utf-8
Content-Disposition: attachment; filename="members-{tenantSlug}-{YYYY-MM-DD}.csv"
```

Sisältö: ensimmäinen rivi on otsikot, sitten data. Sarakkeet:
```
member_number, name, email, membership_type, membership_status, role_in_tenant, joined_at
```

Sivutusta ei käytetä export-tilassa — kaikki suodatukseen sopivat rivit.

## 8. Testausstrategia

### 8.1 Unit-testit (`tests/Unit/Application/Backstage/`)

Yksi testiluokka per use case. Mockatut repositoriot. Kattaa:
- Happy path: oikea auth → oikea repo-kutsu → oikea output
- `ForbiddenException` kun acting ei ole admin (ChangeMemberStatus: ei ole GSA)
- `NotFoundException` kun kohdeobjektia ei ole
- `ValidationException` kun input on virheellinen (väärä decision, tuntematon type jne.)

### 8.2 Integration-testit (`tests/Integration/Persistence/Sql/`)

SqlMemberApplicationRepositoryTest (laajennetaan olemassa olevaa):
- `listPendingForTenant` palauttaa vain saman tenantin ja status=pending -rivit
- `recordDecision` asettaa decided_at/by/note oikein
- `findByIdForTenant` filteröi tenant-id:n

SqlMemberDirectoryRepositoryTest (uusi):
- `listMembersForTenant` palauttaa yhdistetyn näkymän users+user_tenants-tauluista
- Suodattimet (status, type, q) toimivat
- Lajittelu & sivutus toimii
- `changeStatus` muuttaa users-rivin ja kirjoittaa audit-entryn samassa transaktiossa
- `getAuditEntriesForMember` palauttaa vain saman tenantin ja saman käyttäjän entryt

### 8.3 E2E-testit (`tests/E2E/`)

KernelHarness-pohjaiset testit jotka testaavat HTTP-kerrosta päästä päähän:
- `F008_BackstageApplicationsAccessTest` — anonyymi → 401, ei-admin → 403, admin → 200
- `F009_BackstageDecideApplicationTest` — admin hyväksyy → status='approved' + decided_at set + audit entry luotu
- `F010_BackstageMembersGsaOnlyStatusTest` — admin yrittää muuttaa statusta → 403, GSA yrittää → 200

### 8.4 Isolation-testit (`tests/Isolation/`)

`BackstageTenantIsolationTest` (uusi):
- Admin tenantista 'daems' kutsuu `listPendingForTenant('daems')` — ei näe tenantin 'sahegroup' hakemuksia
- Admin tenantista 'daems' kutsuu `findByIdForTenant(sahegroup-app-id, 'daems')` → null
- Sama members + audit-endpointeille

Laajennetaan olemassa olevaa `MemberApplicationTenantIsolationTest` uusilla assertioilla `listPendingForTenant`:ille.

## 9. PR-jako

Tämä on yksi PR (PR 4, jos numeroidaan jatkumona PR 1-3:n jälkeen).

## 10. Toteutusjärjestys

1. Migraatiot 034 + 035 (+ niiden testit, paikka `tests/Integration/Migration/`)
2. Domain-rakenteet: read-modelit + interface-laajennokset
3. SQL-repository-implementaatiot + integration-testit
4. Use caset + unit-testit
5. BackstageController + route-rekisteröinti
6. E2E-testit
7. Isolation-testit
8. Dokumenttien päivitys (api.md, database.md)
9. `composer analyse` (0 virhettä) + `composer test` + `composer test:e2e` (kaikki vihreä)
10. Commit-sarja (yksi per looginen pala)

## 11. Avoimet kysymykset ja riskit

### 11.1 Mitä tarkoittaa "supporter-hakemus hyväksytty"?

Supporter on organisaatio, ei yksittäinen käyttäjä. Hyväksyntä:
- luoko `users`-rivin yhteyshenkilölle? (kuten member?)
- pelkkä organisaatio-rekisteröityminen ilman login-kykyä?

**Päätös:** Tässä specissä `DecideApplication` päivittää pelkän statuksen + audit-entryn. Organisaation instantiointi (esim. erillinen `organizations`-taulu) on oma spec myöhemmin kun vaatimukset selkiintyvät.

### 11.2 Jäsennumeron generointi hyväksynnän yhteydessä

Frontend näyttää `member_number` -sarakkeen, mutta user-kohtaisen jäsennumeron generointi ei kuulu tähän speciin — se on oma sääntökokonaisuutensa (seuraava vapaa, tenant-kohtainen prefix jne.). Hyväksynnän yhteydessä jäsennumero jää nulliksi ja GSA/admin asettaa sen myöhemmin.

### 11.3 Riski: tenant-scope unohtuu repositoryssa

Mitigaatio: isolation-testit ovat pakollisia (Task 7 toteutusjärjestyksessä). Jokainen uusi lukuoperaatio vaatii erillisen isolation-testin joka todistaa että tenantista A ei nähdä tenantin B dataa. PHPStan tyyppitarkastus ei tätä kiinnitä, mutta isolation-testi pysäyttää regression.

### 11.4 Riski: CSV-export iso datamäärä

Yli 10 000 rivin tenantille CSV voi kestää kauan tai syödä muistia. Mitigaatio: tällä hetkellä ei odotettu ongelma (daems tenant on pieni). Jos ongelma tulee: streaming-CSV `fpassthru` + chunk-SQL — myöhempi optimointi.

---

## Appendix A: Reitti- ja payload-esimerkit

### GET /backstage/applications/pending?limit=200

Vastaus (200):
```json
{
  "data": {
    "member": [
      {
        "id": "uuid-1",
        "type": "member",
        "displayName": "Alice Example",
        "email": "alice@example.com",
        "submittedAt": "2026-04-18T10:30:00Z",
        "motivation": "..."
      }
    ],
    "supporter": [
      {
        "id": "uuid-2",
        "type": "supporter",
        "displayName": "Acme Org Ltd",
        "email": "contact@acme.com",
        "submittedAt": "2026-04-19T14:12:00Z",
        "motivation": "..."
      }
    ]
  }
}
```

### POST /backstage/applications/member/{id}/decision

Pyyntö:
```json
{"decision": "approved", "note": "Welcome."}
```

Vastaus (200):
```json
{"data": {"success": true}}
```

### GET /backstage/members?status=active&sort=member_number&dir=ASC&page=1&per_page=50

Vastaus (200):
```json
{
  "data": [
    {
      "userId": "uuid-u1",
      "name": "Alice Example",
      "email": "alice@example.com",
      "membershipType": "individual",
      "membershipStatus": "active",
      "memberNumber": "1001",
      "roleInTenant": "member",
      "joinedAt": "2026-01-15T12:00:00Z"
    }
  ],
  "meta": {
    "page": 1,
    "per_page": 50,
    "total": 127,
    "total_pages": 3
  }
}
```

### POST /backstage/members/{userId}/status (GSA only)

Pyyntö:
```json
{"status": "suspended", "reason": "Payment overdue > 90 days."}
```

Vastaus (200):
```json
{"data": {"success": true}}
```

Vastaus (403, ei-GSA):
```json
{"error": "forbidden", "message": "Only platform admins can change member status."}
```

### GET /backstage/members/{userId}/audit?limit=25

Vastaus (200):
```json
{
  "data": [
    {
      "id": "uuid-a1",
      "previousStatus": "active",
      "newStatus": "suspended",
      "reason": "Payment overdue > 90 days.",
      "performedByName": "Board Admin",
      "createdAt": "2026-04-20T09:15:00Z"
    }
  ]
}
```
