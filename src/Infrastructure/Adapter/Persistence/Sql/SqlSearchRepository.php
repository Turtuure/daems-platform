<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Search\SearchHit;
use Daems\Domain\Search\SearchRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlSearchRepository implements SearchRepositoryInterface
{
    private const SNIPPET_WINDOW = 80;

    public function __construct(private readonly Connection $db) {}

    public function search(
        string $tenantId,
        string $query,
        ?string $type,
        bool $includeUnpublished,
        bool $actingUserIsAdmin,
        int $limitPerDomain,
        string $currentLocale,
    ): array {
        $wantAll = ($type === null);
        $out = [];
        if ($wantAll || $type === 'event') {
            $out = array_merge($out, $this->searchEvents($tenantId, $query, $includeUnpublished, $limitPerDomain, $currentLocale));
        }
        if ($wantAll || $type === 'project') {
            $out = array_merge($out, $this->searchProjects($tenantId, $query, $includeUnpublished, $limitPerDomain, $currentLocale));
        }
        if ($wantAll || $type === 'insight') {
            $out = array_merge($out, $this->searchInsights($tenantId, $query, $includeUnpublished, $limitPerDomain));
        }
        if ($wantAll || $type === 'forum_topic') {
            $out = array_merge($out, $this->searchForum($tenantId, $query, $limitPerDomain));
        }
        if ($actingUserIsAdmin && ($wantAll || $type === 'member')) {
            $out = array_merge($out, $this->searchMembers($tenantId, $query, $limitPerDomain));
        }
        return $out;
    }

    /** @return SearchHit[] */
    private function searchEvents(string $tenantId, string $query, bool $includeUnpublished, int $limit, string $locale): array
    {
        $statusFilter = $includeUnpublished ? '' : " AND e.status='published'";
        $sql = "SELECT e.id, e.slug, e.status, e.event_date,
                       COALESCE(i_cur.title, i_en.title, i_fi.title, i_sw.title) AS title,
                       COALESCE(i_cur.description, i_en.description, i_fi.description, i_sw.description) AS description,
                       COALESCE(i_cur.location, i_en.location, i_fi.location, i_sw.location) AS location,
                       CASE
                           WHEN i_cur.title IS NOT NULL THEN NULL
                           WHEN i_en.title  IS NOT NULL THEN 'en_GB'
                           WHEN i_fi.title  IS NOT NULL THEN 'fi_FI'
                           WHEN i_sw.title  IS NOT NULL THEN 'sw_TZ'
                           ELSE NULL
                       END AS locale_code,
                       GREATEST(
                           IFNULL(MATCH(i_fi.title, i_fi.location, i_fi.description) AGAINST (:q1), 0),
                           IFNULL(MATCH(i_en.title, i_en.location, i_en.description) AGAINST (:q2), 0),
                           IFNULL(MATCH(i_sw.title, i_sw.location, i_sw.description) AGAINST (:q3), 0)
                       ) AS relevance
                FROM events e
                LEFT JOIN events_i18n i_cur ON i_cur.event_id = e.id AND i_cur.locale = :locale
                LEFT JOIN events_i18n i_en  ON i_en.event_id  = e.id AND i_en.locale  = 'en_GB'
                LEFT JOIN events_i18n i_fi  ON i_fi.event_id  = e.id AND i_fi.locale  = 'fi_FI'
                LEFT JOIN events_i18n i_sw  ON i_sw.event_id  = e.id AND i_sw.locale  = 'sw_TZ'
                WHERE e.tenant_id = :t {$statusFilter}
                  AND (
                      MATCH(i_fi.title, i_fi.location, i_fi.description) AGAINST (:q4)
                   OR MATCH(i_en.title, i_en.location, i_en.description) AGAINST (:q5)
                   OR MATCH(i_sw.title, i_sw.location, i_sw.description) AGAINST (:q6)
                  )
                ORDER BY relevance DESC
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [
            ':t' => $tenantId,
            ':q1' => $query, ':q2' => $query, ':q3' => $query,
            ':q4' => $query, ':q5' => $query, ':q6' => $query,
            ':locale' => $locale,
        ]);

        $hits = [];
        foreach ($rows as $r) {
            $title = self::str($r, 'title', '');
            $description = self::str($r, 'description', '');
            $location = self::str($r, 'location', '');
            $localeRaw = $r['locale_code'] ?? null;
            $eventDate = $r['event_date'] ?? null;
            $relevanceRaw = $r['relevance'] ?? 0;
            $hits[] = new SearchHit(
                entityType: 'event',
                entityId: self::str($r, 'id'),
                title: $title,
                snippet: self::snippet($description . ' ' . $location, $query),
                url: '/events/' . self::str($r, 'slug'),
                localeCode: is_string($localeRaw) ? $localeRaw : null,
                status: self::str($r, 'status'),
                publishedAt: is_string($eventDate) ? substr($eventDate, 0, 10) : null,
                relevance: is_numeric($relevanceRaw) ? (float) $relevanceRaw : 0.0,
            );
        }
        return $hits;
    }

    /** @return SearchHit[] */
    private function searchProjects(string $tenantId, string $query, bool $includeUnpublished, int $limit, string $locale): array
    {
        $statusFilter = $includeUnpublished ? '' : " AND p.status='published'";
        $sql = "SELECT p.id, p.slug, p.status, p.created_at,
                       COALESCE(i_cur.title, i_en.title, i_fi.title, i_sw.title) AS title,
                       COALESCE(i_cur.description, i_en.description, i_fi.description, i_sw.description) AS description,
                       COALESCE(i_cur.summary, i_en.summary, i_fi.summary, i_sw.summary) AS summary,
                       CASE
                           WHEN i_cur.title IS NOT NULL THEN NULL
                           WHEN i_en.title  IS NOT NULL THEN 'en_GB'
                           WHEN i_fi.title  IS NOT NULL THEN 'fi_FI'
                           WHEN i_sw.title  IS NOT NULL THEN 'sw_TZ'
                           ELSE NULL
                       END AS locale_code,
                       GREATEST(
                           IFNULL(MATCH(i_fi.title, i_fi.summary, i_fi.description) AGAINST (:q1), 0),
                           IFNULL(MATCH(i_en.title, i_en.summary, i_en.description) AGAINST (:q2), 0),
                           IFNULL(MATCH(i_sw.title, i_sw.summary, i_sw.description) AGAINST (:q3), 0)
                       ) AS relevance
                FROM projects p
                LEFT JOIN projects_i18n i_cur ON i_cur.project_id = p.id AND i_cur.locale = :locale
                LEFT JOIN projects_i18n i_en  ON i_en.project_id  = p.id AND i_en.locale  = 'en_GB'
                LEFT JOIN projects_i18n i_fi  ON i_fi.project_id  = p.id AND i_fi.locale  = 'fi_FI'
                LEFT JOIN projects_i18n i_sw  ON i_sw.project_id  = p.id AND i_sw.locale  = 'sw_TZ'
                WHERE p.tenant_id = :t {$statusFilter}
                  AND (
                      MATCH(i_fi.title, i_fi.summary, i_fi.description) AGAINST (:q4)
                   OR MATCH(i_en.title, i_en.summary, i_en.description) AGAINST (:q5)
                   OR MATCH(i_sw.title, i_sw.summary, i_sw.description) AGAINST (:q6)
                  )
                ORDER BY relevance DESC
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [
            ':t' => $tenantId,
            ':q1' => $query, ':q2' => $query, ':q3' => $query,
            ':q4' => $query, ':q5' => $query, ':q6' => $query,
            ':locale' => $locale,
        ]);

        $hits = [];
        foreach ($rows as $r) {
            $title = self::str($r, 'title', '');
            $description = self::str($r, 'description', '');
            $summary = self::str($r, 'summary', '');
            $localeRaw = $r['locale_code'] ?? null;
            $createdAt = $r['created_at'] ?? null;
            $relevanceRaw = $r['relevance'] ?? 0;
            $hits[] = new SearchHit(
                entityType: 'project',
                entityId: self::str($r, 'id'),
                title: $title,
                snippet: self::snippet($description . ' ' . $summary, $query),
                url: '/projects/' . self::str($r, 'slug'),
                localeCode: is_string($localeRaw) ? $localeRaw : null,
                status: self::str($r, 'status'),
                publishedAt: is_string($createdAt) ? substr($createdAt, 0, 10) : null,
                relevance: is_numeric($relevanceRaw) ? (float) $relevanceRaw : 0.0,
            );
        }
        return $hits;
    }

    /**
     * Insights search: matches on the new insights_i18n FULLTEXT(title,
     * excerpt) — the body content is searchable via i_fi.excerpt + the
     * convenience search_text column on the chrome row when authors keep
     * the excerpt up to date. The displayed title + excerpt are pulled
     * from fi_FI then en_GB then sw_TZ (firstAvailable pattern).
     *
     * @return SearchHit[]
     */
    private function searchInsights(string $tenantId, string $query, bool $includeUnpublished, int $limit): array
    {
        $dateFilter = $includeUnpublished ? '' : ' AND i.published_date <= CURDATE()';
        $sql = "SELECT i.id, i.slug, i.search_text, i.published_date,
                       COALESCE(t_fi.title,   t_en.title,   t_sw.title)   AS title,
                       COALESCE(t_fi.excerpt, t_en.excerpt, t_sw.excerpt) AS excerpt,
                       GREATEST(
                           IFNULL(MATCH(t_fi.title, t_fi.excerpt) AGAINST (:q1), 0),
                           IFNULL(MATCH(t_en.title, t_en.excerpt) AGAINST (:q2), 0),
                           IFNULL(MATCH(t_sw.title, t_sw.excerpt) AGAINST (:q3), 0)
                       ) AS relevance
                FROM insights i
                LEFT JOIN insights_i18n t_fi ON t_fi.insight_id = i.id AND t_fi.locale = 'fi_FI'
                LEFT JOIN insights_i18n t_en ON t_en.insight_id = i.id AND t_en.locale = 'en_GB'
                LEFT JOIN insights_i18n t_sw ON t_sw.insight_id = i.id AND t_sw.locale = 'sw_TZ'
                WHERE i.tenant_id = :t {$dateFilter}
                  AND (
                      MATCH(t_fi.title, t_fi.excerpt) AGAINST (:q4)
                   OR MATCH(t_en.title, t_en.excerpt) AGAINST (:q5)
                   OR MATCH(t_sw.title, t_sw.excerpt) AGAINST (:q6)
                  )
                ORDER BY relevance DESC
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [
            ':t'  => $tenantId,
            ':q1' => $query, ':q2' => $query, ':q3' => $query,
            ':q4' => $query, ':q5' => $query, ':q6' => $query,
        ]);

        $hits = [];
        foreach ($rows as $r) {
            $title         = self::str($r, 'title', '');
            $excerpt       = self::str($r, 'excerpt', '');
            $searchText    = self::str($r, 'search_text', '');
            $snippetSource = $excerpt !== '' ? $excerpt : $searchText;
            $publishedDate = $r['published_date'] ?? null;
            $relevanceRaw  = $r['relevance'] ?? 0;
            $hits[] = new SearchHit(
                entityType: 'insight',
                entityId: self::str($r, 'id'),
                title: $title,
                snippet: self::snippet($snippetSource, $query),
                url: '/insights/' . self::str($r, 'slug'),
                localeCode: null,
                status: 'published',
                publishedAt: is_string($publishedDate) ? substr($publishedDate, 0, 10) : null,
                relevance: is_numeric($relevanceRaw) ? (float) $relevanceRaw : 0.0,
            );
        }
        return $hits;
    }

    /** @return SearchHit[] */
    private function searchForum(string $tenantId, string $query, int $limit): array
    {
        $sql = "SELECT t.id, t.slug, t.created_at, t.title, t.first_post_search_text,
                       c.slug AS category_slug,
                       MATCH(t.title, t.first_post_search_text) AGAINST (:q1) AS relevance
                FROM forum_topics t
                JOIN forum_categories c ON c.id = t.category_id
                WHERE t.tenant_id = :t
                  AND MATCH(t.title, t.first_post_search_text) AGAINST (:q2)
                ORDER BY relevance DESC
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [
            ':t'  => $tenantId,
            ':q1' => $query,
            ':q2' => $query,
        ]);

        $hits = [];
        foreach ($rows as $r) {
            $title = self::str($r, 'title', '');
            $body = self::str($r, 'first_post_search_text', '');
            $createdAt = $r['created_at'] ?? null;
            $relevanceRaw = $r['relevance'] ?? 0;
            $hits[] = new SearchHit(
                entityType: 'forum_topic',
                entityId: self::str($r, 'id'),
                title: $title,
                snippet: self::snippet($body, $query),
                url: '/forums/' . self::str($r, 'category_slug') . '/' . self::str($r, 'slug'),
                localeCode: null,
                status: 'published',
                publishedAt: is_string($createdAt) ? substr($createdAt, 0, 10) : null,
                relevance: is_numeric($relevanceRaw) ? (float) $relevanceRaw : 0.0,
            );
        }
        return $hits;
    }

    /** @return SearchHit[] */
    private function searchMembers(string $tenantId, string $query, int $limit): array
    {
        $pat = '%' . addcslashes($query, "%_\\") . '%';
        $sql = "SELECT u.id, u.name, u.email, u.member_number, u.membership_type,
                       ut.role, t.member_number_prefix
                FROM users u
                JOIN user_tenants ut ON ut.user_id = u.id
                JOIN tenants t       ON t.id       = ut.tenant_id
                WHERE ut.tenant_id = :t
                  AND u.deleted_at IS NULL
                  AND (u.name LIKE :pat1 OR u.email LIKE :pat2)
                LIMIT {$limit}";
        $rows = $this->db->query($sql, [
            ':t'    => $tenantId,
            ':pat1' => $pat,
            ':pat2' => $pat,
        ]);

        $hits = [];
        foreach ($rows as $r) {
            $name       = self::str($r, 'name');
            $memberNum  = self::str($r, 'member_number', '');
            $role       = self::str($r, 'role', '');
            $prefix     = self::str($r, 'member_number_prefix', '');
            $formatted  = self::formatMemberNumber($memberNum, $prefix);
            $hits[] = new SearchHit(
                entityType: 'member',
                entityId: self::str($r, 'id'),
                title: $name,
                snippet: $name . ($formatted !== '' ? ' · ' . $formatted : '') . ($role !== '' ? ' · ' . $role : ''),
                url: '/backstage/members?q=' . urlencode($query),
                localeCode: null,
                status: 'active',
                publishedAt: null,
                relevance: 1.0,
            );
        }
        return $hits;
    }

    /**
     * Format a raw member_number for display — strips leading zeros and joins
     * with the tenant prefix via "-". Mirrors the frontend MemberNumberFormatter
     * so backstage search results match the member-card display (e.g. "DAEMS-123").
     */
    private static function formatMemberNumber(string $raw, string $prefix): string
    {
        if ($raw === '') {
            return '';
        }
        $stripped = ltrim($raw, '0');
        if ($stripped === '') {
            $stripped = '0';
        }
        $prefix = trim($prefix);
        return $prefix === '' ? $stripped : $prefix . '-' . $stripped;
    }

    private static function snippet(string $haystack, string $needle): string
    {
        $pos = mb_stripos($haystack, $needle);
        if ($pos === false) {
            return mb_substr($haystack, 0, self::SNIPPET_WINDOW);
        }
        $start = max(0, $pos - 30);
        return ($start > 0 ? '…' : '') . mb_substr($haystack, $start, self::SNIPPET_WINDOW);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key, string $default = ''): string
    {
        $v = $row[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if ($default !== '' || array_key_exists($key, $row)) {
            return $default;
        }
        throw new \DomainException("Missing or non-string column: {$key}");
    }
}
