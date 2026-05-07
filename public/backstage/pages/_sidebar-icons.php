<?php

declare(strict_types=1);

/**
 * Wave G3 — icon-name → inline SVG <path> markup for the BackstageSidebar
 * render path. Keys correspond to the `icon` field on SidebarEntry in
 * config/modules.php and on the hardcoded shell items in
 * src/Frontend/BackstageSidebar.php.
 *
 * Returning a global associative array (no class wrapper) keeps the require
 * cost minimal — layout.php just merges these into the same SVG element it
 * already used for the hardcoded sidebar items.
 *
 * @return array<string, string> icon name => raw SVG inner markup (no <svg> wrapper)
 */

return [
    // Shell
    'home' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>'
            . '<rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',

    'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',

    'settings' => '<circle cx="12" cy="12" r="3"/>'
                . '<path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',

    // Platform group
    'layers' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/>'
              . '<polyline points="2 17 12 22 22 17"/>'
              . '<polyline points="2 12 12 17 22 12"/>',

    // Module icons (must match `icon` strings in config/modules.php)
    'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
             . '<circle cx="9" cy="7" r="4"/>'
             . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',

    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>'
                . '<line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>'
                . '<line x1="3" y1="10" x2="21" y2="10"/>',

    'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',

    'forum' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',

    'edit' => '<path d="M12 20h9M12 4h9M3 8h4l2 4-2 4H3z"/>'
            . '<circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/>',

    'message-square' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',

    'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>'
            . '<path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
];
