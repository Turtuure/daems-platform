# Milestone 0.7 — MembershipBilling v1 (design)

**Branch:** `membership-billing-v1` (off `dev` @ `a4a3828`)
**Status:** Spec (brainstorming complete, awaiting writing-plans)
**Owner:** Dev Team
**Created:** 2026-05-12

---

## 1. Tausta ja tavoite

Daem Society ry:n säännöt (`daem-society-saannot-2026.pdf`) edellyttävät vuosittaista jäsenmaksujen ja kannatusmaksujen hallintaa. Tämä milestone toteuttaa **MembershipBillingin perustason** — hinta-versioinnin (§ 5), anniversary-pohjaisen laskutuksen, waive/reduce-mekaniikan (§ 5), automaattisen 2 vuoden maksamatta-triggerin (§ 4), manuaalisen ja CSV-pohjaisen maksun kirjauksen sekä hallituksen päätös-integraation (0.6b `board_decisions`).

**Skoppi-rajaus:**

- 0.7 = ydin-billing + manual + CSV. Stripe-integraatio → 0.7.1, Visma → 0.7.2 (omat milestonet).
- 0.7 ei rakenna SMTP-/email-infraa. Notifikaatiot menevät olemassa olevaan UI-bell-systeemiin. Sähköposti-muistutukset → 0.8 MembershipCommunications, joka rakentaa yleishyödyllisen mail-infran kaikille moduleille.
- 0.7 rakentaa cron-runnerin (`bin/console`) anniversary-/overdue-/lapse-cronien tarpeeseen — tämä on yleishyödyllinen infra, ei billing-specific.

**Säännöt-mapping:**

| Säännöt § | Vaatimus | 0.7 toteutus |
|---|---|---|
| § 3 | Kannattavat / Perus / Varsinaiset / Kunniajäsenet | `MembershipType` enum 0.6a:sta (SUPPORTING/BASIC/FULL/HONORARY); kunniajäsen poikkeus (ei laskua) |
| § 4 | Erääntynyt maksu kahtena peräkkäisenä vuonna → eronnut | `LapseInactiveMember` cron-job (2 OVERDUE-laskua peräkkäisinä vuosina → `users.membership_status='LAPSED'`) |
| § 5 | Hallitus päättää vuosittain maksujen suuruuden | `annual_fee_schedules` per (tenant, year, fee_type) + valinnainen `board_decisions` -integraatio |
| § 5 | Vapauttaa tai alentaa määräajaksi | per-lasku WAIVE/REDUCE row-actiont + per-user `user_fee_overrides` määräaikaisille alennuksille |
| § 6 | Tilikausi = kalenterivuosi, tilinpäätös vuosikokoukselle | Lasku.year = kalenterivuosi; raportointi `GET /invoices?year=N` |

---

## 2. Päätetyt design-valinnat (Q&A 2026-05-12)

| # | Päätös | Valittu |
|---|---|---|
| Q1 | Hintarakenne | **Yksinkertainen: per fee_type per vuosi (3 hintaa).** Sub-tier:t (Bronze/Silver/Gold/Platinum) ovat puhdasta kunniaa hallituksen myöntäminä — ei vaikuta hintaan. 0.6b:n `AwardSubTier` jää ennalleen. |
| Q2 | Lapse-trigger tulkinta (§ 4) | **2 peräkkäistä vuosilaskua erääntynyt + maksamatta.** Jos jompikumpi vuosi maksetaan (jopa myöhässä) ennen cron-ajoa, lapse-laskuri nollautuu. |
| Q3 | Billing cycle | **Anniversary-based** (per jäsenen `membership_started_at`). Cron pyörii daily ja luo laskun jäsenille joiden anniversary on tänään. |
| Q4 | Waive/reduce (§ 5) | **Molemmat:** per-lasku WAIVE/REDUCE row-actiont **JA** per-user `user_fee_overrides` (määräaikaiset alennukset). |
| Q5 | Maksupalvelu | **0.7 = manual + CSV.** Stripe → 0.7.1, Visma → 0.7.2 omat milestonet. |
| Q6 | Mail+cron-infra | **0.7 keskittyy billing-logiikkaan.** Notifit UI-bell:iin. Cron-runner rakennetaan 0.7:ssa. SMTP-sähköpostit → 0.8 MembershipCommunications. |
| Q7 | Decision-sidos (§ 5) | **Configurable per tenant:** `tenant_governance_settings.requires_formal_decision_for_fees BOOLEAN`. Jos true → board_decisions kierros pakollinen, jos false → admin-action riittää. |

