<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant\Exception;

final class ModuleDependentEnabledException extends \DomainException
{
    /**
     * @param list<string> $blockingDependents
     */
    public static function for(string $moduleSlug, array $blockingDependents): self
    {
        $list = implode(', ', $blockingDependents);
        return new self(
            "Module '{$moduleSlug}' cannot be disabled: still required by enabled modules: {$list}"
        );
    }
}
