<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

/**
 * Immutable value object describing the route prefixes a module owns. Lives at
 * the platform-catalog level (config/modules.php) — not in module.json — so
 * different deployments can mount the same module under different paths.
 *
 * Used by the registry to:
 *   - Detect overlap between modules at boot (overlapsWith).
 *   - Resolve which module owns an incoming request path (longestMatch).
 */
final class RoutePrefixes
{
    /**
     * @param list<string> $backstage Backstage UI route prefixes (e.g. /backstage/events)
     * @param list<string> $api       API route prefixes (e.g. /api/v1/events)
     */
    public function __construct(
        private readonly array $backstage,
        private readonly array $api,
    ) {
        foreach ($backstage as $p) {
            if ($p === '' || $p[0] !== '/') {
                throw new \InvalidArgumentException(
                    "RoutePrefixes: backstage prefix must be a non-empty string with leading slash, got: '{$p}'"
                );
            }
        }
        foreach ($api as $p) {
            if ($p === '' || $p[0] !== '/') {
                throw new \InvalidArgumentException(
                    "RoutePrefixes: api prefix must be a non-empty string with leading slash, got: '{$p}'"
                );
            }
        }
    }

    /** @return list<string> */
    public function backstagePrefixes(): array
    {
        return $this->backstage;
    }

    /** @return list<string> */
    public function apiPrefixes(): array
    {
        return $this->api;
    }

    /**
     * Returns the longest prefix from either list that matches $path, where
     * "match" means $path equals the prefix exactly OR continues with a slash
     * after the prefix. Returns null if no prefix matches.
     */
    public function longestMatch(string $path): ?string
    {
        $best = null;
        foreach ([$this->backstage, $this->api] as $list) {
            foreach ($list as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    if ($best === null || strlen($prefix) > strlen($best)) {
                        $best = $prefix;
                    }
                }
            }
        }
        return $best;
    }

    /**
     * Returns true if any prefix in this is a path-prefix of any prefix in
     * other (or equal), or vice versa. The trailing-slash trick prevents
     * false positives like /foo vs /foobar.
     */
    public function overlapsWith(self $other): bool
    {
        $left = array_merge($this->backstage, $this->api);
        $right = array_merge($other->backstage, $other->api);
        foreach ($left as $a) {
            foreach ($right as $b) {
                if ($a === $b) {
                    return true;
                }
                if (str_starts_with($a . '/', $b . '/')) {
                    return true;
                }
                if (str_starts_with($b . '/', $a . '/')) {
                    return true;
                }
            }
        }
        return false;
    }
}