---

## 3. Architecture overview

```text
┌─────────────────────────────────────────────────────────────────┐
│  Backstage UI (PHP templates @ public/backstage/governance/billing/) │
│  ┌─────────────┬──────┬────────────┬─────────┐                 │
│  │ Invoices    │ Fees │ Overrides  │ Import  │                 │
│  └─────────────┴──────┴────────────┴─────────┘                 │
└──────────────────┬──────────────────────────────────────────────┘
                   │
         /api/v1/backstage/governance/billing/*
                   │
┌──────────────────▼──────────────────────────────────────────────┐
│  Application/Membership/Billing/  (use cases)                   │
│  DraftAnnualFeeSchedule    GenerateAnniversaryInvoice           │
│  ActivateAnnualFeeSchedule MarkOverdueInvoices                  │
│  SetUserFeeOverride        LapseInactiveMember                  │
│  WaiveMemberFeeInvoice     RecordManualPayment                  │
│  ReduceMemberFeeInvoice    ImportPaymentsCsv                    │
└──────────────────┬──────────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────────────┐
│  Domain/Membership/Billing/  (entities + repo interfaces)       │
│  AnnualFeeSchedule    MemberFeeInvoice    UserFeeOverride       │
│  FeeInvoiceAudit                                                │
└──────────────────┬──────────────────────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────────────────────┐
│  Infrastructure/Adapter/Persistence/Sql/  (concrete repos)      │
│  + Infrastructure/Console/  (NEW: bin/console runner)           │
└─────────────────────────────────────────────────────────────────┘
                   │
                   ▼
           cron jobs (Task Scheduler / cron)
           daily 02:00 — anniversary invoices
           daily 02:30 — mark overdue
           daily 03:00 — lapse inactive
```

---

## 4. Domain layer

`src/Domain/Membership/Billing/`:

```text
AnnualFeeSchedule.php              ← per (tenant, year, fee_type) hintarivi; status draft/proposed/active/superseded
AnnualFeeScheduleId.php
AnnualFeeScheduleRepositoryInterface.php
MemberFeeInvoice.php               ← yksittäisen jäsenen vuosilasku (snapshot)
MemberFeeInvoiceId.php
MemberFeeInvoiceRepositoryInterface.php
MemberFeeInvoiceStatus.php         ← enum: PENDING/PAID/OVERDUE/WAIVED/REDUCED
PaymentRecord.php                  ← value object (amount_cents, paid_at, method, reference, paid_by)
UserFeeOverride.php                ← per-user määräaikainen alennus
UserFeeOverrideId.php
UserFeeOverrideRepositoryInterface.php
FeeInvoiceAudit.php                ← state-flip-rivi
FeeInvoiceAuditRepositoryInterface.php
Exception/
    InvalidFeeAmountException.php
    InvoiceAlreadyPaidException.php
    NoActiveFeeScheduleException.php
```

**Invariantit:**

