<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Dashboard\Support;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\Widget;
use Daems\Domain\Dashboard\WidgetCategory;
use Daems\Domain\Dashboard\WidgetSpan;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;

final class FakeWidget extends Widget
{
    public function __construct(
        private readonly string $id,
        private readonly ?string $module,
        private readonly MinRole $minRole,
        private readonly int $span = 1,
    ) {}

    public function id(): string                  { return $this->id; }
    public function category(): WidgetCategory    { return WidgetCategory::Numbers; }
    public function defaultSpan(): WidgetSpan     { return WidgetSpan::of($this->span); }
    public function minRole(): MinRole            { return $this->minRole; }
    public function module(): ?string             { return $this->module; }
    public function labelKey(): string            { return "label.{$this->id}"; }
    public function descriptionKey(): string      { return "desc.{$this->id}"; }
    public function render(TenantId $t, User $u): string { return "<!--{$this->id}-->"; }
    public function data(TenantId $t): array      { return ['id' => $this->id]; }
}
