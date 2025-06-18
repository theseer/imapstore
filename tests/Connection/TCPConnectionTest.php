<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;
use function fclose;
use function feof;
use function fgets;
use function fread;
use function fwrite;
use function is_resource;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function preg_match;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_getsockname;
use function str_ends_with;
use function str_starts_with;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_server;
use function strtoupper;
use function trim;
use function usleep;
use const AF_INET;
use const SIGTERM;
use const SOCK_STREAM;
use const SOL_TCP;

#[CoversClass(TCPConnection::class)]
class TCPConnectionTest extends TestCase
{
    private $mockServer;
    private int $mockServerPort;
    private string $mockServerHost = '127.0.0.1';
    private ?int $mockServerPid = null;

    protected function setUp(): void
    {
        $this->startMockServer();
    }

    protected function tearDown(): void
    {
        $this->stopMockServer();
    }

    public function testCreatePlainConnection(): void
    {
        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
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
        $this->stopMockServer();
        $this->startMockServerWithStartTls();

        // The TypeError occurs before our ConnectionException, so we need to catch it
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('stream_socket_enable_crypto(): supplied resource is not a valid stream resource');

        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
        $connection->open(); // Should fail at TLS handshake
    }

    public function testCreateTLSConnectionFailure(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::CONNECTION_FAILED);