- Lasku ei voi flipata `PAID → PENDING` (vain admin voi tehdä `payment_reversed` -toiminnon joka palauttaa OVERDUE-tilan + auditin).
- `UserFeeOverride.valid_until > valid_from` jos asetettu.
- `AnnualFeeSchedule.amount_cents >= 0` (0 sallittu = vapautus). HONORARY-tyyppi ei tarvitse hinnastoa.
- `MemberFeeInvoice` on snapshot — `amount_cents` lukitaan luontihetkellä, ei seuraa hinnaston muutoksia.
- Vain admin-rooli tenantissa tai GSA voi suorittaa WAIVE/REDUCE/manual-payment-actionit.

---

## 5. Use cases

`src/Application/Membership/Billing/`:

| Use case | Trigger | Toiminto |
|---|---|---|
| `DraftAnnualFeeSchedule` | Admin UI submit | Luo 3 proposed-riviä. Jos `requires_formal_decision_for_fees=true` → board_decisions-rivi (decision_type='annual_fee_schedule'). Muutoin → kutsuu `ActivateAnnualFeeSchedule` heti. |
| `ActivateAnnualFeeSchedule` | Decision passed event TAI suora kutsu | Vanhat active-rivit (sama year+tenant) → superseded. Proposed-rivit → active, activated_at, activated_by. |
| `SetUserFeeOverride` | Admin UI | Luo `user_fee_overrides`-rivin. Validoi valid_from < valid_until (jos asetettu). |
| `RevokeUserFeeOverride` | Admin UI | Aseta revoked_at=NOW. |
| `GenerateAnniversaryInvoice` | Cron daily | Per tenant: etsi käyttäjät joiden anniversary = today AND membership_status='active' AND membership_type IN (SUPPORTING,BASIC,FULL). Hae aktiivinen hinnasto + override. Luo MemberFeeInvoice-rivin INSERT IGNORE (UNIQUE-suoja). |
| `WaiveMemberFeeInvoice` | Admin row-action | Validoi acting-role. Status PENDING/OVERDUE → WAIVED. waived_at/by/reason. Audit-rivi. |
| `ReduceMemberFeeInvoice` | Admin row-action | original_amount_cents tallennetaan, amount_cents↓. status='REDUCED'. Audit. |
| `RecordManualPayment` | Admin row-action | Status → PAID. paid_at/amount_cents/method/reference/by. Audit. |
| `ImportPaymentsCsv` | Admin UI bulk | Parse CSV, match by reference, return preview. Confirm-vaiheessa luo audit-rivit ja markPaid-toiminnot. |
| `MarkOverdueInvoices` | Cron daily | UPDATE PENDING → OVERDUE jos due_date < NOW - overdue_grace_days. Audit per rivi. |
| `LapseInactiveMember` | Cron daily | Per tenant (jos lapse_check_enabled): etsi user_id joilla year N + year N+1 molemmat OVERDUE-tilassa. Aseta users.membership_status='LAPSED'. member_status_audit-rivi (reason='2v maksamatta'). |
| `WaiveOpenInvoicesOnHonoraryChange` | Admin row-action membership_type-vaihdossa | Kun jäsen muuttuu HONORARY:ksi, kysyy admin-dialogissa "Vapautetaanko N avointa laskua?" → luuppaa WaiveMemberFeeInvoice. |
| `ReverseLapse` (GSA only) | GSA action | users.membership_status='active', `gsa_overrides`-rivi (action='reverse_lapse'). |

---

## 6. Database schema

Migraatiot 089–095 (käyttävät 089+ slottia; 088 oli viimeinen 0.6b:ssä):

