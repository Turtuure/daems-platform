# Membership Core v2 — Governance (Board + Decisions + Expulsions) (0.6b) — Design

**Date:** 2026-05-12
**Branch:** `membership-core-v2-governance` (off `dev`)
**Status:** Design — pending review
**Milestone:** 0.6b (second of three sub-milestones — 0.6a tier shipped 2026-05-12, 0.6c lifecycle follows)
**Bylaws reference:** Daem Society ry säännöt 18.4.2026 § 3 (jäsenryhmät), § 4 (eroaminen + erottaminen), § 7 (hallitus)

## Problem

Milestone 0.6a established the four-value `MembershipType` enum (SUPPORTING / BASIC / FULL / HONORARY) and the per-tenant `tenant_membership_subtiers` honor catalog. What it explicitly deferred:

- No board entity exists. There is no representation of who sits on a tenant's hallitus, what their terms are, or who the puheenjohtaja is.
- No decision-workflow exists. The bylaws require hallituksen yksimielinen päätös for BASIC-hyväksyntä, FULL-kutsuminen, and erottaminen — none of which can be modelled today.
- `users.membership_subtier` stays NULL because there is no award workflow.
- The existing `/backstage/members?view=applications` page lets a single admin approve a BASIC membership with one click — bylaws § 3 require board unanimity (or a standing delegation; see below).
- Erottamis-prosessi (§ 4) requires a kuulemismenettely with a hearing deadline and a valitusoikeus to the next vuosikokous. None of this is in the data model.

0.6b ships the governance infrastructure that closes these gaps.

## Goal

Deliver a **governance workflow engine** that models:

1. **Boards + board-members** with porrastetut toimikaudet, päätösvaltaisuus check, and a one-time GSA bootstrap to seat the first board.
2. **Board decisions** as a generic lifecycle (propose → vote → resolve) with typed payloads, threshold (unanimous|majority), mode (async|sync per § 7), vote_visibility (visible|anonymous per decision), and expiration.
3. **Standing delegations** that allow the board to delegate `approve_basic`, `invite_full`, or `award_subtier` to the admin role.
4. **Erottaminen** as a rich `member_expulsions` aggregate with hearing + statement phases that emits a board decision only after the hearing deadline closes; appeal storage shipped, appeal resolution deferred to 0.9.
5. **GSA overrides** for exceptional cases, with mandatory reason + dedicated audit table.
6. **Dashboard widgets + member-list actions** that surface pending decisions, eligible BASIC members, open expulsions, and active delegations.

## Non-goals (deferred)

- **0.7 MembershipBilling** integration (auto-erottaminen 2v maksamatta -trigger ⇒ programmatic `InitiateMemberExpulsion` calls when 0.7 ships).
- **0.8 Communications** — email/notification delivery for hearings + decisions waiting on your vote. 0.6b records audit events only; 0.8 wires real channels.
- **0.9 Meetings + Voting** — `meeting_reference` becomes an FK to a real meeting record; appeal resolution (vuosikokous votes on upholding/rejecting an expulsion appeal); hallituksen-vaalit replace the GSA bootstrap as the canonical way to renew the roster.
- **0.11 MemberPortal** — public-side appeal form, public board roster page. 0.6b is backstage-only.
- **HONORARY invitation workflow** — § 3 requires a vuosikokous vote on hallituksen motion; depends on 0.9 Meetings.
- **Tenant-specific work-day vs calendar-day hearing length** — calendar days only.
- **User-specific (not role-specific) delegations** — only the `admin` role can be a delegatee.
- **Sub-tier auto-award rules** (e.g. "5 vuoden jäsenyys → bronze automatically proposed") — manual proposal only in 0.6b.

## Architecture

### Clean-Architecture layers

