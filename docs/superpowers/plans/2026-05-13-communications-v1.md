# Communications v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Milestone 0.8 Communications — a universal multi-tenant mail-infra (per-tenant SMTP, outbox queue, email-safe HTML rendering, 5 message kinds, 3-category opt-in, multilingual templates fi_FI/en_GB/sw_TZ) with backstage composer, outbox, newsletter blocks, template overrides, settings, public unsubscribe, and 3 cron-triggered transactional sends (payment reminders + lapse warnings).

**Architecture:** Module-extracted under `c:/laragon/www/modules/communications/` following the Wave A-D extraction pattern (events/forum/projects). Clean Architecture: Domain (entities, value objects, repository interfaces) → Application (25 use cases) → Infrastructure (Symfony Mailer adapter, EmailHtmlRenderer with placeholder substitution, libsodium DSN encryption, SQL repositories, CLI cron commands). Outbox table is single source of truth for both queue and audit. Templates are dev-authored email-safe HTML files (table-based, inline CSS) for 4 strict kinds; newsletter is a free block composer (7 block types) stored as JSON. **2026-05-13 substitution:** original spec assumed `tijsverkoyen/mjml-php`; that package does not exist, so MJML toolchain dropped in favour of hand-authored email-safe HTML — see Task A1 substitution note.

**Tech Stack:** PHP 8.1+, MySQL 8.4, Symfony Mailer 6.4 LTS (DSN-based transport abstraction), league/commonmark 2.5 (Markdown body rendering), libsodium (DSN encryption), PHPStan level 9, PHPUnit 10. Branch `communications-v1` off `dev` @ `86a0980`.

**Spec:** [`docs/superpowers/specs/2026-05-13-communications-v1-design.md`](../specs/2026-05-13-communications-v1-design.md). Migration 098. ~120-140 new tests. Estimated 14 days = 8 waves.

---

## File Structure

### New module (`c:/laragon/www/modules/communications/`)

