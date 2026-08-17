<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\WalletTransaction;

class WalletControllerTest extends ApiTestCase
{
    public function testBalanceReturnsUserWallet(): void
    {
        $user = $this->createUser(['code' => 'WALLET01', 'walletBalance' => '125.50']);
        $this->loginAs($user);

        $result = $this->jsonRequest('GET', '/api/wallet/balance');

        $this->assertSame(200, $result['status'], 'Body: ' . json_encode($result['data']));
        $this->assertSame('WALLET01', $result['data']['username']);
        $this->assertSame('125.50', $result['data']['balance_usd']);
    }

    public function testBalanceRequiresAuth(): void
    {
        $result = $this->jsonRequest('GET', '/api/wallet/balance');
        $this->assertSame(401, $result['status']);
    }

    public function testMeEndpointReturns404(): void
    {
        $user = $this->createUser(['code' => 'WALLET02']);
        $this->loginAs($user);

        $this->client->request('GET', '/api/wallet/me');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(),
            '/api/wallet/me must return 404 (route removed in A.3)');
    }

    public function testWithdrawDecrementsBalanceAndCreatesPendingTx(): void
    {
        $user = $this->createUser(['code' => 'WALLET03', 'walletBalance' => '100.00']);
        $this->loginAs($user);

        $result = $this->jsonRequest('POST', '/api/wallet/withdraw',
            ['amount' => 25.00, 'method' => 'manual_mp', 'notes' => 'Test withdrawal'],
        );

        $this->assertSame(200, $result['status'], 'Body: ' . json_encode($result['data']));
        $this->assertTrue($result['data']['success'] ?? false);
        $this->assertSame('pending', $result['data']['status']);
        $this->assertSame(75.00, (float) $result['data']['new_balance_usd']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(\App\Entity\User::class)->findOneBy(['code' => 'WALLET03']);
        $this->assertSame(75.0, (float) $reloaded->getWalletBalance(),
            'Balance must be decremented immediately');

        $tx = $this->em->getRepository(WalletTransaction::class)->findOneBy(['user' => $reloaded]);
        $this->assertNotNull($tx, 'Transaction must be persisted');
        $this->assertSame(WalletTransaction::TYPE_WITHDRAW, $tx->getType());
        $this->assertSame(WalletTransaction::STATUS_PENDING, $tx->getStatus());
        $this->assertEquals(-25.0, (float) $tx->getAmount());
    }

    public function testWithdrawWithInsufficientBalanceReturns400(): void
    {
        $user = $this->createUser(['code' => 'WALLET04', 'walletBalance' => '10.00']);
        $this->loginAs($user);

        $result = $this->jsonRequest('POST', '/api/wallet/withdraw', ['amount' => 100.00]);

        $this->assertSame(400, $result['status']);
        $this->assertSame('wallet_insufficient', $result['data']['error']);
    }

    public function testWithdrawWithNegativeAmountReturns400(): void
    {
        $user = $this->createUser(['code' => 'WALLET05', 'walletBalance' => '100.00']);
        $this->loginAs($user);

        $result = $this->jsonRequest('POST', '/api/wallet/withdraw', ['amount' => -10]);

        $this->assertSame(400, $result['status']);
    }

    public function testWithdrawWithAmountBelowMinimumReturns400(): void
    {
        $user = $this->createUser(['code' => 'WALLET06', 'walletBalance' => '100.00']);
        $this->loginAs($user);

        $result = $this->jsonRequest('POST', '/api/wallet/withdraw', ['amount' => 0.5]);

        $this->assertSame(400, $result['status']);
    }

    public function testWithdrawRequiresAuth(): void
    {
        $result = $this->jsonRequest('POST', '/api/wallet/withdraw', ['amount' => 10]);
        $this->assertSame(401, $result['status']);
    }

    public function testTransactionsRequiresAuth(): void
    {
        $result = $this->jsonRequest('GET', '/api/wallet/transactions');
        $this->assertSame(401, $result['status']);
    }

    public function testTransactionsReturnsUserHistory(): void
    {
        $user = $this->createUser(['code' => 'WALLET07', 'walletBalance' => '50.00']);
        $this->loginAs($user);

        $result = $this->jsonRequest('GET', '/api/wallet/transactions');
        $this->assertSame(200, $result['status']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertSame([], $result['data']['transactions']);
    }
}