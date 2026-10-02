<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * /sanctum/admin/bookings es admin-only (IsGranted ROLE_ADMIN).
 * Sin gate, cualquier usuario logueado veía reservas 1:1 ajenas.
 */
class BookingsAdminTest extends ApiTestCase
{
    public function testRegularUserIsDenied(): void
    {
        $user = $this->createUser(['code' => 'BK01', 'name' => 'Regular']);
        $this->loginAs($user);

        $this->client->request('GET', '/sanctum/admin/bookings');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanView(): void
    {
        $admin = $this->createAdmin(['code' => 'BKADM']);
        $this->loginAs($admin);

        $this->client->request('GET', '/sanctum/admin/bookings');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