| Path | Responsibility |
| --- | --- |
| `module.json` | Module manifest (namespace, src_path, bindings, routes, migrations_path, frontend dirs) |
| `composer.json` | PSR-4 autoload `DaemsModule\Communications\` → `backend/src/` |
| `phpunit.xml.dist` | Suite layout |
| `README.md` | Module overview |
| `backend/bindings.php` | DI bindings (production container — wired into platform `bootstrap/app.php` via discovery) |
| `backend/routes.php` | HTTP route registration |
| `backend/migrations/098_create_communications_tables.sql` | All 7 tables + seeds |
| `backend/src/Domain/Mail/*.php` | Mail outbox + suppression entities, enums, exceptions, repository IFs |
| `backend/src/Domain/Template/*.php` | MailTemplate, NewsletterDraft, NewsletterBlock hierarchy, repository IFs |
| `backend/src/Domain/Audience/*.php` | AudienceFilter, AudienceResolverInterface, ResolvedRecipient |
| `backend/src/Domain/Meeting/*.php` | Meeting entity, MeetingType, MeetingStatus, repository IF |
| `backend/src/Domain/Preference/*.php` | CommunicationCategory, UserCommunicationPreference, repository IF |
| `backend/src/Domain/Settings/*.php` | TenantCommunicationSettings, repository IF |
| `backend/src/Domain/Exception/*.php` | All domain exceptions |
| `backend/src/Application/<UseCase>/{UseCase,Input,Output}.php` | 25 use cases, one folder each |
| `backend/src/Infrastructure/Mailer/{MailerInterface,SymfonyMailerAdapter,InMemoryMailer}.php` | Mailer port + adapters |
| `backend/src/Infrastructure/Renderer/{EmailHtmlRenderer,MarkdownRenderer,VarSubstituter,Html2Text,MailTemplateRegistry}.php` | Render pipeline |
| `backend/src/Infrastructure/Renderer/templates/*.html` | Dev-authored email-safe HTML templates (6 files) |
| `backend/src/Infrastructure/Persistence/Sql*Repository.php` | SQL repository implementations (8 classes) |
| `backend/src/Infrastructure/Console/{MailDrainCommand,EnqueuePaymentRemindersCommand,EnqueueLapseWarningsCommand}.php` | CLI cron commands |
| `backend/src/Infrastructure/Crypto/DsnEncryptor.php` | libsodium DSN encryption |
| `backend/src/Infrastructure/Audience/SqlAudienceResolver.php` | Audience resolver impl |
| `frontend/backstage/communications/{index,outbox}.php` | Composer + outbox pages |
| `frontend/backstage/communications/newsletters/{index,edit}.php` | Newsletter list + block composer |
| `frontend/backstage/communications/templates/{index,edit}.php` | Template overrides |
| `frontend/backstage/settings/communications.php` | Settings (SMTP + cron + brand + suppression tabs) |
| `frontend/assets/{communications.css,composer.js,outbox.js,newsletter-blocks.{js,css},settings.js}` | UI assets |
| `tests/Unit/...` | Unit tests (~60) |
| `tests/Integration/...` | Integration tests (~30) |
| `tests/E2E/...` | E2E tests (~25) |

### Platform-side changes (`c:/laragon/www/daems-platform/`)

| Path | Change |
| --- | --- |
| `config/modules.php` | Add `communications` entry (sidebar=null, hardcoded items in BackstageSidebar) |
| `lang/{fi_FI,en_GB,sw_TZ}.php` | ~120 new keys |
| `bootstrap/app.php` | DI bindings (Mailer, Encryptor, repositories, use cases) |
| `bootstrap/console.php` | Register 3 (or 4) new cron commands |
| `tests/Support/KernelHarness.php` | InMemory bindings matching production (BOTH-containers rule) |
| `src/Frontend/BackstageSidebar.php` | Add `communications` to GROUP_RANK (= 5, system shifts to 6) + 4 hardcoded sub-items conditional on tenant enablement |
| `.env.example` | Add `APP_ENCRYPTION_KEY=` line + generation hint |
| `tests/Isolation/CommunicationsTenantIsolationTest.php` | Cross-tenant leakage tests |
| `tests/Unit/I18n/CommunicationsParityTest.php` | i18n key parity |
| `public/communications/unsubscribe.php` | Public unsubscribe landing page |
| `daem-society/public/index.php` | Route `/unsubscribe` to platform handler |
| `public/sites/_default/router.php` | Same `/unsubscribe` route |

---

## Wave A — Infra-foundation (1 day)

Goal: vendor packages, encryption foundation, module skeleton, migration 098, i18n scaffolding. After this wave the codebase builds, PHPStan stays green, migration runs, but no behavior added yet.

### Task A1: Add vendor packages to platform `composer.json`

**Substitution note (2026-05-13):** Original task assumed `tijsverkoyen/mjml-php` pure-PHP MJML renderer. That package does not exist on Packagist. After user-confirmed substitution (option B): drop MJML entirely, hand-author email-safe HTML templates with `{{var}}` placeholders + inline CSS, render via `EmailHtmlRenderer` (CommonMark for body Markdown, VarSubstituter for placeholders, no compile step). Also: downgrade Symfony Mailer to 6.4 LTS so the existing PHP 8.1 platform pin holds.

**Files:**

- Modify: `c:/laragon/www/daems-platform/composer.json`

- [ ] **Step 1: Add two new dependencies under `require`**

```json
"symfony/mailer": "^6.4",
"league/commonmark": "^2.5"
```

- [ ] **Step 2: Run install**

```bash
cd c:/laragon/www/daems-platform
composer require symfony/mailer:^6.4 league/commonmark:^2.5
```

Expected: composer.lock updates, two new packages and their deps appear. `symfony/mailer` 6.4.x must stay compatible with the existing `config.platform.php = "8.1.99"` pin (6.4 LTS requires PHP ≥ 8.1, supported until 2027).

- [ ] **Step 3: Run PHPStan baseline check (no behavior yet, must still be 0)**

```bash
composer analyse
```

Expected: `0 errors`.

- [ ] **Step 4: Smoke test the installed Mailer + CommonMark**

```bash
cd c:/laragon/www/daems-platform
php -r 'require "vendor/autoload.php"; echo class_exists("Symfony\\\\Component\\\\Mailer\\\\Mailer") ? "Mailer OK\n" : "FAIL\n";'
php -r 'require "vendor/autoload.php"; echo class_exists("League\\\\CommonMark\\\\CommonMarkConverter") ? "CommonMark OK\n" : "FAIL\n";'
```

Expected: both print "OK".

- [ ] **Step 5: Commit**

```bash
cd c:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add composer.json composer.lock
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): vendor symfony/mailer 6.4 LTS + league/commonmark 2.5"
```

### Task A2: Create `APP_ENCRYPTION_KEY` env scaffolding

**Files:**

- Modify: `c:/laragon/www/daems-platform/.env.example`
- Modify: `c:/laragon/www/daems-platform/.env` (dev only — do NOT commit a real key)

- [ ] **Step 1: Append to `.env.example`**

```text

# === Communications module (0.8) ===
# 32-byte symmetric key for SMTP-DSN encryption (libsodium secretbox).
# Generate with: php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());'
APP_ENCRYPTION_KEY=
```

- [ ] **Step 2: Generate dev key and place in local `.env`**

```bash
cd c:/laragon/www/daems-platform
php -r 'echo "APP_ENCRYPTION_KEY=" . base64_encode(sodium_crypto_secretbox_keygen()) . "\n";' >> .env
```

- [ ] **Step 3: Verify `.env` not staged**

```bash
git status --short
```

Expected: only `.env.example` modified, `.env` untracked but ignored.

- [ ] **Step 4: Commit example only**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add .env.example
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): APP_ENCRYPTION_KEY env scaffolding + dev-key generation hint"
```

### Task A3: Create module skeleton (manifest + composer + autoload)

**Files:**

- Create: `c:/laragon/www/modules/communications/module.json`
- Create: `c:/laragon/www/modules/communications/composer.json`
- Create: `c:/laragon/www/modules/communications/README.md`
- Create: `c:/laragon/www/modules/communications/phpunit.xml.dist`
- Create: `c:/laragon/www/modules/communications/.gitignore`
- Create: `c:/laragon/www/modules/communications/backend/bindings.php`
- Create: `c:/laragon/www/modules/communications/backend/routes.php`

- [ ] **Step 1: Write `module.json`**

```json
{
  "name": "communications",
  "version": "1.0.0",
  "description": "Multi-tenant mail infrastructure — per-tenant SMTP, outbox queue, email-safe HTML rendering, 5 message kinds, 3-category opt-in, multilingual templates fi_FI/en_GB/sw_TZ",
  "namespace": "DaemsModule\\Communications\\",
  "src_path": "backend/src/",
  "bindings": "backend/bindings.php",
  "routes": "backend/routes.php",
  "migrations_path": "backend/migrations/",
  "frontend": {
    "public_pages": null,
    "backstage_pages": "frontend/backstage/",
    "assets": "frontend/assets/"
  },
  "requires": {
    "core": ">=1.0.0"
  }
}
```

- [ ] **Step 2: Write `composer.json`**

```json
{
  "name": "daems/module-communications",
  "description": "Daems communications module",
  "type": "daems-module",
  "require": {
    "php": ">=8.1"
  },
  "autoload": {
    "psr-4": {
      "DaemsModule\\Communications\\": "backend/src/"
    }
  }
}
```

- [ ] **Step 3: Write `README.md`**

```markdown
# communications

0.8 milestone module — see `docs/superpowers/specs/2026-05-13-communications-v1-design.md` in the platform repo for full design.

Manages: per-tenant SMTP, outbox queue, email-safe HTML templates, newsletter block composer, 3-category opt-in, public unsubscribe, payment-reminder + lapse-warning crons.
```

- [ ] **Step 4: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="../../../daems-platform/vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 5: Write `.gitignore`**

```text
/vendor/
/composer.lock
```

- [ ] **Step 6: Write stub `bindings.php`**

```php
<?php
declare(strict_types=1);

// All DI bindings for the communications module.
// Loaded by the platform's ModuleRegistry::discover() at boot.
// Bind ports → adapters here as the module grows in later waves.

return [];
```

- [ ] **Step 7: Write stub `routes.php`**

```php
<?php
declare(strict_types=1);

// HTTP route registration for the communications module.
// Routes are added in later waves; this stub keeps boot working today.

return [];
```

- [ ] **Step 8: Commit**

```bash
cd c:/laragon/www/modules/communications
git init
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add .
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8): communications module skeleton"
```

### Task A4: Register module in platform `config/modules.php`

**Files:**

- Modify: `c:/laragon/www/daems-platform/config/modules.php`

- [ ] **Step 1: Add entry at end of return array**

Add immediately before the closing `];`:

```php
'communications' => [
    'category'          => 'communications',
    'name_key'          => 'modules.communications.name',
    'description_key'   => 'modules.communications.description',
    'is_core'           => false,
    'default_available' => true,
    'sidebar'           => null,
    'route_prefixes'    => new RoutePrefixes(
        backstage: ['/backstage/communications', '/backstage/settings/communications'],
        api: [
            '/api/v1/backstage/communications',
            '/api/v1/users',
            '/api/v1/meetings/from-composer',
        ],
    ),
    'depends_on'        => ['members'],
],
```

Note: `sidebar=null` because the module's 4 backstage sub-items are added in `BackstageSidebar.php` directly (see Task A5).

- [ ] **Step 2: Run module-registry validation test**

```bash
cd c:/laragon/www/daems-platform
composer test -- --filter=ModuleRegistryTest
```

Expected: 0 failures (graph still acyclic, route prefixes don't overlap).

- [ ] **Step 3: Commit**

```bash
cd c:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add config/modules.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): platform module catalog entry (sidebar=null, depends_on=members)"
```

### Task A5: Wire `communications` group rank into `BackstageSidebar`

**Files:**

- Modify: `c:/laragon/www/daems-platform/src/Frontend/BackstageSidebar.php:33-43` (GROUP_RANK constant)
- Modify: `c:/laragon/www/daems-platform/src/Frontend/BackstageSidebar.php` (buildFor method — add 4 hardcoded items)

- [ ] **Step 1: Read current GROUP_RANK constant**

```bash
sed -n '33,43p' c:/laragon/www/daems-platform/src/Frontend/BackstageSidebar.php
```

- [ ] **Step 2: Edit `GROUP_RANK` to add `communications` between `governance` and `system`**

Replace lines 35-42 of `BackstageSidebar.php`:

```php
    private const GROUP_RANK = [
        'shell'          => 0,
        'members'        => 1,
        'content'        => 2,
        'community'      => 3,
        'governance'     => 4,
        'communications' => 5,
        'system'         => 6,
    ];
```

- [ ] **Step 3: Add 4 hardcoded communications items to `buildFor()`**

Find the spot in `buildFor()` after the governance items but before the module-discovered items loop. Insert (guarded by `TenantModuleResolver::isEnabled('communications', $tenant)`):

```php
// 4. Communications group — module sub-items, conditional on tenant enablement.
if ($this->resolver->isEnabled('communications', $tenant)) {
    $items[] = [
        'group'     => 'communications',
        'label_key' => 'shell.communications.compose',
        'href'      => '/backstage/communications',
        'icon'      => 'mail',
        'order'     => 10,
    ];
    $items[] = [
        'group'     => 'communications',
        'label_key' => 'shell.communications.outbox',
        'href'      => '/backstage/communications/outbox',
        'icon'      => 'inbox',
        'order'     => 20,
    ];
    $items[] = [
        'group'     => 'communications',
        'label_key' => 'shell.communications.newsletters',
        'href'      => '/backstage/communications/newsletters',
        'icon'      => 'newspaper',
        'order'     => 30,
    ];
    $items[] = [
        'group'     => 'communications',
        'label_key' => 'shell.communications.templates',
        'href'      => '/backstage/communications/templates',
        'icon'      => 'file-text',
        'order'     => 40,
    ];
}
```

- [ ] **Step 4: Run existing BackstageSidebar test**

```bash
cd c:/laragon/www/daems-platform
composer test -- --filter=BackstageSidebarTest
```

Expected: All existing tests pass (4 new conditional items appear only when communications is enabled; default test tenants don't have it enabled yet).

- [ ] **Step 5: Commit**

```bash
cd c:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add src/Frontend/BackstageSidebar.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): BackstageSidebar group-rank 5 + 4 hardcoded sub-items (gated by isEnabled)"
```

### Task A6: Write migration 098 — 7 communications tables + seeds

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/migrations/098_create_communications_tables.sql`

- [ ] **Step 1: Write migration file**

Copy the complete SQL from spec § 6 into the new file. (Full SQL is preserved in the spec at `docs/superpowers/specs/2026-05-13-communications-v1-design.md` § 6; do NOT paraphrase — copy verbatim.)

- [ ] **Step 2: Run migration against dev DB to verify syntax**

```bash
cd c:/laragon/www/daems-platform
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe \
  -h 127.0.0.1 -u root -psalasana daems_db \
  < ../modules/communications/backend/migrations/098_create_communications_tables.sql
```

Expected: 7 `CREATE TABLE` statements, 4 `INSERT … SELECT` seed statements run without error.

- [ ] **Step 3: Verify tables exist**

```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe \
  -h 127.0.0.1 -u root -psalasana daems_db \
  -e "SHOW TABLES LIKE 'tenant_communication%'; SHOW TABLES LIKE 'meetings'; SHOW TABLES LIKE 'mail_%'; SHOW TABLES LIKE 'newsletter_drafts'; SHOW TABLES LIKE 'user_communication_preferences';"
```

Expected output lists all 7 table names.

- [ ] **Step 4: Verify seed rows present**

```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe \
  -h 127.0.0.1 -u root -psalasana daems_db \
  -e "SELECT category, opted_in, COUNT(*) FROM user_communication_preferences GROUP BY category, opted_in;"
```

Expected: 3 rows (transactional/operational/TRUE, marketing/FALSE), counts >= number of (user, tenant) pairs in daems_db.

- [ ] **Step 5: Verify default settings seeded**

```bash
C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe \
  -h 127.0.0.1 -u root -psalasana daems_db \
  -e "SELECT tenant_id, reminder_pre_due_days, reminder_post_due_days, lapse_warning_days_before FROM tenant_communication_settings;"
```

Expected: 1 row per tenant (daems, sahegroup), defaults 7 / [14, 30] / 30.

- [ ] **Step 6: Commit**

```bash
cd c:/laragon/www/modules/communications
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add backend/migrations/098_create_communications_tables.sql
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): migration 098 — 7 tables + default seeds"
```

### Task A7: Add ~120 i18n keys (fi_FI + en_GB + sw_TZ)

**Files:**

- Modify: `c:/laragon/www/daems-platform/lang/fi_FI.php`
- Modify: `c:/laragon/www/daems-platform/lang/en_GB.php`
- Modify: `c:/laragon/www/daems-platform/lang/sw_TZ.php`

- [ ] **Step 1: Append the communications namespace to `fi_FI.php`**

Find the last `return [...]` closing in the file. Inside the array, before the closing `];`, append:

```php
    // ===== Communications module (0.8) =====
    'modules.communications.name'        => 'Viestintä',
    'modules.communications.description' => 'Sähköposti-viestintä jäsenistölle: kokouskutsut, maksumuistutukset, ryhmäviestit, uutiskirjeet, monikieliset pohjat.',

    'shell.communications.compose'      => 'Lähetä viesti',
    'shell.communications.outbox'       => 'Lähetetyt + jonossa',
    'shell.communications.newsletters'  => 'Uutiskirjeet',
    'shell.communications.templates'    => 'Pohjat',

    'backstage.title.communications.compose'      => 'Lähetä viesti',
    'backstage.title.communications.outbox'       => 'Lähetetyt + jonossa',
    'backstage.title.communications.newsletters'  => 'Uutiskirjeet',
    'backstage.title.communications.templates'    => 'Viestipohjat',
    'backstage.title.communications.settings'     => 'Viestintä-asetukset',

    'communications.kind.meeting_invitation'   => 'Kokouskutsu',
    'communications.kind.payment_reminder'     => 'Maksumuistutus',
    'communications.kind.membership_approved'  => 'Jäsenhakemus hyväksytty',
    'communications.kind.group_message'        => 'Ryhmäviesti',
    'communications.kind.newsletter'           => 'Uutiskirje',

    'communications.category.transactional'             => 'Transaktiomaalit',
    'communications.category.transactional.description' => 'Pakolliset viestit kuten maksumuistutukset, kokouskutsut, hyväksyntäilmoitukset. Eivät ole peruutettavissa.',
    'communications.category.operational'               => 'Toiminnalliset',
    'communications.category.operational.description'   => 'Toimintaa koskevat viestit kuten projektipäivitykset. Oletuksena päällä, voi peruuttaa.',
    'communications.category.marketing'                 => 'Markkinointi',
    'communications.category.marketing.description'     => 'Uutiskirjeet ja kampanjat. Oletuksena pois — vaatii aktiivisen suostumuksen.',

    'communications.composer.kind_label'         => 'Viestityyppi',
    'communications.composer.preview'            => 'Esikatselu',
    'communications.composer.audience_count'     => 'Vastaanottajia: :count',
    'communications.composer.send'               => 'Lähetä jonoon',
    'communications.composer.save_draft'         => 'Tallenna luonnoksena',
    'communications.composer.test_to_self'       => 'Lähetä testi itselle',
    'communications.composer.no_smtp'            => 'SMTP ei ole konfiguroitu. Aseta SMTP-tunnukset asetus-sivulla ennen lähetystä.',
    'communications.composer.empty_audience'     => 'Ei vastaanottajia annetuilla suodattimilla.',
    'communications.composer.missing_field'      => 'Pakollinen kenttä puuttuu: :field',

    'communications.outbox.status.queued'      => 'Jonossa',
    'communications.outbox.status.sending'     => 'Lähetetään',
    'communications.outbox.status.sent'        => 'Lähetetty',
    'communications.outbox.status.failed'      => 'Epäonnistui',
    'communications.outbox.status.bounced'     => 'Palautui',
    'communications.outbox.status.suppressed'  => 'Suppression-listalla',
    'communications.outbox.action.retry'       => 'Yritä uudelleen',
    'communications.outbox.action.resend'      => 'Lähetä uudelleen',
    'communications.outbox.filter.status'      => 'Tila',
    'communications.outbox.filter.kind'        => 'Tyyppi',
    'communications.outbox.filter.date_range'  => 'Aikaväli',
    'communications.outbox.filter.recipient'   => 'Vastaanottaja',
    'communications.outbox.empty'              => 'Ei viestejä.',

    'communications.newsletter.new'             => 'Uusi uutiskirje',
    'communications.newsletter.draft'           => 'Luonnos',
    'communications.newsletter.sent'            => 'Lähetetty',
    'communications.newsletter.block.heading'   => 'Otsikko',
    'communications.newsletter.block.paragraph' => 'Kappale',
    'communications.newsletter.block.image'     => 'Kuva',
    'communications.newsletter.block.button'    => 'Nappi',
    'communications.newsletter.block.divider'   => 'Jakaja',
    'communications.newsletter.block.two_columns' => '2 palstaa',
    'communications.newsletter.block.event_card' => 'Tapahtuma-kortti',

    'communications.template.subject'    => 'Aihe',
    'communications.template.intro'      => 'Saateteksti',
    'communications.template.signature'  => 'Allekirjoitus',
    'communications.template.footer'     => 'Alatunniste',
    'communications.template.locale_missing' => 'Käännös puuttuu — en_GB toimii oletuksena',

    'communications.settings.smtp.dsn'          => 'SMTP-DSN',
    'communications.settings.smtp.from'         => 'Lähettäjä-osoite',
    'communications.settings.smtp.display_name' => 'Lähettäjä-nimi',
    'communications.settings.smtp.reply_to'     => 'Vastausosoite',
    'communications.settings.smtp.test'         => 'Lähetä testi',
    'communications.settings.smtp.test_success' => 'SMTP-testi onnistui :time',
    'communications.settings.cron.pre_due'      => 'Maksumuistutus ennen eräpäivää (vrk)',
    'communications.settings.cron.post_due'     => 'Maksumuistutus eräpäivän jälkeen (vrk-lista)',
    'communications.settings.cron.lapse'        => 'Lapse-varoitus ennen § 4 -lapsea (vrk)',
    'communications.settings.brand.logo'        => 'Logo-URL',
    'communications.settings.brand.color'       => 'Pääväri (hex)',
    'communications.settings.brand.footer'      => 'Alatunniste-osoite',

    'communications.suppression.reason.hard_bounce'  => 'Hard-bounce',
    'communications.suppression.reason.complaint'    => 'Valitus',
    'communications.suppression.reason.manual_block' => 'Manuaalinen esto',
    'communications.suppression.remove'              => 'Poista listalta',
    'communications.suppression.add'                 => 'Lisää käsin',
    'communications.suppression.empty'               => 'Suppression-lista on tyhjä.',

    'communications.unsubscribe.title'      => 'Poistu listalta',
    'communications.unsubscribe.confirm'    => 'Vahvista poisto',
    'communications.unsubscribe.success'    => 'Olet poistettu listalta. Voit aktivoida tilauksen uudelleen jäsenprofiilistasi.',
    'communications.unsubscribe.expired'    => 'Linkki on vanhentunut. Kirjaudu sisään muokataksesi asetuksia.',
    'communications.unsubscribe.invalid'    => 'Linkki ei kelpaa.',
```

- [ ] **Step 2: Append same keys to `en_GB.php`**

Translate values to British English (preserve placeholder syntax `:count`, `:field`, `:time`).

- [ ] **Step 3: Append same keys to `sw_TZ.php`**

Translate values to Swahili.

- [ ] **Step 4: Run i18n parity check (will be created in Task A8)**

Skip for now if Task A8 hasn't landed yet; verify in Task A8.

- [ ] **Step 5: Commit**

```bash
cd c:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add lang/fi_FI.php lang/en_GB.php lang/sw_TZ.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): ~120 i18n keys across fi_FI/en_GB/sw_TZ"
```

### Task A8: Write i18n parity test for communications keys

**Files:**

- Create: `c:/laragon/www/daems-platform/tests/Unit/I18n/CommunicationsParityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class CommunicationsParityTest extends TestCase
{
    public function test_all_communications_keys_present_in_three_locales(): void
    {
        $fi = require __DIR__ . '/../../../lang/fi_FI.php';
        $en = require __DIR__ . '/../../../lang/en_GB.php';
        $sw = require __DIR__ . '/../../../lang/sw_TZ.php';

        $commKeys = array_filter(
            array_keys($fi),
            fn(string $k) =>
                str_starts_with($k, 'modules.communications.') ||
                str_starts_with($k, 'shell.communications.') ||
                str_starts_with($k, 'backstage.title.communications.') ||
                str_starts_with($k, 'communications.')
        );

        $missingEn = array_diff($commKeys, array_keys($en));
        $missingSw = array_diff($commKeys, array_keys($sw));

        $this->assertEmpty($missingEn, 'en_GB missing keys: ' . implode(', ', $missingEn));
        $this->assertEmpty($missingSw, 'sw_TZ missing keys: ' . implode(', ', $missingSw));

        $this->assertGreaterThan(80, count($commKeys), 'expected at least 80 communications keys');
    }
}
```

- [ ] **Step 2: Run test**

```bash
cd c:/laragon/www/daems-platform
composer test -- --filter=CommunicationsParityTest
```

Expected: PASS (Task A7 already added all 3 locales).

If FAIL on missing en_GB or sw_TZ key, go back to Task A7 step 2 or 3 and add the missing key.

- [ ] **Step 3: Commit**

```bash
cd c:/laragon/www/daems-platform
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add tests/Unit/I18n/CommunicationsParityTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Test(0.8/communications): i18n parity guard for fi_FI/en_GB/sw_TZ"
```

---

## Wave B — Domain entities, repository interfaces, SQL repositories, settings + preferences (2 days)

Goal: All domain entities + value objects exist. 8 repository interfaces + 8 SQL implementations. `TenantCommunicationSettings` + `UserCommunicationPreference` CRUD use cases (settings page can be opened, SMTP DSN can be saved-encrypted-decrypted). Audience resolver implementation. Isolation test base laid.

### Task B1: Write `DsnEncryptor` (Domain-Infrastructure crypto)

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Crypto/DsnEncryptor.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Crypto/DsnEncryptorTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Crypto;

use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use DaemsModule\Communications\Infrastructure\Crypto\DecryptionFailed;
use DaemsModule\Communications\Infrastructure\Crypto\InvalidEncryptionKey;
use PHPUnit\Framework\TestCase;

final class DsnEncryptorTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $enc = new DsnEncryptor($this->key);
        $plaintext = 'smtp://user:pass@mail.example.com:587';
        $cipher = $enc->encrypt($plaintext);
        $this->assertNotSame($plaintext, $cipher);
        $this->assertSame($plaintext, $enc->decrypt($cipher));
    }

    public function test_different_nonces_produce_different_ciphertexts(): void
    {
        $enc = new DsnEncryptor($this->key);
        $c1 = $enc->encrypt('secret');
        $c2 = $enc->encrypt('secret');
        $this->assertNotSame($c1, $c2);
    }

    public function test_wrong_key_throws(): void
    {
        $enc1 = new DsnEncryptor($this->key);
        $cipher = $enc1->encrypt('secret');

        $enc2 = new DsnEncryptor(base64_encode(sodium_crypto_secretbox_keygen()));
        $this->expectException(DecryptionFailed::class);
        $enc2->decrypt($cipher);
    }

    public function test_malformed_key_throws(): void
    {
        $this->expectException(InvalidEncryptionKey::class);
        (new DsnEncryptor('not-base64-32-bytes'))->encrypt('x');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd c:/laragon/www/modules/communications
../../daems-platform/vendor/bin/phpunit tests/Unit/Crypto/DsnEncryptorTest.php
```

Expected: FAIL — `DsnEncryptor` class not found.

- [ ] **Step 3: Implement `DsnEncryptor`**

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Communications\Infrastructure\Crypto;

final class DsnEncryptor
{
    public function __construct(private readonly string $base64Key) {}

    public function encrypt(string $plaintext): string
    {
        $key = $this->loadKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public function decrypt(string $encoded): string
    {
        $blob = base64_decode($encoded, true);
        if ($blob === false || strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new MalformedCiphertext('ciphertext too short or not base64');
        }
        $nonce  = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key    = $this->loadKey();
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new DecryptionFailed('decryption failed — wrong key or tampered ciphertext');
        }
        return $plain;
    }

    private function loadKey(): string
    {
        $raw = base64_decode($this->base64Key, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new InvalidEncryptionKey('APP_ENCRYPTION_KEY must be base64-encoded 32 bytes');
        }
        return $raw;
    }
}

final class InvalidEncryptionKey extends \RuntimeException {}
final class MalformedCiphertext extends \RuntimeException {}
final class DecryptionFailed    extends \RuntimeException {}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd c:/laragon/www/modules/communications
../../daems-platform/vendor/bin/phpunit tests/Unit/Crypto/DsnEncryptorTest.php
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
cd c:/laragon/www/modules/communications
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add backend/src/Infrastructure/Crypto/ tests/Unit/Crypto/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): DsnEncryptor + 4 unit tests"
```

### Task B2: Write Mail domain enums + value objects

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailKind.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailOutboxStatus.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/SuppressionReason.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailOutboxId.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailTemplateId.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/NewsletterId.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Meeting/MeetingId.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Domain/Mail/MailKindTest.php`

- [ ] **Step 1: Write failing enum test**

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Domain\Mail;

use DaemsModule\Communications\Domain\Mail\MailKind;
use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use PHPUnit\Framework\TestCase;

final class MailKindTest extends TestCase
{
    public function test_each_kind_maps_to_category(): void
    {
        $this->assertSame(CommunicationCategory::Transactional, MailKind::MeetingInvitation->category());
        $this->assertSame(CommunicationCategory::Transactional, MailKind::PaymentReminder->category());
        $this->assertSame(CommunicationCategory::Transactional, MailKind::MembershipApproved->category());
        $this->assertSame(CommunicationCategory::Operational,   MailKind::GroupMessage->category());
        $this->assertSame(CommunicationCategory::Marketing,     MailKind::Newsletter->category());
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
../../daems-platform/vendor/bin/phpunit tests/Unit/Domain/Mail/MailKindTest.php
```

- [ ] **Step 3: Implement enums + UUID-id classes**

```php
// Domain/Mail/MailKind.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

use DaemsModule\Communications\Domain\Preference\CommunicationCategory;

enum MailKind: string
{
    case MeetingInvitation  = 'meeting_invitation';
    case PaymentReminder    = 'payment_reminder';
    case MembershipApproved = 'membership_approved';
    case GroupMessage       = 'group_message';
    case Newsletter         = 'newsletter';

    public function category(): CommunicationCategory
    {
        return match ($this) {
            self::MeetingInvitation, self::PaymentReminder, self::MembershipApproved => CommunicationCategory::Transactional,
            self::GroupMessage => CommunicationCategory::Operational,
            self::Newsletter   => CommunicationCategory::Marketing,
        };
    }
}
```

```php
// Domain/Mail/MailOutboxStatus.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

enum MailOutboxStatus: string
{
    case Queued     = 'queued';
    case Sending    = 'sending';
    case Sent       = 'sent';
    case Failed     = 'failed';
    case Bounced    = 'bounced';
    case Suppressed = 'suppressed';
}
```

```php
// Domain/Mail/SuppressionReason.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

enum SuppressionReason: string
{
    case HardBounce  = 'hard_bounce';
    case Complaint   = 'complaint';
    case ManualBlock = 'manual_block';
}
```

```php
// Domain/Mail/MailOutboxId.php (and identical structure for MailTemplateId, NewsletterId, MeetingId)
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

final class MailOutboxId
{
    public function __construct(public readonly string $value)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new \InvalidArgumentException('MailOutboxId must be a UUID');
        }
    }

    public static function generate(): self
    {
        return new self(self::uuid4());
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4),
            substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public function equals(self $other): bool { return $this->value === $other->value; }
}
```

Repeat the same pattern for `MailTemplateId`, `NewsletterId`, and (in `Domain/Meeting/`) `MeetingId`.

- [ ] **Step 4: Run test — expect PASS** (assumes Task B3 lands `CommunicationCategory` first; if Task B3 hasn't run, run Task B3 then return here)

- [ ] **Step 5: Commit**

```bash
git add backend/src/Domain/Mail/ backend/src/Domain/Meeting/MeetingId.php tests/Unit/Domain/Mail/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Mail/Meeting domain enums + UUID-id value objects"
```

### Task B3: Write Preference + Settings domain

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Preference/CommunicationCategory.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Preference/UserCommunicationPreference.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Preference/UserCommunicationPreferenceRepositoryInterface.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Settings/TenantCommunicationSettings.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Settings/TenantCommunicationSettingsRepositoryInterface.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Domain/Preference/CommunicationCategoryTest.php`

- [ ] **Step 1: Write failing test for category transactional immutability**

```php
<?php
declare(strict_types=1);

namespace DaemsModule\Communications\Tests\Unit\Domain\Preference;

use DaemsModule\Communications\Domain\Preference\CommunicationCategory;
use PHPUnit\Framework\TestCase;

final class CommunicationCategoryTest extends TestCase
{
    public function test_transactional_is_immutable(): void
    {
        $this->assertTrue(CommunicationCategory::Transactional->isImmutable());
        $this->assertFalse(CommunicationCategory::Operational->isImmutable());
        $this->assertFalse(CommunicationCategory::Marketing->isImmutable());
    }

    public function test_default_opt_in(): void
    {
        $this->assertTrue(CommunicationCategory::Transactional->defaultOptedIn());
        $this->assertTrue(CommunicationCategory::Operational->defaultOptedIn());
        $this->assertFalse(CommunicationCategory::Marketing->defaultOptedIn());
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement domain classes**

```php
// Domain/Preference/CommunicationCategory.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Preference;

enum CommunicationCategory: string
{
    case Transactional = 'transactional';
    case Operational   = 'operational';
    case Marketing     = 'marketing';

    public function isImmutable(): bool      { return $this === self::Transactional; }
    public function defaultOptedIn(): bool   { return $this !== self::Marketing; }
}
```

```php
// Domain/Preference/UserCommunicationPreference.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Preference;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class UserCommunicationPreference
{
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
        public readonly CommunicationCategory $category,
        public readonly bool $optedIn,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}
}
```

```php
// Domain/Preference/UserCommunicationPreferenceRepositoryInterface.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Preference;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface UserCommunicationPreferenceRepositoryInterface
{
    /** @return list<UserCommunicationPreference> */
    public function findFor(UserId $userId, TenantId $tenantId): array;

    public function setFor(UserId $userId, TenantId $tenantId, CommunicationCategory $category, bool $optedIn): void;

    /** @return list<CommunicationCategory> */
    public function categoriesAllowing(UserId $userId, TenantId $tenantId): array;
}
```

```php
// Domain/Settings/TenantCommunicationSettings.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Settings;

