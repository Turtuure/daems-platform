<?php

declare(strict_types=1);

namespace Daems\Application\Membership\SubmitMemberApplication;

final class SubmitMemberApplicationInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $dateOfBirth,
        public readonly ?string $country,
        public readonly string $motivation,
        public readonly ?string $howHeard,
    ) {}
}
