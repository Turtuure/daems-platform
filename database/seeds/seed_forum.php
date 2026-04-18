<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

(static function (): void {
    $file = dirname(__DIR__, 2) . '/.env';
    if (file_exists($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = array_map('trim', explode('=', $line, 2));
            if ($key !== '' && !array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
            }
        }
    }
})();

use Daems\Domain\Forum\ForumCategory;
use Daems\Domain\Forum\ForumCategoryId;
use Daems\Domain\Forum\ForumPost;
use Daems\Domain\Forum\ForumPostId;
use Daems\Domain\Forum\ForumTopic;
use Daems\Domain\Forum\ForumTopicId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlForumRepository;
use Daems\Infrastructure\Framework\Database\Connection;

$db = new Connection([
    'host'     => $_ENV['DB_HOST']     ?? '127.0.0.1',
    'port'     => $_ENV['DB_PORT']     ?? '3306',
    'database' => $_ENV['DB_DATABASE'] ?? 'daems_db',
    'username' => $_ENV['DB_USERNAME'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
]);

$repo = new SqlForumRepository($db);

// ─── Categories ───────────────────────────────────────────────────────────────

$categories = [
    [
        'id'          => ForumCategoryId::fromString('01932c00-0000-7000-8000-000000000001'),
        'slug'        => 'general-discussion',
        'name'        => 'General Discussion',
        'icon'        => 'bi-chat-dots',
        'description' => 'Open conversations about the DAEM community, events, and everything in between.',
        'sort_order'  => 1,
    ],
    [
        'id'          => ForumCategoryId::fromString('01932c00-0000-7000-8000-000000000002'),
        'slug'        => 'introductions',
        'name'        => 'Introductions',
        'icon'        => 'bi-person-wave',
        'description' => 'New to the community? Tell us who you are and what drives you.',
        'sort_order'  => 2,
    ],
    [
        'id'          => ForumCategoryId::fromString('01932c00-0000-7000-8000-000000000003'),
        'slug'        => 'projects-initiatives',
        'name'        => 'Projects & Initiatives',
        'icon'        => 'bi-kanban',
        'description' => 'Discuss ongoing DAEM projects, propose new ideas, and collaborate.',
        'sort_order'  => 3,
    ],
    [
        'id'          => ForumCategoryId::fromString('01932c00-0000-7000-8000-000000000004'),
        'slug'        => 'events',
        'name'        => 'Events',
        'icon'        => 'bi-calendar-event',
        'description' => 'Upcoming events, meetups, and community gatherings.',
        'sort_order'  => 4,
    ],
    [
        'id'          => ForumCategoryId::fromString('01932c00-0000-7000-8000-000000000005'),
        'slug'        => 'resources',
        'name'        => 'Resources & Learning',
        'icon'        => 'bi-book',
        'description' => 'Share articles, guides, and learning materials with the community.',
        'sort_order'  => 5,
    ],
];

foreach ($categories as $c) {
    $repo->saveCategory(new ForumCategory(
        $c['id'], $c['slug'], $c['name'], $c['icon'], $c['description'], $c['sort_order'],
    ));
    echo "Category: {$c['name']}\n";
}

// ─── Topics ───────────────────────────────────────────────────────────────────

$generalId    = '01932c00-0000-7000-8000-000000000001';
$introId      = '01932c00-0000-7000-8000-000000000002';
$projectsId   = '01932c00-0000-7000-8000-000000000003';
$eventsId     = '01932c00-0000-7000-8000-000000000004';
$resourcesId  = '01932c00-0000-7000-8000-000000000005';

$topics = [
    // General — 8 topics
    [
        'id'               => '01932c00-0000-7000-8000-000000000101',
        'category_id'      => $generalId,
        'slug'             => 'welcome-to-the-daem-forum',
        'title'            => 'Welcome to the DAEM Forum!',
        'author_name'      => 'DAEM Admin',
        'avatar_initials'  => 'DA',
        'avatar_color'     => '#2c5f8a',
        'pinned'           => true,
        'reply_count'      => 12,
        'view_count'       => 341,
        'last_activity_at' => '2026-04-17 14:22:00',
        'last_activity_by' => 'Tuure T.',
        'created_at'       => '2026-03-01 10:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000102',
        'category_id'      => $generalId,
        'slug'             => 'forum-guidelines-and-rules',
        'title'            => 'Forum Guidelines & Community Rules',
        'author_name'      => 'DAEM Admin',
        'avatar_initials'  => 'DA',
        'avatar_color'     => '#2c5f8a',
        'pinned'           => true,
        'reply_count'      => 3,
        'view_count'       => 218,
        'last_activity_at' => '2026-03-05 09:15:00',
        'last_activity_by' => 'DAEM Admin',
        'created_at'       => '2026-03-01 10:05:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000103',
        'category_id'      => $generalId,
        'slug'             => 'thoughts-on-community-direction-2026',
        'title'            => 'Thoughts on our community direction for 2026',
        'author_name'      => 'Mikael K.',
        'avatar_initials'  => 'MK',
        'avatar_color'     => '#5a7a3a',
        'pinned'           => false,
        'reply_count'      => 8,
        'view_count'       => 127,
        'last_activity_at' => '2026-04-15 18:44:00',
        'last_activity_by' => 'Aino V.',
        'created_at'       => '2026-04-10 11:30:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000104',
        'category_id'      => $generalId,
        'slug'             => 'looking-for-accountability-partners',
        'title'            => 'Looking for accountability partners — anyone interested?',
        'author_name'      => 'Petra H.',
        'avatar_initials'  => 'PH',
        'avatar_color'     => '#8a4a6f',
        'pinned'           => false,
        'reply_count'      => 15,
        'view_count'       => 203,
        'last_activity_at' => '2026-04-16 20:11:00',
        'last_activity_by' => 'Joonas L.',
        'created_at'       => '2026-04-08 09:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000105',
        'category_id'      => $generalId,
        'slug'             => 'best-resources-for-entrepreneurship',
        'title'            => 'Best resources for early-stage entrepreneurship?',
        'author_name'      => 'Sami R.',
        'avatar_initials'  => 'SR',
        'avatar_color'     => '#6a4a8a',
        'pinned'           => false,
        'reply_count'      => 6,
        'view_count'       => 89,
        'last_activity_at' => '2026-04-14 12:30:00',
        'last_activity_by' => 'Petra H.',
        'created_at'       => '2026-04-12 08:45:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000106',
        'category_id'      => $generalId,
        'slug'             => 'how-daem-changed-my-perspective',
        'title'            => 'How DAEM changed my perspective on leadership',
        'author_name'      => 'Aino V.',
        'avatar_initials'  => 'AV',
        'avatar_color'     => '#3a7a6a',
        'pinned'           => false,
        'reply_count'      => 9,
        'view_count'       => 156,
        'last_activity_at' => '2026-04-13 17:05:00',
        'last_activity_by' => 'Mikael K.',
        'created_at'       => '2026-04-09 14:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000107',
        'category_id'      => $generalId,
        'slug'             => 'remote-collaboration-tips',
        'title'            => 'Tips for effective remote collaboration in teams',
        'author_name'      => 'Joonas L.',
        'avatar_initials'  => 'JL',
        'avatar_color'     => '#7a5a2a',
        'pinned'           => false,
        'reply_count'      => 4,
        'view_count'       => 72,
        'last_activity_at' => '2026-04-11 10:20:00',
        'last_activity_by' => 'Sami R.',
        'created_at'       => '2026-04-11 09:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000108',
        'category_id'      => $generalId,
        'slug'             => 'poll-preferred-meeting-time',
        'title'            => 'Poll: What time works best for monthly community calls?',
        'author_name'      => 'DAEM Admin',
        'avatar_initials'  => 'DA',
        'avatar_color'     => '#2c5f8a',
        'pinned'           => false,
        'reply_count'      => 22,
        'view_count'       => 289,
        'last_activity_at' => '2026-04-17 09:50:00',
        'last_activity_by' => 'Petra H.',
        'created_at'       => '2026-04-05 12:00:00',
    ],

    // Introductions — 2 topics
    [
        'id'               => '01932c00-0000-7000-8000-000000000201',
        'category_id'      => $introId,
        'slug'             => 'introduce-yourself',
        'title'            => 'Introduce Yourself — Tell Us Your Story',
        'author_name'      => 'DAEM Admin',
        'avatar_initials'  => 'DA',
        'avatar_color'     => '#2c5f8a',
        'pinned'           => true,
        'reply_count'      => 5,
        'view_count'       => 412,
        'last_activity_at' => '2026-04-17 16:30:00',
        'last_activity_by' => 'Sami R.',
        'created_at'       => '2026-03-01 10:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000202',
        'category_id'      => $introId,
        'slug'             => 'what-brought-you-to-daem',
        'title'            => 'What brought you to DAEM? Share your journey.',
        'author_name'      => 'Tuure T.',
        'avatar_initials'  => 'TT',
        'avatar_color'     => '#2a6a8a',
        'pinned'           => false,
        'reply_count'      => 7,
        'view_count'       => 183,
        'last_activity_at' => '2026-04-16 11:00:00',
        'last_activity_by' => 'Aino V.',
        'created_at'       => '2026-03-15 14:00:00',
    ],

    // Projects — 2 topics
    [
        'id'               => '01932c00-0000-7000-8000-000000000301',
        'category_id'      => $projectsId,
        'slug'             => 'open-source-mentorship-platform',
        'title'            => 'Proposal: Open-source mentorship matching platform',
        'author_name'      => 'Mikael K.',
        'avatar_initials'  => 'MK',
        'avatar_color'     => '#5a7a3a',
        'pinned'           => false,
        'reply_count'      => 11,
        'view_count'       => 145,
        'last_activity_at' => '2026-04-15 19:30:00',
        'last_activity_by' => 'Joonas L.',
        'created_at'       => '2026-04-07 10:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000302',
        'category_id'      => $projectsId,
        'slug'             => 'daem-podcast-series',
        'title'            => 'DAEM Podcast Series — looking for co-hosts and guests',
        'author_name'      => 'Aino V.',
        'avatar_initials'  => 'AV',
        'avatar_color'     => '#3a7a6a',
        'pinned'           => false,
        'reply_count'      => 5,
        'view_count'       => 98,
        'last_activity_at' => '2026-04-14 14:00:00',
        'last_activity_by' => 'Sami R.',
        'created_at'       => '2026-04-10 09:30:00',
    ],

    // Events — 2 topics
    [
        'id'               => '01932c00-0000-7000-8000-000000000401',
        'category_id'      => $eventsId,
        'slug'             => 'daem-spring-gathering-2026',
        'title'            => 'DAEM Spring Gathering 2026 — Save the Date!',
        'author_name'      => 'DAEM Admin',
        'avatar_initials'  => 'DA',
        'avatar_color'     => '#2c5f8a',
        'pinned'           => true,
        'reply_count'      => 18,
        'view_count'       => 334,
        'last_activity_at' => '2026-04-17 20:00:00',
        'last_activity_by' => 'Petra H.',
        'created_at'       => '2026-03-20 12:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000402',
        'category_id'      => $eventsId,
        'slug'             => 'online-workshop-leadership-may',
        'title'            => 'Online Workshop: Leadership in Uncertain Times (May)',
        'author_name'      => 'Tuure T.',
        'avatar_initials'  => 'TT',
        'avatar_color'     => '#2a6a8a',
        'pinned'           => false,
        'reply_count'      => 6,
        'view_count'       => 112,
        'last_activity_at' => '2026-04-16 15:45:00',
        'last_activity_by' => 'Mikael K.',
        'created_at'       => '2026-04-08 11:00:00',
    ],

    // Resources — 2 topics
    [
        'id'               => '01932c00-0000-7000-8000-000000000501',
        'category_id'      => $resourcesId,
        'slug'             => 'recommended-books-2026',
        'title'            => 'Recommended Books for 2026 — Community List',
        'author_name'      => 'Petra H.',
        'avatar_initials'  => 'PH',
        'avatar_color'     => '#8a4a6f',
        'pinned'           => false,
        'reply_count'      => 14,
        'view_count'       => 231,
        'last_activity_at' => '2026-04-17 08:30:00',
        'last_activity_by' => 'Joonas L.',
        'created_at'       => '2026-01-10 09:00:00',
    ],
    [
        'id'               => '01932c00-0000-7000-8000-000000000502',
        'category_id'      => $resourcesId,
        'slug'             => 'free-tools-for-nonprofits',
        'title'            => 'Free and discounted tools for nonprofits and community orgs',
        'author_name'      => 'Joonas L.',
        'avatar_initials'  => 'JL',
        'avatar_color'     => '#7a5a2a',
        'pinned'           => false,
        'reply_count'      => 8,
        'view_count'       => 177,
        'last_activity_at' => '2026-04-13 16:00:00',
        'last_activity_by' => 'Aino V.',
        'created_at'       => '2026-02-14 14:00:00',
    ],
];

foreach ($topics as $t) {
    $repo->saveTopic(new ForumTopic(
        ForumTopicId::fromString($t['id']),
        $t['category_id'],
        $t['slug'],
        $t['title'],
        $t['author_name'],
        $t['avatar_initials'],
        $t['avatar_color'],
        $t['pinned'],
        $t['reply_count'],
        $t['view_count'],
        $t['last_activity_at'],
        $t['last_activity_by'],
        $t['created_at'],
    ));
    echo "Topic: {$t['title']}\n";
}

// ─── Posts for "introduce-yourself" ──────────────────────────────────────────

$introTopicId = '01932c00-0000-7000-8000-000000000201';

$posts = [
    [
        'id'              => '01932c00-0000-7000-8000-000000000901',
        'topic_id'        => $introTopicId,
        'author_name'     => 'DAEM Admin',
        'avatar_initials' => 'DA',
        'avatar_color'    => '#2c5f8a',
        'role'            => 'Admin',
        'role_class'      => 'role-admin',
        'joined_text'     => 'Joined March 2026',
        'content'         => "Welcome to the DAEM community forum! This thread is the perfect place to introduce yourself — tell us your name, where you're from, what you're passionate about, and what brought you here. We're excited to have you with us.\n\nDon't be shy — every great community starts with one hello!",
        'likes'           => 24,
        'created_at'      => '2026-03-01 10:00:00',
        'sort_order'      => 1,
    ],
    [
        'id'              => '01932c00-0000-7000-8000-000000000902',
        'topic_id'        => $introTopicId,
        'author_name'     => 'Tuure T.',
        'avatar_initials' => 'TT',
        'avatar_color'    => '#2a6a8a',
        'role'            => 'Member',
        'role_class'      => 'role-member',
        'joined_text'     => 'Joined March 2026',
        'content'         => "Hey everyone! I'm Tuure, based in Helsinki. I've been involved in tech entrepreneurship for the past few years and found DAEM through a mutual friend. Really excited about the mentorship initiatives and looking forward to connecting with like-minded people here.",
        'likes'           => 11,
        'created_at'      => '2026-03-02 09:15:00',
        'sort_order'      => 2,
    ],
    [
        'id'              => '01932c00-0000-7000-8000-000000000903',
        'topic_id'        => $introTopicId,
        'author_name'     => 'Aino V.',
        'avatar_initials' => 'AV',
        'avatar_color'    => '#3a7a6a',
        'role'            => 'Member',
        'role_class'      => 'role-member',
        'joined_text'     => 'Joined March 2026',
        'content'         => "Hi! I'm Aino from Tampere. I work in education and have a strong interest in community leadership and sustainable development. I joined DAEM because I believe in the power of networks to create real, lasting change. Looking forward to learning from everyone here!",
        'likes'           => 9,
        'created_at'      => '2026-03-03 14:30:00',
        'sort_order'      => 3,
    ],
    [
        'id'              => '01932c00-0000-7000-8000-000000000904',
        'topic_id'        => $introTopicId,
        'author_name'     => 'Mikael K.',
        'avatar_initials' => 'MK',
        'avatar_color'    => '#5a7a3a',
        'role'            => 'Member',
        'role_class'      => 'role-member',
        'joined_text'     => 'Joined March 2026',
        'content'         => "Hello everyone! Mikael here, from Oulu. Background in product development and I've recently shifted focus toward impact-driven work. DAEM's vision really resonates with me. Happy to be here and can't wait to contribute!",
        'likes'           => 7,
        'created_at'      => '2026-03-10 11:00:00',
        'sort_order'      => 4,
    ],
    [
        'id'              => '01932c00-0000-7000-8000-000000000905',
        'topic_id'        => $introTopicId,
        'author_name'     => 'Sami R.',
        'avatar_initials' => 'SR',
        'avatar_color'    => '#6a4a8a',
        'role'            => 'Supporter',
        'role_class'      => 'role-supporter',
        'joined_text'     => 'Joined April 2026',
        'content'         => "Hey! I'm Sami, currently in Turku. I'm a supporter of DAEM and really believe in what this community stands for. My background is in finance but I'm passionate about social entrepreneurship. Glad to finally be active in the forum — this feels like a great space!",
        'likes'           => 5,
        'created_at'      => '2026-04-17 16:30:00',
        'sort_order'      => 5,
    ],
];

foreach ($posts as $p) {
    $repo->savePost(new ForumPost(
        ForumPostId::fromString($p['id']),
        $p['topic_id'],
        $p['author_name'],
        $p['avatar_initials'],
        $p['avatar_color'],
        $p['role'],
        $p['role_class'],
        $p['joined_text'],
        $p['content'],
        $p['likes'],
        $p['created_at'],
        $p['sort_order'],
    ));
    echo "Post: {$p['author_name']}\n";
}

echo "Forum seeded successfully.\n";
