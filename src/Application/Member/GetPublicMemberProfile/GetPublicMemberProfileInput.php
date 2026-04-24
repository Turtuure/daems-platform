<?php
declare(strict_types=1);

namespace Daems\Application\Member\GetPublicMemberProfile;

final class GetPublicMemberProfileInput
{
    public function __construct(public readonly string $userId) {}
}
