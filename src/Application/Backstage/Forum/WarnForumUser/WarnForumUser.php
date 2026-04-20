<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\WarnForumUser;

use DateTimeImmutable;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumUserWarning;
use Daems\Domain\Forum\ForumUserWarningId;
use Daems\Domain\Forum\ForumUserWarningRepositoryInterface;
use InvalidArgumentException;

/**
 * Admin-side use case: issue a direct warning to a forum user (no report context).
 *
 * Writes a single row to forum_user_warnings. That row IS the audit trail —
 * no forum_moderation_audit entry is written for direct admin warnings.
 */
final class WarnForumUser
{
    public function __construct(
        private readonly ForumUserWarningRepositoryInterface $warnings,
    ) {}

    public function execute(WarnForumUserInput $in): void
    {
        $tenantId = $in->acting->activeTenant;
        if (!$in->acting->isAdminIn($tenantId) && !$in->acting->isPlatformAdmin) {
            throw new ForbiddenException('not_admin');
        }

        $reason = trim($in->reason);
        if ($reason === '') {
            throw new InvalidArgumentException('reason_required');
        }
        if (strlen($reason) > 500) {
            $reason = substr($reason, 0, 500);
        }

        $this->warnings->record(new ForumUserWarning(
            ForumUserWarningId::generate(),
            $tenantId,
            $in->userId,
            $reason,
            null,
            $in->acting->id->value(),
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ));
    }
}