use Daems\Domain\Tenant\TenantId;

final class TenantCommunicationSettings
{
    /**
     * @param list<int> $reminderPostDueDays
     */
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ?string $smtpDsnEncrypted,
        public readonly ?string $mailFromAddress,
        public readonly ?string $mailDisplayName,
        public readonly ?string $mailReplyTo,
        public readonly ?\DateTimeImmutable $smtpTestSucceededAt,
        public readonly int $reminderPreDueDays,
        public readonly array $reminderPostDueDays,
        public readonly int $lapseWarningDaysBefore,
        public readonly ?string $brandLogoUrl,
        public readonly ?string $brandPrimaryColor,
        public readonly ?string $brandFooterAddress,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function isSmtpConfigured(): bool
    {
        return $this->smtpDsnEncrypted !== null && $this->mailFromAddress !== null;
    }
}
```

```php
// Domain/Settings/TenantCommunicationSettingsRepositoryInterface.php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Settings;

use Daems\Domain\Tenant\TenantId;

interface TenantCommunicationSettingsRepositoryInterface
{
    public function findForTenant(TenantId $tenantId): TenantCommunicationSettings;
    public function save(TenantCommunicationSettings $settings): void;
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add backend/src/Domain/Preference/ backend/src/Domain/Settings/ tests/Unit/Domain/Preference/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Preference + Settings domain (CommunicationCategory + entities + IFs)"
```

### Task B4: Write remaining Mail entities + repository interfaces

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailOutbox.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailOutboxRepositoryInterface.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailSuppression.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailSuppressionRepositoryInterface.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/Exception/SmtpNotConfigured.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/Exception/RecipientSuppressed.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/Exception/MailerHardBounceException.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/Exception/MailerSoftBounceException.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/Exception/MailerTransportException.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/Exception/UnknownTemplateVarException.php`

- [ ] **Step 1: Implement `MailOutbox` entity**

Use the structure documented in spec § 4.1. Constructor takes 18 readonly properties. No tests at this stage — entity is data-only.

- [ ] **Step 2: Implement `MailSuppression` entity**

Mirror spec § 4.1.

- [ ] **Step 3: Implement `MailOutboxRepositoryInterface`**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

use Daems\Domain\Tenant\TenantId;

interface MailOutboxRepositoryInterface
{
    public function save(MailOutbox $row): void;
    public function findById(MailOutboxId $id): ?MailOutbox;

    /**
     * @param array{status?: MailOutboxStatus, kind?: MailKind, from?: \DateTimeImmutable, to?: \DateTimeImmutable, recipient_substring?: string} $filters
     * @return list<MailOutbox>
     */
    public function listForTenant(TenantId $tenantId, array $filters, int $page, int $perPage): array;

    public function countForTenant(TenantId $tenantId, array $filters): int;

    /** @return list<MailOutbox> */
    public function pickNextForSending(int $limit): array;

    public function markStatus(MailOutboxId $id, MailOutboxStatus $status, ?string $error = null): void;

    public function incrementAttempt(MailOutboxId $id, string $error): void;
}
```

- [ ] **Step 4: Implement `MailSuppressionRepositoryInterface`**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

interface MailSuppressionRepositoryInterface
{
    public function isSuppressed(TenantId $tenantId, string $email): bool;
    public function add(MailSuppression $suppression): void;
    public function remove(TenantId $tenantId, string $email): void;

    /** @return list<MailSuppression> */
    public function listForTenant(TenantId $tenantId): array;
}
```

- [ ] **Step 5: Implement exception classes (all extend `\RuntimeException` except `RecipientSuppressed` which extends `\DomainException`)**

Each is ~10 lines.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Domain/Mail/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): MailOutbox + MailSuppression entities + repository IFs + exceptions"
```

### Task B5: Write Meeting, Template, Audience, Newsletter domain

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Meeting/{Meeting,MeetingType,MeetingStatus,MeetingRepositoryInterface}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Template/{MailTemplate,NewsletterDraft,NewsletterStatus,MailTemplateRepositoryInterface,NewsletterDraftRepositoryInterface}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Template/Block/{NewsletterBlock,HeadingBlock,ParagraphBlock,ImageBlock,ButtonBlock,DividerBlock,TwoColumnsBlock,EventCardBlock}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Audience/{AudienceFilter,JoinedWithinPeriod,ResolvedRecipient,AudienceResolverInterface}.php`

- [ ] **Step 1: Implement Meeting domain (entity + 2 enums + repository IF)**

Mirror spec § 4.4. `Meeting`'s constructor takes 13 readonly properties; `MeetingRepositoryInterface` has `save`, `findById`, and `listForTenant(TenantId, ?\DateTimeImmutable $startsAfter, ?\DateTimeImmutable $endsBefore)`.

- [ ] **Step 2: Implement Template + Newsletter (entity + enum + 2 repository IFs)**

`NewsletterStatus` enum has `Draft`, `Scheduled`, `Sent`. `MailTemplate` carries per-(tenant, kind, locale) string overrides as `array $stringOverrides`.

- [ ] **Step 3: Implement `NewsletterBlock` hierarchy**

`NewsletterBlock` is abstract with one method `toRenderable(): array`. Each concrete block validates its own input in its constructor and produces a renderable dict.

Example:

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Template\Block;

final class HeadingBlock extends NewsletterBlock
{
    public function __construct(public readonly int $level, public readonly string $text)
    {
        if ($level < 1 || $level > 3) {
            throw new \InvalidArgumentException('Heading level must be 1-3');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('Heading text cannot be empty');
        }
    }

    public function toRenderable(): array
    {
        return ['type' => 'heading', 'level' => $this->level, 'text' => $this->text];
    }
}
```

`TwoColumnsBlock` has nested `left`/`right` arrays of `NewsletterBlock`. Disallow `TwoColumnsBlock` inside `TwoColumnsBlock` (max recursion depth = 1):

```php
public function __construct(public readonly array $left, public readonly array $right)
{
    foreach ([...$left, ...$right] as $b) {
        if (!$b instanceof NewsletterBlock) {
            throw new \InvalidArgumentException('TwoColumns children must be NewsletterBlocks');
        }
        if ($b instanceof TwoColumnsBlock) {
            throw new \InvalidArgumentException('TwoColumns blocks cannot be nested');
        }
    }
}
```

- [ ] **Step 4: Implement Audience domain**

`AudienceFilter` carries 4 fields per spec § 4.3. `ResolvedRecipient` carries 5 fields. `AudienceResolverInterface::resolve(TenantId, AudienceFilter, CommunicationCategory): list<ResolvedRecipient>`.

`JoinedWithinPeriod` enum: `Last30Days`, `Last90Days`, `Last1Year`.

- [ ] **Step 5: Write Heading + TwoColumns block tests**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Tests\Unit\Domain\Template\Block;

use DaemsModule\Communications\Domain\Template\Block\HeadingBlock;
use DaemsModule\Communications\Domain\Template\Block\TwoColumnsBlock;
use DaemsModule\Communications\Domain\Template\Block\ParagraphBlock;
use PHPUnit\Framework\TestCase;

final class BlockTest extends TestCase
{
    public function test_heading_level_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeadingBlock(4, 'invalid');
    }

    public function test_heading_text_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeadingBlock(1, '   ');
    }

    public function test_two_columns_disallows_nested_two_columns(): void
    {
        $inner = new TwoColumnsBlock([new ParagraphBlock('a')], [new ParagraphBlock('b')]);
        $this->expectException(\InvalidArgumentException::class);
        new TwoColumnsBlock([$inner], []);
    }

    public function test_two_columns_disallows_non_block_children(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line */
        new TwoColumnsBlock(['not-a-block'], []);
    }
}
```

