<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Setting;

/**
 * Regression test for B1 (Setting entity bug):
 * The entity used `name: 'setting_key'` while the migration created
 * the column as `key`. On MySQL prod this caused "Unknown column
 * s0_.setting_key" failures. Fix: change `name: 'setting_key'` →
 * `name: 'key'` (matches migration + DB schema).
 *
 * This test seeds two settings via direct INSERT (bypassing ORM to
 * guarantee the column name `key` is the one used) and then queries
 * via the ORM repository to confirm the entity can read them.
 */
final class SettingRepositoryTest extends ApiTestCase
{
    public function testFindAllReadsFromKeyColumn(): void
    {
        $conn = $this->em->getConnection();

        $conn->executeStatement(
            'DELETE FROM settings WHERE "key" IN (?, ?)',
            ['test.foo.bar', 'test.baz.qux']
        );

        $conn->executeStatement(
            'INSERT INTO settings ("key", value, category, description, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['test.foo.bar', 'value1', 'test', 'First test setting', '2026-01-01 00:00:00']
        );
        $conn->executeStatement(
            'INSERT INTO settings ("key", value, category, description, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['test.baz.qux', 'value2', 'test', 'Second test setting', '2026-01-01 00:00:00']
        );

        $repo = $this->em->getRepository(Setting::class);
        $byKey = $repo->findOneBy(['key' => 'test.foo.bar']);

        self::assertNotNull($byKey, 'ORM should find setting seeded with key=test.foo.bar');
        self::assertSame('test.foo.bar', $byKey->getKey());
        self::assertSame('value1', $byKey->getValue());
        self::assertSame('test', $byKey->getCategory());

        $byKey2 = $repo->findOneBy(['key' => 'test.baz.qux']);
        self::assertNotNull($byKey2, 'ORM should find second setting');
        self::assertSame('value2', $byKey2->getValue());
    }

    public function testGetValueAndGetBoolHelpers(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM settings WHERE "key" = ?', ['test.helper.bool']);
        $conn->executeStatement(
            'INSERT INTO settings ("key", value, category, description, updated_at) VALUES (?, ?, ?, ?, ?)',
            ['test.helper.bool', '1', 'test', 'Boolean helper', '2026-01-01 00:00:00']
        );

        $repo = $this->em->getRepository(Setting::class);

        self::assertSame('1', $repo->getValue('test.helper.bool'));
        self::assertTrue($repo->getBool('test.helper.bool'));
        self::assertSame('fallback', $repo->getValue('nonexistent.key', 'fallback'));
        self::assertFalse($repo->getBool('nonexistent.key'));
    }

    public function testFindByCategoryOrdersByKey(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM settings WHERE category = ?', ['test_order_cat']);
        foreach (['zzz', 'aaa', 'mmm'] as $key) {
            $conn->executeStatement(
                'INSERT INTO settings ("key", value, category, description, updated_at) VALUES (?, ?, ?, ?, ?)',
                ['test_order_cat.' . $key, 'v', 'test_order_cat', 'desc', '2026-01-01 00:00:00']
            );
        }

        $repo = $this->em->getRepository(Setting::class);
        $rows = $repo->findByCategory('test_order_cat');

        $keys = array_map(static fn (Setting $s) => substr($s->getKey(), strlen('test_order_cat.')), $rows);
        self::assertSame(['aaa', 'mmm', 'zzz'], $keys, 'findByCategory must order by key ASC');
    }
}
