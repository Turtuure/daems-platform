# Milestone 0.8 — Communications v1 (design)

**Branch:** `communications-v1` (off `dev` @ `86a0980`)
**Status:** Spec (brainstorming complete, awaiting writing-plans)
**Owner:** Dev Team
**Created:** 2026-05-13

---

## 1. Tausta ja tavoite

Daem Society ry:n säännöt ja yleinen Suomen yhdistys-kontekstin tarpeisto edellyttävät automaattista viestintää: kokouskutsut (≥14 vrk ennen vuosikokousta), maksumuistutukset (jäsenmaksut), jäsenhakemuksen hyväksymis-ilmoitukset, ryhmäviestit jäsenryhmille ja säännöllinen uutiskirje. Tämä milestone toteuttaa **yleishyödyllisen mail-infran kaikille moduleille** + ne käytön surfacet (composer, outbox, uutiskirjeet, pohjat, asetukset) jotka 0.8-skoppi rajaa sisään.

0.7 MembershipBilling jätti eksplisiittisesti SMTP-/email-infran 0.8:lle (`docs/superpowers/specs/2026-05-12-membership-billing-v1-design.md` § 2 Q6) — tämä milestone täyttää sen tarpeen ja samalla tuottaa pohjan 0.9 Meetings + Voting + Board -milestonelle, joka käyttää 0.8:n kanavia kokouskutsuihin.

**Skoppi-rajaus (Q1 C):**

- Mail-infra (transport, template-engine, audience-resolusio, outbox, audit, opt-out, monikielisyys fi_FI/en_GB/sw_TZ)
- 5 viestityyppiä: kokouskutsut, maksumuistutukset, jäsenhakemuksen hyväksyntä, ryhmäviestit, uutiskirjeet
- Thin Meeting -entiteetti kokouskutsujen DB-pohjaksi (täysi Meeting-domain → 0.9)
- Per-tenant SMTP (BYO), per-tenant cron-aikataulu-säätö, per-tenant brand-vars

**Out-of-scope** (yksityiskohdat § 11): täysi Meeting-domain, drag-and-drop -editori, inbound-mail, webhook-bouncet, avaus-/klikkaus-tracking, multi-step-kampanjat, A/B-testit, member-facing portal-UI.

**Säännöt-mapping:**

| Säännöt § | Vaatimus | 0.8 toteutus |
| --- | --- | --- |
| § 4 | Jäsenmaksu kahtena peräkkäisenä vuonna erääntynyt → katsotaan eronneeksi | Lapse-varoitus 30 vrk ennen 0.7:n `lapse-inactive-members`-cronin laukeamista (oletus, per-tenant -muokattava) |
| § 5 | Hallitus päättää maksujen suuruuden, vapauttaa tai alentaa määräajaksi | Maksumuistutukset käyttävät 0.7:n `member_fee_invoices.status` ja `due_date`-kenttiä — ei suoraa pykälä-vaadetta, mutta päivittäinen muistutus-cron tukee § 5:n maksu-flowta |
| § 7 (säännöt 2026) | Vuosikokous koollekutsutaan ≥14 vrk ennen | Kokouskutsu-composer ei pakota tätä 0.8:ssa (siirretty 0.9 Meetings-milestonelle) mutta `meetings.starts_at` on tallennettu eli aikarajan myöhempi tarkistus on triviaali |
| GDPR | Markkinointi-viestinnälle aktiivinen suostumus, transactional-viestintä legitimate interest -pohjalla | 3-kategorian malli (Q5 B): transactional pakollinen, operational default-on, marketing default-off + active opt-in vaadittu uutiskirjeisiin |
| GDPR | Datan poisto-/pseudonymisointi-oikeus | 24 kk -säilytys outbox-rivien sisällölle, jonka jälkeen sähköposti hashataan ja body tyhjennetään (retention-cron) |

---

## 2. Päätetyt design-valinnat (Q&A 2026-05-13)

| # | Päätös | Valittu |
| --- | --- | --- |
| Q1 | Skoppi 0.8:n sisällä | **C** — kaikki 5 sisään (mail-infra + kokouskutsut + maksumuistutukset + ryhmäviestit + uutiskirjeet + monikieliset pohjat) |
| Q2 | Mail-transport-kerros | **A + C** — Symfony Mailer DSN-pohjaisena + oma `MailerInterface`-portti Clean Architecture -hengessä |
| Q3 | Per-tenant lähettäjä-identiteetti | **C** — BYO SMTP (per-tenant kryptattu DSN); "Konfiguroi SMTP" -CTA jos puuttuu |
| Q4 | Synkroninen vs jonossa | **B** — outbox-taulu + `bin/console mail:drain` -cron 1 min välein, 3× retry exponential backoff |
| Q5 | Opt-out / tilausmalli (GDPR) | **B** — 3 kategoriaa: `transactional` (pakollinen), `operational` (default-on), `marketing` (default-off, opt-in); kokouskutsut käsitellään transactional-tasolla |
| Q6 | Template-formaatti / authoring | **F-hybrid** — 4 strikt-tyyppiä (devin tekemät MJML-layoutit + admin-stringit per locale) + uutiskirje vapaalla blokki-kokoonpanijalla (7 blokki-tyyppiä); sisäinen renderöinti pure-PHP MJML-portti, body-fieldeissä CommonMark-Markdown |
| Q7 | Audience-suodatus (ryhmäviesti + uutiskirje) | **B** — `membership_type[]` + `locale[]` + `joined_within` + `application_status[]`; tallennettavat segmentit + query-builder → 1.x |
| Q8 | Meeting-entiteetti | **Thin payload-taulu** (Q8 alkuperäinen B): `meetings` tabulla `type`/`starts_at`/`location`/`title_i18n`/`agenda_items_i18n`/`document_urls`/`status`. Ei `/backstage/meetings`-sivua 0.8:ssa — luonti vain composer-sivutuotteena. Täysi Meeting-domain → 0.9. |
| Q9 | Cron-triggerit | **B** — 3 triggeriä: maksumuistutus pre-due, post-due (lista), lapse-varoitus 30 vrk ennen § 4 -triggeriä; **kaikki ajat per-tenant -muokattavia** (`tenant_communication_settings`-taulun sarakkeet) |
| Q10 | Sidebar / UI-rakenne | **A** — oma "Viestintä"-sidebar-ryhmä, 4 alasivua + `/backstage/settings/communications` |

**Operationaaliset oletukset (O1–O8, käyttäjän hyväksymät 2026-05-13):**