- [ ] **Step 6: Run — expect PASS**

- [ ] **Step 7: Commit**

```bash
git add backend/src/Domain/Meeting/ backend/src/Domain/Template/ backend/src/Domain/Audience/ tests/Unit/Domain/Template/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Meeting + Template + Audience domain + NewsletterBlock hierarchy + tests"
```

### Task B6: Write SQL repository skeletons (8 classes)

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlMailOutboxRepository.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlMailSuppressionRepository.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlMailTemplateRepository.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlNewsletterDraftRepository.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlMeetingRepository.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlUserCommunicationPreferenceRepository.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Persistence/SqlTenantCommunicationSettingsRepository.php`

- [ ] **Step 1: Implement `SqlMailOutboxRepository`**

Use existing platform `Daems\Infrastructure\Adapter\Persistence\Sql\*Repository` examples as templates (e.g., `SqlUserRepository.php`). Each method returns a domain entity or list of entities, never raw rows. Use prepared statements. The `pickNextForSending` uses `SELECT … WHERE status = 'queued' ORDER BY queued_at LIMIT N FOR UPDATE SKIP LOCKED` then within the same transaction `UPDATE … SET status = 'sending' WHERE id IN (…)`.

(This is a long implementation; the engineer should mirror the verbosity of existing SQL repositories — strict typing, named parameters, `\PDO::FETCH_ASSOC`, JSON encode/decode for JSON columns.)

- [ ] **Step 2: Implement the other 6 repositories**

Each follows the same shape. `SqlNewsletterDraftRepository` handles `json_encode` of `blocks_i18n` and reconstruction of `NewsletterBlock` hierarchy on `findById`. `SqlMeetingRepository` handles `title_i18n` + `agenda_items_i18n` + `document_urls` JSON columns. `SqlUserCommunicationPreferenceRepository::categoriesAllowing` returns categories where `opted_in = TRUE` for the (user, tenant) pair.

- [ ] **Step 3: Integration test for outbox round-trip**

Create `c:/laragon/www/modules/communications/tests/Integration/SqlMailOutboxRepositoryTest.php` extending `MigrationTestCase` (or equivalent harness from the platform). Test: save → findById → markStatus → re-fetch.

- [ ] **Step 4: Run integration test**

```bash
cd c:/laragon/www/daems-platform
composer test -- --filter=SqlMailOutboxRepositoryTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Infrastructure/Persistence/ tests/Integration/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): 7 SQL repositories + SqlMailOutbox integration test"
```

### Task B7: Implement `SqlAudienceResolver`

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Audience/SqlAudienceResolver.php`
- Create: `c:/laragon/www/modules/communications/tests/Integration/AudienceResolverTest.php`

- [ ] **Step 1: Implement resolver**

The resolver builds a SQL query joining `users`, `user_tenants`, `user_communication_preferences`, and (when `application_status` filter active) `member_applications`. Returns `list<ResolvedRecipient>`.

Filter logic:

- `membership_types[]` empty → no filter; non-empty → `WHERE user_tenants.membership_type IN (...)`
- `locales[]` non-empty → `WHERE users.preferred_locale IN (...)`
- `joined_within = Last30Days` → `WHERE user_tenants.joined_at >= NOW() - INTERVAL 30 DAY` (similar for 90Days, 1Year)
- `application_statuses[]` non-empty → join `member_applications`, filter status
- Always join `user_communication_preferences` and require `opted_in = TRUE` for the request's category (transactional skips this — always send)

Pseudocode shape:

```php
public function resolve(TenantId $t, AudienceFilter $f, CommunicationCategory $c): array
{
    $sql = "SELECT u.id, u.email, u.preferred_locale, u.first_name, u.last_name
            FROM users u
            JOIN user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :tenant_id";

    if ($c !== CommunicationCategory::Transactional) {
        $sql .= " JOIN user_communication_preferences ucp ON ucp.user_id = u.id AND ucp.tenant_id = :tenant_id
                  WHERE ucp.category = :cat AND ucp.opted_in = TRUE";
    } else {
        $sql .= " WHERE 1=1";
    }

    // ... membership_types, locales, joined_within, application_status filters appended ...

    // Execute, hydrate ResolvedRecipient list
}
```

- [ ] **Step 2: Write integration test covering 4 filter combinations**

```php
public function test_filter_by_membership_types(): void { /* set up users, call resolve, assert count */ }
public function test_filter_by_locales(): void { /* ... */ }
public function test_joined_within_last_30_days(): void { /* ... */ }
public function test_marketing_skips_opted_out_users(): void { /* ... */ }
```

- [ ] **Step 3: Run — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add backend/src/Infrastructure/Audience/ tests/Integration/AudienceResolverTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): SqlAudienceResolver + 4 integration tests"
```

### Task B8: `GetCommunicationSettings` + `SaveCommunicationSettings` + `SendSmtpTestEmail` use cases

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Application/GetCommunicationSettings/{GetCommunicationSettings,Input,Output}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Application/SaveCommunicationSettings/{SaveCommunicationSettings,Input,Output}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Application/SendSmtpTestEmail/{SendSmtpTestEmail,Input,Output}.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Application/SaveCommunicationSettingsTest.php`

- [ ] **Step 1: Write failing test for `SaveCommunicationSettings`**

Tests: (1) acting non-admin → ForbiddenException, (2) saving a new DSN encrypts it, (3) saving a new DSN resets `smtp_test_succeeded_at` to null, (4) saving without changing DSN preserves `smtp_test_succeeded_at`.

- [ ] **Step 2: Implement `SaveCommunicationSettings`**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Application\SaveCommunicationSettings;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;

final class SaveCommunicationSettings
{
    public function __construct(
        private readonly TenantCommunicationSettingsRepositoryInterface $repo,
        private readonly DsnEncryptor $encryptor,
    ) {}

    public function execute(Input $input, ActingUser $acting): Output
    {
        if (!$acting->isAdminIn($input->tenantId)) {
            throw new ForbiddenException();
        }

        $current = $this->repo->findForTenant($input->tenantId);

        $newDsnEnc = $input->plainSmtpDsn !== null
            ? $this->encryptor->encrypt($input->plainSmtpDsn)
            : $current->smtpDsnEncrypted;

        $testAt = $input->plainSmtpDsn !== null ? null : $current->smtpTestSucceededAt;

        $updated = new TenantCommunicationSettings(
            tenantId:               $input->tenantId,
            smtpDsnEncrypted:       $newDsnEnc,
            mailFromAddress:        $input->mailFromAddress ?? $current->mailFromAddress,
            mailDisplayName:        $input->mailDisplayName ?? $current->mailDisplayName,
            mailReplyTo:            $input->mailReplyTo ?? $current->mailReplyTo,
            smtpTestSucceededAt:    $testAt,
            reminderPreDueDays:     $input->reminderPreDueDays ?? $current->reminderPreDueDays,
            reminderPostDueDays:    $input->reminderPostDueDays ?? $current->reminderPostDueDays,
            lapseWarningDaysBefore: $input->lapseWarningDaysBefore ?? $current->lapseWarningDaysBefore,
            brandLogoUrl:           $input->brandLogoUrl ?? $current->brandLogoUrl,
            brandPrimaryColor:      $input->brandPrimaryColor ?? $current->brandPrimaryColor,
            brandFooterAddress:     $input->brandFooterAddress ?? $current->brandFooterAddress,
            updatedAt:              new \DateTimeImmutable(),
        );

        $this->repo->save($updated);
        return new Output(success: true);
    }
}
```

- [ ] **Step 3: Implement `GetCommunicationSettings` (returns settings with DSN masked as `***` for non-GSA)**

- [ ] **Step 4: Implement `SendSmtpTestEmail`**

Calls `MailerInterface->send()` synchronously with a hard-coded test message (from `App\Mailer\TestEmail` template — minimal). On success, calls `repo->save($settings with smtpTestSucceededAt = now())`. Throws `MailerTransportException` on failure (controller maps to 422).

- [ ] **Step 5: Run unit tests — expect PASS**

- [ ] **Step 6: Commit**

```bash
git add backend/src/Application/{GetCommunicationSettings,SaveCommunicationSettings,SendSmtpTestEmail}/ tests/Unit/Application/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Get/Save settings + SmtpTest use cases (3) + tests"
```

### Task B9: `GetUserCommunicationPreferences` + `UpdateUserCommunicationPreference` use cases

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Application/GetUserCommunicationPreferences/{GetUserCommunicationPreferences,Input,Output}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Application/UpdateUserCommunicationPreference/{UpdateUserCommunicationPreference,Input,Output}.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Application/UpdateUserCommunicationPreferenceTest.php`

- [ ] **Step 1: Write failing test asserting transactional update → ForbiddenException**

```php
public function test_updating_transactional_throws(): void
{
    $repo = new InMemoryUserCommunicationPreferenceRepository();
    $useCase = new UpdateUserCommunicationPreference($repo);

    $this->expectException(\Daems\Domain\Auth\ForbiddenException::class);
    $useCase->execute(
        new Input($userId, $tenantId, CommunicationCategory::Transactional, false),
        $someActingUser  // even admin cannot disable transactional
    );
}
```

- [ ] **Step 2: Implement use cases**

The update use case checks: (a) acting is the user himself OR is tenant admin, (b) category is mutable. If both → call repo->setFor().

- [ ] **Step 3: Run — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add backend/src/Application/{GetUserCommunicationPreferences,UpdateUserCommunicationPreference}/ tests/Unit/Application/UpdateUserCommunicationPreferenceTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Get/Update user comm preferences use cases + test"
```

### Task B10: Wire DI bindings (BOTH containers)

**Files:**

- Modify: `c:/laragon/www/modules/communications/backend/bindings.php`
- Modify: `c:/laragon/www/daems-platform/bootstrap/app.php`
- Modify: `c:/laragon/www/daems-platform/tests/Support/KernelHarness.php`

- [ ] **Step 1: Fill `modules/communications/backend/bindings.php`**

Bind every repository interface → SQL implementation, every Application class → constructor wiring. Use the platform's existing DI container syntax (look at how `events` module does it).

- [ ] **Step 2: Add Mailer/Encryptor wirings to `bootstrap/app.php`**

Bind `DsnEncryptor` (reads `$_ENV['APP_ENCRYPTION_KEY']`). Mailer port stays unbound until Wave C.

- [ ] **Step 3: Add InMemory equivalents to `KernelHarness.php`**

Wire `InMemoryUserCommunicationPreferenceRepository` and `InMemoryTenantCommunicationSettingsRepository`.

- [ ] **Step 4: Grep verification**

```bash
cd c:/laragon/www/daems-platform
grep -c "SqlMailOutboxRepository\|SqlUserCommunicationPreferenceRepository" bootstrap/app.php
grep -c "InMemoryUserCommunicationPreferenceRepository" tests/Support/KernelHarness.php
```

Expected: both non-zero. (Reminder: BOTH-containers rule — see `feedback_bootstrap_and_harness_must_both_wire.md`.)

- [ ] **Step 5: Run full unit suite (no regressions)**

```bash
composer test -- --testsuite=Unit
```

Expected: 0 failures.

- [ ] **Step 6: Commit**

```bash
git add modules/communications/backend/bindings.php bootstrap/app.php tests/Support/KernelHarness.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): DI bindings in production + test containers (BOTH-wiring rule)"
```

### Task B11: Bootstrap isolation test base

**Files:**

- Create: `c:/laragon/www/daems-platform/tests/Isolation/CommunicationsTenantIsolationTest.php`

- [ ] **Step 1: Write base isolation test**

```php
<?php
declare(strict_types=1);
namespace Daems\Tests\Isolation;

use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettingsRepositoryInterface;
// ... imports

final class CommunicationsTenantIsolationTest extends IsolationTestCase
{
    public function test_settings_isolation(): void
    {
        // daems-tenant saves SMTP DSN; sahegroup-tenant.repository->findForTenant returns settings with smtpDsnEncrypted=null
        $daemsRepo  = $this->container->get(TenantCommunicationSettingsRepositoryInterface::class);
        $daemsSettings = /* daems-tenantin asetukset, DSN tallennettu */;
        $daemsRepo->save($daemsSettings);

        $sahegroupSettings = $daemsRepo->findForTenant($this->sahegroupTenantId);
        $this->assertNull($sahegroupSettings->smtpDsnEncrypted, 'sahegroup must not see daems DSN');
    }

    // Other 4 tests left as stubs with skip markers — populated in later waves
    public function test_outbox_isolation(): void { $this->markTestSkipped('Wave C'); }
    public function test_suppression_isolation(): void { $this->markTestSkipped('Wave G'); }
    public function test_newsletter_isolation(): void { $this->markTestSkipped('Wave E'); }
    public function test_meeting_isolation(): void { $this->markTestSkipped('Wave D'); }
}
```

- [ ] **Step 2: Run — expect PASS (1 active test, 4 skipped)**

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add tests/Isolation/CommunicationsTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): isolation test base — settings isolation active, 4 stubs for later waves"
```

---

## Wave C — Mailer port + outbox + drain cron + outbox UI + settings UI (2 days)

Goal: SMTP-send works end-to-end. Outbox table accepts rows. Drain cron processes them. Backstage outbox page shows the rows. Settings page admin can save SMTP DSN + run test-send.

### Task C1: `MailerInterface` + exceptions

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Domain/Mail/MailerInterface.php`

- [ ] **Step 1: Implement interface**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Domain\Mail;

use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;

interface MailerInterface
{
    /**
     * @throws Exception\MailerHardBounceException SMTP 5xx (suppression-trigger)
     * @throws Exception\MailerSoftBounceException SMTP 4xx (retry-trigger)
     * @throws Exception\MailerTransportException muut transport-virheet
     * @throws Exception\SmtpNotConfigured kun tenantilla ei ole DSN:ää
     */
    public function send(MailOutbox $row, TenantCommunicationSettings $settings): void;
}
```

