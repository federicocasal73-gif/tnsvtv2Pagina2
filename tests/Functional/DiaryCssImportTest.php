<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Defensa contra regresión del import del CSS del Diario.
 *
 * Bug histórico (2026-09-29): src/assets/styles/components/diary.css existía
 * (491 LoC, styling glass + grid + responsive) pero NO estaba importado
 * en components.css ni en shell.html.twig. Resultado: /diario renderizaba
 * HTML plano en prod.
 *
 * Si alguien borra el @import o lo deja fuera de orden, este test falla.
 */
class DiaryCssImportTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = static::getContainer()->getParameter('kernel.project_dir');
    }

    public function testComponentsCssImportsDiaryCss(): void
    {
        $fs = new Filesystem();
        $path = $this->projectDir . '/src/assets/styles/components.css';
        $this->assertTrue($fs->exists($path));
        $contents = file_get_contents($path);
        $this->assertStringContainsString(
            "@import './components/diary.css'",
            $contents,
            'components.css must @import diary.css — bug histórico: el Diario se renderizó sin estilos en prod 2026-09-29 porque faltaba este import.'
        );
    }

    public function testDiaryCssFileExists(): void
    {
        $fs = new Filesystem();
        $path = $this->projectDir . '/src/assets/styles/components/diary.css';
        $this->assertTrue($fs->exists($path), 'diary.css must exist');
        $this->assertGreaterThan(
            1000,
            filesize($path),
            'diary.css looks suspiciously small — verify it is not a stub.'
        );
    }

    public function testDiaryTemplateDefinesExpectedTargets(): void
    {
        $fs = new Filesystem();
        $path = $this->projectDir . '/templates/sanctum/diary.html.twig';
        $this->assertTrue($fs->exists($path));
        $contents = file_get_contents($path);

        // Targets que el controller declara (post-cambios F2 A2).
        $this->assertStringContainsString('data-diary-target="monthFilter"', $contents);
        $this->assertStringContainsString('data-diary-target="searchInput"', $contents);
        // El fingerprint bait-and-switch fue removido (A2.a).
        $this->assertStringNotContainsString(
            'fingerprint',
            $contents,
            'diary.html.twig must NOT contain the fingerprint button (A2.a — bait-and-switch removed).'
        );
        // maxlength 50000 en el textarea (A2.e — sanity cap).
        $this->assertStringContainsString('maxlength="50000"', $contents);
    }
}