| # | Oletus | Status |
| --- | --- | --- |
| O1 | Public `/unsubscribe`-sivu käyttää tenantin `brand_primary_color`-väritystä (lukee HMAC-tokenissa olevasta tenant_id:stä) | Muutettavissa |
| O2 | Admin saa "Lähetä uudelleen" -painikkeen `sent`-statuksen outbox-riville (luo uuden rivin samalla payloadilla) | Muutettavissa |
| O3 | `mail_outbox.body_html`-säilytys 24 kk, sitten pseudonymisointi (sähköposti hash, body tyhjennetty) — retention-cron `mail:retention-cleanup` | Muutettavissa |
| O4 | Failed-rivin retry: 3 yritystä, exponential backoff (1 min, 5 min, 30 min) | Muutettavissa |
| O5 | Send-UI näyttää audience-koon + esimerkki "Anna L., Pekka M., +125 muuta" (privacy-friendly) | Muutettavissa |
| O6 | Adminilla EI ole "force send" -optiota marketing-kategorian opt-out-suodatuksen ohittamiseen | **Lukittu** (GDPR) |
| O7 | SMTP-konfiguroimaton tenant: cron logaa virheen ja siirtyy seuraavaan tenantiin; outbox-rivit pysyvät `queued`; admin-dashboardiin "Sinulla on N lähettämätöntä viestiä, konfiguroi SMTP" -toast | Muutettavissa |
| O8 | `mail_outbox`-rivillä tasan yksi `payload_*_id`-kenttä on ei-null (sovellus-tasolla pakotettu, mahdollinen DB-CHECK myöhemmin) | **Lukittu** (semanttinen) |

---

## 3. Architecture overview

```text
┌─────────────────────────────────────────────────────────────────────────┐
│  Backstage UI (PHP-sivut @ modules/communications/frontend/backstage/)  │
│  ┌─────────┬────────┬──────────────┬──────────┬──────────────────────┐  │
│  │ Composer│ Outbox │ Newsletters  │ Templates│ Settings/SMTP/Brand  │  │
│  └─────────┴────────┴──────────────┴──────────┴──────────────────────┘  │
└──────────────────────────────┬──────────────────────────────────────────┘
                               │
              /api/v1/backstage/communications/*
                               │
┌──────────────────────────────▼──────────────────────────────────────────┐
│  Application/Communications/  (25 use casea)                            │
│  ComposeAndPreviewMessage  SendComposedMessage  RetryOutboxRow          │
│  CreateNewsletterDraft     UpdateNewsletterDraft  SendNewsletter        │
│  ListOutboxRows  DrainMailOutbox  MarkSuppressedRecipientsInPending     │
│  GetTemplateOverrides  SaveTemplateOverrides                            │
│  CreateMeetingFromComposer  GetMeetingForReInvite                       │
│  Get/SaveCommunicationSettings  SendSmtpTestEmail                       │
│  ListSuppressions  AddManualSuppression  RemoveSuppression              │
│  GetUserCommunicationPreferences  UpdateUserCommunicationPreference     │
│  EnqueuePaymentReminders  EnqueueLapseWarnings                          │
└──────────────────────────────┬──────────────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────────────┐
│  Domain/Communications/  (entiteetit, value-objektit, repository-IF:t)  │
│  Mail/   Template/   Audience/   Meeting/   Preference/   Settings/     │
└──────────────────────────────┬──────────────────────────────────────────┘
                               │
┌──────────────────────────────▼──────────────────────────────────────────┐
│  Infrastructure/Communications/                                         │
│  Mailer/      ── SymfonyMailerAdapter, InMemoryMailer                   │
│  Renderer/    ── MjmlRenderer, MarkdownRenderer, VarSubstituter         │
│  Persistence/ ── Sql*Repository:t                                       │
│  Console/     ── MailDrainCommand, EnqueuePaymentReminders,             │
│                  EnqueueLapseWarnings                                   │
│  Crypto/      ── DsnEncryptor (libsodium)                               │
└─────────────────────────────────────────────────────────────────────────┘
```

**Module-rakenne** seuraa Wave A-D extraction-pattern:ia (kuten events/forum/projects):

```text
c:/laragon/www/modules/communications/
├── module.json
├── composer.json
├── README.md
├── phpunit.xml.dist
├── backend/
│   ├── bindings.php
│   ├── routes.php
│   ├── src/                    # namespace DaemsModule\Communications\
│   │   ├── Domain/
│   │   │   ├── Mail/
│   │   │   ├── Template/
│   │   │   ├── Audience/
│   │   │   ├── Meeting/
│   │   │   ├── Preference/
│   │   │   ├── Settings/
│   │   │   └── Exception/
│   │   ├── Application/
│   │   └── Infrastructure/
│   │       ├── Mailer/
│   │       ├── Renderer/
│   │       ├── Persistence/
│   │       ├── Console/
│   │       └── Crypto/
│   └── migrations/
│       └── 098_create_communications_tables.sql
└── frontend/
    ├── backstage/
    │   ├── communications/index.php
    │   ├── communications/outbox.php
    │   ├── communications/newsletters/{index,edit}.php
    │   ├── communications/templates/{index,edit}.php
    │   └── settings/communications.php
    └── assets/
        ├── communications.css
        ├── composer.js
        ├── outbox.js
        ├── newsletter-blocks.{js,css}
        └── settings.js
```

**Platform-puoli (`c:/laragon/www/daems-platform/`):**

- `config/modules.php` — uusi `communications`-rivi
- `lang/{fi_FI,en_GB,sw_TZ}.php` — ~120 uutta avainta
- `bootstrap/app.php` + `tests/Support/KernelHarness.php` — DI-bindit molemmissa (BOTH-containers-rule)
- `bootstrap/console.php` — 3 uutta cron-komentoa
- `Frontend/BackstageSidebar.php` — uusi `communications`-ryhmä GROUP_RANK 5, 4 hardcoded sub-itemia (kuten governance-pattern)
- `.env.example` — `APP_ENCRYPTION_KEY=` -rivi

---

## 4. Domain-malli

Clean Architecture: kaikki framework-vapaata, repository-rajapinnat domain-puolella, SQL-toteutukset infrastructure-puolella.

### 4.1 Mail-domain

```php
namespace DaemsModule\Communications\Domain\Mail;

enum MailKind: string {
    case MeetingInvitation   = 'meeting_invitation';
    case PaymentReminder     = 'payment_reminder';
    case MembershipApproved  = 'membership_approved';
    case GroupMessage        = 'group_message';
    case Newsletter          = 'newsletter';
}

enum MailOutboxStatus: string {
    case Queued     = 'queued';
    case Sending    = 'sending';
    case Sent       = 'sent';
    case Failed     = 'failed';
    case Bounced    = 'bounced';
    case Suppressed = 'suppressed';
}

enum SuppressionReason: string {
    case HardBounce   = 'hard_bounce';
    case Complaint    = 'complaint';
    case ManualBlock  = 'manual_block';
}

final class MailOutbox {
    public function __construct(
        public readonly MailOutboxId $id,
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly CommunicationCategory $category,
        public readonly string $recipientEmail,
        public readonly ?UserId $recipientUserId,
        public readonly SupportedLocale $locale,
        public readonly string $subject,
        public readonly string $bodyHtml,
        public readonly string $bodyText,
        public readonly array $payloadVars,
        public readonly ?MeetingId $payloadMeetingId,
        public readonly ?MemberFeeInvoiceId $payloadInvoiceId,
        public readonly ?NewsletterId $payloadNewsletterId,
        public readonly MailOutboxStatus $status,
        public readonly int $attemptCount,
        public readonly ?string $lastError,
        public readonly DateTimeImmutable $queuedAt,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly UserId $queuedBy,
    ) {}
}

final class MailSuppression {
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly string $emailAddress,
        public readonly SuppressionReason $reason,
        public readonly DateTimeImmutable $suppressedAt,
        public readonly ?string $smtpResponseCode,
        public readonly ?UserId $suppressedBy,
    ) {}
}
```

