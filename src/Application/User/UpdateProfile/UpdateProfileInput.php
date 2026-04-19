<?php

declare(strict_types=1);

namespace Daems\Application\User\UpdateProfile;

use Daems\Domain\Auth\ActingUser;

final class UpdateProfileInput
{
    /**
     * Nullable fields are intentional: null means "not supplied; leave as-is".
     * An empty string means "set to empty". This distinction prevents an
     * omitted field from wiping the stored value (SAST F-002 residual).
     */
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $email = null,
        public readonly ?string $dob = null,
        public readonly ?string $country = null,
        public readonly ?string $addressStreet = null,
        public readonly ?string $addressZip = null,
        public readonly ?string $addressCity = null,
        public readonly ?string $addressCountry = null,
    ) {}
}