```text
089_backfill_membership_started_at_for_supporting.php
    Korjaa 075:n aukko. SUPPORTING-jäsenet eivät saaneet membership_started_at:ta,
    ja sitä tarvitaan anniversary-cron-laskuriin.
    UPDATE users SET membership_started_at = created_at
    WHERE membership_started_at IS NULL AND membership_type = 'SUPPORTING'.

090_create_annual_fee_schedules.sql
    Hinta-versiointi per (tenant, year, fee_type).
    Sarakkeet: id, tenant_id, year SMALLINT, fee_type VARCHAR(20),
    amount_cents INT UNSIGNED, currency CHAR(3) DEFAULT 'EUR',
    status VARCHAR(20) DEFAULT 'draft', decision_id NULL,
    activated_at NULL, activated_by NULL, created_at.
    FK tenants, board_decisions, users.

091_create_member_fee_invoices.sql
    Yksittäisen jäsenen vuosilasku. Snapshot-kentät:
    fee_type, anniversary_date, amount_cents lukitaan luontihetkellä.
    UNIQUE (tenant_id, user_id, year) takaa anniversary-cron-idempotenssin.
    Maksukentät: paid_at, paid_amount_cents, paid_method, paid_reference, paid_by.
    Waive-kentät: waived_at, waived_by, waive_reason, original_amount_cents (REDUCE).
    override_id NULL (linkki user_fee_overrides:iin jos käytetty).

092_create_user_fee_overrides.sql
    Per-user määräaikainen alennus.
    user_id, fee_type, override_amount_cents, valid_from, valid_until NULL,
    reason TEXT, decision_id NULL, created_by, revoked_at, revoked_by.

093_create_member_fee_invoice_audit.sql
    State-flip-rivi: action enum, performed_by NULL (cron-tapauksessa),
    payload_json TEXT (delta-tiedot).

094_extend_tenant_governance_settings_for_billing.sql
    ALTER ADD COLUMN:
      - requires_formal_decision_for_fees TINYINT(1) DEFAULT 0
      - default_due_days_from_anniversary INT DEFAULT 60
      - overdue_grace_days INT DEFAULT 30
      - lapse_check_enabled TINYINT(1) DEFAULT 1

095_extend_board_decisions_decision_type.sql
    Lisää 'annual_fee_schedule' arvon board_decisions.decision_type-listaan
    (tarkka muoto riippuu 0.6b:n enum/CHECK-konventiosta).
```

**Snapshot-malli:** `member_fee_invoices.amount_cents` ja `fee_type` ovat lukittuja luontihetkellä. Hinnaston myöhempi muutos ei muuta jo lähetettyjä laskuja. Tämä on yhdistys-tilinpidon vaatimus (§ 6 tilikausi-periaate).

**LAPSED-status:** Jos `users.membership_status`-enum ei vielä sisällä `LAPSED`-arvoa, lisätään se osana migraatiota 089 tai erikseen 089.5. Tarkistetaan suunnitteluvaiheessa.

---

## 7. HTTP API + Backstage UI

**Sidebar:** lisätään 6. hardcoded-item `governance`-ryhmään (order=60):

```text
governance: board / decisions / expulsions / delegations / settings / BILLING ← UUSI
```

