<?php
declare(strict_types=1);

namespace Daems\Domain\Governance;

enum BoardDecisionType: string
{
    case ApproveBasic       = 'approve_basic';
    case InviteFull         = 'invite_full';
    case Expel              = 'expel';
    case AwardSubTier       = 'award_subtier';
    case RevokeSubTier      = 'revoke_subtier';
    case SubTierCrud        = 'subtier_crud';
    case RemoveBoardMember  = 'remove_board_member';
    case DelegateAuthority  = 'delegate_authority';
    case RevokeDelegation   = 'revoke_delegation';

    /** Whitelist of types that may be delegated to the admin role. */
    public function isDelegatable(): bool
    {
        return match ($this) {
            self::ApproveBasic, self::InviteFull, self::AwardSubTier => true,
            default => false,
        };
    }
}
