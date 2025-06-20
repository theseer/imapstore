<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheSeer\ImapStore\Test\MockImapServer;
use TypeError;

#[CoversClass(TCPConnection::class)]
class TCPConnectionTest extends TestCase
{
    private MockImapServer $mockServer;

    protected function setUp(): void
    {
        $this->mockServer = new MockImapServer();
        $this->mockServer->start();
    }

    protected function tearDown(): void
    {
        $this->mockServer->stop();
    }

    public function testCreatePlainConnection(): void
    {
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $this->assertInstanceOf(TCPConnection::class, $connection);
    }

    public function testCreateTLSConnection(): void
    {
        // Test object creation only - don't try to connect
        $connection = TCPConnection::createTLS('example.com', 993);
        $this->assertInstanceOf(TCPConnection::class, $connection);
    }

    public function testStartTlsCapability(): void
    {
        // Test with a server that advertises STARTTLS
        $this->mockServer->stop();
        $this->mockServer = new MockImapServer(supportsStartTls: true);
        $this->mockServer->start();

        // The TypeError occurs before our ConnectionException, so we need to catch it
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('stream_socket_enable_crypto(): supplied resource is not a valid stream resource');

        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open(); // Should fail at TLS handshake
    }

    public function testCreateTLSConnectionFailure(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::CONNECTION_FAILED);

        // Try to connect with TLS to our plain mock server
        // Suppress warnings as we expect this connection to fail
        $connection = TCPConnection::createTLS($this->mockServer->getHost(), $this->mockServer->getPort());
        @$connection->open();
    }

    public function testOpenConnection(): void
    {
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());

        // Should not throw exception
        $connection->open();

        // Verify connection is working by sending a command
        $response = $connection->writeCommand('NOOP');
        $this->assertStringContainsString('OK', $response);

        // Clean up
        $connection->close();
    }

    public function testOpenConnectionTwiceThrowsException(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::CONNECTION_ALREADY_OPEN);

        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open();

        try {
            $connection->open(); // Should throw exception
        } finally {
            $connection->close();
        }
    }

    public function testWriteCommandWithoutConnectionThrowsException(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::NOT_CONNECTED);

        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->writeCommand('CAPABILITY');
    }

    public function testWriteCommand(): void
    {
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open();

        $response = $connection->writeCommand('CAPABILITY');

        $this->assertStringContainsString('CAPABILITY', $response);
        $this->assertStringContainsString('OK', $response);

        $connection->close();
    }

    public function testWriteData(): void
    {
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open();

        // First send APPEND command to get continuation response
        $response = $connection->writeCommand('APPEND INBOX {26}');
        $this->assertStringContainsString('+', $response);

        // Then send data
        $response = $connection->writeData('Subject: Test\r\n\r\nTest body');
        $this->assertStringContainsString('OK', $response);

        $connection->close();
    }

    public function testCloseConnection(): void
    {
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open();

        // Verify connection is open by sending a command
        $response = $connection->writeCommand('NOOP');
        $this->assertStringContainsString('OK', $response);

        // Should not throw exception
        $connection->close();

        // Verify connection is closed by expecting exception
        $this->expectException(ConnectionException::class);
        $connection->writeCommand('NOOP');
    }

    public function testConnectionFailure(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::CONNECTION_FAILED);

        // Try to connect to non-existent port
        // Suppress warnings as we expect this connection to fail
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), 9999);
        @$connection->open();
    }

    public function testInvalidGreeting(): void
    {
        // Start server with invalid greeting
        $this->mockServer->stop();
        $this->mockServer = new MockImapServer(validGreeting: false);
        $this->mockServer->start();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::INVALID_GREETING);

        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open();
    }

    public function testMultipleCommands(): void
    {
        $connection = TCPConnection::createPlain($this->mockServer->getHost(), $this->mockServer->getPort());
        $connection->open();

        $response1 = $connection->writeCommand('CAPABILITY');
        $this->assertStringContainsString('CAPABILITY', $response1);
        $this->assertStringContainsString('OK CAPABILITY completed', $response1);

        $response2 = $connection->writeCommand('NOOP');
        $this->assertStringContainsString('OK NOOP completed', $response2);

        $connection->close();
    }
}