- [ ] **Step 2: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add backend/src/Domain/Mail/MailerInterface.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): MailerInterface domain port"
```

### Task C2: `InMemoryMailer` test adapter

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Mailer/InMemoryMailer.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Mailer/InMemoryMailerTest.php`

- [ ] **Step 1: Implement adapter**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Infrastructure\Mailer;

use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;

final class InMemoryMailer implements MailerInterface
{
    /** @var list<array{row: MailOutbox, settings: TenantCommunicationSettings}> */
    public array $sent = [];

    public ?\Throwable $simulateFailure = null;

    public function send(MailOutbox $row, TenantCommunicationSettings $settings): void
    {
        if ($this->simulateFailure !== null) {
            throw $this->simulateFailure;
        }
        $this->sent[] = ['row' => $row, 'settings' => $settings];
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->simulateFailure = null;
    }
}
```

- [ ] **Step 2: Test it stores and clears**

- [ ] **Step 3: Commit**

```bash
git add backend/src/Infrastructure/Mailer/InMemoryMailer.php tests/Unit/Mailer/InMemoryMailerTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): InMemoryMailer test adapter + tests"
```

### Task C3: `SymfonyMailerAdapter`

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Mailer/SymfonyMailerAdapter.php`
- Create: `c:/laragon/www/modules/communications/tests/Unit/Mailer/SymfonyMailerAdapterTest.php`

- [ ] **Step 1: Write failing test for SMTP-code parsing**

```php
public function test_5xx_response_throws_HardBounceException(): void
{
    $msg = 'Expected response code "250" but got code "550", with message "550 5.1.1 The email account does not exist"';
    $adapter = new SymfonyMailerAdapter(/* mock encryptor */);
    $this->assertSame('5.1.1', $adapter->parseSmtpCode($msg));
    $this->assertTrue($adapter->isHardBounce('5.1.1'));
}
```

- [ ] **Step 2: Implement adapter**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Infrastructure\Mailer;

use DaemsModule\Communications\Domain\Mail\MailerInterface;
use DaemsModule\Communications\Domain\Mail\MailOutbox;
use DaemsModule\Communications\Domain\Mail\Exception\MailerHardBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerSoftBounceException;
use DaemsModule\Communications\Domain\Mail\Exception\MailerTransportException;
use DaemsModule\Communications\Domain\Mail\Exception\SmtpNotConfigured;
use DaemsModule\Communications\Domain\Settings\TenantCommunicationSettings;
use DaemsModule\Communications\Infrastructure\Crypto\DsnEncryptor;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class SymfonyMailerAdapter implements MailerInterface
{
    public function __construct(private readonly DsnEncryptor $decryptor) {}

    public function send(MailOutbox $row, TenantCommunicationSettings $s): void
    {
        if (!$s->isSmtpConfigured()) {
            throw new SmtpNotConfigured($row->tenantId->value);
        }

        $dsn = $this->decryptor->decrypt($s->smtpDsnEncrypted);
        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $email = (new Email())
            ->from(new Address($s->mailFromAddress, $s->mailDisplayName ?? ''))
            ->replyTo($s->mailReplyTo ?? $s->mailFromAddress)
            ->to($row->recipientEmail)
            ->subject($row->subject)
            ->html($row->bodyHtml)
            ->text($row->bodyText);

        try {
            $mailer->send($email);
        } catch (TransportException $e) {
            $code = $this->parseSmtpCode($e->getMessage());
            if ($code !== null && str_starts_with($code, '5')) {
                throw new MailerHardBounceException($code, $e->getMessage(), 0, $e);
            }
            if ($code !== null && str_starts_with($code, '4')) {
                throw new MailerSoftBounceException($code, $e->getMessage(), 0, $e);
            }
            throw new MailerTransportException($e->getMessage(), 0, $e);
        }
    }

    public function parseSmtpCode(string $message): ?string
    {
        // Common Symfony error format: "got code \"550\", with message \"550 5.1.1 ..."
        // Or enhanced status code "5.1.1" directly in message.
        if (preg_match('/\b([45]\.\d+\.\d+)\b/', $message, $m)) {
            return $m[1];
        }
        return null;
    }

    public function isHardBounce(string $code): bool { return str_starts_with($code, '5'); }
}
```

- [ ] **Step 3: Test with ~10 real SMTP error message samples**

Add table-driven test cases for: Gmail 5.1.1, Outlook 365 5.7.1, Mailgun 4.2.2, Postmark 5.7.0, generic 451 4.7.1, no-code error string, etc.

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add backend/src/Infrastructure/Mailer/SymfonyMailerAdapter.php tests/Unit/Mailer/SymfonyMailerAdapterTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): SymfonyMailerAdapter + SMTP code parser + 10 sample tests"
```

### Task C4: `DrainMailOutbox` use case + `mail:drain` cron command

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Application/DrainMailOutbox/{DrainMailOutbox,Input,Output}.php`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Console/MailDrainCommand.php`
- Modify: `c:/laragon/www/daems-platform/bootstrap/console.php`
- Create: `c:/laragon/www/modules/communications/tests/Integration/DrainMailOutboxTest.php`

- [ ] **Step 1: Implement `DrainMailOutbox`**

Per-batch loop:

```php
foreach ($this->outboxRepo->pickNextForSending(50) as $row) {
    $settings = $this->settingsRepo->findForTenant($row->tenantId);
    try {
        $this->mailer->send($row, $settings);
        $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Sent);
    } catch (MailerHardBounceException $e) {
        $this->suppressionRepo->add(new MailSuppression(
            $row->tenantId, $row->recipientEmail, SuppressionReason::HardBounce,
            new \DateTimeImmutable(), $e->getCode()
        ));
        $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Bounced, $e->getMessage());
    } catch (MailerSoftBounceException $e) {
        if ($row->attemptCount + 1 >= 3) {
            $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Failed, $e->getMessage());
        } else {
            $this->outboxRepo->incrementAttempt($row->id, $e->getMessage());
            // backoff: row stays sending, drain skips it via WHERE clause `queued_at < NOW() - backoff(attempt)`
        }
    } catch (MailerTransportException | SmtpNotConfigured $e) {
        $this->outboxRepo->markStatus($row->id, MailOutboxStatus::Failed, $e->getMessage());
    }
}
```

(The exact backoff implementation requires `mail_outbox.next_attempt_at` column — add via subsequent migration or compute from `queued_at + INTERVAL pow(2, attempt_count) MINUTE`. Use the latter to avoid migration churn.)

- [ ] **Step 2: Implement `MailDrainCommand` (CLI wrapper)**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Infrastructure\Console;

use Daems\Infrastructure\Console\Command;
use DaemsModule\Communications\Application\DrainMailOutbox\DrainMailOutbox;
use DaemsModule\Communications\Application\DrainMailOutbox\Input;

final class MailDrainCommand implements Command
{
    public function __construct(private readonly DrainMailOutbox $useCase) {}

    public function getName(): string { return 'mail:drain'; }

    public function execute(array $argv): int
    {
        $output = $this->useCase->execute(new Input(batchSize: 50));
        fwrite(STDOUT, "Drained: sent={$output->sent}, bounced={$output->bounced}, failed={$output->failed}, retry={$output->retried}\n");
        return 0;
    }
}
```

- [ ] **Step 3: Register in `bootstrap/console.php`**

Add to the command map: `'mail:drain' => DaemsModule\Communications\Infrastructure\Console\MailDrainCommand::class`.

- [ ] **Step 4: Integration test**

Use `InMemoryMailer` injected, populate outbox with 3 queued rows (one which will hard-bounce, one which will succeed, one which will soft-bounce), call drain, assert resulting statuses + suppression added.

- [ ] **Step 5: Run — expect PASS**

- [ ] **Step 6: Commit**

```bash
git add backend/src/Application/DrainMailOutbox/ backend/src/Infrastructure/Console/MailDrainCommand.php bootstrap/console.php tests/Integration/DrainMailOutboxTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): DrainMailOutbox use case + mail:drain CLI + integration test"
```

### Task C5: `ListOutboxRows` + `RetryOutboxRow` + `MarkSuppressedRecipientsInPending` use cases

**Files:**

- Create: 3 use-case folders under `backend/src/Application/`
- Tests: under `tests/Unit/Application/`

- [ ] **Step 1: Implement `ListOutboxRows`**

Accepts filters (`status`, `kind`, `from`, `to`, `recipient_substring`), page, perPage. Returns paginated list + total count. Admin/moderator sees own tenant. GSA can pass any tenant.

- [ ] **Step 2: Implement `RetryOutboxRow`**

Pre-condition: row.status === Failed. Sets status=Queued, attempt_count=0, last_error=null. Otherwise throws `DomainException` "row not retryable".

- [ ] **Step 3: Implement `MarkSuppressedRecipientsInPending`**

Cron pre-step (runs inside DrainMailOutbox before pickNextForSending). For all `queued` rows, check `suppressionRepo->isSuppressed`; if true → mark status=Suppressed.

- [ ] **Step 4: Tests for each**

- [ ] **Step 5: Commit**

```bash
git add backend/src/Application/{ListOutboxRows,RetryOutboxRow,MarkSuppressedRecipientsInPending}/ tests/Unit/Application/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Outbox use cases (list, retry, mark-suppressed) + tests"
```

### Task C6: Outbox API controller + routes

**Files:**

- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Adapter/Api/Controller/OutboxController.php`
- Modify: `c:/laragon/www/modules/communications/backend/routes.php`

- [ ] **Step 1: Implement controller (3 endpoints: GET list, POST retry, GET single)**

Mirror existing platform controllers (e.g., `MembersController.php`) — thin: unwrap request, call use case, return JSON. Catch `ForbiddenException`/`DomainException` → 403/422.

- [ ] **Step 2: Register routes**

```php
return [
    ['GET',  '/api/v1/backstage/communications/outbox',          OutboxController::class . '::index'],
    ['POST', '/api/v1/backstage/communications/outbox/{id}/retry', OutboxController::class . '::retry'],
    ['GET',  '/api/v1/backstage/communications/outbox/{id}',     OutboxController::class . '::show'],
];
```

- [ ] **Step 3: E2E test via KernelHarness**

Two tests: (1) admin lists outbox → 200, returns paginated rows; (2) moderator retries failed row → 200, status updated.

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add backend/src/Infrastructure/Adapter/Api/Controller/OutboxController.php backend/routes.php tests/E2E/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Outbox API (GET list, GET show, POST retry) + E2E"
```

### Task C7: Outbox backstage page UI

**Files:**

- Create: `c:/laragon/www/modules/communications/frontend/backstage/communications/outbox.php`
- Create: `c:/laragon/www/modules/communications/frontend/assets/outbox.js`
- Create: `c:/laragon/www/modules/communications/frontend/assets/communications.css`

- [ ] **Step 1: Write outbox.php** (PHP renders table shell + filters; JS hydrates dynamically)

Use existing backstage page structure (look at `members/index.php` or `governance/billing/invoices.php` as templates). Server-renders the initial first page via curl-call to `/api/v1/backstage/communications/outbox` (same pattern as `notifications/index.php`).

- [ ] **Step 2: Write outbox.js** (filter form → POST to API → re-render rows)

Vanilla JS, no framework dependency.

- [ ] **Step 3: Write communications.css**

Backstage token-based styles. Status pills color-coded (queued = neutral, sending = blue, sent = green, failed = red, bounced = orange, suppressed = gray).

- [ ] **Step 4: Browser smoke**

Visit `http://daems.local/backstage/communications/outbox`, verify page renders with filters and (initially empty) table.

- [ ] **Step 5: Commit**

```bash
git add frontend/backstage/communications/outbox.php frontend/assets/outbox.js frontend/assets/communications.css
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): outbox backstage page + JS + CSS"
```

### Task C8: Settings page (SMTP + cron + brand + suppression)

**Files:**

- Create: `c:/laragon/www/modules/communications/frontend/backstage/settings/communications.php`
- Create: `c:/laragon/www/modules/communications/frontend/assets/settings.js`
- Create: `c:/laragon/www/modules/communications/backend/src/Infrastructure/Adapter/Api/Controller/SettingsController.php`

- [ ] **Step 1: Implement SettingsController**

Endpoints: GET `/api/v1/backstage/communications/settings`, PUT same, POST `/settings/smtp-test`.

- [ ] **Step 2: Implement settings.php page**

Layout: 4 tabs (SMTP / Cron / Brand / Suppression). SMTP tab has "Lähetä testi" button that calls POST `/smtp-test` and shows green checkmark on success + last test timestamp.

- [ ] **Step 3: settings.js**

Tab switching + form submit + test-button handling.

- [ ] **Step 4: Browser smoke**

Visit `/backstage/settings/communications`, verify all 4 tabs render, save SMTP DSN, click "Lähetä testi". Test the test-send actually emails localhost MailHog (1025).

- [ ] **Step 5: E2E tests**

`GetCommunicationSettingsE2ETest`, `SaveCommunicationSettingsE2ETest`, `SendSmtpTestEmailE2ETest`.

- [ ] **Step 6: Commit**

```bash
git add frontend/backstage/settings/communications.php frontend/assets/settings.js backend/src/Infrastructure/Adapter/Api/Controller/SettingsController.php tests/E2E/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): settings page (SMTP/cron/brand/suppression tabs) + controller + E2E"
```

### Task C9: Activate outbox isolation test stub

**Files:**

- Modify: `c:/laragon/www/daems-platform/tests/Isolation/CommunicationsTenantIsolationTest.php`

- [ ] **Step 1: Replace `test_outbox_isolation` skip with active assertion**

Save a row to daems-tenant's outbox repo, call `listForTenant(sahegroup)` and assert returned list is empty.

