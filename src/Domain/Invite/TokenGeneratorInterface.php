<?php

declare(strict_types=1);

namespace Daems\Domain\Invite;

interface TokenGeneratorInterface
{
    /** Returns a url-safe random string of at least 32 bytes of entropy. */
    public function generate(): string;
}
