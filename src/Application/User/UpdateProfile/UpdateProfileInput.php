<?php

declare(strict_types=1);

namespace Daems\Application\User\UpdateProfile;

final class UpdateProfileInput
{
    public function __construct(
        public readonly string $userId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $dob,
        public readonly string $country,
        public readonly string $addressStreet,
        public readonly string $addressZip,
        public readonly string $addressCity,
        public readonly string $addressCountry,
    ) {}
}
