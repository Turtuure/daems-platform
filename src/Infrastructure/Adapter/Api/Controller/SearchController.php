<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Search\Search\Search;
use Daems\Application\Search\Search\SearchInput;
use Daems\Application\Search\Search\SearchOutput;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\Tenant;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class SearchController
{
    public function __construct(private readonly Search $search) {}

    public function public(Request $request): Response
    {
        $tenantId = $this->requireTenant($request)->id->value();
        $q        = $request->string('q') ?? '';
        $type     = $request->string('type');
        $limitRaw = $request->query('limit');
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : 5;
        $locale   = $this->resolveLocale($request);

        if ($type === 'members') {
            return Response::json(['error' => 'invalid_type'], 422);
        }

        $out = $this->search->execute(new SearchInput(
            tenantId: $tenantId,
            rawQuery: $q,
            type: $type,
            includeUnpublished: false,
            actingUserIsAdmin: false,
            limitPerDomain: max(1, min(50, $limit)),
            currentLocale: $locale,
        ));

        return self::respond($out);
    }

    public function backstage(Request $request): Response
    {
        $actor    = $request->requireActingUser();
        $tenantId = $this->requireTenant($request)->id->value();
        $q        = $request->string('q') ?? '';
        $type     = $request->string('type');
        $limitRaw = $request->query('limit');
        $limit    = is_numeric($limitRaw) ? (int) $limitRaw : 20;
        $locale   = $this->resolveLocale($request);

        $isAdmin = $actor->isPlatformAdmin || $actor->isAdminIn($actor->activeTenant);

        if ($type === 'members' && !$isAdmin) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $out = $this->search->execute(new SearchInput(
            tenantId: $tenantId,
            rawQuery: $q,
            type: $type,
            includeUnpublished: true,
            actingUserIsAdmin: $isAdmin,
            limitPerDomain: max(1, min(50, $limit)),
            currentLocale: $locale,
        ));

        return self::respond($out);
    }

    private static function respond(SearchOutput $out): Response
    {
        return Response::json([
            'data' => array_map(static fn($h) => $h->toArray(), $out->hits),
            'meta' => [
                'count'   => $out->count,
                'query'   => $out->query,
                'type'    => $out->type,
                'partial' => $out->partial,
                'reason'  => $out->reason,
            ],
        ]);
    }

    private function requireTenant(Request $request): Tenant
    {
        $tenant = $request->attribute('tenant');
        if (!$tenant instanceof Tenant) {
            throw new NotFoundException('unknown_tenant');
        }
        return $tenant;
    }

    private function resolveLocale(Request $request): string
    {
        $locale = $request->attribute('locale');
        if ($locale instanceof SupportedLocale) {
            return $locale->value();
        }
        return 'fi_FI';
    }
}
