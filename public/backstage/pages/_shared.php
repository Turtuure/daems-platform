<?php
declare(strict_types=1);

/**
 * Include a PHP partial from C:\laragon\www\modules\shared\.
 *
 * @param string               $relPath Relative path WITHOUT '.php' suffix,
 *                                      e.g. 'components/cards/kpi-card/kpi-card'.
 * @param array<string, mixed> $vars    Variables made available to the partial
 *                                      (extracted with EXTR_SKIP so collisions
 *                                      with existing scope are silently ignored).
 *
 * @throws \RuntimeException If the resolved path is outside the modules/shared
 *                           sandbox or the file does not exist.
 */
function daems_shared_partial(string $relPath, array $vars = []): void {
    // _shared.php sits at daems-platform/public/backstage/pages/, so four ..
    // segments hop up to C:\laragon\www\ and into modules/shared from there.
    $base = realpath(__DIR__ . '/../../../../modules/shared');
    $file = $base !== false
          ? realpath($base . '/' . $relPath . '.php')
          : false;
    if ($base === false || $file === false || !str_starts_with($file, $base)) {
        throw new \RuntimeException("Shared partial not found or outside sandbox: $relPath");
    }
    extract($vars, EXTR_SKIP);
    require $file;
}