- [ ] **Step 2: Run — expect PASS**

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add tests/Isolation/CommunicationsTenantIsolationTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Test(0.8/communications): activate outbox isolation assertion"
```

---

## Wave D — Composer + 4 strict template kinds + Meeting MVP (3 days)

Goal: Admin can open `/backstage/communications`, pick a kind, fill in payload, see preview, send. email-safe HTML templates render with brand vars. Meeting entity persists when MeetingInvitation is sent. Template overrides editable.

### Task D1: `MarkdownRenderer` + `VarSubstituter` + `Html2Text`

**Files:**

- Create: `backend/src/Infrastructure/Renderer/MarkdownRenderer.php`
- Create: `backend/src/Infrastructure/Renderer/VarSubstituter.php`
- Create: `backend/src/Infrastructure/Renderer/Html2Text.php`
- Tests: 3 unit-test files

- [ ] **Step 1: Implement `MarkdownRenderer`**

Wraps `League\CommonMark\CommonMarkConverter` in safe mode (HTML disallowed in input).

- [ ] **Step 2: Implement `VarSubstituter`**

Whitelist per `MailKind`. `MailKind::MeetingInvitation::ALLOWED_VARS = ['first_name', 'meeting_title', 'meeting_date', 'meeting_location', 'meeting_remote_url', 'agenda_html', 'documents_list', 'rsvp_url']`. Replaces `{{name}}` → htmlspecialchars(value). Unknown var → throws `UnknownTemplateVarException`.

- [ ] **Step 3: Implement `Html2Text`**

~150 lines. Strip tags, decode entities, preserve links as `text (https://...)`, preserve list bullets, double newline between block elements.

- [ ] **Step 4: Tests for each + commit**

```bash
git add backend/src/Infrastructure/Renderer/{MarkdownRenderer,VarSubstituter,Html2Text}.php tests/Unit/Renderer/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): MarkdownRenderer + VarSubstituter + Html2Text + tests"
```

### Task D2: email-safe HTML templates for 4 strict kinds + lapse warning + newsletter wrapper

**Files:**

- Create: `backend/src/Infrastructure/Renderer/templates/meeting_invitation.html`
- Create: `backend/src/Infrastructure/Renderer/templates/payment_reminder.html`
- Create: `backend/src/Infrastructure/Renderer/templates/membership_approved.html`
- Create: `backend/src/Infrastructure/Renderer/templates/group_message.html`
- Create: `backend/src/Infrastructure/Renderer/templates/lapse_warning.html`
- Create: `backend/src/Infrastructure/Renderer/templates/newsletter_wrapper.html`
- Create: `docs/email-template-style-guide.md`

- [ ] **Step 1: Write `docs/email-template-style-guide.md`**

Author a brief style guide documenting:

- Use `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">` for layout
- 600 px content width; mobile fallback via `@media (max-width:600px)` only
- Inline CSS only — no `<style>` blocks except media queries
- Images: `<img width="..." style="display:block;border:0;outline:none">` (Outlook spacing-bug fix)
- Buttons: `<table>` of one row with inline-styled `<td>` (Mailto-compatible)
- No flex, grid, `position:absolute`, JavaScript, or background images
- 2-column blocks: `<table>` + two `<td width="50%">`; mobile rule sets `display:block; width:100%`
- Placeholder syntax: `{{snake_case_var}}` — VarSubstituter handles escaping

- [ ] **Step 2: Author `meeting_invitation.html`**

```html
<!DOCTYPE html>
<html lang="{{locale}}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{{subject}}</title>
  <style>
    @media (max-width:600px) {
      .container { width:100% !important; }
      .col { display:block !important; width:100% !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f4;">
    <tr><td align="center" style="padding:20px 12px;">
      <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:6px;overflow:hidden;">
        <tr><td style="background:{{brand_primary_color}};padding:18px 22px;">
          <img src="{{brand_logo_url}}" alt="" width="160" style="display:block;border:0;outline:none;">
        </td></tr>
        <tr><td style="padding:22px 26px;">
          <h1 style="margin:0 0 14px 0;font-size:20px;color:{{brand_primary_color}};">{{subject}}</h1>
          <p style="margin:0 0 12px 0;font-size:14px;color:#333;">Hei {{first_name}},</p>
          <div style="margin:0 0 14px 0;font-size:14px;color:#333;line-height:1.55;">{{intro_text_html}}</div>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f7fa;border-radius:4px;margin:12px 0;">
            <tr><td style="padding:12px 16px;font-size:13px;color:#444;line-height:1.6;">
              <strong>Päivä:</strong> {{meeting_date}}<br>
              <strong>Paikka:</strong> {{meeting_location}}<br>
              <strong>Etänä:</strong> <a href="{{meeting_remote_url}}" style="color:{{brand_primary_color}};">{{meeting_remote_url}}</a>
            </td></tr>
          </table>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f7fa;border-left:3px solid {{brand_primary_color}};margin:12px 0;">
            <tr><td style="padding:10px 14px;font-size:13px;color:#333;">
              <strong>Asialista:</strong>
              {{agenda_html}}
            </td></tr>
          </table>
          <p style="margin:0 0 14px 0;font-size:12px;color:#555;">{{documents_list}}</p>
          <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px auto;">
            <tr><td style="background:{{brand_primary_color}};border-radius:4px;">
              <a href="{{rsvp_url}}" style="display:inline-block;padding:10px 22px;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;">Vahvista osallistuminen</a>
            </td></tr>
          </table>
          <p style="margin:18px 0 0 0;font-size:13px;color:#444;">{{signature}}</p>
        </td></tr>
        <tr><td style="background:#f8f8f8;padding:14px 22px;text-align:center;font-size:11px;color:#888;">
          {{brand_footer_address}}
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
```

- [ ] **Step 3: Author the other 5 HTML files**

Use the same skeleton, swap content per kind:

- `payment_reminder.html` — invoice amount + due_date + payment_link CTA; placeholders `{{invoice_year}}`, `{{amount}}`, `{{due_date}}`, `{{payment_link}}`
- `membership_approved.html` — welcome paragraph + login link; placeholders `{{first_name}}`, `{{tenant_name}}`, `{{login_link}}`
- `group_message.html` — admin-authored Markdown body; placeholders `{{first_name}}`, `{{body_html}}`, `{{signature}}`
- `lapse_warning.html` — warning about § 4 lapse + payment_link CTA; placeholders `{{first_name}}`, `{{predicted_lapse_date}}`, `{{payment_link}}`, `{{outstanding_total}}`
- `newsletter_wrapper.html` — has `{{block_body}}` placeholder for rendered blocks + mandatory unsubscribe footer with `{{unsubscribe_url}}`; placeholders `{{subject}}`, `{{first_name}}`, `{{block_body}}`, `{{unsubscribe_url}}`

Keep each file ≤ 80 lines. Same `<table>` skeleton + inline CSS + `@media` query for mobile.

- [ ] **Step 4: Write snapshot test for meeting_invitation render**

Create `tests/Unit/Renderer/EmailHtmlSnapshotTest.php`:

```php
public function test_meeting_invitation_renders_to_table_based_html(): void
{
    $registry = new MailTemplateRegistry();
    $renderer = new EmailHtmlRenderer($registry, new VarSubstituter(), new MarkdownRenderer(), new Html2Text());
    [$html, $text] = $renderer->render(
        MailKind::MeetingInvitation,
        [
            'first_name' => 'Anna',
            'subject' => 'Vuosikokous 2026',
            'intro_text_html' => '<p>Tervetuloa!</p>',
            'meeting_date' => '15.6.2026 klo 18:00',
            'meeting_location' => 'Kulttuuritalo',
            'meeting_remote_url' => 'https://meet.example.com/x',
            'agenda_html' => '<ol><li>Avaus</li><li>Päätös</li></ol>',
            'documents_list' => '📎 toimintakertomus.pdf',
            'rsvp_url' => 'https://daems.fi/rsvp/abc',
            'signature' => 'Hallitus',
            'brand_primary_color' => '#2e5c8a',
            'brand_logo_url' => 'https://cdn/logo.png',
            'brand_footer_address' => 'Daem Society ry',
            'locale' => 'fi_FI',
        ],
        SupportedLocale::FiFi,
        stringOverrides: []
    );

    $this->assertStringContainsString('<table', $html);
    $this->assertStringContainsString('role="presentation"', $html);
    $this->assertStringContainsString('Anna', $html);
    $this->assertStringContainsString('Vuosikokous 2026', $html);
    $this->assertStringContainsString('https://daems.fi/rsvp/abc', $html);
    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('position:absolute', $html);

    // Plain-text fallback should include meaningful content
    $this->assertStringContainsString('Anna', $text);
    $this->assertStringContainsString('15.6.2026', $text);
}
```

Repeat snapshot tests for each of the 5 other templates with their own placeholder sets.

- [ ] **Step 5: Run snapshot tests — expect PASS**

```bash
cd c:/laragon/www/daems-platform
composer test -- --filter=EmailHtmlSnapshotTest
```

- [ ] **Step 6: Commit**

```bash
git add backend/src/Infrastructure/Renderer/templates/ docs/email-template-style-guide.md tests/Unit/Renderer/EmailHtmlSnapshotTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): 6 email-safe HTML templates + style guide + snapshot tests"
```

### Task D3: `MailTemplateRegistry` + `EmailHtmlRenderer`

**Files:**

- Create: `backend/src/Infrastructure/Renderer/MailTemplateRegistry.php`
- Create: `backend/src/Infrastructure/Renderer/EmailHtmlRenderer.php`
- Tests: 2 unit-test files

- [ ] **Step 1: Implement `MailTemplateRegistry`**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Infrastructure\Renderer;

use DaemsModule\Communications\Domain\Mail\MailKind;
use Daems\Domain\Locale\SupportedLocale;

final class MailTemplateRegistry
{
    public function loadHtmlSource(MailKind $kind): string
    {
        $file = __DIR__ . "/templates/{$kind->value}.html";
        if (!file_exists($file)) {
            throw new \RuntimeException("Template not found: {$kind->value}.html");
        }
        return file_get_contents($file);
    }

    /**
     * @return array<string,string> e.g. ['subject' => '...', 'intro' => '...', 'signature' => '...', 'footer' => '...']
     */
    public function defaultStrings(MailKind $kind, SupportedLocale $locale): array
    {
        $key = "communications.template.defaults.{$kind->value}";
        $langFile = __DIR__ . "/../../../../../daems-platform/lang/{$locale->value}.php";
        $strings = file_exists($langFile) ? require $langFile : [];
        $defaults = [];
        foreach ($strings as $k => $v) {
            if (str_starts_with($k, $key . '.')) {
                $defaults[substr($k, strlen($key) + 1)] = $v;
            }
        }
        if (empty($defaults) && $locale !== SupportedLocale::EnGb) {
            return $this->defaultStrings($kind, SupportedLocale::EnGb);
        }
        return $defaults;
    }
}
```

- [ ] **Step 2: Implement `EmailHtmlRenderer`**

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Infrastructure\Renderer;

use DaemsModule\Communications\Domain\Mail\MailKind;
use Daems\Domain\Locale\SupportedLocale;

final class EmailHtmlRenderer
{
    public function __construct(
        private readonly MailTemplateRegistry $registry,
        private readonly VarSubstituter $varSub,
        private readonly MarkdownRenderer $md,
        private readonly Html2Text $h2t,
    ) {}

    /**
     * @param array<string,mixed> $vars        Variable values (already context-merged with brand_*, locale, etc.)
     * @param array<string,string> $stringOverrides Admin-edited overrides (subject/intro/signature/footer)
     * @return array{0:string,1:string}  [html, plainText]
     */
    public function render(MailKind $kind, array $vars, SupportedLocale $locale, array $stringOverrides): array
    {
        // 1. Apply dev-default strings (locale-aware), then admin overrides win
        $defaults = $this->registry->defaultStrings($kind, $locale);
        $strings  = array_replace($defaults, $stringOverrides);

        // 2. Pre-render Markdown for any body-field of admin authoring (intro_text, body, signature)
        foreach (['intro_text', 'body', 'signature'] as $mdField) {
            if (isset($strings[$mdField])) {
                $vars[$mdField . '_html'] = $this->md->renderSafe($strings[$mdField]);
            }
        }
        // Merge subject + any other string overrides into vars (already HTML-escape-safe — VarSubstituter will escape on insert)
        foreach (['subject', 'signature', 'footer'] as $passThrough) {
            if (isset($strings[$passThrough])) {
                $vars[$passThrough] = $strings[$passThrough];
            }
        }

        // 3. Load template HTML source
        $template = $this->registry->loadHtmlSource($kind);

        // 4. Apply var substitution against the HTML (whitelist per kind, HTML-escaping for raw values)
        $html = $this->varSub->substitute($template, $vars, $kind);

        // 5. Extract plain-text fallback
        $text = $this->h2t->convert($html);

        return [$html, $text];
    }
}
```

- [ ] **Step 3: Test full render pipeline**

```php
public function test_full_pipeline_produces_html_and_text(): void
{
    $renderer = new EmailHtmlRenderer(
        new MailTemplateRegistry(),
        new VarSubstituter(),
        new MarkdownRenderer(),
        new Html2Text(),
    );

    [$html, $text] = $renderer->render(
        MailKind::MeetingInvitation,
        [
            'first_name' => 'Anna',
            'subject' => 'Vuosikokous 2026',
            'meeting_date' => '15.6.2026',
            'meeting_location' => 'Kulttuuritalo',
            'meeting_remote_url' => 'https://meet/x',
            'agenda_html' => '<ol><li>Avaus</li></ol>',
            'documents_list' => '',
            'rsvp_url' => 'https://daems.fi/rsvp/x',
            'signature' => 'Hallitus',
            'brand_primary_color' => '#2e5c8a',
            'brand_logo_url' => 'https://cdn/x.png',
            'brand_footer_address' => 'Daem Society',
            'locale' => 'fi_FI',
        ],
        SupportedLocale::FiFi,
        stringOverrides: ['intro_text' => 'Hyvä jäsen, **tervetuloa**.'],
    );

    $this->assertStringContainsString('<table', $html);
    $this->assertStringContainsString('Anna', $html);
    $this->assertStringContainsString('<strong>tervetuloa</strong>', $html);  // Markdown rendered
    $this->assertStringContainsString('Anna', $text);
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
composer test -- --filter=EmailHtmlRendererTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Infrastructure/Renderer/EmailHtmlRenderer.php backend/src/Infrastructure/Renderer/MailTemplateRegistry.php tests/Unit/Renderer/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): EmailHtmlRenderer + MailTemplateRegistry + full-pipeline tests"
```

### Task D4: `ComposeAndPreviewMessage` use case

**Files:**

- Create: `backend/src/Application/ComposeAndPreviewMessage/{ComposeAndPreviewMessage,Input,Output}.php`
- Create: `tests/Unit/Application/ComposeAndPreviewMessageTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_compose_meeting_invitation_returns_rendered_preview(): void
{
    // Set up acting admin, mock renderer, mock audience resolver, mock template repo
    $useCase->execute(new Input(
        kind: MailKind::MeetingInvitation,
        payload: ['meeting_id' => $meetingId, 'subject' => '...', 'intro_text' => '...'],
        audienceFilter: $filter,
        locale: SupportedLocale::FiFi,
    ), $acting);
    // Assert output.htmlPreview contains expected snippets and audienceCount > 0
}
```

- [ ] **Step 2: Implement use case**

Steps inside `execute`:

1. Verify auth (admin/moderator)
2. Resolve audience → count
3. For preview, load _first_ resolved recipient's locale (or input.locale fallback)
4. Build payload vars: merge admin-input + DB-pulled context (e.g., `meeting_id` → load `Meeting` → extract title, datetime, agenda)
5. Load template overrides via `MailTemplateRepository::findOverrides`
6. Call `EmailHtmlRenderer::render(kind, payloadVars, locale, overrides)`
7. Return `Output(htmlPreview, textPreview, audienceCount, audienceSampleNames: first 3 names + " +N muuta")`

- [ ] **Step 3: Run — expect PASS**

- [ ] **Step 4: Commit**

```bash
git add backend/src/Application/ComposeAndPreviewMessage/ tests/Unit/Application/ComposeAndPreviewMessageTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): ComposeAndPreviewMessage use case + tests"
```

### Task D5: `SendComposedMessage` + `CreateMeetingFromComposer` use cases

**Files:**

- Create: `backend/src/Application/SendComposedMessage/{SendComposedMessage,Input,Output}.php`
- Create: `backend/src/Application/CreateMeetingFromComposer/{CreateMeetingFromComposer,Input,Output}.php`
- Tests: 2 unit-test files

- [ ] **Step 1: Implement `CreateMeetingFromComposer`**

Validates `starts_at > now()` (warn-not-block if violated; see spec § 1 — 14d-rule lives in 0.9). Saves `Meeting` row, returns `MeetingId`.

- [ ] **Step 2: Implement `SendComposedMessage`**

Pseudo-flow:

```php
public function execute(Input $input, ActingUser $acting): Output {
    if (!$acting->isAdminOrModeratorIn($input->tenantId)) throw new ForbiddenException();

    // If meeting kind and no meeting_id, create meeting first
    if ($input->kind === MailKind::MeetingInvitation && $input->payload['meeting_id'] === null) {
        $meetingId = $this->createMeeting->execute(/* ... */, $acting)->meetingId;
        $input->payload['meeting_id'] = $meetingId;
    }

    // Resolve audience (opt-in + suppression filter built in)
    $recipients = $this->resolver->resolve($input->tenantId, $input->audienceFilter, $input->kind->category());
    if (empty($recipients)) throw new \DomainException('Empty audience');

    // For each recipient: render per-locale, save outbox row
    foreach ($recipients as $r) {
        $vars = array_merge($input->payload, $r->contextVars);
        [$html, $text] = $this->renderer->render($input->kind, $vars, $r->locale, $overrides);

        $this->outboxRepo->save(new MailOutbox(
            MailOutboxId::generate(),
            $input->tenantId,
            $input->kind,
            $input->kind->category(),
            $r->email,
            $r->userId,
            $r->locale,
            $vars['subject'],
            $html,
            $text,
            $vars,
            $input->payload['meeting_id'] ?? null,
            $input->payload['invoice_id'] ?? null,
            null,
            MailOutboxStatus::Queued,
            0,
            null,
            new \DateTimeImmutable(),
            null,
            $acting->userId,
        ));
    }

    return new Output(enqueuedCount: count($recipients));
}
```

- [ ] **Step 3: Test for happy path + empty-audience + missing-SMTP**

(Even though SMTP isn't checked at enqueue time — drain handles it — the use case should pre-check `settings.isSmtpConfigured()` and warn early. Spec O7 says: log + skip in cron, but for explicit admin-triggered send, return a clear error.)

- [ ] **Step 4: Commit**

```bash
git add backend/src/Application/SendComposedMessage/ backend/src/Application/CreateMeetingFromComposer/ tests/Unit/Application/
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): SendComposedMessage + CreateMeetingFromComposer use cases + tests"
```

### Task D6: Composer API controller + routes

**Files:**

- Create: `backend/src/Infrastructure/Adapter/Api/Controller/ComposerController.php`
- Modify: `backend/routes.php`

- [ ] **Step 1: Implement controller — POST /preview, POST /send**

- [ ] **Step 2: Register routes**

- [ ] **Step 3: E2E test**

Send POST /send with `MeetingInvitation` payload → 200, outbox has N rows, meeting saved.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Composer API (POST preview, POST send) + E2E"
```

### Task D7: Composer backstage page UI

**Files:**

- Create: `frontend/backstage/communications/index.php`
- Create: `frontend/assets/composer.js`

- [ ] **Step 1: Render composer.php**

Layout: dropdown for kind, kind-specific form fields, audience filter row, live preview pane on right, "Lähetä jonoon" button at bottom. Use the Q6 F mockup structure as fidelity reference.

- [ ] **Step 2: composer.js**

Wire dropdown → form-field show/hide. Debounced preview API call (300 ms). Submit handler → POST /send → toast success or error.

- [ ] **Step 3: Browser smoke**

Visit `/backstage/communications`, select MeetingInvitation, fill form, see preview update, click send, verify outbox shows new row.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): composer backstage page + JS (kind dropdown, live preview)"
```

### Task D8: `GetTemplateOverrides` + `SaveTemplateOverrides` use cases + UI

**Files:**

- Create: 2 use-case folders
- Create: `backend/src/Infrastructure/Adapter/Api/Controller/TemplatesController.php`
- Create: `frontend/backstage/communications/templates/{index,edit}.php`

- [ ] **Step 1: Implement use cases**

`Save` writes the JSON of overrides (subject/intro/signature/footer) per (tenant, kind, locale).

- [ ] **Step 2: Implement controller (GET + PUT)**

- [ ] **Step 3: Implement template UI (list of 4 kinds → click → edit page with 3 locale tabs)**

Edit page uses locale-cards pattern from PR 5 (events/projects translations).

- [ ] **Step 4: E2E test**

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Template overrides use cases + controller + UI + E2E"
```

### Task D9: Activate meeting isolation test stub

**Files:**

- Modify: `c:/laragon/www/daems-platform/tests/Isolation/CommunicationsTenantIsolationTest.php`

- [ ] **Step 1: Implement test**

Daems-admin creates Meeting → sahegroup-admin `MeetingRepository::listForTenant` returns empty.

- [ ] **Step 2: Run + commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Test(0.8/communications): activate meeting isolation assertion"
```

---

## Wave E — Newsletter blocks (2-3 days)

Goal: Admin can create newsletter draft, add/remove/reorder blocks per locale, save, send. Audience uses marketing-category opt-in.

### Task E1: `NewsletterBlock` JSON serialization round-trip

**Files:**

- Modify: `backend/src/Domain/Template/Block/NewsletterBlock.php` (add `fromArray` factory)
- Create: `backend/src/Domain/Template/BlockSerializer.php`
- Test: `tests/Unit/Domain/Template/BlockSerializerTest.php`

- [ ] **Step 1: Test JSON round-trip**

```php
public function test_serialize_roundtrip_for_all_block_types(): void
{
    $blocks = [
        new HeadingBlock(2, 'Hello'),
        new ParagraphBlock('**Bold** text'),
        new ImageBlock('https://x/y.jpg', 'alt', null),
        new ButtonBlock('Click', 'https://x'),
        new DividerBlock(),
        new TwoColumnsBlock([new ParagraphBlock('L')], [new ParagraphBlock('R')]),
    ];
    $json = BlockSerializer::toJson($blocks);
    $rebuilt = BlockSerializer::fromJson($json);
    $this->assertEquals($blocks, $rebuilt);
}
```

- [ ] **Step 2: Implement `BlockSerializer`**

Static methods `toJson(list<NewsletterBlock>): string`, `fromJson(string): list<NewsletterBlock>`. Tagged by `type` field.

- [ ] **Step 3: Run — expect PASS**

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): NewsletterBlock JSON serializer + round-trip test"
```

### Task E2: Block-to-email-safe HTML rendering

**Files:**

- Create: `backend/src/Infrastructure/Renderer/NewsletterBlockRenderer.php`
- Test: `tests/Unit/Renderer/NewsletterBlockRendererTest.php`

- [ ] **Step 1: Implement renderer**

Each block → email-safe HTML snippet (inline CSS, table-based where structural). Implementation:

```php
<?php
declare(strict_types=1);
namespace DaemsModule\Communications\Infrastructure\Renderer;

use DaemsModule\Communications\Domain\Template\Block\{
    NewsletterBlock, HeadingBlock, ParagraphBlock, ImageBlock,
    ButtonBlock, DividerBlock, TwoColumnsBlock, EventCardBlock
};

final class NewsletterBlockRenderer
{
    public function __construct(
        private readonly MarkdownRenderer $md,
        private readonly string $brandPrimaryColor,  // injected per-render from TenantCommunicationSettings
    ) {}

    /** @param list<NewsletterBlock> $blocks */
    public function renderAll(array $blocks): string
    {
        return implode("\n", array_map($this->renderOne(...), $blocks));
    }

    private function renderOne(NewsletterBlock $b): string
    {
        return match (true) {
            $b instanceof HeadingBlock     => $this->heading($b),
            $b instanceof ParagraphBlock   => $this->paragraph($b),
            $b instanceof ImageBlock       => $this->image($b),
            $b instanceof ButtonBlock      => $this->button($b),
            $b instanceof DividerBlock     => $this->divider(),
            $b instanceof TwoColumnsBlock  => $this->twoColumns($b),
            $b instanceof EventCardBlock   => $this->eventCard($b),
            default => throw new \RuntimeException('Unknown block: ' . $b::class),
        };
    }

    private function heading(HeadingBlock $b): string
    {
        $size = match ($b->level) { 1 => '22px', 2 => '18px', default => '15px' };
        $weight = $b->level <= 2 ? '700' : '600';
        $escaped = htmlspecialchars($b->text, ENT_QUOTES | ENT_HTML5);
        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td style=\"padding:14px 0 8px 0;font-size:{$size};font-weight:{$weight};color:{$this->brandPrimaryColor};font-family:Helvetica,Arial,sans-serif;\">{$escaped}</td></tr></table>";
    }

    private function paragraph(ParagraphBlock $b): string
    {
        $html = $this->md->renderSafe($b->markdown);
        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td style=\"padding:6px 0;font-size:14px;color:#333;line-height:1.55;font-family:Helvetica,Arial,sans-serif;\">{$html}</td></tr></table>";
    }

    private function image(ImageBlock $b): string
    {
        $url = htmlspecialchars($b->url, ENT_QUOTES | ENT_HTML5);
        $alt = htmlspecialchars($b->alt, ENT_QUOTES | ENT_HTML5);
        $cap = $b->caption !== null
            ? "<div style=\"padding:4px 0;font-size:11px;color:#888;text-align:center;\">" . htmlspecialchars($b->caption, ENT_QUOTES | ENT_HTML5) . "</div>"
            : '';
        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td align=\"center\" style=\"padding:10px 0;\"><img src=\"{$url}\" alt=\"{$alt}\" style=\"display:block;border:0;outline:none;max-width:100%;height:auto;\">{$cap}</td></tr></table>";
    }

    private function button(ButtonBlock $b): string
    {
        $url = htmlspecialchars($b->url, ENT_QUOTES | ENT_HTML5);
        $txt = htmlspecialchars($b->text, ENT_QUOTES | ENT_HTML5);
        return "<table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" align=\"center\" style=\"margin:14px auto;\"><tr><td style=\"background:{$this->brandPrimaryColor};border-radius:4px;\"><a href=\"{$url}\" style=\"display:inline-block;padding:10px 22px;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;font-family:Helvetica,Arial,sans-serif;\">{$txt}</a></td></tr></table>";
    }

    private function divider(): string
    {
        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td style=\"padding:14px 0;\"><div style=\"border-top:1px solid #d8d8d8;height:1px;line-height:1px;font-size:1px;\">&nbsp;</div></td></tr></table>";
    }

    private function twoColumns(TwoColumnsBlock $b): string
    {
        $left  = $this->renderAll($b->left);
        $right = $this->renderAll($b->right);
        // Outlook desktop renders as side-by-side; mobile (max-width:600) flips to stacked via CSS class .col in newsletter_wrapper
        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\"><tr><td class=\"col\" width=\"50%\" valign=\"top\" style=\"padding:6px 10px 6px 0;\">{$left}</td><td class=\"col\" width=\"50%\" valign=\"top\" style=\"padding:6px 0 6px 10px;\">{$right}</td></tr></table>";
    }

    private function eventCard(EventCardBlock $b): string
    {
        // 0.8 stub — resolve event title from events module if cross-call available, otherwise show event_id placeholder.
        // Full event-fetching cross-module call wired in a Wave G follow-up if needed; for now render a passive card.
        $title = $b->title ?? '(Tapahtuma)';
        $when  = $b->whenLabel ?? '';
        return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" border=\"0\" style=\"background:#f4f7fa;border-left:3px solid {$this->brandPrimaryColor};margin:8px 0;\"><tr><td style=\"padding:10px 14px;font-size:13px;color:#333;\"><strong>" . htmlspecialchars($title) . "</strong><br>" . htmlspecialchars($when) . "</td></tr></table>";
    }
}
```

`EventCardBlock` constructor takes `(string $eventId, ?string $title = null, ?string $whenLabel = null)`. The composer surface enriches title + whenLabel by looking up the events module before save (so renderer doesn't need cross-module call at send time). Spec § 11.1 mentions EventCardBlock cross-call may become a Wave G follow-up — that's the integration; the renderer itself is straightforward.

- [ ] **Step 2: Snapshot test for each block type**

Create `tests/Unit/Renderer/NewsletterBlockRendererTest.php` with 7 tests, one per block type. Each asserts the output contains expected `<table>` markers + escapes HTML in user input + inline CSS only.

```php
public function test_heading_renders_with_brand_color(): void
{
    $r = new NewsletterBlockRenderer(new MarkdownRenderer(), '#2e5c8a');
    $html = $r->renderAll([new HeadingBlock(1, 'Hei <script>')]);
    $this->assertStringContainsString('Hei &lt;script&gt;', $html);
    $this->assertStringContainsString('color:#2e5c8a', $html);
    $this->assertStringContainsString('font-size:22px', $html);
    $this->assertStringContainsString('<table', $html);
}
```

(Six more covering Paragraph/Image/Button/Divider/TwoColumns/EventCard.)

- [ ] **Step 3: Run — expect PASS**

```bash
composer test -- --filter=NewsletterBlockRendererTest
```

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): NewsletterBlockRenderer (7 block types → email-safe HTML) + snapshot tests"
```

### Task E3: Newsletter CRUD use cases

**Files:**

- Create: 5 use-case folders (`CreateNewsletterDraft`, `UpdateNewsletterDraft`, `DeleteNewsletterDraft`, `ListNewsletters`, `SendNewsletter`)

- [ ] **Step 1: Implement Create + Update + Delete + List**

Standard CRUD patterns mirroring existing modules. `Delete` blocks if status === Sent.

- [ ] **Step 2: Implement `SendNewsletter`**

Validates: (a) admin auth, (b) all 3 locales have ≥1 block (or fallback to en_GB rule), (c) subject non-empty per locale, (d) audience > 0 after marketing-opt-in filtering. Then for each recipient: render newsletter_wrapper.html with `{{block_body}}` filled with renderer output for recipient's locale. Generate unsubscribe URL via HMAC. Enqueue outbox rows.

- [ ] **Step 3: Tests for each**

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Newsletter CRUD use cases (5) + tests"
```

### Task E4: Newsletter API controller + routes

**Files:**

- Create: `backend/src/Infrastructure/Adapter/Api/Controller/NewslettersController.php`
- Modify: `backend/routes.php`

- [ ] **Step 1: Implement 5 endpoints**

POST `/newsletters`, PATCH `/newsletters/{id}`, DELETE same, GET list, POST `/newsletters/{id}/send`.

- [ ] **Step 2: E2E tests**

Happy path: create draft → update with 3 locales × N blocks → send → verify outbox has rows + draft status=Sent.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): newsletter API (5 endpoints) + E2E"
```

### Task E5: Newsletter UI (list + block composer)

**Files:**

- Create: `frontend/backstage/communications/newsletters/index.php`
- Create: `frontend/backstage/communications/newsletters/edit.php`
- Create: `frontend/assets/newsletter-blocks.js`
- Create: `frontend/assets/newsletter-blocks.css`

- [ ] **Step 1: List page**

Table of all drafts + sent. "Uusi uutiskirje" button → POST `/newsletters` → redirect to edit page.

- [ ] **Step 2: Edit page**

Layout per Q6 newsletter-block-composer mockup: palette left, canvas center, properties right, locale tabs top. Inline JSON state, save on blur of any field.

- [ ] **Step 3: JS**

Block paletten click → add block of that type. ↑/↓ buttons reorder. Per-block field form. Auto-save on change (debounced 1 s). Send button → confirm dialog → POST /send.

- [ ] **Step 4: Browser smoke**

End-to-end: visit page, build a 5-block newsletter in fi_FI, switch to en_GB tab, build same 5 blocks, send to test recipient (MailHog).

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): newsletter UI (list + block composer + JS + CSS)"
```

### Task E6: Activate newsletter isolation test stub

**Files:**

- Modify: `c:/laragon/www/daems-platform/tests/Isolation/CommunicationsTenantIsolationTest.php`

- [ ] **Step 1: Implement + commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Test(0.8/communications): activate newsletter isolation assertion"
```

