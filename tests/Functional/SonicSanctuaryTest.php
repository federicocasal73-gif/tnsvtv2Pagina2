<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Sprint 4.4 — regression tests for the Sonic Sanctuary (cross-page audio
 * player). The tests are static-only (no WebTestCase) because the controller
 * is a Stimulus frontend module; the only thing we can verify from PHP is
 * that the templates / JS / CSS / partials are wired correctly so that when
 * the compiled bundle reaches the browser, nothing critical is missing.
 *
 * Scope:
 *   - shell.html.twig mounts sonic-sanctuary with all expected targets
 *   - shell.html.twig only mounts the player when app.user is set
 *   - shell.html.twig links sonic-sanctuary.css
 *   - the partial _partials/sonic_sanctuary_panel.html.twig has the 3 tabs
 *   - users.html.twig wires the Global Temple Broadcast to sonic:global-*
 *   - sonic_sanctuary_controller.js declares every target the template uses
 *   - sonic-sanctuary.css defines the classes the template references
 *
 * These tests complement TurboPermanentTest::testGlobalMiniPlayerIsMountedInFloats
 * (which only checks that SOME controller mounts — these check the details).
 */
class SonicSanctuaryTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
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

    // ─── shell.html.twig ────────────────────────────────────────────

    public function testShellLinksSonicSanctuaryCss(): void
    {
        // The CSS must be linked from the global shell so the side panel
        // and mini-player render correctly on every page.
        $tpl = $this->read('templates/shell.html.twig');
        $this->assertStringContainsString(
            "styles/sonic-sanctuary.css",
            $tpl,
            'shell.html.twig must link sonic-sanctuary.css so the audio player styles load on every page.'
        );
    }

    public function testShellRendersAllSonicSanctuaryTargets(): void
    {
        // Targets that are defined directly in shell.html.twig (the mini-player
        // + the launcher button). The panel partial defines the rest — see
        // testControllerDeclaresEveryTargetTemplateUses below. Each Stimulus
        // target must be declared in the template where it's used so the
        // framework can resolve `this.targetNameTarget`. Missing → silent breakage.
        $tpl = $this->read('templates/shell.html.twig');
        $expectedTargets = [
            'miniPlayer',
            'miniLauncher',
            'miniTitle',
            'miniElapsed',
            'miniDuration',
            'miniPlayBtn',
            'miniIcon',
        ];
        foreach ($expectedTargets as $target) {
            $this->assertStringContainsString(
                "data-sonic-sanctuary-target=\"$target\"",
                $tpl,
                "shell.html.twig is missing sonic-sanctuary target: $target"
            );
        }
    }

    public function testShellGatesSonicSanctuaryBehindAppUser(): void
    {
        // Anonymous visitors must NOT see the player — it has no purpose
        // for them (no presets, no uploads, no global playlist UI). The
        // {% if app.user %} block in shell.html.twig enforces this; we
        // assert the gating is in place at the source-code level (a
        // rendered page is tested via WebTestCase elsewhere).
        $tpl = $this->read('templates/shell.html.twig');
        $this->assertMatchesRegularExpression(
            '/\{%\s*if\s+app\.user\s*%\}[\s\S]*?data-controller="sonic-sanctuary"[\s\S]*?\{%\s*endif\s*%\}/m',
            $tpl,
            'data-controller="sonic-sanctuary" must be wrapped in {% if app.user %} so anonymous visitors never see the player.'
        );
    }

    // ─── panel partial ──────────────────────────────────────────────

    public function testPanelHasThreeTabs(): void
    {
        // The 3 tabs are the user's mental model for the player. Without
        // them, the panel is just a blank panel.
        $partial = $this->read('templates/_partials/sonic_sanctuary_panel.html.twig');
        foreach (['temploTab', 'mineTab', 'globalTab'] as $tab) {
            $this->assertStringContainsString(
                "data-sonic-sanctuary-target=\"$tab\"",
                $partial,
                "panel partial must declare target: $tab"
            );
        }
        // The Templo tab is the default — verify the button carrying
        // data-tab="templo" also has the is-active class (any attribute order).
        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*\bdata-tab="templo")(?=[^>]*\bclass="[^"]*\bis-active\b)[^>]*>/',
            $partial,
            'panel partial must mark the Templo tab as the default active tab on first load.'
        );
        // Each tab has a distinct icon + label so users can find their source
        $this->assertStringContainsString('Templo', $partial);
        $this->assertStringContainsString('Mías', $partial);
        $this->assertStringContainsString('Global', $partial);
    }

    public function testPanelHasDropZone(): void
    {
        // Drag-drop is the killer feature; verify the partial wires up the
        // file input + drop overlay + upload button.
        $partial = $this->read('templates/_partials/sonic_sanctuary_panel.html.twig');
        $this->assertStringContainsString(
            'data-sonic-sanctuary-target="dropOverlay"',
            $partial,
            'panel partial must declare dropOverlay target so drag-drop visual feedback works.'
        );
        $this->assertStringContainsString(
            'data-sonic-sanctuary-target="fileInput"',
            $partial,
            'panel partial must declare fileInput target so triggerUpload() can open the picker.'
        );
        $this->assertStringContainsString(
            'triggerUpload',
            $partial,
            'panel partial must wire a "Subir" button to click->sonic-sanctuary#triggerUpload.'
        );
    }

    public function testPanelHasKeyboardHints(): void
    {
        // The keyboard shortcuts (Space/M/E/←/→) are a power-user feature;
        // the partial should display them so users discover them.
        $partial = $this->read('templates/_partials/sonic_sanctuary_panel.html.twig');
        $this->assertStringContainsString(
            'data-sonic-sanctuary-target="keyboardHints"',
            $partial,
            'panel partial must declare keyboardHints target so the hint badges can be updated.'
        );
        foreach (['Space', 'M', 'E'] as $key) {
            $this->assertStringContainsString(
                "<kbd>$key</kbd>",
                $partial,
                "panel partial must show keyboard hint: $key"
            );
        }
    }

    public function testPanelAcceptsAudioMimeTypes(): void
    {
        // The drag-drop file input must accept only audio formats. If we
        // loosen to "image/*" by accident, users would see confusing errors.
        $partial = $this->read('templates/_partials/sonic_sanctuary_panel.html.twig');
        $this->assertStringContainsString(
            'accept="audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/ogg,audio/x-vorbis+ogg"',
            $partial,
            'file input must restrict to audio MIME types (mp3, wav, ogg).'
        );
    }

    // ─── sonic_sanctuary_controller.js ──────────────────────────────

    public function testControllerDeclaresEveryTargetTemplateUses(): void
    {
        // Defence in depth: every data-sonic-sanctuary-target="X" in either
        // shell.html.twig or the panel partial must be declared as
        // static targets in the controller. If someone adds a new target to
        // a template but forgets to add it to the JS, Stimulus silently
        // no-ops the action.
        $controller = $this->read('src/assets/controllers/sonic_sanctuary_controller.js');
        $usedInTemplates = $this->collectTemplateTargets();
        $declaredInController = $this->parseStaticTargets($controller);

        foreach ($usedInTemplates as $target) {
            $this->assertContains(
                $target,
                $declaredInController,
                "Target '$target' is used in templates but missing from sonic_sanctuary_controller's static targets list."
            );
        }
    }

    public function testControllerHandlesGlobalBroadcastEvents(): void
    {
        // The "Global Temple Broadcast" widget in /sanctum/users dispatches
        // these three CustomEvents. The controller must listen for them or
        // the decorative widget stays decorative.
        $controller = $this->read('src/assets/controllers/sonic_sanctuary_controller.js');
        foreach (['sonic:global-play', 'sonic:global-prev', 'sonic:global-next'] as $evt) {
            $this->assertStringContainsString(
                "'$evt'",
                $controller,
                "sonic_sanctuary_controller.js must addEventListener('$evt')."
            );
        }
    }

    public function testControllerHasOnlyOneAnalyserDestinationConnection(): void
    {
        // B1 from Sprint 4.1: `analyser.connect(audioCtx.destination)` was
        // duplicated 4 times, causing the audio graph to grow unboundedly.
        // The fix moves the connection into ensureAudioCtx(). This test
        // guards against regression.
        $controller = $this->read('src/assets/controllers/sonic_sanctuary_controller.js');
        // Allow 1 active callsite + the explanatory comment that mentions
        // the bug name (which counts as text but not code).
        $matches = preg_match_all('/this\.analyser\.connect\(this\.audioCtx\.destination\)/', $controller);
        $this->assertSame(
            1,
            $matches,
            "sonic_sanctuary_controller.js must call analyser.connect(destination) exactly once (got {$matches})."
        );
    }

    public function testControllerHasKeyboardShortcuts(): void
    {
        // The keyboard shortcuts are advertised in the panel UI. Verify
        // the controller actually listens for them. (M and E use toLowerCase()
        // so caps lock / shift don't break the shortcut.)
        $controller = $this->read('src/assets/controllers/sonic_sanctuary_controller.js');
        $this->assertStringContainsString("e.key === ' '", $controller,
            "sonic_sanctuary_controller.js must handle Space (play/pause).");
        $this->assertStringContainsString("e.key.toLowerCase() === 'm'", $controller,
            "sonic_sanctuary_controller.js must handle M (mute).");
        $this->assertStringContainsString("e.key.toLowerCase() === 'e'", $controller,
            "sonic_sanctuary_controller.js must handle E (toggle panel).");
        foreach (["'ArrowLeft'", "'ArrowRight'"] as $key) {
            $this->assertStringContainsString(
                "e.key === $key",
                $controller,
                "sonic_sanctuary_controller.js must handle $key (prev/next)."
            );
        }
    }

    public function testControllerCallsStopCurrentOnDeleteMine(): void
    {
        // B3 from Sprint 4.1: deleteMine() must call stopCurrent() when
        // the deleted track is currently playing. Otherwise audio keeps
        // streaming from the now-deleted entity.
        $controller = $this->read('src/assets/controllers/sonic_sanctuary_controller.js');
        $this->assertStringContainsString(
            'async deleteMine(',
            $controller,
            'sonic_sanctuary_controller.js must expose deleteMine().'
        );
        // Locate the deleteMine method body and check for stopCurrent() call.
        $this->assertMatchesRegularExpression(
            '/async\s+deleteMine[\s\S]*?if\s*\(\s*this\.activeSource\s*===\s*[\'"]mine[\'"][\s\S]*?this\.stopCurrent\(\)/m',
            $controller,
            'deleteMine() must call stopCurrent() when the deleted id matches activeTrackId in source "mine".'
        );
    }

    public function testControllerCleansUpBodyClassOnDisconnect(): void
    {
        // B4 from Sprint 4.1: disconnect() must remove the `sonic-panel-open`
        // body class so logout/transition with the panel open doesn't leave
        // the page frozen (overflow:hidden).
        $controller = $this->read('src/assets/controllers/sonic_sanctuary_controller.js');
        $this->assertMatchesRegularExpression(
            '/disconnect\(\)\s*\{[\s\S]*?document\.body\.classList\.remove\([\'"]sonic-panel-open[\'"]\)/m',
            $controller,
            'disconnect() must remove the body.sonic-panel-open class to avoid scroll-lock on logout.'
        );
    }

    // ─── users.html.twig ────────────────────────────────────────────

    public function testUsersPageWiresGlobalBroadcast(): void
    {
        // The decorative Global Temple Broadcast widget in /sanctum/users
        // used to be inert. After Phase 3 it must dispatch sonic:global-*
        // events. Verify the onclick attributes are present.
        $tpl = $this->read('templates/sanctum/users.html.twig');
        foreach (['sonic:global-play', 'sonic:global-prev', 'sonic:global-next'] as $evt) {
            $this->assertStringContainsString(
                "'$evt'",
                $tpl,
                "users.html.twig must dispatch $evt on Global Temple Broadcast buttons."
            );
        }
        // Sanity: the decorative button should NOT use Stimulus data-action
        // anymore (we removed that pattern).
        $this->assertStringNotContainsString(
            'data-action="click->users-admin#playGlobal"',
            $tpl,
            'users.html.twig must not wire the Global Temple Broadcast via Stimulus action — it uses vanilla onclick + CustomEvent now.'
        );
    }

    // ─── sonic-sanctuary.css ────────────────────────────────────────

    public function testCssDefinesAllClassesTemplateReferences(): void
    {
        // If the CSS doesn't define a class the template uses, the panel
        // will render unstyled. List the critical classes that must exist.
        $css = $this->read('src/assets/styles/sonic-sanctuary.css');
        foreach ([
            'sonic-mini-player',
            'sonic-mini-launcher',
            'sonic-panel',
            'sonic-panel-header',
            'sonic-panel-close',
            'sonic-tabs',
            'sonic-tab',
            'sonic-list',
            'sonic-track',
            'sonic-track-row',
            'sonic-track-delete',
            'sonic-visualizer',
            'sonic-controls',
            'sonic-volume',
            'sonic-mute-btn',
            'sonic-loop-btn',
            'sonic-keyboard',
            'sonic-drop-overlay',
            'sonic-empty',
            'sonic-empty-cta',
        ] as $class) {
            $this->assertMatchesRegularExpression(
                '/\.' . preg_quote($class, '/') . '\b/',
                $css,
                "sonic-sanctuary.css must define class: .{$class}"
            );
        }
    }

    public function testCssUsesReducedMotionQuery(): void
    {
        // The CSS includes a @media (prefers-reduced-motion: reduce) block
        // to disable transitions for accessibility. Verify it.
        $css = $this->read('src/assets/styles/sonic-sanctuary.css');
        $this->assertStringContainsString(
            'prefers-reduced-motion',
            $css,
            'sonic-sanctuary.css must respect prefers-reduced-motion for accessibility.'
        );
    }

    // ─── helpers ───────────────────────────────────────────────────

    /** @return string[] list of every `data-sonic-sanctuary-target="X"` value used in shell + panel */
    private function collectTemplateTargets(): array
    {
        $shell = $this->read('templates/shell.html.twig');
        $panel = $this->read('templates/_partials/sonic_sanctuary_panel.html.twig');
        $found = [];
        preg_match_all('/data-sonic-sanctuary-target="([^"]+)"/', $shell, $m1);
        preg_match_all('/data-sonic-sanctuary-target="([^"]+)"/', $panel, $m2);
        foreach (array_merge($m1[1] ?? [], $m2[1] ?? []) as $t) {
            if (!in_array($t, $found, true)) $found[] = $t;
        }
        return $found;
    }

    /** @return string[] contents of static targets = [a, b, c] */
    private function parseStaticTargets(string $controllerJs): array
    {
        if (!preg_match('/static\s+targets\s*=\s*\[(.*?)\]/s', $controllerJs, $m)) {
            $this->fail("sonic_sanctuary_controller.js must declare `static targets = [...]`.");
        }
        $body = $m[1];
        preg_match_all("/'([^']+)'/", $body, $matches);
        return $matches[1] ?? [];
    }
}
