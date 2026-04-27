<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use DaemsModule\Events\Domain\Event;
use DaemsModule\Events\Domain\EventId;
use Daems\Domain\Tenant\TenantId;
use DaemsModule\Events\Infrastructure\SqlEventRepository;
use Daems\Infrastructure\Framework\Database\Connection;

// Load .env
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '') { $_ENV[$k] = $v; putenv("{$k}={$v}"); }
    }
}

$db = new Connection([
    'host'     => $_ENV['DB_HOST']     ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT']     ?? '3306',
    'database' => $_ENV['DB_DATABASE'] ?? 'daems_db',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
]);

$repo = new SqlEventRepository($db);

$daemsTenantIdRaw = $db->queryOne("SELECT id FROM tenants WHERE slug = 'daems'");
if ($daemsTenantIdRaw === null || !is_string($daemsTenantIdRaw['id'] ?? null)) {
    throw new RuntimeException("Tenant 'daems' not found — run migration 019 first.");
}
$t = TenantId::fromString($daemsTenantIdRaw['id']);

$events = [
    new Event(EventId::generate(), $t, 'annual-meetup-2026',  'Annual Meetup 2026',   'upcoming', '2026-06-05', '12:00–18:00', 'Helsinki',   false, 'Our flagship annual gathering — a full-day event bringing members together for talks, workshops, and networking. This year we return to Helsinki for a packed programme of presentations from members and invited guests, hands-on workshops, and plenty of time to connect with fellow members. Whether you\'re a founding member or newly joined, this is the highlight of the Daem Society calendar. Doors open at noon; the formal programme runs from 13:00 with workshops, project updates, and a keynote from a special guest speaker. The day closes with an informal networking session.', '/assets/img/events/annual-meetup-2026.webp', []),
    new Event(EventId::generate(), $t, 'community-workshop',  'Community Workshop',   'upcoming', '2026-08-12', '14:00–17:00', 'Espoo',      false, 'A hands-on workshop for members to collaborate on active projects and align on the next quarter\'s initiatives.',                                                                                                                                                                                                                                                                                                                                                                                                                                      null,                                              []),
    new Event(EventId::generate(), $t, 'online-qa-session',   'Online Q&A Session',   'online',   '2026-09-03', '18:00–19:30', 'Online',     true,  'Open Q&A with the board — ask anything about the association, upcoming projects, or membership.',                                                                                                                                                                                                                                                                                                                                                                                                                                                     '/assets/img/events/online-qa-session.webp',       []),
    new Event(EventId::generate(), $t, 'founding-day-2025',   'Founding Day 2025',    'past',     '2025-10-15', '15:00–20:00', 'Järvenpää',  false, 'Annual celebration of the association\'s founding — dinner, reflections, and looking ahead to the year to come.',                                                                                                                                                                                                                                                                                                                                                                                                                                      null,                                              []),
    new Event(EventId::generate(), $t, 'open-forum-2025',     'Open Forum 2025',      'past',     '2025-03-08', '17:00–19:00', 'Online',     true,  'An open discussion forum for members to share ideas, raise concerns, and collectively shape the direction of the association.',                                                                                                                                                                                                                                                                                                                                                                                                                         null,                                              []),
];

foreach ($events as $event) {
    $repo->save($event);
    echo "Seeded: {$event->slug()}\n";
}

echo "Done.\n";
