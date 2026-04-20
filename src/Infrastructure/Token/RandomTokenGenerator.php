<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Token;

use Daems\Domain\Invite\TokenGeneratorInterface;

final class RandomTokenGenerator implements TokenGeneratorInterface
{
    public function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