```
src/Domain/Governance/                        (NEW namespace)
  Board.php                                   entity (id, tenantId, bootstrappedByUserId, bootstrappedAt, createdAt)
  BoardId.php                                 Uuid7Id subclass
  BoardMember.php                             entity (id, boardId, userId, role, termStartedAt, termEndsAt, termEndedAt, termEndedReason)
  BoardMemberId.php                           Uuid7Id
  BoardMemberRole.php                         enum: Chair | Member
  BoardMemberTermEndedReason.php              enum: Resigned | Removed | LostFullStatus | TermExpired

  BoardDecision.php                           aggregate
  BoardDecisionId.php                         Uuid7Id
  BoardDecisionType.php                       enum: ApproveBasic | InviteFull | Expel | AwardSubTier | RevokeSubTier | SubTierCrud | RemoveBoardMember | DelegateAuthority | RevokeDelegation
  BoardDecisionThreshold.php                  enum: Unanimous | Majority
  BoardDecisionMode.php                       enum: Async | Sync
  BoardDecisionStatus.php                     enum: Pending | Passed | Rejected | Expired | Withdrawn
  BoardDecisionVoteVisibility.php             enum: Visible | Anonymous
  BoardDecisionSubTierCrudOperation.php       enum: Create | Update | Delete

  BoardDecisionVote.php                       entity (id, decisionId, boardMemberId, vote, castAt)
  BoardDecisionVoteValue.php                  enum: Yes | No | Abstain

  BoardDelegation.php                         entity (id, tenantId, decisionType, delegatedToRole, sourceDecisionId, validFrom, revokedAt)
  BoardDelegationId.php                       Uuid7Id

  TenantGovernanceSettings.php                entity (tenantId, expulsionHearingDays, decisionExpirationDays)

  BoardRepositoryInterface.php
  BoardMemberRepositoryInterface.php
  BoardDecisionRepositoryInterface.php
  BoardDecisionVoteRepositoryInterface.php
  BoardDelegationRepositoryInterface.php
  TenantGovernanceSettingsRepositoryInterface.php

  Exception/
    BoardNotBootstrapped, BoardAlreadyBootstrapped,
    NotABoardMember, BoardMemberTermExpired,
    DecisionAlreadyResolved, AsyncRequiresUnanimous,
    VoteAlreadyCast, NotEligibleForFull,
    InsufficientQuorum, DelegationNotPermittedForType,
    DuplicateActiveDelegation, GsaOverrideRequiresReason,
    InvalidBootstrapRoster (size/role/chair), BoardCandidateNotFull,
    ExpulsionHearingNotElapsed, ExpulsionAlreadyAdvanced, AppealAlreadyFiled

src/Domain/Membership/                        (existing namespace, additions only)
  MemberExpulsion.php                         aggregate
  MemberExpulsionId.php                       Uuid7Id
  MemberExpulsionStatus.php                   enum: Hearing | AwaitingVote | Expelled | Rejected | Appealed
  MemberExpulsionRepositoryInterface.php

  MemberSubTierAward.php                      entity
  MemberSubTierAwardId.php                    Uuid7Id
  MemberSubTierAwardRepositoryInterface.php

  IsEligibleForFullMembership.php             domain service (pure function)

src/Domain/Audit/                             (NEW namespace — small)
  GsaOverride.php                             entity
  GsaOverrideId.php                           Uuid7Id
  GsaOverrideAction.php                       enum: ForceApproveBasic | (room for future)
  GsaOverrideRepositoryInterface.php

src/Application/Governance/
  BootstrapBoard.php
  CastBoardVote.php
  WithdrawBoardDecision.php
  ResolveBoardDecisionIfReady.php             called after every vote
  ExpireOverdueBoardDecisionsCron.php         called by the cron runner
  ResolveBoardDecisionExecutorRegistry.php    routes passed-decision to the right executor

  Propose/
    ProposeApproveBasic.php
    ProposeInviteFull.php
    ProposeAwardSubTier.php
    ProposeRevokeSubTier.php
    ProposeSubTierCrud.php
    ProposeRemoveBoardMember.php
    ProposeDelegateAuthority.php
    ProposeRevokeDelegation.php

  Delegate/
    ApproveBasicAsDelegate.php
    InviteFullAsDelegate.php
    AwardSubTierAsDelegate.php

  Executor/
    ApproveBasicExecutor.php                  implements BoardDecisionExecutorInterface
    InviteFullExecutor.php
    ExpelExecutor.php
    AwardSubTierExecutor.php
    RevokeSubTierExecutor.php
    SubTierCrudExecutor.php
    RemoveBoardMemberExecutor.php
    DelegateAuthorityExecutor.php
    RevokeDelegationExecutor.php

src/Application/Membership/                   (existing namespace, additions)
  InitiateMemberExpulsion.php
  SubmitExpulsionStatement.php
  AdvanceExpulsionToVote.php
  FileExpulsionAppeal.php

src/Application/Audit/
  GsaForceApproveBasic.php

src/Infrastructure/Adapter/Persistence/Sql/
  SqlBoardRepository.php
  SqlBoardMemberRepository.php
  SqlBoardDecisionRepository.php
  SqlBoardDecisionVoteRepository.php
  SqlBoardDelegationRepository.php
  SqlTenantGovernanceSettingsRepository.php
  SqlMemberExpulsionRepository.php
  SqlMemberSubTierAwardRepository.php
  SqlGsaOverrideRepository.php

src/Infrastructure/Adapter/Api/Controller/Governance/
  BoardController.php                         GET/POST/DELETE on /governance/board*
  BoardDecisionController.php                 GET/POST on /governance/decisions*
  ExpulsionController.php                     GET/POST on /governance/expulsions*
  DelegationController.php                    GET on /governance/delegations
  GsaOverrideController.php                   POST on /governance/gsa-overrides/*
  EligibilityController.php                   GET on /governance/eligibility/*
```

### Domain rules — the load-bearing invariants

These rules are enforced in the Domain layer and tested at the Unit level. They are NOT relaxable by Application or Infrastructure code.

1. **Mode/threshold coupling (§ 7):** `mode = Async` REQUIRES `threshold = Unanimous`. Constructor of `BoardDecision` throws `AsyncRequiresUnanimous` if this is violated.
2. **Quorum (§ 7):** päätösvaltainen kun pj (chair) on aktiivinen JA äänestäneitä on `>= ceil(activeCount / 2)`. Tarkistus juoksee `ResolveBoardDecisionIfReady`-vaiheessa. Async-unanimous ohittaa quorum-tarkistuksen koska se vaatii kaikkien aktiivisten äänen.
3. **FULL-only board candidates (§ 7):** `BootstrapBoard` ja `ProposeAddBoardMember` (jos myöhemmin lisätään) rejektoivat userId:n jos `users.membership_type != 'FULL'` tai `users.membership_status != 'active'`.
4. **One board per tenant:** `UNIQUE KEY uniq_board_tenant ON boards(tenant_id)`. `BootstrapBoard` throws `BoardAlreadyBootstrapped` jos rivi on jo olemassa.
5. **Board size 1–5 (§ 7):** Bootstrap-validointi tarkistaa `1 <= count(members) <= 5` ja tasan yhden `Chair`-roolin. Runtime-mutaatio (RemoveBoardMember) ei voi rikkoa alarajaa — yritys poistaa viimeistä jäsentä → `LastBoardMemberCannotBeRemoved`.
6. **Term-validation lähtee tenantille:** Bootstrap-validointi tarkistaa että `term_ends_at - term_started_at` on 1–4 vuoden välillä. Porrastus (pj 2v, puolet muista 2v, toinen puoli 4v) on UI-ohje GSA:lle — ei domain-tason validaatiota koska olemassa olevien yhdistysten siirtymä keskelle toimikautta ei mahdu siihen tiukkaan sääntöön.
7. **Vote-cast eligibility:** `CastBoardVote` tarkistaa että äänestäjä on aktiivinen board-jäsen (`term_ended_at IS NULL AND term_started_at <= NOW AND term_ends_at > NOW`) ja että decision on `Pending`.
8. **Re-vote allowed:** Sama jäsen voi muuttaa äänensä pending-decisionissa. `UNIQUE(decision_id, board_member_id)` + UPSERT.
9. **Delegation whitelist:** Vain `ApproveBasic`, `InviteFull`, `AwardSubTier` voidaan delegoida; `ProposeDelegateAuthority` rejektoi muut tyypit `DelegationNotPermittedForType`-poikkeuksella.
10. **Standing-delegation precedence:** kun delegaatio on voimassa, propose-use case ohittaa decision-keräyksen ja luo `BoardDecision`-rivin `status=Passed, viaDelegation=true, resolvedAt=NOW`, ja kutsuu executoria heti. Audit-rivi säilyy.
11. **Eligibility-precondition 12 kk (§ 3):** `ProposeInviteFull` JA `InviteFullAsDelegate` molemmat kutsuvat `IsEligibleForFullMembership(userId, tenantId)`. Domain-service: `users.membership_type == 'BASIC' AND users.membership_status == 'active' AND users.membership_started_at <= NOW - INTERVAL 12 MONTH`. Sitoutuminen on hallituksen harkinta (vapaateksti `reason`-kentässä, ei koodi-tarkistus).
12. **Expulsion-proposer:** Vain aktiivinen board-jäsen voi kutsua `InitiateMemberExpulsion`. Admin ilman board-paikkaa ei voi.
13. **Expulsion-advancement:** `AdvanceExpulsionToVote` vain pj:lle. Vaatii joko `hearing_deadline_at <= NOW` TAI `statement_received_at IS NOT NULL`.
14. **Sub-tier-appliesTo-matching:** `ProposeAwardSubTier` validoi että target user:n `membership_type` matchaa subtier:n `applies_to` (SUPPORTING tai BASIC).
15. **Award/revoke preconditions:**
    - `ProposeAwardSubTier` rejektoi jos `users.membership_subtier IS NOT NULL` (käytä ensin RevokeSubTier). Vaihtoehtoisesti hallitus tekee yhden `RevokeSubTier`-decisionin ja sen jälkeen `AwardSubTier`-decisionin.
    - `ProposeRevokeSubTier` rejektoi jos `users.membership_subtier IS NULL` (`NoActiveSubTierToRevoke`).
