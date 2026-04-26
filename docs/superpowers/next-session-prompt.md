# Next session prompt — Phase 3-7 per-section KPI strips

Avaa `claude` `C:\laragon\www\daems-platform`-hakemistossa ja liitä tämä prompti:

---

Aloita Phase 3-7 — per-section KPI-stripit kaikille jäljellä oleville backstage-osioille.

KONTEKSTI

Phase 1 (insights pilot, dev) ja Phase 2 (forum redesign + sub-pages, dev) ovat shipattu. Lue molemmat artefaktit jotka kertovat suunnan ja patternit:
- docs/superpowers/specs/2026-04-25-backstage-redesign-design.md (Phase 1 + design system)
- docs/superpowers/plans/2026-04-25-backstage-redesign-phase1.md
- docs/superpowers/specs/2026-04-25-forum-redesign-design.md (Phase 2)
- docs/superpowers/plans/2026-04-25-forum-redesign-phase2.md

Phase 1 toimitti shared design system -primitiivit (kpi-card, data-explorer, slide-panel, confirm-dialog, pill, btn variants, skeleton, error-state, empty-state) jotka kaikki sivut voivat käyttää. Phase 2 lisäsi `kpi-card--compact` -variantin (ei sparklineä) sub-nav-käyttöön.

Insights ja Forum ovat valmiit. Dashboard (/backstage) on toiminut Phase 1:n KPI-card-pohjalta. Settings on read-only ilman luonnollisia KPI:tä.

GOAL (Phase 3-7, isompi kuin yksi PR)

Lisää per-section KPI-strip seuraaville sivuille (kullekin omat 4 KPI:tä sparklineineen):
- Members (`/backstage/members`)
- Applications (`/backstage/applications`)
- Events (`/backstage/events`)
- Projects (`/backstage/projects`)
- Notifications (`/backstage/notifications`)

KPI-strip on dashboard-tasolla (sparklinet päällä, ei compact). Sivut säilyvät muuten sellaisenaan tässä iteraatiossa — kyse ei ole täydestä redesignista, vaan KPI-stripin lisäyksestä yläosaan.

Per osio tarvitaan:
- Backend: ListXStats use case + repository-metodit + controller-metodi + route + DI BOTH-wire (bootstrap/app.php + KernelHarness.php) + Unit + Integration + E2E + Isolation -testit
- Frontend: KPI-strip-partial + JS-tiedosto stats-fetchille + sivun index.php päivitys

WORKFLOW

Käytä superpowers-skill-ketjua — auto-mode on kovaa preferoitu:

1. Brainstorming (`superpowers:brainstorming`) — yksi yhteinen brainstorm joka päättää 4 KPI:tä per osio (5 osiota × 4 KPI = 20 KPI:tä). Älä venytä — KPI:t ovat pitkälti johdettavissa olemassa olevasta data-mallista. Kysy korkeintaan 2-3 selventävää kysymystä per osio.

2. Spec — kirjoita `docs/superpowers/specs/2026-04-26-per-section-kpi-strips-design.md` joka kuvaa koko 5-osion sarjan. Jokaiselle osiolle: KPI-määritelmät + sparkline-lähteet + repo-method-nimet.

3. Plan — kirjoita `docs/superpowers/plans/2026-04-26-per-section-kpi-strips.md`. Toteuta osio kerrallaan (5 alivaihetta), jokaisesta alivaiheesta tulee oma commit-sarja.

4. Execute — `superpowers:subagent-driven-development`. Per osio sama rakenne kuin Phase 1 + Phase 2: TDD backendissä, manuaalinen UAT frontendillä.

KONVENTIOT (ALL CRITICAL)

- Commit identity: `git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "..."`
- Ei `Co-Authored-By:`-trailerit. Ei auto-pushaa.
- Älä koskaan stage `.claude/` tai `.superpowers/` (gitignored — verifioi `git status --short` ennen jokaista committia).
- BOTH-wire-sääntö: jokainen uusi controller/use case bind sekä `bootstrap/app.php`:hen ETTÄ `tests/Support/KernelHarness.php`:hen. Phase 1:n memoryssa: `feedback_bootstrap_and_harness_must_both_wire.md`.
- PHPStan level 9 = 0 errors per commit.
- No `transform: translate*` / `scale*` on `:hover` — käytä border-color, background, opacity. Memory: `feedback_no_hover_translate_animations.md`.
- Visual Companion -animaatiot eivät renderöidy tässä käyttäjäympäristössä — pidä brainstorm tekstissä. Memory: `feedback_visual_companion_animation_unreliable.md`.
- Forbidden tool: kaikki `mcp__code-review-graph__*` — hyytyvät subagent-sessioissa.
- Käytä `vendor/bin/phpunit --testsuite Unit/Integration/E2E` suoraan, ei `composer test:all` (Composerin process timeout katkaisee Integration-suiten).
- Test DB resetointi: `"C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe" -h127.0.0.1 -uroot -psalasana -e "DROP DATABASE IF EXISTS daems_db_test; CREATE DATABASE daems_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"` ennen Integration-testejä jos kontaminaatiota.
- Apache vhost on käyttäjä lisännyt manuaalisesti `daems.local` (frontti) ja `daems-platform.local` (backend). PHP-proxyt vaativat `session_start()`:n alussa (Apache-direct-serve ohittaa front-controllerin, missä session muuten startataan) — sama korjaus kuin Phase 1 lisäsi insights.php:hen ja Phase 2 forum.php:hen.

KPI-EHDOTUKSIA (ÄLÄ LUKITA, TARKISTA BRAINSTORMISSA)

- Members: Total / New (30d) / Supporters / Inactive
- Applications: Pending / Approved (30d) / Rejected (30d) / Avg response time
- Events: Upcoming / Drafts / Total registrations (30d) / Pending proposals
- Projects: Active / Drafts / Featured / Pending proposals
- Notifications: Unread for actor / All-time count / By type breakdown / —

ALOITUS

Lue 4 referenssidokumenttia ensin. Sitten invokoi `superpowers:brainstorming` ja kysy minulta ensimmäinen selventävä kysymys (suosittelen aloittaa Members-osion KPI-määritelmistä koska se on suurin sivu).

Auto-mode on käytössä — etene tehokkaasti, kysy vain kun kriittinen kohta vaatii valintaa.
