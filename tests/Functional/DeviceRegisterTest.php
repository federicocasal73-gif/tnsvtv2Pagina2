<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Device;

/**
 * Contract tests for POST /api/devices/register (web push onboarding).
 *
 * The push_controller.js Stimulus controller POSTs {user_code, fcm_token,
 * platform} here after minting an FCM token. Stays under the
 * devices_register rate limit (5/hour/IP): 4 requests total.
 */
class DeviceRegisterTest extends ApiTestCase
{
    public function testMissingFieldsReturn400(): void
    {
        $r = $this->jsonRequest('POST', '/api/devices/register', ['user_code' => 'X']);

        $this->assertSame(400, $r['status']);
    }

    public function testUnknownUserReturns404(): void
    {
        $r = $this->jsonRequest('POST', '/api/devices/register', [
            'user_code' => 'NOPE99',
            'fcm_token' => 'tok-1',
        ]);

        $this->assertSame(404, $r['status']);
    }

    public function testRegistersWebDevice(): void
    {
        $this->createUser(['code' => 'DEV01', 'name' => 'Device User']);

        $r = $this->jsonRequest('POST', '/api/devices/register', [
            'user_code' => 'DEV01',
            'fcm_token' => 'fcm-test-token-abc',
            'platform' => 'web',
        ]);

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success'] ?? false);

        $device = $this->em->getRepository(Device::class)->findOneBy(['fcmToken' => 'fcm-test-token-abc']);
        $this->assertNotNull($device);
        $this->assertSame('DEV01', $device->getUser()?->getCode());
        $this->assertSame('web', $device->getPlatform());
    }

    public function testSameTokenIsIdempotent(): void
    {
        $this->createUser(['code' => 'DEV02', 'name' => 'Device User 2']);

        $body = ['user_code' => 'DEV02', 'fcm_token' => 'fcm-test-token-dedupe', 'platform' => 'web'];
        $first = $this->jsonRequest('POST', '/api/devices/register', $body);
        $second = $this->jsonRequest('POST', '/api/devices/register', $body);

        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame($first['data']['device_id'], $second['data']['device_id']);
    }
}
