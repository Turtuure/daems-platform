<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlProjectRepository;
use Daems\Infrastructure\Framework\Database\Connection;

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

$repo = new SqlProjectRepository($db);

$projects = [
    new Project(
        ProjectId::generate(),
        'daem-forums',
        'Daem Forums',
        'community',
        'bi-chat-dots',
        'An open discussion platform for members to share ideas, ask questions, and collaborate on community topics.',
        '<p>Daem Forums is the primary space for ongoing community dialogue within Daem Society. It is designed around the idea that the best ideas emerge from open, structured conversation — not just at events, but continuously between them.</p>

<h2>What It Is</h2>
<p>The forum is organised into topic categories covering governance, projects, events, and open discussion. Any member can start a thread, reply to existing discussions, or propose new categories. Moderation is handled by a rotating panel of volunteer members.</p>

<h2>Goals</h2>
<ul>
    <li>Provide a persistent space for community discussion that is not tied to any single event or moment.</li>
    <li>Surface member expertise and enable peer learning across the community.</li>
    <li>Support decision-making processes by giving members a structured place to deliberate.</li>
</ul>

<h2>Current Status</h2>
<p>The forum launched in 2025 and is actively used by members. Development is ongoing — upcoming improvements include improved search, email notifications, and threaded replies.</p>

<h2>Get Involved</h2>
<p>All members can participate in the forums immediately after joining. If you are interested in moderating, reach out to the Community team via the contact page.</p>',
        'active',
        1,
    ),
    new Project(
        ProjectId::generate(),
        'open-source-toolkit',
        'Open Source Toolkit',
        'technology',
        'bi-code-slash',
        'A shared library of open-source tools built by and for Daem Society members — freely available to all.',
        '<p>The Open Source Toolkit is a growing collection of utilities, templates, and small applications created by Daem Society members and released under open licences. It reflects our belief that the tools we build for ourselves are often useful to others too.</p>

<h2>What\'s Included</h2>
<p>Current tools in the toolkit include a membership onboarding checklist template, a meeting agenda and minutes format, a lightweight event feedback form builder, and several scripts for community data analysis. All tools are documented and maintained by their original contributors.</p>

<h2>Contributing</h2>
<p>Any member can contribute a tool to the toolkit. Submissions are reviewed by the Technology Committee for quality, documentation, and licence compatibility. Tools that are actively maintained and well-documented receive the "Maintained" badge.</p>
<ul>
    <li>Fork the repository and add your tool in its own directory.</li>
    <li>Include a README with purpose, usage instructions, and licence information.</li>
    <li>Open a pull request — the review process typically takes one to two weeks.</li>
</ul>

<h2>Licence</h2>
<p>All tools in the Toolkit are released under the MIT Licence unless otherwise noted. Contributions under other open licences are accepted on a case-by-case basis.</p>',
        'active',
        2,
    ),
    new Project(
        ProjectId::generate(),
        'annual-meetup-2026',
        'Annual Meetup 2026',
        'events',
        'bi-calendar-event',
        'Our flagship annual gathering — a full-day event bringing members together for talks, workshops, and networking.',
        '<p>The Annual Meetup is the highlight of the Daem Society calendar. Each year, we bring together members from across Finland and beyond for a full day of presentations, workshops, and community connection. The 2026 edition returns to Helsinki on 5 June.</p>

<h2>Programme</h2>
<p>The day runs from noon to early evening. Doors open at 12:00; the formal programme begins at 13:00 with a welcome from the board and a keynote from an invited guest speaker. The afternoon includes parallel workshop tracks, project updates from active teams, and open networking time.</p>
<ul>
    <li><strong>12:00</strong> — Doors open, registration, informal networking</li>
    <li><strong>13:00</strong> — Welcome and keynote</li>
    <li><strong>14:00</strong> — Workshop tracks (3 parallel sessions)</li>
    <li><strong>16:00</strong> — Project showcase and community updates</li>
    <li><strong>17:00</strong> — Open networking and close</li>
</ul>

<h2>Get Involved</h2>
<p>Members can propose a workshop or lightning talk via the Events section. We particularly welcome sessions led by members who want to share something they have been working on — it does not need to be polished, just useful or interesting to the community.</p>',
        'active',
        3,
    ),
    new Project(
        ProjectId::generate(),
        'member-survey-2026',
        'Member Survey 2026',
        'research',
        'bi-clipboard-data',
        'An annual survey to understand the needs, interests, and priorities of our members — results shared openly.',
        '<p>The Member Survey is Daem Society\'s primary tool for understanding who our members are, what they value, and how the association can better serve them. Results are shared openly with all members and inform the annual planning process.</p>

<h2>What We Ask</h2>
<p>The survey covers four areas: member demographics and background, satisfaction with current activities and communications, priorities for the coming year, and open feedback. It takes approximately ten minutes to complete and is available in English and Finnish.</p>

<h2>How Results Are Used</h2>
<p>Survey results are analysed by the Research Committee and presented at the Annual Meetup. A full report is published in the Insights section. Key findings directly inform the board\'s planning priorities for the following year — past surveys have led to changes in event formats, forum structure, and membership fee levels.</p>

<h2>2026 Timeline</h2>
<ul>
    <li><strong>April 2026</strong> — Survey design and member review period</li>
    <li><strong>May 2026</strong> — Survey live for four weeks</li>
    <li><strong>June 2026</strong> — Results presented at Annual Meetup</li>
    <li><strong>July 2026</strong> — Full report published in Insights</li>
</ul>',
        'active',
        4,
    ),
    new Project(
        ProjectId::generate(),
        'learning-hub',
        'Daem Learning Hub',
        'community',
        'bi-book',
        'A curated knowledge base where members share guides, tutorials, and resources on topics they care about.',
        '<p>The Daem Learning Hub is a member-maintained knowledge base — a living library of guides, tutorials, how-tos, and curated resources on topics the community cares about. It is built on the principle that every member knows something worth sharing.</p>

<h2>What You\'ll Find</h2>
<p>The Hub is organised into topic collections: digital tools and privacy, community organising and governance, open source development, research methods, and personal productivity. Each collection is maintained by a volunteer editor who reviews submissions and keeps content up to date.</p>

<h2>Contributing</h2>
<p>Any member can submit a guide or resource. Submissions go through a light editorial review to check for accuracy and clarity before publication. We especially welcome contributions that cover practical skills, lessons learned from community projects, or curated reading lists on topics relevant to civil society.</p>

<h2>Status</h2>
<p>The Learning Hub is currently in active development. An initial set of collections is live, with more planned for release through 2026. If you would like to volunteer as a collection editor, contact the Community team.</p>',
        'active',
        5,
    ),
    new Project(
        ProjectId::generate(),
        'platform-api',
        'Platform API',
        'technology',
        'bi-plug',
        'A public API for the Daem Society Platform — enabling integrations, bots, and third-party community tools.',
        '<p>The Platform API exposes Daem Society\'s public data — events, projects, insights, and more — through a structured REST interface. It is designed for members who want to build integrations, automation, or third-party tools on top of the association\'s infrastructure.</p>

<h2>What\'s Available</h2>
<p>The current API provides read access to public events, projects, and insights. Authentication endpoints for member-specific data are planned for a future release. The API follows REST conventions and returns JSON responses.</p>
<ul>
    <li><strong>GET /api/v1/events</strong> — List all events with optional type filter</li>
    <li><strong>GET /api/v1/events/{slug}</strong> — Get a single event by slug</li>
    <li><strong>GET /api/v1/projects</strong> — List all projects with optional category filter</li>
    <li><strong>GET /api/v1/insights</strong> — List all insights without body content</li>
    <li><strong>GET /api/v1/insights/{slug}</strong> — Get a single insight including full content</li>
</ul>

<h2>Getting Started</h2>
<p>No authentication is required for public endpoints. Rate limiting applies: 60 requests per minute per IP address. API documentation is available in the developer section of the member area.</p>

<h2>Roadmap</h2>
<p>Planned additions include member authentication (OAuth 2.0), forum read access, webhook support for event notifications, and a GraphQL endpoint alongside the REST API.</p>',
        'active',
        6,
    ),
];

foreach ($projects as $project) {
    $repo->save($project);
    echo "Seeded: {$project->slug()}\n";
}

echo "Done.\n";