### 4.2 Template-domain

```php
namespace DaemsModule\Communications\Domain\Template;

final class MailTemplate {
    public function __construct(
        public readonly MailTemplateId $id,
        public readonly TenantId $tenantId,
        public readonly MailKind $kind,
        public readonly SupportedLocale $locale,
        public readonly array $stringOverrides,
        public readonly DateTimeImmutable $updatedAt,
        public readonly UserId $updatedBy,
    ) {}
}

final class NewsletterDraft {
    public function __construct(
        public readonly NewsletterId $id,
        public readonly TenantId $tenantId,
        public readonly string $internalName,
        public readonly array $subjectByLocale,
        public readonly array $blocksByLocale,
        public readonly AudienceFilter $audience,
        public readonly NewsletterStatus $status,
        public readonly ?DateTimeImmutable $sentAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly UserId $createdBy,
    ) {}
}

abstract class NewsletterBlock {
    abstract public function toRenderable(): array;
}
final class HeadingBlock     extends NewsletterBlock { /* level 1..3, text */ }
final class ParagraphBlock   extends NewsletterBlock { /* markdown */ }
final class ImageBlock       extends NewsletterBlock { /* url, alt, caption */ }
final class ButtonBlock      extends NewsletterBlock { /* text, url */ }
final class DividerBlock     extends NewsletterBlock { /* (empty) */ }
final class TwoColumnsBlock  extends NewsletterBlock { /* left[], right[] (recursion) */ }
final class EventCardBlock   extends NewsletterBlock { /* eventId — renderöityy auto */ }
```

### 4.3 Audience-domain

```php
namespace DaemsModule\Communications\Domain\Audience;

final class AudienceFilter {
    public function __construct(
        public readonly array $membershipTypes,
        public readonly array $locales,
        public readonly ?JoinedWithinPeriod $joinedWithin,
        public readonly array $applicationStatuses,
    ) {}
}

interface AudienceResolverInterface {
    /** @return list<ResolvedRecipient> */
    public function resolve(TenantId $tenant, AudienceFilter $filter, CommunicationCategory $category): array;
}

final class ResolvedRecipient {
    public function __construct(
        public readonly UserId $userId,
        public readonly string $email,
        public readonly SupportedLocale $locale,
        public readonly string $firstName,
        public readonly array $contextVars,
    ) {}
}
```

### 4.4 Meeting-domain (thin payload)

```php
namespace DaemsModule\Communications\Domain\Meeting;

enum MeetingType: string {
    case AnnualMeeting = 'annual_meeting';
    case Extraordinary = 'extraordinary';
    case BoardMeeting  = 'board_meeting';
}

enum MeetingStatus: string {
    case Draft        = 'draft';
    case Scheduled    = 'scheduled';
    case InvitesSent  = 'invites_sent';
    case Completed    = 'completed';
}

final class Meeting {
    public function __construct(
        public readonly MeetingId $id,
        public readonly TenantId $tenantId,
        public readonly MeetingType $type,
        public readonly array $titleByLocale,
        public readonly DateTimeImmutable $startsAt,
        public readonly ?string $location,
        public readonly ?string $remoteUrl,
        public readonly array $agendaItemsByLocale,
        public readonly array $documentUrls,
        public readonly MeetingStatus $status,
        public readonly DateTimeImmutable $createdAt,
        public readonly UserId $createdBy,
    ) {}
}
```

### 4.5 Preference + Settings -domain

```php
namespace DaemsModule\Communications\Domain\Preference;

enum CommunicationCategory: string {
    case Transactional = 'transactional';
    case Operational   = 'operational';
    case Marketing     = 'marketing';
}

final class UserCommunicationPreference {
    public function __construct(
        public readonly UserId $userId,
        public readonly TenantId $tenantId,
        public readonly CommunicationCategory $category,
        public readonly bool $optedIn,
        public readonly DateTimeImmutable $updatedAt,
    ) {}
}

namespace DaemsModule\Communications\Domain\Settings;

final class TenantCommunicationSettings {
    public function __construct(
        public readonly TenantId $tenantId,
        public readonly ?string $smtpDsnEncrypted,
        public readonly ?string $mailFromAddress,
        public readonly ?string $mailDisplayName,
        public readonly ?string $mailReplyTo,
        public readonly ?DateTimeImmutable $smtpTestSucceededAt,
        public readonly int $reminderPreDueDays,
        public readonly array $reminderPostDueDays,
        public readonly int $lapseWarningDaysBefore,
        public readonly ?string $brandLogoUrl,
        public readonly ?string $brandPrimaryColor,
        public readonly ?string $brandFooterAddress,
        public readonly DateTimeImmutable $updatedAt,
    ) {}
}
```

### 4.6 Repository-rajapinnat (8 interfacea)

- `MailOutboxRepositoryInterface` — `save`, `findById`, `listForTenant(filters)`, `pickNextForSending(int $limit)`, `markStatus(id, status, error)`
- `MailSuppressionRepositoryInterface` — `isSuppressed(tenant, email)`, `add(...)`, `remove(...)`, `listForTenant(filters)`
- `MailTemplateRepositoryInterface` — `findOverrides(tenant, kind, locale)`, `saveOverrides(...)`
- `NewsletterDraftRepositoryInterface` — `save`, `findById`, `listForTenant`
- `MeetingRepositoryInterface` — `save`, `findById`, `listForTenant(timeWindow)`
- `UserCommunicationPreferenceRepositoryInterface` — `findFor(user, tenant)`, `setFor(...)`, `categoriesAllowing(user, tenant)`
- `TenantCommunicationSettingsRepositoryInterface` — `findForTenant`, `save`
- `AudienceResolverInterface` (Domain-puolella, ks. § 4.3)

---

## 5. Use case -inventaari

25 use casea jaettuna 9 ryhmään (yksityiskohdat brainstorming-keskustelussa § 3, tiivistetään tähän).

### 5.1 Composer + send

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `ComposeAndPreviewMessage` | Renderöi HTML+text + laskee audience-koon ilman tallennusta | admin / moderator |
| `SendComposedMessage` | Resolvoi audience + opt-in + suppression-suodatus → enqueue N outbox-riviä | admin / moderator (Newsletter: vain admin) |
| `RetryOutboxRow` | Failed-rivi → queued, attempt_count nollaus | admin |