16. **Sub-tier-CRUD body-validaatio:**
    - `operation = create`: pakolliset `sub_tier_slug`, `name`, `rank_order`, `applies_to`. Rejektoi jos `(tenant_id, applies_to, sub_tier_slug)` -kombinaatio on jo olemassa (`DuplicateSubTierSlug`).
    - `operation = update`: pakollinen `applies_to + sub_tier_slug` (avain); ainakin yksi `name` / `rank_order` / uusi `sub_tier_slug` (rename) annettu.
    - `operation = delete`: pakollinen `applies_to + sub_tier_slug`. Rejektoi jos taulu `member_sub_tier_awards` viittaa subtieriin aktiivisilla (revoked_at IS NULL) myönnöillä — käyttäjien on ensin revoke:ttava (`SubTierInUse`).
17. **Vote-storage transparenssi:** Yksittäiset äänet tallennetaan AINA `board_decision_votes`-tauluun riippumatta `vote_visibility`-arvosta. `vote_visibility = anonymous` vaikuttaa vain API-vastauksen redaktioon (UI näyttää aggregaatin, ei rivejä). Domain-tason audit-eheys säilyy. GSA:lle ei toistaiseksi tarjota force-reveal-endpointia (lykätään tarpeen tullen).

### Decision-lifecycle (state machine)

```
                  withdraw (proposer or chair)
                          ┌─────────────────┐
                          ▼                 │
[propose] ──→ Pending ──────────────────────→ Withdrawn
                │
                ├─→ ResolveBoardDecisionIfReady tunnistaa threshold + quorum täyttyy → Passed → executor.execute()
                │
                ├─→ ResolveBoardDecisionIfReady tunnistaa ettei voida enää saavuttaa thresholdia → Rejected
                │
                └─→ ExpireOverdueBoardDecisionsCron havaitsee expires_at < NOW → Expired
```

**Resoluutio-tarkistuksen logiikka** (Unit-tested per case):

```
ActiveBoard = SELECT * FROM board_members WHERE board_id = ? AND term_ended_at IS NULL
              AND term_started_at <= NOW AND term_ends_at > NOW
yes      = count(votes WHERE vote=Yes)
no       = count(votes WHERE vote=No)
abstain  = count(votes WHERE vote=Abstain)
voted    = yes + no + abstain
active   = count(ActiveBoard)

if threshold == Unanimous:
    if no > 0 OR abstain > 0          → Rejected
    if yes == active                  → Passed
    else                              → Pending

if threshold == Majority:
    quorum_met         = (chair in voters) AND (voted >= ceil(active / 2))
    max_possible_yes   = (active - voted) + yes
    strict_majority    = floor(active / 2) + 1   // > active/2

    if quorum_met AND yes >= strict_majority     → Passed
    if max_possible_yes < strict_majority        → Rejected  // even all remaining yes can't reach majority
    else                                          → Pending
```

Worked examples:

- active=5, votes=[no,no,no] → max_possible_yes=2, strict_majority=3 → Rejected.
- active=5, votes=[yes,yes,no] (chair voted yes) → quorum met, yes=2 < 3 → Pending.
- active=5, votes=[yes,yes,yes,no] (chair in) → quorum met, yes=3 ≥ 3 → Passed.
- active=4, votes=[yes,yes] (chair in) → quorum met (ceil(4/2)=2), yes=2 < strict_majority=3 → Pending.
- active=4, votes=[yes,yes,no,no] (chair in) → quorum met, yes=2 < 3, max_possible_yes=2 < 3 → Rejected.

For mode = Async + Unanimous, quorum is implicit — passing requires every active member to cast Yes, so the formula reduces to "all active voted Yes".

### Delegation lifecycle

- Delegation is created by an executor running `DelegateAuthorityExecutor` after a `Pending → Passed` resolution of a `DelegateAuthority` decision.
- Delegation is voimassa kun `valid_from <= NOW AND (revoked_at IS NULL OR revoked_at > NOW)`.
- Yksi voimassa oleva delegaatio per `(tenant_id, decision_type, delegated_to_role)`. Jos uusi `DelegateAuthority`-päätös passaa olemassa olevan kanssa samoilla parametreilla, `DelegateAuthorityExecutor` kumoaa ensin vanhan (`revoked_at = NOW`) ja luo uuden — audit-trail säilyttää molemmat.
- `RevokeDelegationExecutor` asettaa `revoked_at = NOW`. Tuleva propose-flow ei enää käytä delegaatiota.