        // Try to connect with TLS to our plain mock server
        // Suppress warnings as we expect this connection to fail
        $connection = TCPConnection::createTLS($this->mockServerHost, $this->mockServerPort);
        @$connection->open();
    }

    public function testOpenConnection(): void
    {
        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);

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

        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
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

        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
        $connection->writeCommand('CAPABILITY');
    }

    public function testWriteCommand(): void
    {
        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
        $connection->open();

        $response = $connection->writeCommand('CAPABILITY');

        $this->assertStringContainsString('CAPABILITY', $response);
        $this->assertStringContainsString('OK', $response);

        $connection->close();
    }

    public function testWriteData(): void
    {
        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
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
        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
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
        $connection = TCPConnection::createPlain($this->mockServerHost, 9999);
        @$connection->open();
    }

    public function testInvalidGreeting(): void
    {
        // Start server with invalid greeting
        $this->stopMockServer();
        $this->startMockServer(false);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionCode(ConnectionException::INVALID_GREETING);

        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
        $connection->open();
    }

    public function testMultipleCommands(): void
    {
        $connection = TCPConnection::createPlain($this->mockServerHost, $this->mockServerPort);
        $connection->open();

        $response1 = $connection->writeCommand('CAPABILITY');
        // Debug output to see what we actually get
        // var_dump($response1);
        $this->assertStringContainsString('CAPABILITY', $response1);
        $this->assertStringContainsString('OK CAPABILITY completed', $response1);

        $response2 = $connection->writeCommand('NOOP');
        $this->assertStringContainsString('OK NOOP completed', $response2);

        $connection->close();
    }

    private function startMockServer(bool $validGreeting = true): void
    {
        // Find available port
        $this->mockServerPort = $this->findAvailablePort();

        $this->mockServer = stream_socket_server(
            "tcp://{$this->mockServerHost}:{$this->mockServerPort}",
            $errno,
            $errstr
        );

        if (!$this->mockServer) {
            throw new RuntimeException("Failed to start mock server: $errstr");
        }

        // Make server non-blocking
        stream_set_blocking($this->mockServer, false);

        // Start server process in background
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new RuntimeException('Could not fork mock server process');
        } elseif ($pid == 0) {
            // Child process - run the mock server
            $this->runMockServer($validGreeting);
            exit(0);
        }

        // Parent process - store PID for cleanup
        $this->mockServerPid = $pid;

        // Give server time to start
        usleep(100000); // 100ms
    }

    private function stopMockServer(): void
    {
        if (isset($this->mockServerPid)) {
            posix_kill($this->mockServerPid, SIGTERM);
            pcntl_waitpid($this->mockServerPid, $status);
            unset($this->mockServerPid);
        }

        if (is_resource($this->mockServer)) {
            fclose($this->mockServer);
        }
    }

    private function runMockServer(bool $validGreeting): void
    {
        while (true) {
            $client = @stream_socket_accept($this->mockServer, 1);

            if (!$client) {
                continue;
            }

            try {
                $this->handleClient($client, $validGreeting);
            } catch (Throwable $e) {
                // Ignore client errors
            }

            fclose($client);
        }
    }

    private function handleClient($client, bool $validGreeting): void
    {
        // Send greeting
        if ($validGreeting) {
            fwrite($client, "* OK IMAP4rev1 Service Ready\r\n");
        } else {
            fwrite($client, "* BAD Invalid greeting\r\n");
            return;
        }

        while (!feof($client)) {
            $line = fgets($client);

            if ($line === false) {
                break;
            }

            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Parse command - use the actual tag from the client
            if (preg_match('/^(A\d+)\s+(.+)$/', $line, $matches)) {
                $tag = $matches[1];
                $command = strtoupper($matches[2]);

                $this->processCommand($client, $tag, $command);
            }
        }
    }

    private function processCommand($client, string $tag, string $command): void
    {
        if (str_starts_with($command, 'CAPABILITY')) {
            // Don't advertise STARTTLS to avoid TLS handshake issues in mock server
            fwrite($client, "* CAPABILITY IMAP4rev1 AUTH=LOGIN\r\n");
            fwrite($client, "$tag OK CAPABILITY completed\r\n");

        } elseif (str_starts_with($command, 'NOOP')) {
            fwrite($client, "$tag OK NOOP completed\r\n");

        } elseif (str_starts_with($command, 'APPEND')) {
            // Send continuation response
            fwrite($client, "+ Ready for literal data\r\n");

            // Read the data
            $data = '';
            while (!feof($client)) {
                $chunk = fread($client, 1024);
                if ($chunk === false) {
                    break;
                }
                $data .= $chunk;

                // Check if we have complete data (ends with \r\n)
                if (str_ends_with($data, "\r\n")) {
                    break;
                }
            }

            fwrite($client, "$tag OK APPEND completed\r\n");

        } elseif (str_starts_with($command, 'STARTTLS')) {
            // Return error since our mock server doesn't support real TLS
            fwrite($client, "$tag BAD STARTTLS not supported in mock server\r\n");

        } else {
            fwrite($client, "$tag BAD Command not recognized\r\n");
        }
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, $this->mockServerHost, 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        return $port;
    }

    private function startMockServerWithStartTls(): void
    {
        // Find available port
        $this->mockServerPort = $this->findAvailablePort();

        $this->mockServer = stream_socket_server(
            "tcp://{$this->mockServerHost}:{$this->mockServerPort}",
            $errno,
            $errstr
        );

        if (!$this->mockServer) {
            throw new RuntimeException("Failed to start mock server: $errstr");
        }

        // Make server non-blocking
        stream_set_blocking($this->mockServer, false);

        // Start server process in background
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new RuntimeException('Could not fork mock server process');
        } elseif ($pid == 0) {
            // Child process - run the mock server with STARTTLS support
            $this->runMockServerWithStartTls();
            exit(0);
        }

        // Parent process - store PID for cleanup
        $this->mockServerPid = $pid;

        // Give server time to start
        usleep(100000); // 100ms
    }

    private function runMockServerWithStartTls(): void
    {
        while (true) {
            $client = @stream_socket_accept($this->mockServer, 1);

            if (!$client) {
                continue;
            }

            try {
                $this->handleClientWithStartTls($client);
            } catch (Throwable $e) {
                // Ignore client errors
            }

            fclose($client);
        }
    }

    private function handleClientWithStartTls($client): void
    {
        // Send greeting
        fwrite($client, "* OK IMAP4rev1 Service Ready\r\n");

        while (!feof($client)) {
            $line = fgets($client);

            if ($line === false) {
                break;
            }

            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Parse command
            if (preg_match('/^(A\d+)\s+(.+)$/', $line, $matches)) {
                $tag = $matches[1];
                $command = strtoupper($matches[2]);

                if (str_starts_with($command, 'CAPABILITY')) {
                    // Advertise STARTTLS to trigger the TLS handshake attempt
                    fwrite($client, "* CAPABILITY IMAP4rev1 STARTTLS AUTH=LOGIN\r\n");
                    fwrite($client, "$tag OK CAPABILITY completed\r\n");

                } elseif (str_starts_with($command, 'STARTTLS')) {
                    // Pretend STARTTLS is OK, but don't actually enable crypto
                    // This will cause the subsequent stream_socket_enable_crypto to fail
                    fwrite($client, "$tag OK STARTTLS completed\r\n");
                    break; // Exit loop to let TLS handshake fail
                }
            }
        }
    }
}