### 5.2 Outbox + drain

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `ListOutboxRows` | Filteroitavissa per status/kind/päivämääräväli/vastaanottaja | admin / moderator / GSA |
| `DrainMailOutbox` | Cron: poimii 50 queued-riviä, kutsuu `MailerInterface->send()`, päivittää statukset | System (cron) |
| `MarkSuppressedRecipientsInPending` | Skannaa `queued`-rivit, suppressed-osoitteet → status `suppressed` | System (cron) |

### 5.3 Newsletter

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `CreateNewsletterDraft` | Luo tyhjä luonnos | admin |
| `UpdateNewsletterDraft` | Päivitä blokit per locale + subject + audience | admin |
| `SendNewsletter` | Validoi parity + opt-in + bulk-enqueue | admin |
| `DeleteNewsletterDraft` | Vain Draft/Scheduled poistuu, Sent ei | admin |
| `ListNewsletters` | Lista + statistiikka | admin |

### 5.4 Templates

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `GetTemplateOverrides` | Lataa per-(kind, locale) admin-stringit | admin |
| `SaveTemplateOverrides` | Tallenna per-locale subject + intro + signature + footer | admin |

### 5.5 Meetings (thin, vain composer-flowta varten)

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `CreateMeetingFromComposer` | Composer-sivutuote: luo Meeting + enqueue invites | admin |
| `GetMeetingForReInvite` | Lataa olemassa oleva Meeting uusinta-kutsulle | admin |

### 5.6 Settings + SMTP

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `GetCommunicationSettings` | DSN palautuu maskattuna; muut suorina | admin (osa) / GSA (kaikki) |
| `SaveCommunicationSettings` | Kryptaa DSN, nollaa `smtp_test_succeeded_at` uuden DSN:n yhteydessä | admin |
| `SendSmtpTestEmail` | Synkroninen testilähetys → onnistui = `smtp_test_succeeded_at` päivittyy | admin |

### 5.7 Suppression

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `ListSuppressions` | Per-tenant suppression-lista | admin / GSA |
| `AddManualSuppression` | Admin lisää käsin, reason `ManualBlock` | admin |
| `RemoveSuppression` | Admin poistaa osoitteen | admin |

### 5.8 Preferences

| Use case | Tehtävä | Auth |
| --- | --- | --- |
| `GetUserCommunicationPreferences` | Per-(user, tenant, kategoria) opt-in-tilat (transactional ei näy) | user-itse / admin |
| `UpdateUserCommunicationPreference` | Päivitä yksi (kategoria, opted_in); transactional → `ForbiddenException` | user-itse / admin |

### 5.9 Cron-erityiset (System-konteksti)

| Use case | Cron-rivi | Logiikka |
| --- | --- | --- |
| `EnqueuePaymentReminders` | `0 7 * * *` | Per-tenant: laskut joiden `due_date - now() == pre_due_days` JA `status = pending` → pre-due-enqueue; laskut joiden offset matchaa `post_due_days[]`-listaa JA `status = overdue` → post-due-enqueue; idempotenssi: ei dupea saman 24h aikana |
| `EnqueueLapseWarnings` | `0 8 * * *` | Per-tenant: jäsenet joilla `lapse_warning_days_before` päivän etäisyys ennustettuun § 4 -triggeriin (1 vuoden vanha overdue + edellinen vuosi unpaid) |

**`DrainMailOutbox` cron** pyörii erikseen `* * * * *` (`bin/console mail:drain`, lockfile).

---

## 6. Tietokantaskeema (migraatio 098)

7 uutta taulua. Kaikki per-tenant (`tenant_id` 2. sarake, FK `tenants.id` ON DELETE CASCADE).

