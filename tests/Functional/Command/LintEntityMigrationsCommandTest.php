<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for `app:lint:entity-migrations` (Risk #3 in RISK_MITIGATION.md).
 *
 * Strategy: copy a small fixture tree into a temporary directory,
 * point the command at it via --entity-dir / --migrations-dir, and
 * assert the exit code + output. We don't pollute the real
 * src/Entity / migrations/ directories because:
 *
 *   - The real entity dir has 61 entities; testing "create a new
 *     orphan entity" would require writing + deleting a file in
 *     production code paths.
 *   - We want isolated, repeatable tests that run offline.
 *
 * What we cover:
 *  1. Clean state (every entity has a migration) → SUCCESS.
 *  2. One orphan entity (no migration references it) → FAILURE with the
 *     orphan's name + table in the error message.
 *  3. Multi-line raw SQL CREATE TABLE is detected by the linter.
 *  4. Schema API createTable() form is detected by the linter.
 *  5. Missing entity dir / missing migrations dir → exit code 2.
 */
class LintEntityMigrationsCommandTest extends KernelTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        static::ensureKernelShutdown();
        self::bootKernel();
        $this->tmpDir = sys_get_temp_dir() . '/tnsvt-lint-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/entities');
        mkdir($this->tmpDir . '/migrations');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
        parent::tearDown();
    }

    public function testCleanStateReturnsSuccess(): void
    {
        $this->writeEntity('User.php', 'users');
        $this->writeEntity('Post.php', 'posts');
        $this->writeMigration('Version20260101.php', "CREATE TABLE users (id INT);\nCREATE TABLE posts (id INT);\n");

        $exit = $this->runLint();
        $this->assertSame(0, $exit, 'All entities matched — expected SUCCESS');
        $this->assertStringContainsString('All new entities have a corresponding migration', $this->tester->getDisplay());
    }

    public function testOrphanEntityFailsLint(): void
    {
        // Migration written FIRST (older mtime), entity written AFTER
        // (newer mtime) → entity is "newer than latest migration" → flagged.
        $this->writeMigration('Version20260101.php', "CREATE TABLE users (id INT);\nCREATE TABLE posts (id INT);\n", mtimeOffset: -60);
        $this->writeEntity('User.php', 'users');
        $this->writeEntity('Post.php', 'posts');
        $this->writeEntity('BrandNew.php', 'brand_new_table');

        $exit = $this->runLint();
        $this->assertSame(1, $exit, 'Orphan entity — expected FAILURE');
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('BrandNew.php', $display);
        $this->assertStringContainsString('brand_new_table', $display);
    }

    public function testSchemaApiCreateTableIsDetected(): void
    {
        $this->writeEntity('Foo.php', 'foo');
        $this->writeMigration('Version20260101.php',
            "<?php\n\$schema->createTable('foo');\n"
        );

        $exit = $this->runLint();
        $this->assertSame(0, $exit, 'Schema API createTable() form should be detected');
    }

    public function testMultilineRawSqlCreateTableIsDetected(): void
    {
        $this->writeEntity('Foo.php', 'foo');
        $this->writeMigration('Version20260101.php',
            "<?php\n\$this->addSql(\"CREATE TABLE foo (\nid INT,\nname VARCHAR(64)\n)\");\n"
        );

        $exit = $this->runLint();
        $this->assertSame(0, $exit, 'Multi-line raw SQL CREATE TABLE should be detected');
    }

    public function testEntityWithoutTableAttributeIsSkippedAsWarning(): void
    {
        // No #[ORM\Table(name: ...)] — the linter should warn + skip.
        $this->writeEntity('NoTable.php', null);
        $this->writeMigration('Version20260101.php', "-- empty migration");

        $exit = $this->runLint();
        // Command returns SUCCESS for warnings-only.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('NoTable.php', $this->tester->getDisplay());
        $this->assertStringContainsString('no #[ORM\\Table] attribute', $this->tester->getDisplay());
    }

    public function testTraitFileIsSkipped(): void
    {
        $this->writeEntity('UserTrait.php', null, isTrait: true);
        $this->writeMigration('Version20260101.php', "-- empty");

        $exit = $this->runLint();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Checked 0 entities', $this->tester->getDisplay());
    }

    public function testMissingEntityDirReturnsError2(): void
    {
        rmdir($this->tmpDir . '/entities');
        $this->writeMigration('Version20260101.php', "-- empty");

        $exit = $this->runLint();
        $this->assertSame(2, $exit, 'Missing entity dir → exit 2');
        $this->assertStringContainsString('Entity directory not found', $this->tester->getDisplay());
    }

    public function testMissingMigrationsDirReturnsError2(): void
    {
        rmdir($this->tmpDir . '/migrations');
        $this->writeEntity('Foo.php', 'foo');

        $exit = $this->runLint();
        $this->assertSame(2, $exit, 'Missing migrations dir → exit 2');
        $this->assertStringContainsString('Migrations directory not found', $this->tester->getDisplay());
    }

    public function testLegacyEntityWithoutMigrationIsSilentlySkipped(): void
    {
        // Entity written FIRST (older mtime), migration written AFTER
        // (newer mtime). The linter treats the entity as "legacy"
        // (mtime <= latest migration) → silently OK.
        $this->writeEntity('Old.php', 'old_table', mtimeOffset: -3600);
        $this->writeMigration('Version20260101.php', "-- empty", mtimeOffset: -1);

        $exit = $this->runLint();
        // The entity's table is not referenced anywhere, BUT it is
        // considered legacy → success.
        $this->assertSame(0, $exit);
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    private CommandTester $tester;

    private function runLint(): int
    {
        $kernel = self::$kernel;
        $app = new Application($kernel);
        $cmd = $app->find('app:lint:entity-migrations');
        $this->tester = new CommandTester($cmd);
        return $this->tester->execute([
            '--entity-dir'     => $this->tmpDir . '/entities',
            '--migrations-dir' => $this->tmpDir . '/migrations',
        ]);
    }

    private function writeEntity(string $basename, ?string $tableName, bool $isTrait = false, int $mtimeOffset = 0): void
    {
        $classDecl = $isTrait ? 'trait ' . basename($basename, '.php') : 'class ' . basename($basename, '.php');
        $content = "<?php\nnamespace App\\Entity;\n";
        $content .= "use Doctrine\\ORM\\Mapping as ORM;\n";
        if ($isTrait) {
            $content .= "$classDecl { }\n";
        } else {
            $content .= "#[ORM\\Entity(repositoryClass: FooRepository::class)]\n";
            if ($tableName !== null) {
                $content .= "#[ORM\\Table(name: '$tableName')]\n";
            }
            $content .= "$classDecl { #[ORM\\Id] #[ORM\\Column] private ?int \$id = null; }\n";
        }
        $path = $this->tmpDir . '/entities/' . $basename;
        file_put_contents($path, $content);
        if ($mtimeOffset !== 0) {
            touch($path, time() + $mtimeOffset);
        }
    }

    private function writeMigration(string $basename, string $body, int $mtimeOffset = 0): void
    {
        $path = $this->tmpDir . '/migrations/' . $basename;
        file_put_contents($path, "<?php\n$body\n");
        if ($mtimeOffset !== 0) {
            touch($path, time() + $mtimeOffset);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_dir($f)) {
                $this->removeTree($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }
}