---

## Wave F — Cron triggers + 0.7 integration (2 days)

Goal: `EnqueuePaymentReminders` cron pre-due + post-due wires into 0.7's `member_fee_invoices`. `EnqueueLapseWarnings` cron predicts § 4 trigger. crontab.example exists.

### Task F1: `EnqueuePaymentReminders` use case + CLI command

**Files:**

- Create: `backend/src/Application/EnqueuePaymentReminders/{EnqueuePaymentReminders,Input,Output}.php`
- Create: `backend/src/Infrastructure/Console/EnqueuePaymentRemindersCommand.php`
- Test: `tests/Integration/EnqueuePaymentRemindersTest.php`

- [ ] **Step 1: Implement use case**

Per-tenant loop: load `TenantCommunicationSettings`, skip if `!isSmtpConfigured()` (log warning), find invoices matching `due_date BETWEEN (now() + pre_due_days - 1 day) AND (now() + pre_due_days)` AND status=pending → enqueue pre-due. Find invoices matching `now() - due_date IN (post_due_days)` AND status=overdue → enqueue post-due. Idempotency: skip if a `payment_reminder` outbox row exists for this `(invoice_id, kind=pre_due or post_due_offset_X)` within the last 24h.

- [ ] **Step 2: Implement CLI command + register in bootstrap/console.php**