```sql
-- 098_create_communications_tables.sql

-- 1. Tenant-asetukset (SMTP + cron + brand)
CREATE TABLE tenant_communication_settings (
    tenant_id                    CHAR(36) PRIMARY KEY,
    smtp_dsn_encrypted           TEXT             NULL,
    mail_from_address            VARCHAR(255)     NULL,
    mail_display_name            VARCHAR(255)     NULL,
    mail_reply_to                VARCHAR(255)     NULL,
    smtp_test_succeeded_at       DATETIME(3)      NULL,
    reminder_pre_due_days        INT              NOT NULL DEFAULT 7,
    reminder_post_due_days       JSON             NOT NULL,
    lapse_warning_days_before    INT              NOT NULL DEFAULT 30,
    brand_logo_url               VARCHAR(500)     NULL,
    brand_primary_color          VARCHAR(7)       NULL,
    brand_footer_address         TEXT             NULL,
    updated_at                   DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_tcs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- 2. Meetings (thin payload)
CREATE TABLE meetings (
    id                CHAR(36)     PRIMARY KEY,
    tenant_id         CHAR(36)     NOT NULL,
    type              ENUM('annual_meeting','extraordinary','board_meeting') NOT NULL,
    starts_at         DATETIME(3)  NOT NULL,
    location          VARCHAR(500) NULL,
    remote_url        VARCHAR(500) NULL,
    title_i18n        JSON         NOT NULL,
    agenda_items_i18n JSON         NOT NULL,
    document_urls     JSON         NOT NULL,
    status            ENUM('draft','scheduled','invites_sent','completed') NOT NULL DEFAULT 'draft',
    created_at        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by        CHAR(36)     NOT NULL,
    INDEX idx_meetings_tenant_starts (tenant_id, starts_at),
    INDEX idx_meetings_tenant_status (tenant_id, status),
    CONSTRAINT fk_meetings_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_meetings_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 3. Mail-outbox (lähetysjono + audit)
CREATE TABLE mail_outbox (
    id                    CHAR(36)     PRIMARY KEY,
    tenant_id             CHAR(36)     NOT NULL,
    kind                  ENUM('meeting_invitation','payment_reminder','membership_approved','group_message','newsletter') NOT NULL,
    category              ENUM('transactional','operational','marketing') NOT NULL,
    recipient_email       VARCHAR(255) NOT NULL,
    recipient_user_id     CHAR(36)     NULL,
    locale                ENUM('fi_FI','en_GB','sw_TZ') NOT NULL,
    subject               TEXT         NOT NULL,
    body_html             MEDIUMTEXT   NOT NULL,
    body_text             MEDIUMTEXT   NOT NULL,
    payload_vars          JSON         NOT NULL,
    payload_meeting_id    CHAR(36)     NULL,
    payload_invoice_id    CHAR(36)     NULL,
    payload_newsletter_id CHAR(36)     NULL,
    status                ENUM('queued','sending','sent','failed','bounced','suppressed') NOT NULL DEFAULT 'queued',
    attempt_count         INT          NOT NULL DEFAULT 0,
    last_error            TEXT         NULL,
    queued_at             DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    sent_at               DATETIME(3)  NULL,
    queued_by             CHAR(36)     NOT NULL,
    pseudonymized_at      DATETIME(3)  NULL,
    INDEX idx_outbox_drain   (status, queued_at),
    INDEX idx_outbox_tenant  (tenant_id, queued_at DESC),
    INDEX idx_outbox_invoice (payload_invoice_id),
    INDEX idx_outbox_meeting (payload_meeting_id),
    INDEX idx_outbox_retention (queued_at, pseudonymized_at),
    CONSTRAINT fk_outbox_tenant FOREIGN KEY (tenant_id)         REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_outbox_user   FOREIGN KEY (recipient_user_id) REFERENCES users(id)
);

-- 4. Mail-suppressions (hard-bounce + complaint + manual block)
CREATE TABLE mail_suppressions (
    tenant_id           CHAR(36)     NOT NULL,
    email_address       VARCHAR(255) NOT NULL,
    reason              ENUM('hard_bounce','complaint','manual_block') NOT NULL,
    smtp_response_code  VARCHAR(16)  NULL,
    suppressed_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    suppressed_by       CHAR(36)     NULL,
    PRIMARY KEY (tenant_id, email_address),
    INDEX idx_suppression_tenant (tenant_id, suppressed_at DESC),
    CONSTRAINT fk_suppr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- 5. Template-overrides (4 strikt-tyypin admin-stringit, ei uutiskirjettä)
CREATE TABLE mail_template_overrides (
    tenant_id  CHAR(36) NOT NULL,
    kind       ENUM('meeting_invitation','payment_reminder','membership_approved','group_message') NOT NULL,
    locale     ENUM('fi_FI','en_GB','sw_TZ') NOT NULL,
    overrides  JSON     NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    updated_by CHAR(36) NOT NULL,
    PRIMARY KEY (tenant_id, kind, locale),
    CONSTRAINT fk_mto_tenant FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_mto_user   FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- 6. Newsletter-drafts (vapaa blokki-rakenne)
CREATE TABLE newsletter_drafts (
    id              CHAR(36) PRIMARY KEY,
    tenant_id       CHAR(36) NOT NULL,
    internal_name   VARCHAR(255) NOT NULL,
    subject_i18n    JSON     NOT NULL,
    blocks_i18n     JSON     NOT NULL,
    audience_filter JSON     NOT NULL,
    status          ENUM('draft','scheduled','sent') NOT NULL DEFAULT 'draft',
    sent_at         DATETIME(3) NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by      CHAR(36) NOT NULL,
    updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_newsletter_tenant_status (tenant_id, status),
    CONSTRAINT fk_nl_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_nl_creator FOREIGN KEY (created_by) REFERENCES users(id)
);

-- 7. User-communication-preferences
CREATE TABLE user_communication_preferences (
    user_id    CHAR(36) NOT NULL,
    tenant_id  CHAR(36) NOT NULL,
    category   ENUM('transactional','operational','marketing') NOT NULL,
    opted_in   BOOLEAN  NOT NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, tenant_id, category),
    INDEX idx_ucp_tenant (tenant_id, category, opted_in),
    CONSTRAINT fk_ucp_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ucp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);

-- Seed: default-preferences olemassaoleville jäsenille
INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
SELECT u.id, ut.tenant_id, 'transactional', TRUE
FROM users u JOIN user_tenants ut ON ut.user_id = u.id
ON DUPLICATE KEY UPDATE opted_in = opted_in;

INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
SELECT u.id, ut.tenant_id, 'operational', TRUE
FROM users u JOIN user_tenants ut ON ut.user_id = u.id
ON DUPLICATE KEY UPDATE opted_in = opted_in;

INSERT INTO user_communication_preferences (user_id, tenant_id, category, opted_in)
SELECT u.id, ut.tenant_id, 'marketing', FALSE
FROM users u JOIN user_tenants ut ON ut.user_id = u.id
ON DUPLICATE KEY UPDATE opted_in = opted_in;

-- Seed: default-asetukset jokaiselle olemassa olevalle tenantille
INSERT INTO tenant_communication_settings (tenant_id, reminder_post_due_days)
SELECT id, JSON_ARRAY(14, 30) FROM tenants
ON DUPLICATE KEY UPDATE tenant_id = tenant_id;
```

**Tärkeät yksityiskohdat:**

- `mail_outbox.body_html`/`body_text` renderöidään ennen outbox-rivin tallennusta → drain-cron ei renderöi enää → idempotentti retry
- `mail_outbox.payload_vars` säilyttää alkuperäiset muuttujat audit/debug-tarkoitukseen
- Yksi `payload_*_id`-kenttä on ei-null per rivi (O8 — sovellus-tasolla pakotettu)
- `pseudonymized_at` mahdollistaa 24kk-retention-cronin: `UPDATE mail_outbox SET body_html='', body_text='', payload_vars='{}', recipient_email=SHA2(recipient_email, 256), pseudonymized_at=NOW() WHERE queued_at < NOW() - INTERVAL 24 MONTH AND pseudonymized_at IS NULL`
- Suppression-key on `(tenant_id, email_address)` — per-tenant-isolaatio: sama osoite voi olla suppression-listalla useammassa tenantissa erikseen
- `reminder_post_due_days` JSON validoidaan PHP-tasolla: lista 1-5 INT-arvoa väliltä 1-180

---

## 7. Infrastructure

### 7.1 MailerInterface + adapterit

Portti (Domain):

```php
namespace DaemsModule\Communications\Domain\Mail;

interface MailerInterface {
    /**
     * @throws MailerHardBounceException SMTP 5xx
     * @throws MailerSoftBounceException SMTP 4xx
     * @throws MailerTransportException muut transport-virheet
     * @throws SmtpNotConfigured kun tenantilla ei ole DSN:ää
     */
    public function send(MailOutbox $row, TenantCommunicationSettings $settings): void;
}
```

Tuotanto: `SymfonyMailerAdapter` käyttää `symfony/mailer`-paketin DSN-pohjaista Transport-luokkaa. SMTP-virhekoodin parsiminen Symfony-virheviestistä → bounce-luokitus (HardBounce 5xx → suppression, SoftBounce 4xx → retry).

Testit: `InMemoryMailer` säilyttää lähetetyt viestit listalla, KernelHarness wirettää sen E2E-testeihin.

### 7.2 Renderöinti-pipeline

```text
MailKind + payloadVars + locale + stringOverrides
  ↓
MailTemplateRegistry → MJML-template + dev-stringit
  ↓
Apply stringOverrides → korvaa subject/intro/signature/footer
  ↓
Apply Markdown rendering body-fieldeissä (CommonMark safe mode)
  ↓
Apply {{var}} substitution (whitelist per kind, HTML-escapaus)
  ↓
Compile MJML → HTML (tijsverkoyen/mjml-php)
  ↓
Extract plain-text from HTML (oma Html2Text-helper)
  ↓
MailOutbox.bodyHtml + MailOutbox.bodyText
```

**Vendor-paketit:**

- `symfony/mailer:^7.0`
- `league/commonmark:^2.5`
- `tijsverkoyen/mjml-php` (pure-PHP MJML-portti; jos jäljessä, fallback Node-CLI:hen Wave A:n proof-of-concept-vaiheessa)