### Expulsion sub-flow (oma rich aggregate)

```
[InitiateMemberExpulsion] ──→ member_expulsions row
                              status = Hearing
                              hearing_deadline_at = NOW + tenant_settings.expulsion_hearing_days
                              ↓
                              (optional) [SubmitExpulsionStatement]
                              status = Hearing (with statement)
                              ↓
                              (chair only) [AdvanceExpulsionToVote]
                              (preconditions: hearing_deadline elapsed OR statement received)
                              status = AwaitingVote
                              decision_id = INSERT board_decisions (type=Expel, threshold=Unanimous, mode=Sync)
                              ↓
                              [CastBoardVote × n] → ResolveBoardDecisionIfReady
                              ↓
                              if Passed: ExpelExecutor runs
                                          UPDATE users SET membership_status='expelled',
                                                           membership_ended_at=NOW,
                                                           membership_status_reason=?
                                          UPDATE member_expulsions SET expelled_at=NOW, status=Expelled
                              if Rejected: UPDATE member_expulsions SET status=Rejected
                              ↓
                              (any time after Expelled, until vuosikokous) [FileExpulsionAppeal]
                              UPDATE member_expulsions SET appeal_filed_at=NOW, appeal_text=?, status=Appealed
                              (0.9 wires the real appeal resolution; 0.6b stores only)
```

### Bootstrap flow

```
[GSA opens TenantManagement → Tenant detail → "Hallitus"-tab]
   ↓
   (no board exists for this tenant)
   Show "Istuta hallitus" form: select 1–5 FULL members from tenant,
   assign exactly one Chair, set term_started_at + term_ends_at per member.
   ↓
[BootstrapBoard use case]
   - validate: board does not exist yet
   - validate: 1 <= count <= 5
   - validate: exactly one chair
   - validate: every user is FULL + active in this tenant
   - validate: every term_ends_at - term_started_at in [1y, 4y]
   - INSERT boards row (tenant_id, bootstrapped_by_user_id=GSA, bootstrapped_at=NOW)
   - INSERT board_members rows
   - INSERT audit event
```

After bootstrap, the GSA-bootstrap button disappears from the UI. All subsequent roster changes go through `Resign` (self-service for board member; not yet wired in 0.6b — covered as a TODO at the end of this spec) or `ProposeRemoveBoardMember` (board decision, unanimous, sync).

### GSA override

`POST /governance/gsa-overrides/approve-basic` is the only override exposed in 0.6b. It:

- Requires GSA auth.
- Requires body `reason` (≥10 chars).
- Skips the standard board-decision / delegation flow entirely.
- Calls the same `ApproveBasicExecutor` logic that a `Passed` decision would.
- Records a row in `gsa_overrides` with action, target_application_id, gsa_user_id, reason, performed_at.

Future override actions (force-invite-full, force-award-subtier, force-revoke-subtier) can be added with the same pattern when needed. Out of scope here.

## Database schema

Migrations 078–088:

```sql
-- 078_create_boards.sql
CREATE TABLE boards (
    id                       CHAR(36)  NOT NULL,
    tenant_id                CHAR(36)  NOT NULL,
    bootstrapped_by_user_id  CHAR(36)  NOT NULL,
    bootstrapped_at          DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at               DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_board_tenant (tenant_id),
    CONSTRAINT fk_board_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_board_bootstrapper FOREIGN KEY (bootstrapped_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 079_create_board_members.sql
CREATE TABLE board_members (
    id                  CHAR(36)     NOT NULL,
    board_id            CHAR(36)     NOT NULL,
    user_id             CHAR(36)     NOT NULL,
    role                ENUM('chair','member') NOT NULL,
    term_started_at     DATETIME     NOT NULL,
    term_ends_at        DATETIME     NOT NULL,
    term_ended_at       DATETIME     NULL,
    term_ended_reason   ENUM('resigned','removed','lost_full_status','term_expired') NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_board (board_id),
    KEY idx_user (user_id),
    KEY idx_active (board_id, term_ended_at, term_ends_at),
    CONSTRAINT fk_bm_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_bm_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 080_create_board_decisions.sql
CREATE TABLE board_decisions (
    id                          CHAR(36)     NOT NULL,
    board_id                    CHAR(36)     NOT NULL,
    decision_type               ENUM('approve_basic','invite_full','expel','award_subtier','revoke_subtier','subtier_crud','remove_board_member','delegate_authority','revoke_delegation') NOT NULL,
    threshold                   ENUM('unanimous','majority') NOT NULL,
    mode                        ENUM('async','sync') NOT NULL,
    vote_visibility             ENUM('visible','anonymous') NOT NULL,
    status                      ENUM('pending','passed','rejected','expired','withdrawn') NOT NULL DEFAULT 'pending',
    proposed_by_user_id         CHAR(36)     NOT NULL,
    proposed_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at                  DATETIME     NOT NULL,
    resolved_at                 DATETIME     NULL,
    meeting_reference           VARCHAR(255) NULL,
    withdrawal_reason           TEXT         NULL,
    via_delegation              TINYINT(1)   NOT NULL DEFAULT 0,
    delegation_id               CHAR(36)     NULL,

    payload_target_user_id      CHAR(36)     NULL,
    payload_application_id      CHAR(36)     NULL,
    payload_sub_tier_slug       VARCHAR(30)  NULL,
    payload_sub_tier_name       VARCHAR(80)  NULL,
    payload_sub_tier_rank       INT          NULL,
    payload_sub_tier_applies_to ENUM('SUPPORTING','BASIC') NULL,
    payload_sub_tier_operation  ENUM('create','update','delete') NULL,
    payload_board_member_id     CHAR(36)     NULL,
    payload_delegation_type     VARCHAR(40)  NULL,
    payload_delegated_to_role   VARCHAR(40)  NULL,
    payload_delegation_revoke_id CHAR(36)    NULL,
    payload_reason              TEXT         NULL,

    PRIMARY KEY (id),
    KEY idx_board_status (board_id, status),
    KEY idx_type (decision_type),
    KEY idx_expires (status, expires_at),
    CONSTRAINT fk_bd_board FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE,
    CONSTRAINT fk_bd_proposer FOREIGN KEY (proposed_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 081_create_board_decision_votes.sql
CREATE TABLE board_decision_votes (
    id                CHAR(36)  NOT NULL,
    decision_id       CHAR(36)  NOT NULL,
    board_member_id   CHAR(36)  NOT NULL,
    vote              ENUM('yes','no','abstain') NOT NULL,
    cast_at           DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_vote (decision_id, board_member_id),
    KEY idx_decision (decision_id),
    CONSTRAINT fk_v_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id) ON DELETE CASCADE,
    CONSTRAINT fk_v_member FOREIGN KEY (board_member_id) REFERENCES board_members(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 082_create_board_delegations.sql
CREATE TABLE board_delegations (
    id                   CHAR(36)     NOT NULL,
    tenant_id            CHAR(36)     NOT NULL,
    decision_type        ENUM('approve_basic','invite_full','award_subtier') NOT NULL,
    delegated_to_role    ENUM('admin') NOT NULL,
    source_decision_id   CHAR(36)     NOT NULL,
    valid_from           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at           DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_active (tenant_id, decision_type, delegated_to_role, revoked_at),
    CONSTRAINT fk_deleg_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_deleg_source FOREIGN KEY (source_decision_id) REFERENCES board_decisions(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 083_create_member_expulsions.sql
CREATE TABLE member_expulsions (
    id                       CHAR(36)  NOT NULL,
    tenant_id                CHAR(36)  NOT NULL,
    target_user_id           CHAR(36)  NOT NULL,
    proposed_by_user_id      CHAR(36)  NOT NULL,
    reason                   TEXT      NOT NULL,
    hearing_deadline_at      DATETIME  NOT NULL,
    statement_text           TEXT      NULL,
    statement_received_at    DATETIME  NULL,
    decision_id              CHAR(36)  NULL,
    decided_at               DATETIME  NULL,
    expelled_at              DATETIME  NULL,
    appeal_filed_at          DATETIME  NULL,
    appeal_text              TEXT      NULL,
    status                   ENUM('hearing','awaiting_vote','expelled','rejected','appealed') NOT NULL DEFAULT 'hearing',
    created_at               DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant_status (tenant_id, status),
    KEY idx_target (target_user_id),
    CONSTRAINT fk_exp_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_exp_user FOREIGN KEY (target_user_id) REFERENCES users(id),
    CONSTRAINT fk_exp_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 084_create_member_sub_tier_awards.sql
CREATE TABLE member_sub_tier_awards (
    id                    CHAR(36)     NOT NULL,
    tenant_id             CHAR(36)     NOT NULL,
    user_id               CHAR(36)     NOT NULL,
    sub_tier_slug         VARCHAR(30)  NOT NULL,
    decision_id           CHAR(36)     NOT NULL,
    awarded_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at            DATETIME     NULL,
    revoke_decision_id    CHAR(36)     NULL,
    PRIMARY KEY (id),
    KEY idx_user_active (user_id, revoked_at),
    KEY idx_tenant (tenant_id),
    CONSTRAINT fk_award_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_award_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_award_decision FOREIGN KEY (decision_id) REFERENCES board_decisions(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 085_create_tenant_governance_settings.sql
CREATE TABLE tenant_governance_settings (
    tenant_id                  CHAR(36)  NOT NULL,
    expulsion_hearing_days     INT       NOT NULL DEFAULT 14,
    decision_expiration_days   INT       NOT NULL DEFAULT 60,
    updated_at                 DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_tgs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 086_create_gsa_overrides.sql
CREATE TABLE gsa_overrides (
    id              CHAR(36)  NOT NULL,
    gsa_user_id     CHAR(36)  NOT NULL,
    tenant_id       CHAR(36)  NOT NULL,
    action          ENUM('force_approve_basic') NOT NULL,
    target_id       CHAR(36)  NOT NULL,
    reason          TEXT      NOT NULL,
    performed_at    DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tenant (tenant_id),
    KEY idx_gsa (gsa_user_id),
    CONSTRAINT fk_gsa_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    CONSTRAINT fk_gsa_user FOREIGN KEY (gsa_user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

```sql
-- 087_add_invited_to_full_at_to_users.sql
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS invited_to_full_at DATETIME NULL AFTER membership_ended_at;
```

```php
<?php
// 088_seed_governance_settings_defaults.php
// Seed default governance settings (14 vrk hearing + 60 vrk expiration) for
// every existing tenant. Idempotent — INSERT IGNORE keeps re-runs safe.
//
// @var \PDO $pdo  — provided by MigrationRunner

