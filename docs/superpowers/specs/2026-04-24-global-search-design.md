# Global Search — Design Spec

**Date:** 2026-04-24
**Status:** Approved, ready for planning
**Roadmap ref:** `docs/planning/roadmap.md` §3 "Search"

## Goal

Ship a single search bar that finds content across the platform:
- **Public surfaces**: events, projects, forum topics, insights
- **Backstage (admin)**: everything above + members + drafts/unpublished rows

One canonical backend; two frontends (public + backstage) sharing one typeahead JS component.

## Decisions locked in brainstorming

1. **Scope**: all 5 domains (events, projects, forum topics, insights, members) in this PR.
2. **Insights backstage admin**: tracked as separate follow-up PR — not in this spec.
3. **UX pattern**: hybrid — typeahead dropdown in top-bar + dedicated `/search?q=` results page.
4. **Localization**: match in any locale, display the user's current locale (fallback to en_GB when the current-locale translation is missing). Results badge `(en)`/`(sw)` when displayed text comes from a fallback.
5. **Tenant scope**: always scoped to the current Host's tenant. Platform admins do NOT search across tenants.
6. **Forum body**: search topic `title` + the first post's `content` (the post with the lowest `sort_order` per topic). Other replies are not indexed.
7. **Ranking**: MySQL FULLTEXT `NATURAL LANGUAGE MODE` relevance; no cross-domain weighting.
8. **Min query length**: 2 characters. Shorter requests return empty with `meta.reason='query_too_short'`.
9. **Debounce**: 300 ms on typeahead inputs.
10. **Draft/publish filtering**: public search returns only published content (events/projects: `status='published'`; insights: `published_date <= CURDATE()`; forum topics: always visible). Backstage search returns all statuses.
11. **Result fields**: title, ~80-char snippet (match context, highlighted on client), domain badge, date when relevant, URL.
12. **Dedicated page filters**: domain-type chips (All / Events / Projects / Forum / Insights [/ Members admin]). No date filter, no explicit ranking toggle in MVP.
13. **Rate-limit**: none in MVP.
14. **Implementation**: per-domain FULLTEXT on source tables (`events_i18n`, `projects_i18n`, `insights`, `forum_topics`) + LIKE for members. No denormalized `search_index` table.
15. **Members matching**: `users.name` + `users.email` via LIKE. Result shows formatted member number (`DAEMS-123`) + role; link points to `/backstage/members?q=…`.
16. **HTML stripping**: insights `content` and forum first-post `content` are copied into a new `search_text` / `first_post_search_text` column at write time (PHP-side strip, not DB trigger) so FULLTEXT indexes plain text.

## Architecture

### Platform (clean architecture)

```
src/Domain/Search/
    SearchHit.php                      # unified result value object
    SearchRepositoryInterface.php
src/Application/Search/Search/
    Search.php                         # use case
    SearchInput.php
    SearchOutput.php
src/Infrastructure/Adapter/Persistence/Sql/
    SqlSearchRepository.php            # per-domain parallel SELECTs
src/Infrastructure/Adapter/Api/Controller/
    SearchController.php
```

### Routes (platform)

```
GET /api/v1/search
    ?q={term}
    &type={all|events|projects|forum|insights}
    &limit={5|20}
    Middleware: [TenantContextMiddleware, LocaleMiddleware]
    Auth: none

GET /api/v1/backstage/search
    ?q={term}
    &type={all|events|projects|forum|insights|members}
    &limit={5|20}
    Middleware: [TenantContextMiddleware, LocaleMiddleware, AuthMiddleware]
    Authorization: any authenticated user sees content of their tenant;
                   `type=members` requires tenant role admin OR is_platform_admin
```

### Routes & files (daem-society)

```
public/
  index.php                              # add routes: /search, /backstage/search, /api/search, /api/backstage/search
  pages/
    search/index.php                     # dedicated public page
    backstage/search/index.php           # dedicated backstage page
  api/
    search.php                           # public proxy → /api/v1/search
    backstage/search.php                 # authed proxy → /api/v1/backstage/search
  partials/
    top-nav.php                          # add search icon + overlay for public
    search-typeahead.php                 # shared partial: input + dropdown markup (used by both top-bars)
  assets/
    js/daems-search.js                   # shared typeahead JS (debounce, fetch, render)
    css/daems-search.css                 # dropdown + dedicated page styles
```

Backstage top-bar already has a search slot in the layout (per roadmap description); we wire the same JS component there.

## Components

### `SearchHit` value object

Unified shape returned to the client regardless of source domain.