**Var-substituutio:** Whitelist-pohjainen. Jokainen `MailKind` listaa sallitut muuttujat (`MeetingInvitation::ALLOWED_VARS = ['first_name', 'meeting_title', ...]`). Tuntematon muuttuja → `UnknownTemplateVarException`. Substituutio HTML-escapaa kaikki arvot ennen MJML-kompilaatiota (XSS-suojaus).

### 7.3 DSN-kryptaus (libsodium)

```php
namespace DaemsModule\Communications\Infrastructure\Crypto;

final class DsnEncryptor {
    public function __construct(private readonly string $base64Key) {}

    public function encrypt(string $plaintext): string {
        $key   = base64_decode($this->base64Key, true);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public function decrypt(string $encoded): string {
        $blob   = base64_decode($encoded, true);
        $nonce  = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key    = base64_decode($this->base64Key, true);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new DecryptionFailed();
        }
        return $plain;
    }
}
```

Asennus: `.env.example` saa rivin `APP_ENCRYPTION_KEY=` + ohjeen generointiin: `php -r 'echo base64_encode(sodium_crypto_secretbox_keygen());'`. Avain-rotaatio = out-of-scope (1.x).

### 7.4 Cron-toteutus

3 uutta `bin/console`-komentoa, rekisteröity `bootstrap/console.php`:ssa:

| Komento | Schedule | Use case |
| --- | --- | --- |
| `php bin/console mail:drain` | `* * * * *` (lockfile estää overlapin) | `DrainMailOutbox` (50 riviä per ajo) |
| `php bin/console mail:enqueue-payment-reminders` | `0 7 * * *` | `EnqueuePaymentReminders` |
| `php bin/console mail:enqueue-lapse-warnings` | `0 8 * * *` | `EnqueueLapseWarnings` |

Lisäksi placeholder-komento `mail:retention-cleanup` 0.8.x-follow-upille (24kk-pseudonymisointi).

`crontab.example` repoon, GSA-asentaja kopioi `crontab -e`:hen.

---

## 8. Backstage UI + API + frontend

### 8.1 Backstage-sivut

5 PHP-sivua sijaitsevat `modules/communications/frontend/backstage/`:

| Sivu | URL | Sisältö |
| --- | --- | --- |
| Composer | `/backstage/communications` | Viestityyppi-dropdown → typed kentät → audience-suodatus → esikatselu → "Lähetä jonoon" |
| Outbox | `/backstage/communications/outbox` | Tauluna kaikki rivit + filtterit (status/kind/päivämääräväli/vastaanottaja) + retry-painike failed-tilalle + "Lähetä uudelleen" (O2) sent-tilalle |
| Newsletters | `/backstage/communications/newsletters` | Luonnokset + julkaistut, "Uusi uutiskirje" → blokki-kokoonpanija |
| Templates | `/backstage/communications/templates` | 4 strikt-tyypin valikkolista + per-(kind, locale) -overrideedit |
| Settings | `/backstage/settings/communications` | SMTP + cron-aikataulu + brand-vars + suppression-lista (välilehtinä) |

Kontekstista esitäyttävät syvälinkit: `?meeting=42`, `?invoice=99`, `?kind=group_message&audience_preset=board`.

### 8.2 API-reitit

20 uutta reittiä (`/api/v1/backstage/communications/*` + `/api/v1/users/{}/communication-preferences` + `/api/v1/meetings/from-composer`). Yksityiskohdat brainstorming-keskustelussa § 6.2 — taulukko tiivistettynä:

| Method | Reitti | Use case |
| --- | --- | --- |
| POST | `/preview` | `ComposeAndPreviewMessage` |
| POST | `/send` | `SendComposedMessage` |
| GET | `/outbox` | `ListOutboxRows` |
| POST | `/outbox/{id}/retry` | `RetryOutboxRow` |
| POST,PATCH,DELETE | `/newsletters[/{id}]` | Newsletter-CRUD |
| POST | `/newsletters/{id}/send` | `SendNewsletter` |
| GET,PUT | `/templates/{kind}/{locale}` | Template-overrides |
| GET,PUT | `/settings` | Settings-CRUD |
| POST | `/settings/smtp-test` | `SendSmtpTestEmail` |
| GET,POST,DELETE | `/suppressions` | Suppression-management |
| GET,PUT | `/users/{userId}/communication-preferences` | Preference-CRUD |
| POST | `/meetings/from-composer` | `CreateMeetingFromComposer` (sisäinen) |

**`config/modules.php`-rivi:**

```php
'communications' => [
    'category'          => 'communications',
    'name_key'          => 'modules.communications.name',
    'description_key'   => 'modules.communications.description',
    'is_core'           => false,
    'default_available' => true,
    'sidebar'           => null, // sidebar-itemit hardcoded BackstageSidebar.php:hen (4 alasivua, kuten governance-ryhmä)
    'route_prefixes'    => new RoutePrefixes(
        backstage: ['/backstage/communications', '/backstage/settings/communications'],
        api: [
            '/api/v1/backstage/communications',
            '/api/v1/users/{}/communication-preferences',
            '/api/v1/meetings/from-composer',
        ],
    ),
    'depends_on'        => ['members'],
],
```

**Sidebar-itemit (`BackstageSidebar.php`):** Communications-moduuli noudattaa _governance-ryhmän pattern:ia_ — modules.php:ssä `sidebar=null`, ja `BackstageSidebar.php` lisää 4 alasivu-itemia conditionalisesti jos `TenantModuleResolver::isEnabled('communications', $tenant)` palauttaa true. `GROUP_RANK`-konstantiin lisätään `'communications' => 5` (sijoittuu governance-ryhmän jälkeen, system-ryhmä siirtyy 6:een). Intra-group `order`-arvot: Composer 10, Outbox 20, Newsletters 30, Templates 40. Asetukset eivät näy Viestintä-ryhmässä — `/backstage/settings/communications` löytyy Settings-sivun välilehtinä, ei omana sidebar-itemina.

### 8.3 Public unsubscribe (GDPR-pakollinen)

Marketing-luokan uutiskirjeissä alapalkkiin lisätään aina linkki:

```text
Et halua enää uutiskirjeitä? Poistu listalta:
https://daem-society.fi/unsubscribe?t=<token>
```

Token = HMAC-allekirjoitettu payload `{user_id, tenant_id, category, expires_at: +30d}`. Avain johdetaan `APP_ENCRYPTION_KEY`-arvosta `sodium_crypto_generichash`-konteksti-stringillä `"unsubscribe"`.

**Public-route** lisätään `daem-society/public/index.php`-delegaatioblokkiin sekä `public/sites/_default/router.php`:hen:

```php
if (preg_match('#^/unsubscribe/?$#', $path)) {
    require __DIR__ . '/../../daems-platform/public/communications/unsubscribe.php';
    exit;
}
```

`public/communications/unsubscribe.php`:

1. Validoi HMAC + expires_at → palauta 410 jos vanhentunut, 400 jos tampered
2. Lataa tenantin `TenantCommunicationSettings` → renderöi sivu tenantin `brand_primary_color`-väritystä käyttäen (O1)
3. Jos GET → näytä vahvistus-painike
4. Jos POST → kutsu `UpdateUserCommunicationPreference($user_id, $tenant_id, $category, opted_in: false)` SystemUser-kontekstissa → "Poistettu listalta" -vahvistus

### 8.4 Sähköposti-templatet (devin tekemät MJML-tiedostot)

Repo-sijainti: `modules/communications/backend/src/Infrastructure/Renderer/templates/`

```text
templates/
├── meeting_invitation.mjml
├── payment_reminder.mjml
├── membership_approved.mjml
├── group_message.mjml
├── lapse_warning.mjml           # (cron-spesifinen alavariantti maksumuistutuksesta)
└── newsletter-wrapper.mjml      # blokki-renderöinnin runko
```

Kaikki templatet käyttävät `{{brand_primary_color}}`, `{{brand_logo_url}}`, `{{brand_footer_address}}` -muuttujia jotka resolvoidaan render-vaiheessa tenantin `TenantCommunicationSettings`-rivistä.

### 8.5 CSS + JS-assetit

```text
modules/communications/frontend/assets/
├── communications.css
├── composer.js
├── outbox.js
├── newsletter-blocks.{js,css}
└── settings.js
```

Käyttävät olemassa olevia Backstage-CSS-tokeneja (`--backstage-*`) — ei uusia design-tokeneja.

### 8.6 i18n-avaimet

~120 uutta avainta `lang/{fi_FI,en_GB,sw_TZ}.php`-tiedostoihin:

- `modules.communications.{name,description}`
- `backstage.title.communications.*` (5 sivua)
- `communications.kind.*` (5 viestityyppiä)
- `communications.category.{transactional,operational,marketing}` + selitykset
- `communications.composer.*` (formit, validointi-virheet)
- `communications.outbox.*` (status-tekstit, filtterit, painikkeet)
- `communications.newsletter.*` (blokki-tyypit, editor-tekstit)
- `communications.template.*` (override-kentät)
- `communications.settings.*` (SMTP, brand-vars, cron-asetukset)
- `communications.suppression.*` (lista, reason-tekstit)
- `communications.unsubscribe.*` (public-sivun tekstit)

Parity-testi `tests/Unit/I18n/CommunicationsParityTest.php` varmistaa kaikkien avaimien olevan kolmessa locale-tiedostossa.

---

## 9. Testaus-strategia

Arvio: ~120-140 uutta testiä. Kaikki 4 tasoa (CLAUDE.md cross-cutting concerns).

### 9.1 Unit (~60 testiä, `tests/Unit/Communications/`)

Domain-objektit, value-objektit, exceptionit, mailer-adapterit (SMTP-koodi-parsija), kryptaus (round-trip + virhetilat), renderöijät (VarSubstituter / MarkdownRenderer / MjmlRenderer / Html2Text), use-casien logiikka InMemory-repoilla, audience-resolver.

### 9.2 Integration (~30 testiä, `tests/Integration/Communications/`, MySQL)

Pohjautuu `MigrationTestCase`-base-luokkaan. SqlRepository-toteutukset (JSON-serialisointi, FOR UPDATE SKIP LOCKED -lukitus, idempotentit insertit), cron-komentojen DB-side, 0.7-kytkennät (`EnqueuePaymentReminders` lukee `member_fee_invoices`).

### 9.3 Isolation (~5 testiä, `tests/Isolation/CommunicationsTenantIsolationTest.php`)

`IsolationTestCase`-base, daems + sahegroup rinnakkain. Outbox / suppression / newsletters / meetings / settings — kaikki tarkistettavat cross-tenant-näkymättömyydeltä.

### 9.4 E2E (~25 testiä, `tests/E2E/Communications/`, KernelHarness)

`InMemoryMailer` + InMemory-fake-repot. Composer happy-path, SMTP-not-configured-handling, audience tyhjä, marketing-newsletter opt-in -suodatus, outbox-filtterointi, retry-flow, template-CRUD, settings-CRUD, SMTP-test, suppression-management, preferences (transactional-yritys → 403), unsubscribe-token validointi (happy + tampered).

### 9.5 Authorization (10+ E2E-testiä)

Per-reitti minimi-pari: moderator → 403 sopivasti, member → 403 sopivasti, admin → onnistuu, GSA-cross-tenant onnistuu.

### 9.6 i18n-parity (1)

`CommunicationsParityTest` tarkistaa kaikkien `communications.*`-avaimien olevan 3 locale-tiedostossa.

### 9.7 PHPStan level 9

`composer analyse` palauttaa 0 virhettä uudelle koodille. Strict-typed `Domain\Communications\*`, ei `mixed`-paluuarvoja, ei template-vapaita `array`-tyyppejä.

---

## 10. Toteutus-aallot

| # | Aalto | Riippuu | Sisältö | Arvio |
| --- | --- | --- | --- | --- |
| **A** | Infra-foundation | — | Vendor-paketit, `DsnEncryptor` + libsodium, `APP_ENCRYPTION_KEY` .env, `module.json` + `config/modules.php`, sidebar-ryhmä, migraatio 098, i18n-avaimet, MJML-portin proof-of-concept | 1 päivä |
| **B** | Domain + repositoryt + settings | A | Kaikki domain-entiteetit, 8 repository-interfacea, SQL-toteutukset, settings + preferences -CRUD, audience-resolver, isolation-test-pohja | 2 päivää |
| **C** | Mailer + outbox + drain-cron | A+B | `MailerInterface` + adapterit, `DrainMailOutbox` + `mail:drain` cron, outbox-CRUD, outbox-UI-sivu, settings-sivu + SMTP-test | 2 päivää |
| **D** | Composer + 4 strikt-tyyppiä + Meeting MVP | A+B+C | 4 MJML-templatea, renderöijät, composer use-caset + UI, Meeting-thin + `CreateMeetingFromComposer`, template-override-UI | 3 päivää |
| **E** | Newsletter-blokit | A+B+C+D | `NewsletterBlock`-hierarkia (7 tyyppiä), JSON-serialisointi, newsletter-CRUD-use-caset, blokki-kokoonpanija-UI, audience-suodatus marketing-kategorialla | 2-3 päivää |
| **F** | Cron-triggerit + 0.7-integraatio | A+B+C+D (rinnakkain E:n kanssa) | `EnqueuePaymentReminders` + cron, `EnqueueLapseWarnings` + cron + § 4 -ennustus, idempotenssi, `crontab.example`, PaymentReminder + LapseWarning -MJML-templatet | 2 päivää |
| **G** | Suppression + GDPR + bounce-handling | A+B+C+D+E+F | Auto-suppression hard-bouncen jälkeen (UI ja manual-flow), suppression-CRUD-UI, public `/unsubscribe` + HMAC, frontend-route-lisäykset, marketing-templaten alapalkin unsubscribe-linkki | 1-2 päivää |
| **H** | Polish + test-sweep + smoke | A-G | Browser-smoke 5 sivua × 3 tenanttia, 4 testitasoa vihreänä, PHPStan 0, i18n-parity, BOTH-containers DI, migraatio-smoke | 1 päivä |

