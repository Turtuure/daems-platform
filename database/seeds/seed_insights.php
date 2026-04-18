<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlInsightRepository;
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

$repo = new SqlInsightRepository($db);

$insights = [
    new Insight(
        InsightId::generate(),
        'ai-in-civil-society',
        'How AI Is Reshaping Civil Society',
        'analysis',
        'Analysis',
        true,
        '2026-03-20',
        'Daem Society Board',
        6,
        'Artificial intelligence is no longer a distant prospect — it is already transforming how associations, NGOs, and community organisations operate. We explore what this means for civil society and how Daem Society is responding.',
        null,
        ['AI', 'Policy', 'Civil Society', 'Technology'],
        '<p>Artificial intelligence is reshaping every corner of society — and civil society organisations are no exception. From automating administrative tasks to enabling new forms of community engagement, AI presents both opportunities and risks that associations like ours must engage with thoughtfully.</p>

<h2>The Opportunity</h2>
<p>For resource-constrained organisations, AI tools offer meaningful efficiency gains. Meeting transcription, document drafting, member communication, and event planning can all be supported by AI assistants — freeing up volunteer time for the work that truly requires human judgement and relationship-building.</p>
<p>Beyond administration, AI opens up new possibilities for community intelligence. Analysing member feedback at scale, identifying emerging themes in forum discussions, or surfacing relevant resources for members are tasks that were previously too labour-intensive for small organisations to attempt.</p>

<h2>The Risks</h2>
<p>The same tools that streamline operations can also introduce new risks. Over-reliance on AI-generated content can erode the authentic voice that makes civil society organisations distinctive. Algorithmic systems can embed biases that disadvantage certain members. And the concentration of powerful AI capabilities in the hands of a few large providers raises questions about data sovereignty and organisational independence.</p>
<blockquote>
    <p>Civil society organisations exist precisely because some values — trust, participation, accountability — cannot be optimised away. AI must serve those values, not substitute for them.</p>
</blockquote>

<h2>Daem Society\'s Position</h2>
<p>We believe in using AI as a tool that amplifies human capability, not one that replaces human judgement. Our approach is guided by three principles:</p>
<ul>
    <li><strong>Transparency:</strong> We will be open about when and how we use AI in our operations and communications.</li>
    <li><strong>Member control:</strong> Decisions that affect members will continue to be made by members — AI may inform those decisions, but it will not make them.</li>
    <li><strong>Ongoing review:</strong> We will regularly assess our use of AI tools and their impact on our community, adjusting course where needed.</li>
</ul>
<p>We invite members to engage with us on this topic. The Forum has a dedicated thread where you can share your views, experiences, and concerns about AI in the context of our community.</p>',
    ),
    new Insight(
        InsightId::generate(),
        'open-source-community',
        'Building Open Source Communities That Last',
        'report',
        'Report',
        true,
        '2026-02-11',
        'Projects Committee',
        5,
        'A summary of our research into how successful open source communities sustain contributor engagement over time — and what lessons apply to member-run associations like Daem Society.',
        null,
        ['Open Source', 'Community', 'Governance', 'Research'],
        '<p>Over the past quarter, the Projects Committee conducted a review of long-lived open source communities to understand what sustains contributor engagement beyond the initial excitement of a new project. This report summarises our findings and their implications for Daem Society.</p>

<h2>What We Studied</h2>
<p>We looked at twelve open source projects that have maintained active contributor communities for more than five years, spanning a range of sizes, governance models, and domains. We analysed their governance documents, contributor guidelines, decision-making processes, and public communications over time.</p>

<h2>Key Findings</h2>
<p>Three factors emerged consistently across the communities we studied:</p>
<ul>
    <li><strong>Clear contribution pathways:</strong> Healthy communities make it easy to go from newcomer to trusted contributor. They document not just how to contribute code, but how to earn trust, take on responsibility, and eventually influence direction.</li>
    <li><strong>Lightweight governance with escalation paths:</strong> The most durable communities avoid both anarchy and bureaucracy. They operate informally by default, but have clear processes for resolving disagreements when they arise.</li>
    <li><strong>Visible recognition:</strong> Contributors who feel their work is seen and valued stay engaged. This doesn\'t require elaborate reward systems — public acknowledgement, changelog attribution, and leadership opportunities are often sufficient.</li>
</ul>

<h2>Implications for Daem Society</h2>
<p>These findings resonate with our own experience. We have seen member engagement rise when we launch new projects with clear onboarding, and plateau when contribution pathways are unclear.</p>
<blockquote>
    <p>The best community infrastructure is nearly invisible — it just makes it easy to do the right thing and hard to feel lost.</p>
</blockquote>
<p>Based on this research, the Projects Committee recommends three initiatives for the coming year: a contributor guide for each active project, a lightweight RFC process for significant decisions, and a quarterly member spotlight in the Insights section.</p>
<p>The full research notes are available in the member area. We welcome feedback and discussion in the Forums.</p>',
    ),
    new Insight(
        InsightId::generate(),
        'digital-rights-2025',
        'Digital Rights in 2025: Where Do We Stand?',
        'opinion',
        'Opinion',
        false,
        '2026-01-08',
        'Member Contributor',
        4,
        'A member perspective on the state of digital rights heading into 2026 — from encryption battles and platform accountability to the growing importance of data literacy for ordinary people.',
        null,
        ['Digital Rights', 'Privacy', 'Policy', 'Opinion'],
        '<p><em>This piece represents the views of a contributing member and not the official position of Daem Society.</em></p>

<p>2025 was a year of contradictions for digital rights. In some respects, we made genuine progress — strong encryption became the default in more platforms, several countries strengthened data protection frameworks, and digital literacy initiatives reached millions of people who had previously been excluded from the conversation.</p>

<h2>The Wins</h2>
<p>It would be wrong to start with the bad news when there is real progress to acknowledge. End-to-end encryption is no longer a specialist concern — it is now a baseline expectation for messaging apps, and the political battles to mandate backdoors have, at least for now, stalled in most jurisdictions.</p>
<p>Data portability has also improved meaningfully. Several major platforms now support standardised export formats, making it genuinely easier for users to move between services. This is not yet the interoperability we need, but it is a step in the right direction.</p>

<h2>The Ongoing Concerns</h2>
<p>Against those wins, the concentration of power in a small number of technology platforms has continued unchecked. Content moderation remains opaque, inconsistent, and often arbitrary. Algorithmic systems shape public discourse in ways that are not well understood even by the organisations deploying them.</p>
<blockquote>
    <p>Digital rights are not a technical question. They are a question about what kind of society we want to live in — and who gets to decide.</p>
</blockquote>
<p>Perhaps most troublingly, the gap between those who understand their digital rights and those who do not has widened. Informed users can navigate privacy settings, use VPNs, and make deliberate choices about the platforms they use. But for the majority, the default settings — designed with commercial interests in mind — remain the actual settings.</p>

<h2>What Associations Like Ours Can Do</h2>
<p>Civil society organisations have a particular role to play in digital rights — not as campaigners necessarily, but as educators and exemplars. When Daem Society chooses open platforms, respects member data, and builds transparent governance, we are making an argument through practice.</p>
<p>I would encourage more members to engage with digital rights questions — not just as abstract policy debates, but as concrete choices that affect our community every day. The Forums are a good place to start.</p>',
    ),
];

foreach ($insights as $insight) {
    $repo->save($insight);
    echo "Seeded: {$insight->slug()}\n";
}

echo "Done.\n";