```php
final class SearchHit {
    public function __construct(
        public readonly string $entityType,   // 'event'|'project'|'insight'|'forum_topic'|'member'
        public readonly string $entityId,
        public readonly string $title,
        public readonly string $snippet,       // ~80 chars, plain text, markup-free
        public readonly string $url,           // e.g. '/events/summer-2026'
        public readonly ?string $localeCode,   // non-null when fallback locale was used
        public readonly string $status,        // 'published'|'draft'|'archived'|... (domain-specific)
        public readonly ?string $publishedAt,  // YYYY-MM-DD or null
        public readonly float $relevance,      // MATCH score or synthetic score for LIKE results
    ) {}
    public function toArray(): array { /* flat JSON shape */ }
}
```

### `SearchRepositoryInterface`

```php
interface SearchRepositoryInterface {
    /**
     * @param string $tenantId
     * @param string $query           trimmed, ≥2 chars — caller guarantees
     * @param string|null $type       null/'all' runs every domain; specific string runs one
     * @param bool $includeUnpublished  true on backstage path
     * @param bool $actingUserIsAdmin   controls whether 'members' domain runs
     * @param int $limitPerDomain     5 for typeahead, 20 for dedicated page
     * @param string $currentLocale   for i18n fallback resolution
     * @return SearchHit[]
     */
    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array;
}
```

### `Search` use case

1. Trims `$query`; rejects if strlen < 2 → returns empty `SearchOutput` with `reason='query_too_short'`.
2. Normalises `$type`: invalid strings treated as `'all'`.
3. Derives `$isAdmin` from acting user (role in tenant `admin`/`moderator` treated as admin here; `is_platform_admin` always admin).
4. Calls repo.
5. Interleaves results per domain when `type='all'` (round-robin 1 per domain for typeahead; grouped by domain for dedicated page — caller passes a flag).
6. Returns `SearchOutput` with `{hits, count, query, type, partial: bool}`.

### `SearchController::index`

Thin controller:
- Parse `q`, `type`, `limit`
- Detect backstage route vs public (two separate controller methods keep routing simple: `public(Request)` and `backstage(Request)`)
- On backstage: verify auth middleware already ran (route config), read acting user
- On `type=members` + backstage: if `!isAdmin` → `403 {error: 'forbidden'}`
- On `type=members` + public route: `422 {error: 'invalid_type'}`
- Returns `{data: SearchHit[], meta: {count, query, type, partial}}`

## Data flow — example "summer" on public

```
Browser (top-nav search overlay)
  │ user types "summer", 300 ms debounce
  ▼
GET /api/search?q=summer
  │ society proxy reads session, adds no token (public)
  ▼
GET /api/v1/search?q=summer        Host: daems.local
  │ TenantContextMiddleware → daems tenant
  │ LocaleMiddleware → fi_FI (from Accept-Language)
  ▼
SearchController::public
  ▼
Search::execute(tenant=daems, query='summer', type=null, includeUnpublished=false, isAdmin=false, limitPerDomain=5, locale=fi_FI)
  ▼
SqlSearchRepository::search
  ├─ events: SELECT e.id, e.slug, e.start_at, e.status, COALESCE(i_fi.title, i_en.title) AS title, ... ,
  │                 MATCH(i_fi.title, i_fi.location, i_fi.description) AGAINST(?) AS score_fi,
  │                 MATCH(i_en.title, i_en.location, i_en.description) AGAINST(?) AS score_en
  │          FROM events e
  │          LEFT JOIN events_i18n i_fi ON i_fi.event_id=e.id AND i_fi.locale='fi_FI'
  │          LEFT JOIN events_i18n i_en ON i_en.event_id=e.id AND i_en.locale='en_GB'
  │          LEFT JOIN events_i18n i_sw ON i_sw.event_id=e.id AND i_sw.locale='sw_TZ'
  │          WHERE e.tenant_id=? AND e.status='published'
  │            AND (MATCH(i_fi.title,...) AGAINST(?) OR MATCH(i_en...) OR MATCH(i_sw...))
  │          ORDER BY GREATEST(...) DESC LIMIT 5
  ├─ projects: analogous JOIN over projects_i18n
  ├─ insights: SELECT WHERE tenant_id + published_date <= CURDATE() + MATCH(title, search_text) LIMIT 5
  └─ forum_topics: SELECT WHERE tenant_id + MATCH(title, first_post_search_text) LIMIT 5
  ▼
Each row mapped to SearchHit; snippet computed via mb_stripos + surrounding word context
  ▼
Response {data: [...≤20 items], meta: {count, query:'summer', type:'all', partial:false}}
  ▼
Society proxy relays JSON
  ▼
JS renders dropdown: grouped by domain with badge, max 5 per group, "View all results →" link at bottom → /search?q=summer
```