**Kokonaisarvio:** ~14 päivää = ~3 viikkoa Dev-Team-tahdilla (samaa luokkaa kuin 0.7).

**Branchi:** `communications-v1` off `dev` @ `86a0980`. Atomic commits per aalto. Ei pushaa ennen "pushaa"-pyyntöä.

### 10.1 0.7- ja 0.6b-kytkennät

0.8 ei muuta olemassaolevien taulujen skeemaa, mutta lukee:

- `member_fee_invoices` (0.7) — payment-reminder cron
- `users` + `user_tenants` (auth) — audience-resolver
- `tenants` (0.5) — tenant-resolusio
- `member_status_audit` (0.7) — lapse-ennustuslogiikka
- `board_decisions` (0.6b) — ryhmäviestin "hallitus"-audience-preset

**Olemassaolevien tiedostojen muutokset:**

- `bootstrap/app.php` — kaikki uudet DI-bindit
- `tests/Support/KernelHarness.php` — samat InMemory-fakeilla (BOTH-containers-rule)
- `bootstrap/console.php` — 3 (tai 4 jos retention) uutta cron-komentoa
- `Frontend/BackstageSidebar.php` — uusi `communications`-ryhmä GROUP_RANK 5, 4 hardcoded sub-itemia (kuten governance-pattern)
- `lang/{fi_FI,en_GB,sw_TZ}.php` — ~120 uutta avainta
- `.env.example` — `APP_ENCRYPTION_KEY=`
- `daem-society/public/index.php` — `/unsubscribe`-route delegaatioblokkiin
- `public/sites/_default/router.php` — sama route

### 10.2 Riskit + ennakkomitigaatiot

| Riski | Vaikutus | Mitigaatio |
| --- | --- | --- |
| `tijsverkoyen/mjml-php` pure-PHP-portti jäljessä MJML 4.x:ää | Brand-templatet eivät renderöidy | Wave A:n PoC-vaihe: jos vajaa → fallback Node-pohjaiseen `mjmlio/mjml-php`:hen Laragon-Node-asennuksella |
| BYO SMTP-konfigurointi vaikea debug-aata admin-puolelta | Yhdistys ei saa lähetyksiä toimimaan | SMTP-test-painike asetus-sivulla (synkroninen) + dashboard-toast "N lähettämätöntä viestiä — konfiguroi SMTP" |
| Bounce-koodien parsiminen Symfony-virheviestistä hauras | Hard-bounce voi mennä ohi → suppression jää lisäämättä | Unit-test ~20 todellista SMTP-virheviestiä (Postmark, Gmail, Mailgun, Outlook 365) + whitelist regex |
| MJML pure-PHP-portin mahdolliset XSS-ongelmat | Markdown-syöte voi vuotaa raakaa HTML:ää | `VarSubstituter` HTML-escapaa kaikki muuttujat **ennen** MJML-kompilaatiota; CommonMark safe-modessa |
| 500-vastaanottajan newsletter blokkaa drain-cronin minuutiksi | Muut viestit jonossa | Drainer poimii 50 riviä per ajo; 500 viestiä jakautuu 10 minuutille |
| GDPR retention 24kk -cron unohtuu | Henkilötietoja kertyy ikuisesti | Placeholder-cron-komento `mail:retention-cleanup` repoon Wave G:ssä; merkitään 0.8.x follow-upiksi jos aikaa ei jää H-aaltoon |

---

## 11. Out-of-scope + open questions

### 11.1 Eksplisiittisesti 0.8:n ulkopuolella

| Aihe | Mihin siirtyy | Perustelu |
| --- | --- | --- |
| Stripe / Visma -maksugateway-integraatio | Post-v1.0.0 | 0.7-aikana päätetty |
| Täysi Meeting-domain (äänestys, päätös, osallistuja-rekisteri, hallitus-toimet) | 0.9 | Q8 lukitsi thin Meeting -entiteetin |
| `/backstage/meetings`-hallintasivu | 0.9 | Käyttäjän päätös Q8:ssa |
| Kokouskutsu-RSVP-pohjaiset muistutukset | 0.9 (vaatii RSVP-tilan) | Q9:ssä keskusteltu |
| Tallennettavat audience-segmentit | 1.x | Q7 C-vaihtoehto |
| Audience-query-builder | 1.x | Q7 D-vaihtoehto |
| Per-tenant SMTP-domainin verifiointi-UI (DKIM CNAME) | 1.x | Q3 B+ -laajennus |
| Drag-and-drop -uutiskirje-editori (Unlayer/GrapesJS) | 1.x jos tarve | Q6 E-vaihtoehto |
| Sähköpostin inbound-käsittely | Out-of-scope | Yhdensuuntainen riittää 0.8 |
| Webhook-pohjaiset bounce-notifit | 1.x | 0.8 hoitaa SMTP-vastauksen koodista |
| Avaus-/klikkaus-tracking | Out-of-scope | GDPR-komplikaatioita |
| Kokouksen dokumentit platform-storageen | 0.10 Documents | 0.8 tallentaa ulkoiset URL:t |
| Multi-step kampanja-sekvenssit + A/B-testit | Out-of-scope | Ei yhdistys-käyttötapaus |
| Member-facing portal-UI preferences-CRUD:lle | 0.11 MemberPortal | Backend valmis 0.8:ssa, UI 0.11 |
| 24kk-retention-cron toteutus | 0.8.x follow-up | Sektio 10:n riski-taulussa |
| Avain-rotaatio `APP_ENCRYPTION_KEY` | 1.x | Out-of-scope 0.8 |

### 11.2 Operationaaliset oletukset (käyttäjän hyväksymät 2026-05-13)

Ks. § 2 taulukko O1-O8. Kaikki hyväksytty käyttäjän vastauksella 2026-05-13.

---

## 12. Viittaukset

- `docs/superpowers/specs/2026-05-12-membership-billing-v1-design.md` — 0.7 spec, viittaa 0.8:lle SMTP-/mail-infran rakentamisen
- `docs/planning/roadmap.md` — milestone-listaus
- Memory: `project_milestones_before_v1_release.md` — 0.5–0.12 -milestone-sarja
- Memory: `feedback_bootstrap_and_harness_must_both_wire.md` — DI-wiring BOTH-containers-rule
- Memory: `feedback_markdown_no_lint_errors.md` — markdownlint zero-warning -sääntö (tämä spec)
- Memory: `feedback_isolation_suite_flaky.md` — Isolation-suite flakiness (rerun ennen kuin syyttää uutta bugia)
- Säännöt PDF: `daem-society-saannot-2026.pdf` § 4, § 5, § 7 (kokouskutsu ≥14vrk)
