<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RevokeAuthToken;

final class RevokeAuthTokenOutput
{
    public function __construct(public readonly bool $revoked = true) {}
}
