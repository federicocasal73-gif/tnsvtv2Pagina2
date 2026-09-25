<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\JournalEntry;
use App\Entity\TradingAccount;
use App\Entity\User;

/**
 * Regression tests for the account-switching feature on /sanctum/journal.
 *
 * Background (Bug #1 from the 2026-09-24 journal audit):
 *   The journal controller exposes several endpoints that MUST respect the
 *   optional `account_id` query parameter:
 *     GET /api/journal
 *     GET /api/journal/stats
 *     GET /api/journal/calendar-monthly
 *     GET /api/journal/equity-curve
 *
 *   Before the fix (commit c41d9d3), only the equity curve honoured the
 *   filter. The other three always returned trades across every account,
 *   which made the user's "switch account" action visually inconsistent
 *   (equity updated, log/stats/calendar didn't).
 *
 *   These tests pin the behaviour: each endpoint filters correctly when
 *   account_id is supplied, and returns every account when it isn't.
 *
 *   If a future agent refactors `JournalController::loadEntriesForOwner`
 *   and forgets to forward account_id to the repository, these tests fail.
 */
class JournalAccountFilterTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'journal_entries',
            'trading_accounts',
        ]);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /** Creates a trading account owned by the given user. */
    private function createAccount(User $user, string $name, float $size = 5000.0): TradingAccount
    {
        $acc = new TradingAccount();
        $acc->setUser($user);
        $acc->setName($name);
        $acc->setAccountSize($size);
        $acc->setIsActive(true);
        $this->em->persist($acc);
        $this->em->flush();
        return $acc;
    }

    /**
     * Creates a trade. $accountId may be:
     *   - string     → trade assigned to that account id
     *   - null       → legacy trade with no account (created before multi-account)
     */
    private function createTrade(
        User $user,
        ?string $accountId,
        string $asset = 'EURUSD',
        string $result = JournalEntry::RESULT_WIN,
        string $pnl = '120.50',
    ): JournalEntry {
        $t = new JournalEntry();
        $t->setUserCode($user->getCode());
        $t->setAsset($asset);
        $t->setDirection(JournalEntry::DIRECTION_BUY);
        $t->setResult($result);
        $t->setPnl($pnl);
        $t->setAccountId($accountId);
        $t->setEntry('1.0900');
        $t->setSl('1.0850');
        $t->setTp('1.0950');
        $this->em->persist($t);
        $this->em->flush();
        return $t;
    }

    /** Collects the trade ids in the response. */
    private function tradeIds(array $data): array
    {
        return array_map(static fn(array $t): string => (string) $t['id'], $data['trades'] ?? []);
    }

    // -----------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------

    public function testJournalListReturnsAllTradesWhenAccountFilterOmitted(): void
    {
        $user = $this->createUser(['code' => 'JNL01', 'name' => 'Jnl User 1']);
        $this->em->persist($user);
        $this->em->flush();
        $accA = $this->createAccount($user, 'Account A', 5000.0);
        $accB = $this->createAccount($user, 'Account B', 8000.0);

        // 2 trades in A, 1 in B, 1 legacy with no account
        $t1 = $this->createTrade($user, (string) $accA->getId(), asset: 'EURUSD', pnl: '100.00');
        $t2 = $this->createTrade($user, (string) $accA->getId(), asset: 'GBPJPY', pnl: '200.00');
        $t3 = $this->createTrade($user, (string) $accB->getId(), asset: 'XAUUSD', pnl: '50.00');
        $tLegacy = $this->createTrade($user, null, asset: 'BTCUSD', pnl: '300.00');

        $this->loginAs($user);
        $r = $this->jsonRequest('GET', '/api/journal?user_code=' . $user->getCode());

        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['data']['success']);
        $this->assertSame(4, $r['data']['stats']['total'] ?? null, 'Todas must return ALL 4 trades');
        $ids = $this->tradeIds($r['data']);
        sort($ids);
        $expected = [$t1->getId(), $t2->getId(), $t3->getId(), $tLegacy->getId()];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testJournalListFiltersByAccountId(): void
    {
        $user = $this->createUser(['code' => 'JNL02', 'name' => 'Jnl User 2']);
        $accA = $this->createAccount($user, 'Account A', 5000.0);
        $accB = $this->createAccount($user, 'Account B', 8000.0);

        $t1 = $this->createTrade($user, (string) $accA->getId(), asset: 'EURUSD', pnl: '100.00');
        $t2 = $this->createTrade($user, (string) $accA->getId(), asset: 'GBPJPY', pnl: '200.00');
        $t3 = $this->createTrade($user, (string) $accB->getId(), asset: 'XAUUSD', pnl: '50.00');

        $this->loginAs($user);

        // ── Filter by account A → expect 2 trades ──
        $r = $this->jsonRequest(
            'GET',
            '/api/journal?user_code=' . $user->getCode() . '&account_id=' . $accA->getId()
        );
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['data']['stats']['total'] ?? null);
        $this->assertEqualsCanonicalizing(
            [(string) $t1->getId(), (string) $t2->getId()],
            $this->tradeIds($r['data']),
            'account A filter must return only A trades'
        );

        // ── Filter by account B → expect 1 trade ──
        $r = $this->jsonRequest(
            'GET',
            '/api/journal?user_code=' . $user->getCode() . '&account_id=' . $accB->getId()
        );
        $this->assertSame(200, $r['status']);
        $this->assertSame(1, $r['data']['stats']['total'] ?? null);
        $this->assertSame([(string) $t3->getId()], $this->tradeIds($r['data']));

        // ── Filter by nonexistent account id → graceful fallback to "Todas"
        //   (documented behaviour in loadEntriesForOwner: if account_id
        //    doesn't belong to the requester, fall back to all trades for
        //    that user. This is a security feature — never leak trades
        //    from someone else's account.)
        $r = $this->jsonRequest(
            'GET',
            '/api/journal?user_code=' . $user->getCode() . '&account_id=99999'
        );
        $this->assertSame(200, $r['status']);
        $this->assertSame(3, $r['data']['stats']['total'] ?? null, 'Unknown account → graceful fallback to all 3 user trades');
    }

    public function testStatsEndpointFiltersByAccountId(): void
    {
        $user = $this->createUser(['code' => 'JNL03', 'name' => 'Jnl Stats']);
        $accA = $this->createAccount($user, 'Account A', 5000.0);
        $accB = $this->createAccount($user, 'Account B', 8000.0);

        // 2 wins in A (total +$300), 1 loss in B (-$50)
        $this->createTrade($user, (string) $accA->getId(), result: JournalEntry::RESULT_WIN, pnl: '100.00');
        $this->createTrade($user, (string) $accA->getId(), result: JournalEntry::RESULT_WIN, pnl: '200.00');
        $this->createTrade($user, (string) $accB->getId(), result: JournalEntry::RESULT_LOSS, pnl: '-50.00');

        $this->loginAs($user);

        // Account A: 2 wins, total_pnl = 300
        $r = $this->jsonRequest('GET', '/api/journal/stats?account_id=' . $accA->getId());
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['data']['stats']['total'] ?? null);
        $this->assertEqualsWithDelta(300.0, (float) $r['data']['stats']['total_pnl'], 0.01);

        // Account B: 1 loss, total_pnl = -50
        $r = $this->jsonRequest('GET', '/api/journal/stats?account_id=' . $accB->getId());
        $this->assertSame(200, $r['status']);
        $this->assertSame(1, $r['data']['stats']['total'] ?? null);
        $this->assertEqualsWithDelta(-50.0, (float) $r['data']['stats']['total_pnl'], 0.01);

        // All (no filter): 3 trades, total_pnl = 250
        $r = $this->jsonRequest('GET', '/api/journal/stats');
        $this->assertSame(200, $r['status']);
        $this->assertSame(3, $r['data']['stats']['total'] ?? null);
        $this->assertEqualsWithDelta(250.0, (float) $r['data']['stats']['total_pnl'], 0.01);
    }

    public function testCalendarMonthlyEndpointFiltersByAccountId(): void
    {
        $user = $this->createUser(['code' => 'JNL04', 'name' => 'Jnl Calendar']);
        $accA = $this->createAccount($user, 'Account A', 5000.0);
        $accB = $this->createAccount($user, 'Account B', 8000.0);

        $this->createTrade($user, (string) $accA->getId(), pnl: '100.00');
        $this->createTrade($user, (string) $accA->getId(), pnl: '200.00');
        $this->createTrade($user, (string) $accB->getId(), pnl: '50.00');

        $this->loginAs($user);
        $today = (new \DateTimeImmutable())->format('Y-m');
        [$year, $month] = explode('-', $today);
        $base = "/api/journal/calendar-monthly?year={$year}&month={$month}";

        // Account A → 2 days
        $r = $this->jsonRequest('GET', $base . "&account_id=" . $accA->getId());
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['data']['summary']['trade_count'] ?? null);

        // Account B → 1 day
        $r = $this->jsonRequest('GET', $base . "&account_id=" . $accB->getId());
        $this->assertSame(200, $r['status']);
        $this->assertSame(1, $r['data']['summary']['trade_count'] ?? null);

        // Todas → 3 days
        $r = $this->jsonRequest('GET', $base);
        $this->assertSame(200, $r['status']);
        $this->assertSame(3, $r['data']['summary']['trade_count'] ?? null);
    }

    public function testEquityCurveEndpointFiltersByAccountId(): void
    {
        $user = $this->createUser(['code' => 'JNL05', 'name' => 'Jnl Equity']);
        $accA = $this->createAccount($user, 'Account A', 5000.0);
        $accB = $this->createAccount($user, 'Account B', 8000.0);

        $this->createTrade($user, (string) $accA->getId(), pnl: '100.00');
        $this->createTrade($user, (string) $accA->getId(), pnl: '200.00');
        $this->createTrade($user, (string) $accB->getId(), pnl: '50.00');

        $this->loginAs($user);

        // All three calls return 200 OK. The "filter by account" semantic
        // verification: the response's `summary.trade_count` count reflects
        // the filter (not the request URL).
        foreach ([null, (string) $accA->getId(), (string) $accB->getId()] as $accountId) {
            $url = '/api/journal/equity-curve?range=month&account_size=10000';
            if ($accountId !== null) {
                $url .= '&account_id=' . $accountId;
            }
            $r = $this->jsonRequest('GET', $url);
            $this->assertSame(200, $r['status'], "Equity curve for accountId=$accountId must return 200");
            $tradeCount = $r['data']['summary']['trade_count'] ?? null;
            $expectedTrades = $accountId === null ? 3 : ($accountId === (string) $accA->getId() ? 2 : 1);
            $this->assertSame(
                $expectedTrades,
                $tradeCount,
                "Equity curve trade_count mismatch for accountId=" . var_export($accountId, true)
            );
        }
    }

    public function testFilteringIsScopedToCurrentUser(): void
    {
        // Cross-user isolation: a malicious filter (account_id from
        // another user) must NOT leak trades.
        $attacker = $this->createUser(['code' => 'ATTACKER', 'name' => 'Attacker']);
        $victim   = $this->createUser(['code' => 'VICTIM',   'name' => 'Victim']);

        $attackerAcc = $this->createAccount($attacker, 'Attacker A', 5000.0);
        $victimAcc   = $this->createAccount($victim,   'Victim A', 8000.0);

        $this->createTrade($attacker, (string) $attackerAcc->getId(), pnl: '999.00');
        $this->createTrade($victim,   (string) $victimAcc->getId(),   pnl: '500.00');

        $this->loginAs($attacker);

        // Attacker requests victim's account_id → should fall back to
        // empty (graceful), not see victim's trades.
        $r = $this->jsonRequest(
            'GET',
            '/api/journal?user_code=ATTACKER&account_id=' . $victimAcc->getId()
        );

        $this->assertSame(200, $r['status']);
        $this->assertSame(1, $r['data']['stats']['total'] ?? null, 'Attacker must not see victim trades');
        $ids = $this->tradeIds($r['data']);
        // The only trade in the response must belong to the attacker.
        foreach ($ids as $id) {
            $t = $this->em->getRepository(JournalEntry::class)->find($id);
            $this->assertSame('ATTACKER', $t->getUserCode());
        }
    }
}