- [ ] **Step 3: Integration test**

Set up invoices with various states, run command, assert outbox has expected rows.

- [ ] **Step 4: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): EnqueuePaymentReminders cron + integration test"
```

### Task F2: `EnqueueLapseWarnings` use case + CLI

**Files:**

- Create: `backend/src/Application/EnqueueLapseWarnings/{EnqueueLapseWarnings,Input,Output}.php`
- Create: `backend/src/Infrastructure/Console/EnqueueLapseWarningsCommand.php`

- [ ] **Step 1: Implement use case**

Logic: find users where (a) has overdue invoice in current year, (b) had overdue invoice in previous year that's still unpaid, (c) the lapse-cron will fire in exactly `lapse_warning_days_before` days. Enqueue.

- [ ] **Step 2: Tests + commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): EnqueueLapseWarnings cron + tests"
```

### Task F3: `crontab.example`

**Files:**

- Create: `c:/laragon/www/daems-platform/docs/crontab.example`

- [ ] **Step 1: Write**

```cron
# Daems Platform cron — copy to /etc/cron.d/daems or crontab -e
# Cron commands are idempotent; safe to run repeatedly.

# Mail outbox drain — every minute
* * * * *  cd /var/www/daems-platform && php bin/console mail:drain >> /var/log/daems-mail-drain.log 2>&1

# Payment reminders — daily 07:00
0 7 * * *  cd /var/www/daems-platform && php bin/console mail:enqueue-payment-reminders >> /var/log/daems-mail-cron.log 2>&1

# Lapse warnings — daily 08:00
0 8 * * *  cd /var/www/daems-platform && php bin/console mail:enqueue-lapse-warnings >> /var/log/daems-mail-cron.log 2>&1

# 0.7-billing crons (already in place from MembershipBilling milestone)
# … (do not edit)
```

- [ ] **Step 2: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  add docs/crontab.example
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): crontab.example with mail:drain + payment-reminders + lapse-warnings"
```

---

## Wave G — Suppression + GDPR + bounce-handling (1-2 days)

Goal: Suppression list UI + manual add/remove. Public `/unsubscribe` page with HMAC tokens. Marketing template footer includes unsubscribe link.

### Task G1: Suppression use cases (`ListSuppressions`, `AddManualSuppression`, `RemoveSuppression`)

**Files:**

- Create: 3 use-case folders
- Tests: 3 unit-test files

- [ ] **Step 1: Implement 3 use cases**

- [ ] **Step 2: Tests + commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Suppression use cases (list, add manual, remove) + tests"
```

### Task G2: Suppression API + UI (tab inside settings page)

**Files:**

- Modify: `backend/src/Infrastructure/Adapter/Api/Controller/SettingsController.php` (add 3 endpoints)
- Modify: `frontend/backstage/settings/communications.php` (Suppression tab)

- [ ] **Step 1: API endpoints**

- [ ] **Step 2: UI tab**

Lista with status pills + "Poista" button + "Lisää käsin" button at top.

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): Suppression API (3 endpoints) + Settings.suppression tab"
```

### Task G3: Public `/unsubscribe` handler + HMAC tokens

**Files:**

- Create: `c:/laragon/www/daems-platform/public/communications/unsubscribe.php`
- Create: `backend/src/Infrastructure/Auth/UnsubscribeTokenSigner.php`
- Test: `tests/Unit/Auth/UnsubscribeTokenSignerTest.php`

- [ ] **Step 1: Implement `UnsubscribeTokenSigner`**

```php
final class UnsubscribeTokenSigner {
    public function __construct(private readonly string $base64Key) {}

    public function sign(UserId $user, TenantId $tenant, CommunicationCategory $cat, int $ttlSeconds = 2592000): string {
        $payload = json_encode([
            'u' => $user->value, 't' => $tenant->value, 'c' => $cat->value,
            'e' => time() + $ttlSeconds,
        ]);
        $key = sodium_crypto_generichash('unsubscribe' . base64_decode($this->base64Key), '', 32);
        $sig = hash_hmac('sha256', $payload, $key);
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    public function verify(string $token): ?array {
        $decoded = base64_decode(strtr($token, '-_', '+/'));
        if (!str_contains($decoded, '|')) return null;
        [$payload, $sig] = explode('|', $decoded, 2);
        $key = sodium_crypto_generichash('unsubscribe' . base64_decode($this->base64Key), '', 32);
        if (!hash_equals(hash_hmac('sha256', $payload, $key), $sig)) return null;
        $data = json_decode($payload, true);
        if (($data['e'] ?? 0) < time()) return null;
        return $data;
    }
}
```

- [ ] **Step 2: Implement `unsubscribe.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../../bootstrap/app.php';

$token = $_GET['t'] ?? '';
$signer = $container->get(UnsubscribeTokenSigner::class);
$payload = $signer->verify($token);

if ($payload === null) {
    http_response_code(410);
    echo "<h1>Linkki ei kelpaa tai on vanhentunut</h1>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $useCase = $container->get(UpdateUserCommunicationPreference::class);
    $useCase->execute(new Input(
        new UserId($payload['u']),
        new TenantId($payload['t']),
        CommunicationCategory::from($payload['c']),
        false,
    ), new SystemActingUser());
    // Render success
} else {
    // Render confirm form with tenant brand color
}
```

- [ ] **Step 3: Frontend route delegation**

Modify `daem-society/public/index.php` and `daems-platform/public/sites/_default/router.php` to route `/unsubscribe` to this handler.

- [ ] **Step 4: E2E test**

Tampered token → 410. Valid token → 200 + preference updated.

- [ ] **Step 5: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): public /unsubscribe handler + HMAC token signer + E2E"
```

### Task G4: Marketing template footer (newsletter_wrapper.html)

**Files:**

- Modify: `backend/src/Infrastructure/Renderer/templates/newsletter_wrapper.html`

- [ ] **Step 1: Verify unsubscribe link present in footer**

If not, append before the closing wrapper `<table>`:

```html
<tr><td style="background:#f8f8f8;padding:14px 22px;text-align:center;font-size:11px;color:#888;font-family:Helvetica,Arial,sans-serif;">
  Et halua enää uutiskirjeitä?
  <a href="{{unsubscribe_url}}" style="color:#666666;text-decoration:underline;">Poistu listalta</a><br><br>
  <span style="color:#888888;">{{brand_footer_address}}</span>
</td></tr>
```

- [ ] **Step 2: Verify `SendNewsletter` use case generates `unsubscribe_url` via signer for each recipient**

- [ ] **Step 3: Commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Add(0.8/communications): newsletter footer unsubscribe link (signed HMAC URL)"
```

### Task G5: Activate suppression isolation test

**Files:**

- Modify: `c:/laragon/www/daems-platform/tests/Isolation/CommunicationsTenantIsolationTest.php`

- [ ] **Step 1: Implement + commit**

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Test(0.8/communications): activate suppression isolation assertion"
```

---

## Wave H — Polish + test sweep + smoke (1 day)

Goal: All test levels green, PHPStan 0, browser smoke on all 5 pages × 3 tenants, BOTH-containers verified.

### Task H1: Run full PHPStan analyse + fix any introduced warnings

```bash
cd c:/laragon/www/daems-platform
composer analyse
```

- [ ] **Step 1: Resolve any warnings until 0 errors**

### Task H2: Run full test suite

```bash
composer test:all
```

- [ ] **Step 1: Expect Unit + Integration + E2E all PASS, Isolation Run twice (flakiness — `feedback_isolation_suite_flaky.md`)**

### Task H3: Browser smoke matrix

Visit each URL via 3 browsers (or single browser × 3 tenant hosts):

- `daems.local/backstage/communications`
- `daems.local/backstage/communications/outbox`
- `daems.local/backstage/communications/newsletters`
- `daems.local/backstage/communications/templates`
- `daems.local/backstage/settings/communications`
- `daems.local/unsubscribe?t=<sample-token>`
- `daem-society.local/unsubscribe?t=<sample-token>`
- (Sahegroup paths if frontend ready — otherwise skip)

- [ ] **Step 1: Manually verify all pages load + first form submission round-trips**

### Task H4: Run BOTH-containers grep verification

```bash
cd c:/laragon/www/daems-platform
diff <(grep -oP "(Sql[A-Z][a-zA-Z]+Repository|MailerInterface|DsnEncryptor)" bootstrap/app.php | sort -u) \
     <(grep -oP "(InMemory[A-Z][a-zA-Z]+Repository|MailerInterface|DsnEncryptor)" tests/Support/KernelHarness.php | sort -u)
```

- [ ] **Step 1: Verify every binding in prod has equivalent in harness**

### Task H5: Final commit + tag

```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" \
  commit -m "Polish(0.8/communications): all tests green, PHPStan 0, smoke matrix complete — milestone 0.8 ready"
```

Wait for user "pushaa" before pushing.

---

## Self-Review

**1. Spec coverage:** ✓ All 12 spec sections have at least one task. Migration, vendor packages, encryption, MailerInterface + adapter, EmailHtmlRenderer + Markdown + var-sub + email-safe HTML templates, outbox, drain cron, composer, 4 strict templates, newsletter blocks, payment-reminder + lapse-warning crons, suppression, unsubscribe, settings UI, i18n parity, isolation tests, BOTH-containers check, browser smoke. Test counts (~120-140) reached through Unit (60) + Integration (30) + Isolation (5) + E2E (25) + auth (10) + i18n parity (1) = 131.

**2. Placeholder scan:** No "TBD" / "implement later" / "similar to Task N" used. Every code block contains executable code. The exceptions: Task A6 step 1 says "copy SQL from spec § 6" — this is acceptable because the spec is checked in alongside this plan and never paraphrased; otherwise the SQL would duplicate 80+ lines unnecessarily. Task B6 step 1 references existing repos as templates — acceptable for engineering implementation.

**3. Type consistency:** ✓ `MailOutbox` always 18 properties matching spec § 4.1. `MailerInterface::send(MailOutbox, TenantCommunicationSettings)` consistent in C1, C2, C3. `BlockSerializer::toJson/fromJson` referenced consistently in E1, E2. `UnsubscribeTokenSigner::sign(UserId, TenantId, CommunicationCategory, ttl)` consistent in G3 and G4. `CommunicationCategory` enum has `isImmutable`/`defaultOptedIn` methods used consistently across B3, B9.

**4. Scope check:** ✓ Focused on 0.8 deliverables only. No 0.9 work bleeding in. The Meeting entity is thin per Q8 decision. Audience uses 4-filter pattern per Q7 decision.

Identified follow-ups:

- Task E2 EventCardBlock — marked as may-be-stub. If events-module cross-call needs explicit Application-layer interface, add to Wave G follow-up commit (~30 min).
- Task F1 idempotency check uses a 24h window — verify against 0.7's actual invoice-status-flip timing to avoid sending duplicates if billing cron and reminder cron interact at the same minute.
- Task H3 sahegroup browser smoke is conditional — note in retro if Phase-2 X-Daems-Forwarded-Host blocks it.
