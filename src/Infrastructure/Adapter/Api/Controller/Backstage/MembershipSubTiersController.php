<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller\Backstage;

use Daems\Application\Membership\ListMembershipSubTiers\ListMembershipSubTiers;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

/**
 * Backstage HTTP wrapper for the per-tenant Membership sub-tier (honor) catalog.
 *
 * Gated by AuthMiddleware + TenantContextMiddleware (routing). This controller
 * additionally restricts read access to tenant admin OR Global System Admin —
 * regular members and moderators must not see the sub-tier catalog editor.
 */
final class MembershipSubTiersController
{
    public function __construct(
        private readonly ListMembershipSubTiers $listSubTiers,
    ) {}

    public function index(Request $req): Response
    {
        $actor = $req->requireActingUser();
        if (!$actor->isPlatformAdmin() && $actor->roleInActiveTenant?->value !== 'admin') {
            throw new ForbiddenException('admin_or_gsa_required');
        }

        $output = $this->listSubTiers->execute($actor->activeTenant);

        $items = array_map(
            static fn($st) => [
                'id'         => $st->id->value(),
                'slug'       => $st->slug,
                'name'       => $st->name,
                'rank_order' => $st->rankOrder,
                'applies_to' => $st->appliesTo->value,
            ],
            $output->items,
        );

        return Response::json(['data' => $items]);
    }
}