`BackstageSidebar.php`:ään yksi lisäkohta. `BackstageSidebarTest`:n baseline-count 8 → 9, ordered-test hrefs 11 → 12 (peilaa 0.6b:n follow-up #1 korjausta).

**UI-sivut** (`public/backstage/governance/billing/`):

| Path | Tarkoitus |
|---|---|
| `/backstage/governance/billing` | Landing — KPI-strip (PENDING/OVERDUE/PAID/LAPSED tämän vuoden) + laskulista filter-bar:lla |
| `/backstage/governance/billing?view=fees` | Vuosihinnaston katselija — vuoden valinta, status-merkit, decision-linkki |
| `/backstage/governance/billing?view=fees&year=2027&edit=1` | Hinnaston editori — 3 input (SUPPORTING/BASIC/FULL), submit-painike vaihtelee tenant-asetuksen mukaan |
| `/backstage/governance/billing?view=overrides` | Override-lista, "Uusi" + "Peruuta" |
| `/backstage/governance/billing?view=import` | CSV-tuonti, preview, vahvistus |

**Row-actiont laskulistalla:** Merkitse maksetuksi · Vapauta · Alenna · Näytä audit (drawer).

**API-päätepisteet** (`/api/v1/backstage/governance/billing/*`):

```text
GET    /invoices?year=&status=&fee_type=&user_id=&page=
POST   /invoices/{id}/mark-paid       {amount_cents, paid_at, method, reference}
POST   /invoices/{id}/waive            {reason}
POST   /invoices/{id}/reduce           {amount_cents, reason}
GET    /invoices/{id}/audit

GET    /fee-schedules?year=
POST   /fee-schedules                  {year, fees:{SUPPORTING,BASIC,FULL}}
POST   /fee-schedules/{id}/activate    (kutsutaan decision-passed-handlerista)

GET    /overrides?user_id=&active_only=
POST   /overrides                      {user_id, fee_type, amount_cents, valid_from, valid_until, reason}
POST   /overrides/{id}/revoke

POST   /payments/import-csv            (multipart) → preview JSON
POST   /payments/import-csv/confirm    {matches: [...]}
```

**Autorisointi:** `acting.isAdminIn(tenantId) || acting.isPlatformAdmin` jokaisessa päätepisteessä. Tenant-scope automaattinen `TenantContextMiddleware`:n kautta.

---

## 8. Cron-runner-infra (uusi 0.7:ssä)

```text
bin/console <command> [--option=value]
```

**Runner-rakenne:**

```text
bin/console                                    ← php entry, parsii argv, dispatchaa
src/Infrastructure/Console/
├── ConsoleKernel.php                          ← bootaa app, resolvaa command-luokat
├── CommandInterface.php                       ← execute(array $args): int
├── CommandRegistry.php                        ← name → FQCN map
└── LockManager.php                            ← flock()-pohjainen yksi-prosessi-takuu
```

**Kolme komentoa:**

| Komento | Skedyyli | Toiminto |
|---|---|---|
| `membership:generate-anniversary-invoices` | Daily 02:00 | Luo `member_fee_invoices`-rivit niille jäsenille joiden anniversary = tänään |
| `membership:mark-overdue-invoices` | Daily 02:30 | PENDING → OVERDUE jos due_date < NOW - overdue_grace_days |
| `membership:lapse-inactive-members [--dry-run]` | Daily 03:00 | 2 peräkkäistä OVERDUE → users.membership_status=LAPSED |

**Concurrency-suoja:** `flock()` `var/run/cron-<command>.lock`-tiedostoon. Jos toinen ajo käynnissä → exit 0 + log "already running, skipped".

**Logitus:** JSONL-rivit `var/log/cron/<cmd>-YYYY-MM-DD.log`. Yksi per tenant + summary-rivi loppuun. Per-tenant try/catch — yhden kaatuminen ei estä muita.

**Skedulointi:** `docs/operations/cron-setup.md` Windows Task Scheduler + Linux/macOS crontab.

**Dev-tila:** kaikki manuaalisesti ajettavissa `php bin/console <cmd>`. `--dry-run` lapse-cronissa antaa preview-listan ilman commitia.

---

## 9. Integration with 0.6b

### Hinnaston formal-decision -flow (`requires_formal_decision_for_fees=true`)

```text
1. Admin POST /fee-schedules {year:2027, fees:{...}}
   → DraftAnnualFeeSchedule:
       a. INSERT annual_fee_schedules-rivit, status='proposed'
       b. INSERT board_decisions (decision_type='annual_fee_schedule',
                                  payload_json={schedule_ids:[...], fees:{...}})
       c. Palauttaa decision_id

2. [0.6b äänestysflow käynnissä]

3. decision.status='passed' event
   → AnnualFeeSchedulePassedHandler (uusi, 0.6b:n DecisionPassedDispatcheriin)
       a. Vanhat active-rivit (sama year+tenant) → status='superseded'
       b. Proposed-rivit → status='active', activated_at=NOW, activated_by=passed_by
       c. Audit-rivi.
```

**Jos `requires_formal_decision_for_fees=false`:** sama use case, mutta suora superseding+activation samassa transaktiossa. Audit-rivi `annual_fee_schedule_activated_direct`.

### Lapse vs. expulsion — KESKEINEN EROTTELU

| | Lapse (§ 4 deemed-resignation) | Expulsion (0.6b § 4 erottaminen) |
|---|---|---|
| Trigger | Cron: 2 OVERDUE-laskua peräkkäisinä vuosina | Manuaalinen: admin/board jäsen-rikkomuksesta |
| Päätöksen tekijä | Säännöt itse (automaattinen) | Hallitus yksimielisesti |
| Hearing | EI | Kyllä (`expulsion_hearing_days`, statement) |
| Valitusoikeus | EI | Kyllä (next vuosikokous) |
| Taulu | `member_status_audit` (vain audit) | `member_expulsions` + `member_status_audit` |
| Status | `users.membership_status = LAPSED` | `users.membership_status = EXPELLED` |
| Reason | `'2v maksamatta'` (auto) | Admin syöttää |
| Reversibility | Maksu ennen cron-ajoa | Vuosikokous-päätös tai GSA-override |

**`LapseInactiveMember`-use case EI kutsu `InitiateMemberExpulsion`:ia** — eri domain-action, oma flow. Jaettu vain `member_status_audit`-taulu.

### GSA-override (0.6b `gsa_overrides`)

GSA voi:

- Pakota suora fee-schedule-aktivointi ohittaen formal decision → `gsa_overrides`-rivi (action='bypass_fee_decision')
- Peruuttaa lapse:n manuaalisesti → `users.membership_status='active'`, audit-rivi reason='GSA override: lapse reversed'

### Sub-tier (0.6b `AwardSubTier`)

Ei muutu. Bronze/Silver/Gold/Platinum on puhdas kunnia-mekaniikka. `tenant_membership_subtiers`-taulu pysyy ilman pricing-saraketta (kuten 076:n kommentti totesi).

### Kunniajäsen (§ 3)

- Anniversary-cron filtteri: `fee_type != 'HONORARY'` → ei luo laskua
- Jäsenen tyypin vaihto HONORARY:ksi → admin-dialogi "Vapautetaanko N avointa laskua?" → `WaiveOpenInvoicesOnHonoraryChange` luuppaa WaiveMemberFeeInvoice:n.

### § 6 tilikausi

`MemberFeeInvoice.year` = kalenterivuosi (ei jäsenyysvuosi). `GET /invoices?year=2026` palauttaa täydellisen tilikauden raportin → tukee § 6:n vuosikokous-vaatimusta.

---

## 10. Testing strategy

| Taso | Sijainti | Kohde |
|---|---|---|
| Unit | `tests/Unit/Application/Membership/Billing/*` | Use caset InMemory-repo:illa, yksi testi per use case + per ehto |
| Unit | `tests/Unit/Domain/Membership/Billing/*` | Entity-invariantit |
| Integration | `tests/Integration/Infrastructure/Sql*FeeRepositoryTest.php` | SQL-repo CRUD + edge-cases |
| Isolation | `tests/Isolation/MembershipBillingTenantIsolationTest.php` | Tenant A:n data ei vuoda B:lle |
| E2E | `tests/E2E/Backstage/MembershipBillingEndpointTest.php` | KernelHarness happy-path |
| Cron | `tests/Integration/Cron/*CommandTest.php` | --dry-run + DB-state-assertiot, idempotenssi (aja 2x) |

**Tunnettu riski:** Isolation-suite on epädeterministinen post-0.6b (memo `feedback_isolation_suite_flaky.md`). Rerun-strategia riittää 0.7:n shippauksessa.

### Acceptance criteria (Säännöt-mapping)

| Vaatimus | Toteutus | Testi |
|---|---|---|
| § 3 Kunniajäsen ei maksa | Anniversary-cron filtteri + auto-waive type-muutoksessa | Unit + Isolation |
| § 4 Lapse 2v maksamatta | LapseInactiveMember cron | Integration |
| § 4 Lapse ≠ expulsion | Eri use caset, eri taulut, eri reversibility | Unit (eksplisiittinen) |
| § 5 Hallitus päättää vuosittain | annual_fee_schedules + formal decision -flow | E2E + Integration |
| § 5 Vapautus/alennus määräajaksi | user_fee_overrides + WAIVE/REDUCE row-actiont | Unit + E2E |
| § 6 Tilikausi = kalenterivuosi | Lasku.year = kalenterivuosi | Unit |
| § 6 Tilinpäätös | `GET /invoices?year=2026` palauttaa täydellisen vuoden | E2E |

### Verifiointi-skenaariot (UAT)

1. **Hinnaston vahvistus (formal):** admin draft → board äänestää → passed → invoices snapshot-arvolla
2. **Hinnaston vahvistus (suora):** tenant requires_formal=false → admin painaa → aktivointi heti
3. **Anniversary-cron:** käyttäjä jonka membership_started_at = tänään → lasku luotu seuraavalla cron-ajolla
4. **Anniversary-cron idempotenssi:** sama cron 2x samana päivänä → UNIQUE estää duplikaatin
5. **Override-vaikutus:** käyttäjälle override 50% → seuraava anniversary-lasku puolet hinnastosta
6. **WAIVE-action:** admin vapauttaa overdue-laskun → status WAIVED + audit-rivi
7. **REDUCE-action:** admin alentaa PENDING-laskun → amount_cents↓ + original_amount_cents säilytetty + audit
8. **Manual payment:** admin merkitsee maksetuksi → status PAID + paid_at/method/ref/by täytetty
9. **CSV-tuonti:** lataa pankin tilote → ehdottaa N matchia → vahvista → laskut PAID
10. **Lapse-trigger:** käyttäjä jolla 2025 + 2026 OVERDUE → cron-ajon jälkeen LAPSED
11. **Lapse-reversible (maksu ennen cron):** maksa 2025 ennen cron-ajoa → ei lapse:ta vaikka 2026 olisi overdue
12. **GSA un-lapse:** GSA painaa "Reverse lapse" → membership_status=active + gsa_overrides-rivi
13. **Honorary-vaihto:** admin asettaa membership_type=HONORARY + valitsee waive-dialogissa → avoimet laskut WAIVED
14. **Tenant-isolaatio:** tenant A:n hinnasto/lasku/override näkyy vain A:lle

---

## 11. Phase breakdown (writing-plans:lle handover)

~14 phasea, ~70–90 commit, 4–6 viikkoa karkeasti.

| # | Phase | Toiminto |
|---|---|---|
| 1 | Cron-runner infra | `bin/console`, ConsoleKernel, LockManager, CommandRegistry |
| 2 | AnnualFeeSchedule (no decision flow) | Domain + DB (mig 090) + suora aktivointi-UI |
| 3 | Decision-integraatio | board_decisions-sidos + `requires_formal_decision_for_fees`-asetus + handler |
| 4 | MemberFeeInvoice domain + DB | Mig 091 + entity + repo + interface |
| 5 | GenerateAnniversaryInvoices cron | Mig 089 (membership_started_at backfill) + cron-job + idempotenssi-testit |
| 6 | UserFeeOverride domain + DB + UI | Mig 092 + override-UI + anniversary-cron-integraatio |
| 7 | WAIVE / REDUCE row-actiont | Mig 093 (audit) + use caset + UI |
| 8 | RecordManualPayment + UI | Mark-paid modal + audit |
| 9 | MarkOverdueInvoices cron | Cron + mig 094 (grace_days asetus) |
| 10 | LapseInactiveMember use case + cron | Lapse-logiikka + `lapse_check_enabled`-asetus + integraatio member_status_audit:iin |
| 11 | GSA override paths | ReverseLapse, bypass_fee_decision |
| 12 | Honorary auto-waive | WaiveOpenInvoicesOnHonoraryChange + admin-dialogi |
| 13 | CSV bulk import | Parser, matcher, confirm-flow |
| 14 | Sidebar + i18n + E2E smoke | BackstageSidebar 6. governance-item + lang-keys (fi_FI/en_GB/sw_TZ) + browser-smoke check-list |

---

## 12. Avoinna olevat kysymykset (planning-vaiheessa selvitettäväksi)

- `users.membership_status`-enumin tarkka muoto: onko `LAPSED` jo lisätty 0.6b:ssä vai pitääkö 0.7:n lisätä? Tarkista mig 074/078–083 + Domain/User/User.php.
- `board_decisions.decision_type`:n tarkka muoto: ENUM vs. VARCHAR vs. CHECK constraint. Mig 095:n tarkka muoto riippuu tästä.
- `gsa_overrides`-taulun `action`-sarake: onko olemassa-oleva enum laajennettava vai sallitaanko vapaa string? Tarkista mig 086.
- CSV-tuonnin tarkka formaatti: Nordea / OP / Aktia poikkeavat. Aluksi tuetaan vain Nordea-CSV; muut tenantit voivat tarvita post-0.7 -laajennusta.
- Onko `tenant_governance_settings`-rivi olemassa kaikille tenanteille (seed 088)? Jos ei, 094-migraatio kaatuu — lisättävä INSERT IGNORE -seed myös niille tenanteille jotka eivät vielä ole settings-rivissä.
- **Leap-year edge case:** Jäsen joka liittyi 2024-02-29 (karkauspäivä). Miten anniversary käsitellään ei-karkausvuonna? Vaihtoehdot: (a) anniversary siirretään 02-28:ksi, (b) 03-01:ksi, (c) ei laskua sinä vuonna. Konventio päätettävä planning-vaiheessa.
- **Lapse-trigger semantics:** Tarkennus — 2 peräkkäistä OVERDUE-laskua tarkoittaa että MOLEMPIEN vuosien laskut ovat tilassa OVERDUE cron-ajon hetkellä. Jos jompikumpi on PAID/WAIVED/REDUCED→PAID, ei lapse:ta. PARTIAL payment ei ole tuettu 0.7:ssä (lasku on full-or-nothing).
- **Anniversary-cron raja-arvo:** mitä tehdä jos käyttäjä on liittynyt JUURI ENNEN (esim. 30 päivän sisällä) ensimmäistä anniversary-laskutusta? Onko prorata-mekaniikkaa vai lähteekö ensimmäinen lasku vasta seuraavalla anniversaryllä? Suositus: ensimmäinen lasku menee vasta SEURAAVALLA anniversaryllä (eli liittynyt 2026-01-15 saa ensimmäisen laskun 2027-01-15). Tämä on yksinkertaisin ja kaikki tietävät että ensimmäinen vuosi on "ilmainen-mutta-pakollinen-jäsenyys".

---

## 13. Riippuvuudet ja seuraavat milestonet

**0.7 EI riipu** SMTP-/email-infrasta — UI-bell-notifikaatiot riittävät. Mutta:

- **0.8 MembershipCommunications** rakentaa mail-infran ja AKTIVOI billing-sähköpostimuistutukset (uudet templatet billing-domainille mutta jaettu queue ja SMTP-konfiguraatio).
- **0.7.1 Stripe-integraatio** lisää maksulinkit + webhook-vastaanottimet
- **0.7.2 Visma-integraatio** lisää tilinpidollisen kytkennän

Nämä on dokumentoitu memoon `project_milestones_before_v1_release.md`.

---

*Spec valmis brainstormauksesta 2026-05-12. Seuraava: writing-plans skill luo PLAN.md per phase.*
