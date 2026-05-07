<?php
/**
 * Locale-cards partial — shared by event/project editors.
 *
 * Usage:
 *   $kind = 'event' | 'project';
 *   $entityId = UUID string (may be empty on create mode).
 *   include __DIR__ . '/../shared/locale-cards.php';
 *
 * The JS (locale-cards.js) populates the grid + fields from the entity
 * payload after mount() is called with { kind, entityId, translations,
 * coverage }. Empty state is safe: renders placeholders with 0/N coverage.
 */

declare(strict_types=1);

$kind     = $kind     ?? 'event';
$entityId = $entityId ?? '';
?>
<div class="locale-cards-container"
     data-kind="<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>"
     data-entity-id="<?= htmlspecialchars($entityId, ENT_QUOTES, 'UTF-8') ?>">
    <div class="locale-cards-grid" role="tablist" aria-label="Locale translations"></div>
    <div class="locale-cards-editor">
        <div class="locale-cards-fields"></div>
        <div class="locale-cards-actions">
            <button type="button" class="btn btn--primary locale-cards-save">Save</button>
            <span class="locale-cards-status" aria-live="polite"></span>
        </div>
    </div>
</div>