$insert = $pdo->prepare(
    'INSERT IGNORE INTO tenant_governance_settings (tenant_id, expulsion_hearing_days, decision_expiration_days)
     VALUES (?, 14, 60)'
);
$tenants = $pdo->query('SELECT id FROM tenants')->fetchAll(\PDO::FETCH_COLUMN);
foreach ($tenants as $tenantId) {
    $insert->execute([$tenantId]);
}
```

Numbers 089–090 are reserved as buffers in case an implementation task splits a table into a follow-up migration.

## API contract

All endpoints under `/api/v1/backstage/governance/*`. Tenant context resolved by host header via existing `TenantContextMiddleware`. Auth header `X-Daems-Session` carries identity.

### Boards & members

```
GET /governance/board
    Auth: tenant member (any role)
    Response: { board: { id, bootstrapped_by, bootstrapped_at } | null,
                members: [{ id, user_id, user_name, role, term_started_at, term_ends_at,
                            term_ended_at?, term_ended_reason? }] }
    If board null → tells UI to show bootstrap CTA (if caller is GSA).

POST /governance/board/bootstrap
    Auth: GSA only
    Body: { members: [{ user_id, role: chair|member, term_started_at, term_ends_at }] }
    Errors: 403 not-GSA, 409 board-already-bootstrapped, 422 validation (size, chair count, FULL status, term length)

GET /governance/board/eligible-users
    Auth: GSA or tenant admin
    Response: { users: [{ id, name, email, member_number }] }  // FULL + active in this tenant
```

### Decisions

```
GET /governance/decisions?status=pending&type=approve_basic&my_pending=true
    Auth: board member, admin, or GSA
    Response: { data: [{ id, type, threshold, mode, vote_visibility, status,
                         proposed_by, proposed_at, expires_at, payload_summary,
                         tally: { yes, no, abstain } }] }

GET /governance/decisions/{id}
    Auth: as above
    Response: { decision: {...},
                votes: [{ board_member_id, name, vote, cast_at }]  // empty array if anonymous AND viewer hasn't voted
                                                                    // aggregate-only otherwise }

POST /governance/decisions/approve-basic           body: { application_id, vote_visibility }
POST /governance/decisions/invite-full              body: { user_id, vote_visibility, reason }
POST /governance/decisions/award-subtier            body: { user_id, sub_tier_slug, vote_visibility, reason, meeting_reference }
POST /governance/decisions/revoke-subtier           body: { user_id, vote_visibility, reason, meeting_reference }
POST /governance/decisions/subtier-crud             body: { operation, sub_tier_slug?, name?, rank_order?, applies_to?, vote_visibility, meeting_reference }
POST /governance/decisions/remove-board-member      body: { board_member_id, vote_visibility, reason, meeting_reference }
POST /governance/decisions/delegate-authority       body: { decision_type, delegated_to_role: admin, vote_visibility, meeting_reference }
POST /governance/decisions/revoke-delegation        body: { delegation_id, reason, meeting_reference }
    Auth (all propose endpoints): active board member
    Response: 201 { decision: {...} }
              200 { decision: { status: passed, via_delegation: true } } if delegation took precedence
    Errors: 422 (eligibility, async-requires-unanimous, applies-to-mismatch, …), 403 (not-board-member)

POST /governance/decisions/{id}/vote                body: { vote: yes|no|abstain }
    Auth: active board member
    Response: 200 { decision: { ...possibly-resolved... } }
    Errors: 409 (already-resolved, term-expired), 403 (not-on-this-board)

POST /governance/decisions/{id}/withdraw            body: { withdrawal_reason }
    Auth: proposer OR chair
    Response: 200 { decision: { status: withdrawn } }
```

### Expulsions

```
GET  /governance/expulsions?status=hearing
GET  /governance/expulsions/{id}
POST /governance/expulsions                          body: { target_user_id, reason }
POST /governance/expulsions/{id}/statement           body: { statement_text }
POST /governance/expulsions/{id}/advance-to-vote     (no body)
POST /governance/expulsions/{id}/appeal              body: { appeal_text }
```

### Delegations

```
GET  /governance/delegations
    Response: { data: [{ id, decision_type, delegated_to_role, valid_from, source_decision_id }] }
```

### GSA overrides

```
POST /governance/gsa-overrides/approve-basic         body: { application_id, reason }
    Auth: GSA only; reason ≥ 10 chars
    Response: 200 + audit row
```

### Eligibility queries

```
GET /governance/eligibility/full-membership
    Auth: board member or admin
    Response: { data: [{ user_id, name, member_number, membership_started_at, months_since_join }] }
```

## Backstage UI

Sidebar group **"Hallinto"** (i18n key `backstage.sidebar.governance`), gated to tenants where `boards` row exists. For GSA on a tenant without a board, a banner on the tenant-detail page shows "Hallitus ei ole vielä istutettu" with a Bootstrap CTA — that's the only entry-point before the board exists.

### Pages

| Path | Purpose | Roles |
|------|---------|-------|
| `/backstage/governance/board` | Board roster. Cards per member showing role/term/days-remaining. "Ehdota poistoa" button → ProposeRemoveBoardMember dialog. Bootstrap form for GSA when board empty. | Board, admin, GSA |
| `/backstage/governance/decisions` | List view. Filters: status, type, my_pending. Inline tally + Vote button. | Board, admin, GSA |
| `/backstage/governance/decisions/new` | New-decision wizard. Choose type → type-specific form. Mode + vote_visibility selector. meeting_reference field appears when mode=sync. | Board only |
| `/backstage/governance/decisions/{id}` | Single decision. Payload summary. Vote panel (yes/no/abstain). Vote-list (visible/anonymous handling). Withdraw button for proposer/chair. | Board, admin, GSA |
| `/backstage/governance/expulsions` | List. Status filters. | Board, admin |
| `/backstage/governance/expulsions/new` | Initiate expulsion: target user picker, reason textarea, hearing_deadline preview. | Board only |
| `/backstage/governance/expulsions/{id}` | Full expulsion view. Statement editor (for target user). Advance-to-vote button for chair (when allowed). Decision link. Appeal panel. | Board, admin, target user (statement only) |
| `/backstage/governance/delegations` | Active delegations. "Ehdota peruutusta" button → ProposeRevokeDelegation. | Board, admin |
| `/backstage/governance/settings` | tenant_governance_settings editor: expulsion_hearing_days, decision_expiration_days. Read-only for board members; editable for admin or GSA. | Board (read), admin/GSA (edit) |

### Dashboard widgets

| Widget | Span | Audience | Description |
|--------|------|----------|-------------|
| `PendingDecisionsForMeKpiWidget` | 1 | Board only | Count of pending decisions awaiting this user's vote. Click → /governance/decisions?my_pending=true |
| `EligibleForFullMembershipWidget` | 2 | Board, admin | Table of BASIC members with months_since_join ≥ 12. Inline "Ehdota FULL-kutsua" button. |
| `OpenExpulsionsKpiWidget` | 1 | Board, admin | Count of expulsions in `hearing` or `awaiting_vote`. Click → /governance/expulsions?status=hearing,awaiting_vote |
| `DelegationsActiveKpiWidget` | 1 | Board, admin | Count of active delegations. |

All four widgets added to admin + GSA default layouts via `DefaultLayouts::adminDefault()` / `gsaDefault()`. `DefaultLayoutsTest` expected widget count rises from 9 → 13.

### Members-page integrations

- Members list row action menu (board-only): "Ehdota erottamista" → opens expulsion-initiation modal.
- Member detail page (board-only): "Ehdota FULL-kutsua" (visible if eligible), "Ehdota sub-tier-myöntämistä" (always, opens picker).
- Applications list row "Hyväksy" button:
  - If `BoardDelegation(approve_basic, admin)` voimassa → direct approve (audit `via_delegation=true`).
  - Else → opens propose-decision modal that creates an `approve_basic` decision.

## i18n keys

Add to `lang/{fi_FI,en_GB,sw_TZ}.php`. Approximately 95 new keys × 3 locales.

Sidebar:
- `backstage.sidebar.governance` = Hallinto / Governance / Utawala

Board:
- `governance.board.title`
- `governance.board.bootstrap.title`, `governance.board.bootstrap.cta`, `governance.board.bootstrap.help`
- `governance.board.members.empty`
- `governance.board.member.role.chair`, `governance.board.member.role.member`
- `governance.board.member.term_starts`, `governance.board.member.term_ends`, `governance.board.member.days_remaining`
- `governance.board.member.ended.resigned`, `governance.board.member.ended.removed`, `governance.board.member.ended.lost_full_status`, `governance.board.member.ended.term_expired`

Decisions:
- `governance.decision.type.{approve_basic,invite_full,expel,award_subtier,revoke_subtier,subtier_crud,remove_board_member,delegate_authority,revoke_delegation}`
- `governance.decision.threshold.{unanimous,majority}`
- `governance.decision.mode.{async,sync}`
- `governance.decision.status.{pending,passed,rejected,expired,withdrawn}`
- `governance.decision.vote.{yes,no,abstain}`
- `governance.decision.vote_visibility.{visible,anonymous}`
- `governance.decision.action.{propose,vote,withdraw}`
- `governance.decision.tally`, `governance.decision.expires_in_days`, `governance.decision.via_delegation`
- `governance.decision.meeting_reference.label`, `governance.decision.meeting_reference.placeholder`
- `governance.decision.subtier_crud.operation.{create,update,delete}`

Expulsions:
- `governance.expulsion.status.{hearing,awaiting_vote,expelled,rejected,appealed}`
- `governance.expulsion.hearing_deadline`
- `governance.expulsion.statement.label`, `governance.expulsion.statement.empty`, `governance.expulsion.statement.submit`
- `governance.expulsion.advance_to_vote`, `governance.expulsion.advance_blocked_until_deadline`
- `governance.expulsion.appeal.label`, `governance.expulsion.appeal.submit`, `governance.expulsion.appeal.note_deferred_to_next_meeting`

Delegations:
- `governance.delegation.title`
- `governance.delegation.delegated_to.admin`
- `governance.delegation.active_since`
- `governance.delegation.revoke`

Settings:
- `governance.settings.title`
- `governance.settings.expulsion_hearing_days.label`, `governance.settings.expulsion_hearing_days.help`
- `governance.settings.decision_expiration_days.label`, `governance.settings.decision_expiration_days.help`

Eligibility:
- `governance.eligibility.full_membership.title`, `governance.eligibility.full_membership.empty`
- `governance.eligibility.full_membership.months_since_join`

GSA overrides:
- `governance.gsa_override.approve_basic.title`, `governance.gsa_override.approve_basic.reason.label`, `governance.gsa_override.approve_basic.confirm`

Errors (mapped from domain exceptions):
- `governance.error.{not_a_board_member, async_requires_unanimous, board_already_bootstrapped, board_not_bootstrapped, not_eligible_for_full, vote_already_cast, decision_already_resolved, insufficient_quorum, delegation_not_permitted_for_type, duplicate_active_delegation, gsa_override_requires_reason, invalid_bootstrap_roster_size, invalid_bootstrap_roster_chair, board_candidate_not_full, expulsion_hearing_not_elapsed, expulsion_already_advanced, appeal_already_filed, last_board_member_cannot_be_removed}`

Dashboard widgets:
- `backstage.dashboard.widget.pending_decisions_for_me_kpi.{label,description}`
- `backstage.dashboard.widget.eligible_for_full_membership.{label,description}`
- `backstage.dashboard.widget.open_expulsions_kpi.{label,description}`
- `backstage.dashboard.widget.delegations_active_kpi.{label,description}`

Parity is enforced by the existing i18n parity test that diffs key-sets across the three locale files.

## Testing

| Suite | Coverage |
|-------|----------|
| Unit | `BoardDecision` state-machine (every transition + invariant); `BoardDecisionThreshold` resolution logic per (threshold × quorum × vote-distribution) matrix; `BoardDelegation::isActive` boundary (valid_from = NOW, revoked_at = NOW); `MemberExpulsion` state machine; `IsEligibleForFullMembership` 12 kk boundary (exact 12 months / 11mo 30d / 12mo 1d); `MembershipType::allowsSubTier` (existing) + bootstrap-validation invariants; quorum calc (chair missing, <ceil(active/2), exactly ceil); `BoardMember::isActive` (date boundaries). |
| Integration | Each SQL repository CRUD against `MigrationTestCase`; cron-task expiration (`ExpireOverdueBoardDecisionsCron` flips `Pending→Expired` when `expires_at < NOW`); executor atomicity (Expel-executor updates `users` + `member_expulsions` in one transaction; failure rolls both back); idempotency of `088_seed_governance_settings_defaults.php`. |
| Isolation | `BoardIsolationTest`, `BoardDecisionIsolationTest`, `MemberExpulsionIsolationTest`, `BoardDelegationIsolationTest`, `MemberSubTierAwardIsolationTest`, `GsaOverrideIsolationTest`. Each verifies tenant A's data is invisible to tenant B's repository calls. Uses existing `IsolationTestCase` base which seeds `daems` + `sahegroup`. |
| E2E | (1) GSA bootstrap → propose approve_basic → 3 board members vote yes → application status flips to `approved`, user row created. (2) Propose invite_full for a BASIC user with < 12 months → 422 `not_eligible_for_full`. (3) Initiate expulsion → submit statement → advance-to-vote → board votes unanimous yes → user `membership_status='expelled'`. (4) Delegate approve_basic to admin → admin POSTs approve-basic → decision auto-passes with `via_delegation=true`, `member_applications` flips. (5) Async + majority propose → 400 `async_requires_unanimous`. (6) GSA override force-approves basic → audit row in `gsa_overrides`, no board decision created. (7) Propose award_subtier where subtier.applies_to mismatches user.membership_type → 422 applies-to-mismatch. |
| Static | PHPStan level 9 = 0 errors after every commit. |
| i18n parity | fi_FI / en_GB / sw_TZ all hold the same ~95 new keys. |

Per-commit test discipline (matches 0.6a): every commit ends `Pending → Passed`-style: `composer analyse && composer test && composer test:e2e` all green before commit.

## DI wiring (BOTH containers)

Each new class binds in:

- `bootstrap/app.php` — `Sql*Repository` + production `*Executor` instances.
- `tests/Support/KernelHarness.php` — `InMemory*Repository` fakes under `tests/Support/Fake/` + executor fakes that simulate effects against the in-memory store.

In-memory fakes needed:
- `InMemoryBoardRepository`
- `InMemoryBoardMemberRepository`
- `InMemoryBoardDecisionRepository`
- `InMemoryBoardDecisionVoteRepository`
- `InMemoryBoardDelegationRepository`
- `InMemoryTenantGovernanceSettingsRepository`
- `InMemoryMemberExpulsionRepository`
- `InMemoryMemberSubTierAwardRepository`
- `InMemoryGsaOverrideRepository`

The harness seeds default governance settings (14 + 60) for the test tenant on construction so E2E tests can immediately propose/initiate without a per-test seed dance.

## Module Registry posture

Governance is a **core platform capability**, not a module. Reasoning: every tenant has a hallitus by § 7 — there is no "tenant without a board" mode. Therefore:

- No new entry in `config/modules.php`.
- Routes `/api/v1/backstage/governance/*` and `/backstage/governance/*` register unconditionally in `public/backstage/router.php` and `api-router.php`.
- Dashboard widgets register unconditionally for board members; non-board users simply do not see them (role gate, not module gate).

If a future tenant truly does not want governance features (unlikely given the bylaws requirement), the tenant can be left in the un-bootstrapped state — the sidebar will not render the "Hallinto" group because the gate is `boards row exists`.

## Migration of currently running tenants

- The 13 migrations add tables and one column; no existing data is mutated.
- `088_seed_governance_settings_defaults.php` adds one row per tenant (idempotent).
- Existing tenants (`daems`, `sahegroup`) start in the "no board bootstrapped" state. The GSA must explicitly bootstrap each one via the UI before any governance feature is visible.
- The Members → Applications page behaviour does not change until a board exists. With no board, the existing one-click approve flow is the only option (backward-compatible). Once a board is bootstrapped, the one-click flow is replaced by the propose-flow unless a `BoardDelegation` is created.
- Existing member_applications, users.membership_status, members rows are untouched.

## Out-of-scope tickets created in passing

Log as backlog when implementing — do NOT address inline:

- **Board-member self-resignation** (`POST /governance/board/me/resign`) — small flow, deferred to 0.6c. Until then: chair or admin can propose `remove_board_member`.
- **Member-applications: applicant-side visibility of "your application is pending board approval"** — public-side / member-portal scope (0.11). Backstage flow today does not notify the applicant when their application stalls in board review.
- **Bulk expulsion** for 2-vuotinen maksulaiminlyönti — 0.7 MembershipBilling triggers will programmatically call `InitiateMemberExpulsion`.
- **Notification delivery** (email / push) for "you have N pending decisions awaiting your vote" + "hearing notice sent" — 0.8 Communications.
- **Appeal resolution** (vuosikokouksen uphold/reject) — 0.9 Meetings.
- **Public board roster page** on tenant frontend — 0.11 MemberPortal.
- **Election workflow** to renew board roster — 0.9 Meetings replaces bootstrap as the canonical roster-renewal path.

## Pre-resolved questions from brainstorm

For audit:

- Scope: single milestone 0.6b, no further split.
- Decision-process mode: dual-mode (`async|sync`). Domain rule: `mode=async ⇒ threshold=unanimous`.
- Bootstrap: GSA-only, one-time per tenant, audit-trailed.
- Applications flow: three-level — default (board decision) + standing delegation (whitelisted types only, admin role) + GSA override (with reason + dedicated audit table).
- Delegation whitelist: `approve_basic`, `invite_full`, `award_subtier`. Erottaminen + sub-tier-CRUD + remove-board-member are never delegoitavissa.
- Roster departure: `term_ended_at + term_ended_reason`. No varajäsen concept (bylaws do not define one). Seat stays empty until next election (0.9).
- Expulsion: rich `member_expulsions` aggregate. Hearing-deadline 14 vrk default (per-tenant override). Only board members can propose. Appeal stored in 0.6b, resolved in 0.9.
- 12 kk rule: push (widget) + pull (precondition). "Sitoutuminen" is board judgment via free-text reason.
- Proposer authority: any active board member. (Expulsion has the stricter rule above.)
- Vote visibility: per-decision choice — `visible | anonymous`. Anonymous shows aggregate-only to non-voters.
- Expiration: per-tenant `decision_expiration_days` default 60.
- Architecture: hybrid — one `BoardDecision` aggregate + typed propose-use cases per decision_type + executor services + rich side tables (`member_expulsions`, `member_sub_tier_awards`).

## Estimated implementation size

- 13 migrations (078–090; 089–090 buffer)
- ~25 Domain classes (entities, enums, exceptions, repository interfaces)
- ~15 Application use cases + 9 executors
- ~9 SQL repositories + 9 InMemory fakes
- ~6 controllers + routing
- ~10 backstage pages/components + 4 dashboard widgets
- ~40+ Unit tests, ~15+ Integration tests, ~6 Isolation tests, ~10 E2E tests
- ~95 i18n keys × 3 locales
- **Estimated commit count:** ~100–130
- **Estimated implementation-plan task count:** ~35–45
