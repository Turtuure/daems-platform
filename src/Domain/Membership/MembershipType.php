<?php
declare(strict_types=1);

namespace Daems\Domain\Membership;

enum MembershipType: string
{
    case Supporting = 'SUPPORTING';
    case Basic      = 'BASIC';
    case Full       = 'FULL';
    case Honorary   = 'HONORARY';

    public function hasVotingRights(): bool       { return $this === self::Full; }
    public function isEligibleForBoard(): bool    { return $this === self::Full; }
    public function paysMembershipFee(): bool     { return $this === self::Basic || $this === self::Full; }
    public function paysSupporterFee(): bool      { return $this === self::Supporting; }
    public function allowsSubTier(): bool         { return $this === self::Supporting || $this === self::Basic; }

    public static function fromLegacyString(string $legacy): self
    {
        return match (strtolower($legacy)) {
            'supporter'           => self::Supporting,
            'basic', 'individual' => self::Basic,
            'full'                => self::Full,
            'honorary'            => self::Honorary,
            default               => throw new \InvalidArgumentException("Unknown legacy membership_type: {$legacy}"),
        };
    }
}
