<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Doctrine\DbRetryMiddleware;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DbRetryMiddlewareTest extends TestCase
{
    private function mockConnection(): DriverConnection
    {
        return $this->createMock(DriverConnection::class);
    }

    public function testFirstTrySuccessNoRetry(): void
    {
        $conn = $this->mockConnection();
        $inner = $this->createMock(DriverInterface::class);
        $inner->expects($this->once())->method('connect')->willReturn($conn);

        $wrapped = (new DbRetryMiddleware(new NullLogger()))->wrap($inner);

        $this->assertSame($conn, $wrapped->connect([]));
    }

    public function testRetriesOnceAfterConnectionException(): void
    {
        $conn = $this->mockConnection();
        $inner = $this->createMock(DriverInterface::class);
        $inner
            ->expects($this->exactly(2))
            ->method('connect')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($this->createMock(ConnectionException::class)),
                $conn
            );

        $wrapped = (new DbRetryMiddleware(new NullLogger()))->wrap($inner);

        $this->assertSame($conn, $wrapped->connect([]));
    }

    public function testRethrowsWhenRetryAlsoFails(): void
    {
        $inner = $this->createMock(DriverInterface::class);
        $inner
            ->expects($this->exactly(2))
            ->method('connect')
            ->willThrowException($this->createMock(ConnectionException::class));

        $wrapped = (new DbRetryMiddleware(new NullLogger()))->wrap($inner);

        $this->expectException(ConnectionException::class);
        $wrapped->connect([]);
    }

    public function testNonConnectionExceptionsAreNotRetried(): void
    {
        $inner = $this->createMock(DriverInterface::class);
        $inner
            ->expects($this->once())
            ->method('connect')
            ->willThrowException(new \RuntimeException('boom'));

        $wrapped = (new DbRetryMiddleware(new NullLogger()))->wrap($inner);

        $this->expectException(\RuntimeException::class);
        $wrapped->connect([]);
    }
}
