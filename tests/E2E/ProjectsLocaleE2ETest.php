<?php
declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class ProjectsLocaleE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-21T12:00:00Z'));
    }

    public function test_accept_language_switches_project_content(): void
    {
        $this->h->projects->save(new Project(
            ProjectId::generate(),
            $this->h->testTenantId,
            'bi-slug',
            'Projekti',
            'community',
            'bi-folder',
            'Tiivistelmä',
            'Kuvaus',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            new TranslationMap([
                'fi_FI' => ['title' => 'Projekti', 'summary' => 'Tiivistelmä', 'description' => 'Kuvaus'],
                'en_GB' => ['title' => 'Project', 'summary' => 'Summary', 'description' => 'Description'],
            ]),
        ));

        $resp = $this->h->request('GET', '/api/v1/projects/bi-slug', [], ['Accept-Language' => 'en-GB']);
        $this->assertSame(200, $resp->status());
        $data = json_decode($resp->body(), true);
        $this->assertIsArray($data);
        $this->assertSame('Project', $data['data']['title']);
        $this->assertFalse($data['data']['title_fallback']);
    }

    public function test_fallback_marker_when_requested_locale_missing(): void
    {
        $this->h->projects->save(new Project(
            ProjectId::generate(),
            $this->h->testTenantId,
            'fb-proj',
            'Fallback',
            'research',
            'bi-book',
            'Summary',
            'Description',
            'active',
            0,
            null,
            false,
            date('Y-m-d H:i:s'),
            new TranslationMap([
                'en_GB' => ['title' => 'Fallback', 'summary' => 'Summary', 'description' => 'Description'],
            ]),
        ));

        $resp = $this->h->request('GET', '/api/v1/projects/fb-proj', [], ['Accept-Language' => 'fi-FI']);
        $this->assertSame(200, $resp->status());
        $data = json_decode($resp->body(), true);
        $this->assertIsArray($data);
        $this->assertSame('Fallback', $data['data']['title']);
        $this->assertTrue($data['data']['title_fallback']);
    }
}
