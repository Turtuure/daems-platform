<?php

declare(strict_types=1);

namespace Daems\Application\User\DeleteAccount;

final class DeleteAccountOutput
{
    public function __construct(
        public readonly bool $deleted,
        public readonly ?string $error = null,
    ) {}
}
