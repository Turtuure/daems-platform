<?php
declare(strict_types=1);

/**
 * Platform module catalog.
 *
 * Each entry attaches platform-level metadata (sidebar placement, route
 * prefixes, i18n keys, dependency graph) to a module discovered under
 * c:/laragon/www/modules/<name>. Lives at the platform layer — NOT inside
 * each module's own module.json — so different deployments can sidebar-
 * group, mount, or omit modules without forking module sources.
 *
 * The shape consumed by ModuleRegistry::mergePlatformMetadata():
 *   string  name           => array{
 *     category:          string,
 *     name_key:          string,
 *     description_key:   string,
 *     is_core:           bool,
 *     default_available: bool,
 *     sidebar:           ?SidebarEntry,
 *     route_prefixes:    RoutePrefixes,
 *     depends_on:        list<string>,
 *   }
 *
 * Constraints (enforced by ModuleRegistry::validateGraph at boot):
 *   - Every catalog name must exist as a discovered module.
 *   - Every depends_on entry must exist as a discovered module.
 *   - Dependency graph must be acyclic.
 *   - route_prefixes across modules must NOT overlap.
 *   - is_core=true requires default_available=true.
 */

use Daems\Infrastructure\Module\RoutePrefixes;
use Daems\Infrastructure\Module\SidebarEntry;

return [
    'members' => [
        'category'          => 'members',
        'name_key'          => 'modules.members.name',
        'description_key'   => 'modules.members.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar'           => new SidebarEntry(
            group: 'governance',
            order: 5,
            icon: 'users',
            href: '/backstage/members',
        ),
        'route_prefixes'    => new RoutePrefixes(
            backstage: ['/backstage/members'],
            api: [
                '/api/v1/members',
                '/api/v1/applications',
                '/api/v1/backstage/members',
                '/api/v1/backstage/applications',
            ],
        ),
        'depends_on'        => [],
    ],

    'events' => [
        'category'          => 'content',
        'name_key'          => 'modules.events.name',
        'description_key'   => 'modules.events.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar'           => new SidebarEntry(
            group: 'content',
            order: 20,
            icon: 'calendar',
            href: '/backstage/events',
        ),
        'route_prefixes'    => new RoutePrefixes(
            backstage: ['/backstage/events'],
            api: [
                '/api/v1/events',
                '/api/v1/events-legacy',
                '/api/v1/event-proposals',
                '/api/v1/backstage/events',
                '/api/v1/backstage/event-proposals',
            ],
        ),
        'depends_on'        => [],
    ],

    'projects' => [
        'category'          => 'content',
        'name_key'          => 'modules.projects.name',
        'description_key'   => 'modules.projects.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar'           => new SidebarEntry(
            group: 'content',
            order: 21,
            icon: 'folder',
            href: '/backstage/projects',
        ),
        'route_prefixes'    => new RoutePrefixes(
            backstage: ['/backstage/projects', '/backstage/project-proposals'],
            api: [
                '/api/v1/projects',
                '/api/v1/projects-legacy',
                '/api/v1/project-comments',
                '/api/v1/project-proposals',
                '/api/v1/backstage/projects',
                '/api/v1/backstage/proposals',
                '/api/v1/backstage/comments',
            ],
        ),
        'depends_on'        => [],
    ],

    'forum' => [
        'category'          => 'community',
        'name_key'          => 'modules.forum.name',
        'description_key'   => 'modules.forum.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar'           => new SidebarEntry(
            group: 'community',
            order: 30,
            icon: 'forum',
            href: '/backstage/forum',
        ),
        'route_prefixes'    => new RoutePrefixes(
            backstage: ['/backstage/forum'],
            api: [
                '/api/v1/forum',
                '/api/v1/backstage/forum',
            ],
        ),
        'depends_on'        => [],
    ],

    'insights' => [
        'category'          => 'content',
        'name_key'          => 'modules.insights.name',
        'description_key'   => 'modules.insights.description',
        'is_core'           => false,
        'default_available' => true,
        'sidebar'           => new SidebarEntry(
            group: 'content',
            order: 40,
            icon: 'edit',
            href: '/backstage/insights',
        ),
        'route_prefixes'    => new RoutePrefixes(
            backstage: ['/backstage/insights'],
            api: [
                '/api/v1/insights',
                '/api/v1/backstage/insights',
            ],
        ),
        'depends_on'        => [],
    ],
];
