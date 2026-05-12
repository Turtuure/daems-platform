<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Dashboard\CoreWidgets;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Governance\BoardDecisionRepositoryInterface;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionVoteRepositoryInterface;
use Daems\Domain\Governance\BoardMemberRepositoryInterface;
use Daems\Domain\Governance\BoardRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Infrastructure\Dashboard\WidgetRenderer;
use Daems\Frontend\I18n;

final class PendingDecisionsForMeKpiWidget extends Widget
{
    private const ICON = '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>';

    public function __construct(
        private readonly BoardRepositoryInterface $boards,
        private readonly BoardDecisionRepositoryInterface $decisions,
        private readonly BoardMemberRepositoryInterface $members,
        private readonly BoardDecisionVoteRepositoryInterface $votes,
    ) {}

    public function id(): string              { return 'governance.pending_decisions_for_me_kpi'; }
    public function category(): WidgetCategory { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan  { return WidgetSpan::of(1); }
    public function minRole(): MinRole         { return MinRole::Admin; }
    public function module(): ?string          { return null; }
    public function labelKey(): string         { return 'backstage.dashboard.widget.pending_decisions_for_me_kpi.label'; }
    public function descriptionKey(): string   { return 'backstage.dashboard.widget.pending_decisions_for_me_kpi.description'; }

    public function render(TenantId $tenantId, User $user): string
    {
        $d = $this->data($tenantId, $user);
        return WidgetRenderer::kpi(
            value:   (int) $d['count'],
            label:   I18n::t($this->labelKey()),
            color:   'amber',
            iconSvg: self::ICON,
        );
    }

    /** @return array{count:int} */
    public function data(TenantId $tenantId, ?User $user = null): array
    {
        if ($user === null) {
            return ['count' => 0];
        }

        $board = $this->boards->findForTenant($tenantId);
        if ($board === null) {
            return ['count' => 0];
        }

        $now = new \DateTimeImmutable();

        // Find this user's active board_member row
        $myMemberId = null;
        foreach ($this->members->listActiveForBoard($board->id, $now) as $m) {
            if ($m->userId->equals($user->id())) {
                $myMemberId = $m->id;
                break;
            }
        }

        if ($myMemberId === null) {
            return ['count' => 0];
        }

        // Count pending decisions where I haven't voted yet
        $count = 0;
        foreach ($this->decisions->listForBoard($board->id, BoardDecisionStatus::Pending) as $d) {
            if ($this->votes->findByMember($d->id, $myMemberId) === null) {
                $count++;
            }
        }

        return ['count' => $count];
    }
}