Admin path identical except: backstage routes, `includeUnpublished=true`, members query added when `type ∈ {all, members}` and `isAdmin=true`.

## Schema changes

Three migrations:

### 059_fulltext_events_projects_i18n.sql

```sql
ALTER TABLE events_i18n   ADD FULLTEXT INDEX ft_title_body (title, location, description);
ALTER TABLE projects_i18n ADD FULLTEXT INDEX ft_title_body (title, summary, description);
```

### 060_search_text_insights.sql

```sql
ALTER TABLE insights
  ADD COLUMN search_text MEDIUMTEXT NULL AFTER content,
  ADD FULLTEXT INDEX ft_title_body (title, search_text);

-- backfill: strip tags from content (done in a PHP migration helper to share the
-- same strip logic the runtime uses)
```

### 061_search_text_forum_topics.sql

```sql
ALTER TABLE forum_topics
  ADD COLUMN first_post_search_text MEDIUMTEXT NULL,
  ADD FULLTEXT INDEX ft_title_body (title, first_post_search_text);

-- backfill: for each topic, copy the post with the lowest sort_order
UPDATE forum_topics ft
   SET first_post_search_text = (
     SELECT content FROM forum_posts p
      WHERE p.topic_id = ft.id
      ORDER BY p.sort_order ASC, p.created_at ASC
      LIMIT 1
   );
```

### Write-path sync (PHP, no DB triggers)

| Repository | Method | Sync action |
|---|---|---|
| `SqlInsightRepository` | `save` (create/update) | `search_text = strip_tags($content)` |
| `SqlForumRepository` | `saveTopic` (topic create — first post included) | `first_post_search_text = strip_tags($firstPostContent)` |
| `SqlForumRepository` | `updateFirstPost` (if editable) | sync `first_post_search_text` |

No sync needed for events/projects (FULLTEXT indexes existing columns directly).

No sync needed for users — members search is LIKE against `name` + `email` directly.

## Error handling

| Condition | Response |
|---|---|
| `q` absent, empty, or < 2 chars after trim | `200 {data:[], meta:{count:0, query, reason:'query_too_short'}}` |
| Invalid `type` value | normalise to `'all'`, proceed |
| `type=members` on public route | `422 {error:'invalid_type'}` |
| `type=members` on backstage route, non-admin actor | `403 {error:'forbidden'}` |
| Unauthenticated on backstage route | `401` (AuthMiddleware handles) |
| DB error on one domain query | log, continue; `meta.partial = true` in response |
| Zero matches across all domains | `200 {data:[], meta:{count:0, query, type}}` |

## Testing

### Unit (Application layer)

- `SearchTest::test_rejects_query_below_min_length`
- `SearchTest::test_invalid_type_falls_back_to_all`
- `SearchTest::test_members_excluded_when_not_admin_on_type_all`
- `SearchTest::test_output_shape_matches_contract`

### Integration (MigrationTestCase + SQL)

- `finds_event_in_current_locale`
- `finds_event_via_fallback_locale_and_flags_locale_code`
- `excludes_draft_events_for_public_but_returns_for_admin`
- `tenant_scope_isolates_results` — daems event not returned when Host=sahegroup
- `forum_body_match_returns_hit` — topic with non-matching title but matching first-post body
- `members_search_is_admin_only` — non-admin gets empty for type=members
- `members_search_matches_name_and_email`
- `insight_respects_published_date` — future-dated insight hidden from public
- `snippet_contains_match_context`

### E2E (Playwright chromium, smoke — same set CI runs today)

- Public: top-nav search icon opens overlay; typing ≥2 chars shows dropdown; click result → domain page
- Backstage: top-bar input → dropdown; click → domain admin page
- Dedicated `/search?q=…`: chips filter by type; URL updates on chip change

## Out of scope (tracked for later)

- Insights backstage admin (separate PR, listed as follow-up)
- Date range filter on dedicated search page
- Per-domain ranking weights / "featured" boosts
- Rate limiting
- Search analytics (click-through, zero-result queries)
- Fuzzy / typo-tolerant matching (would need external engine)
- Highlighted term bolding in snippet beyond simple client-side wrapping

## Operational notes

- InnoDB `innodb_ft_min_token_size` default = 3. Two-letter searches will miss FULLTEXT but still work for members (LIKE). Accepted trade-off in MVP.
- Stopwords use InnoDB default list — sufficient for EN/FI content. No custom list in MVP.
- Index size: 4 FULLTEXT indexes + 2 new MEDIUMTEXT columns. On current data volumes (≤ a few thousand rows per table) this is negligible (< 50 MB total).
