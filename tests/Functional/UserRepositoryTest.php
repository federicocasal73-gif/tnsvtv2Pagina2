<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;

/**
 * Regresión 2026-09-30: un try/catch en findByCode() tragaba la excepción
 * 42S22 de metadata Doctrine stale y devolvía null → LegacyHeader y
 * UserProvider::refreshUser fallaban con 401 fantasma en TODOS los
 * endpoints autenticados. findByCode debe propagar errores de infra
 * (500 visible) y devolver null SOLO cuando el código no existe.
 */
class UserRepositoryTest extends ApiTestCase
{
    public function testFindByCodeReturnsExistingUser(): void
    {
        $u = $this->createUser(['code' => 'FINDME01', 'name' => 'Find Me']);
        $this->em->clear();

        $found = $this->em->getRepository(User::class)->findByCode('FINDME01');

        $this->assertNotNull($found, 'findByCode debe resolver un código existente');
        $this->assertSame($u->getId(), $found->getId());
        $this->assertSame('FINDME01', $found->getCode());
    }

    public function testFindByCodeUnknownReturnsNull(): void
    {
        $this->assertNull(
            $this->em->getRepository(User::class)->findByCode('NOPEXX'),
            'findByCode debe devolver null (no excepción) para código inexistente'
        );
    }

    public function testFindByCodeHydratesNewColumns(): void
    {
        // Si la metadata Doctrine está stale (p.ej. falta email_verified_at
        // tras una migración), findOneBy tira 42S22. Este test lo hace
        // visible en CI en vez de degradar a 401 silenciosos en prod.
        $u = $this->createUser(['code' => 'METACHECK01']);
        $u->setEmail('meta@example.com');
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(User::class)->findByCode('METACHECK01');

        $this->assertNotNull($found);
        $this->assertTrue($found->hasVerifiedEmail());
    }
}