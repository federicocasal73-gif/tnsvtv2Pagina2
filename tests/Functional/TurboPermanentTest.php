<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Guards against accidental removal of Turbo Drive integration in the shell.
 *
 * P10 / F10 commit 3 ships:
 *   - `import '@hotwired/turbo'` in `stimulus_bootstrap.js`
 *   - `data-turbo-permanent` on `#sanctum-sidebar`, `.sanctum-topbar`,
 *      and `#sanctum-floats` in `shell.html.twig`
 *
 * If any of these regress, the global mini-player (commit 4) loses its
 * mount point across navigation. These tests fail CI.
 */
class TurboPermanentTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = static::getContainer()->getParameter('kernel.project_dir');
    }

    private function read(string $relative): string
    {
        $fs = new Filesystem();
        $path = $this->projectDir . '/' . $relative;
        $this->assertTrue($fs->exists($path), 'Missing fixture: ' . $relative);
        return file_get_contents($path);
    }

    public function testStimulusBootstrapImportsTurbo(): void
    {
        $js = $this->read('src/assets/stimulus_bootstrap.js');
        $this->assertStringContainsString("import '@hotwired/turbo'", $js,
            'stimulus_bootstrap.js must import @hotwired/turbo so Turbo Drive intercepts navigations.');
    }

    public function testImportmapRegistersTurbo(): void
    {
        $importmap = $this->read('importmap.php');
        $this->assertStringContainsString("'@hotwired/turbo'", $importmap,
            'importmap.php must register the @hotwired/turbo package.');
        $this->assertStringContainsString('turbo.module.js', $importmap,
            '@hotwired/turbo must point at the vendored turbo.module.js.');
    }

    public function testUxTurboConfigEnablesCsrfHeaderCheck(): void
    {
        // P10 / F10 commit 3 — Symfony form submissions round-trip through
        // Turbo. The `framework.csrf_protection.check_header` flag must be on
        // so `csrf_protection_controller.js` can attach the token header.
        $this->assertFileExists($this->projectDir . '/config/packages/ux_turbo.yaml');
        $cfg = file_get_contents($this->projectDir . '/config/packages/ux_turbo.yaml');
        $this->assertStringContainsString('check_header: true', $cfg,
            'ux_turbo.yaml must enable framework.csrf_protection.check_header');
    }

    public function testSidebarIsTurboPermanent(): void
    {
        $tpl = $this->read('templates/shell.html.twig');
        // Match the sidebar opening tag + its permanence marker
        $this->assertMatchesRegularExpression(
            '/<aside[^>]*id="sanctum-sidebar"[^>]*data-turbo-permanent/i',
            $tpl,
            '#sanctum-sidebar must carry data-turbo-permanent so it survives Turbo visits.'
        );
    }

    public function testTopbarIsTurboPermanent(): void
    {
        $tpl = $this->read('templates/shell.html.twig');
        $this->assertMatchesRegularExpression(
            '/<header[^>]*class="sanctum-topbar"[^>]*data-turbo-permanent/i',
            $tpl,
            '.sanctum-topbar must carry data-turbo-permanent so the title/notification bell survives navigation.'
        );
    }

    public function testFloatsContainerIsTurboPermanent(): void
    {
        $tpl = $this->read('templates/shell.html.twig');
        // #sanctum-floats is the mount point for the mini-player (commit 4)
        // and the existing lightbox / command palette / onboarding singletons.
        $this->assertMatchesRegularExpression(
            '/<div[^>]*id="sanctum-floats"[^>]*data-turbo-permanent/i',
            $tpl,
            '#sanctum-floats must carry data-turbo-permanent so global UIs survive navigation.'
        );
    }

    public function testMainStaysReplaceable(): void
    {
        // The OUTER element <main class="sanctum-main"> is intentionally NOT
        // permanent: it's the surface Turbo swaps in/out on every visit.
        // Asserting this prevents someone from accidentally promoting it,
        // which would freeze the app on the first page.
        $tpl = $this->read('templates/shell.html.twig');
        $this->assertDoesNotMatchRegularExpression(
            '/<main[^>]*class="sanctum-main"[^>]*data-turbo-permanent/i',
            $tpl,
            '<main class="sanctum-main"> must NOT be permanent — it is the page surface Turbo replaces.'
        );
    }

    public function testCsrfControllerAlreadyKnowsAboutTurbo(): void
    {
        // csrf_protection_controller.js was authored against Turbo Drive
        // before the import was wired (commit c554680 era). It registers
        // submit-start/submit-end listeners that only fire once Turbo is
        // active. If a future refactor moves the file or strips the
        // listeners, CSRF breaks for form submissions through Turbo.
        $js = $this->read('src/assets/controllers/csrf_protection_controller.js');
        $this->assertStringContainsString('turbo:submit-start', $js);
        $this->assertStringContainsString('turbo:submit-end', $js);
    }
}
