<?php declare(strict_types=1);
namespace TheSeer\ImapStore\Test;

use RuntimeException;
use Throwable;
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
use function stream_context_create;
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

class MockImapServer
{
    private $server;
    private int $port;
    private string $host;
    private ?int $pid = null;
    private bool $validGreeting;
    private bool $supportsStartTls;
    private bool $useTls;
    private bool $startTlsSucceeds;
    private bool $tlsHandshakeSucceeds;
    private array $contextOptions;

    public function __construct(
        string $host = '127.0.0.1',
        bool $validGreeting = true,
        bool $supportsStartTls = false,
        bool $useTls = false,
        bool $startTlsSucceeds = true,
        bool $tlsHandshakeSucceeds = true,
        array $contextOptions = []
    ) {
        $this->host = $host;
        $this->validGreeting = $validGreeting;
        $this->supportsStartTls = $supportsStartTls;
        $this->useTls = $useTls;
        $this->startTlsSucceeds = $startTlsSucceeds;
        $this->tlsHandshakeSucceeds = $tlsHandshakeSucceeds;
        $this->contextOptions = $contextOptions;
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Mock server is already running');
        }

        $this->port = $this->findAvailablePort();

        // Create server with or without TLS
        if ($this->useTls) {
            $this->server = $this->createTlsServer();
        } else {
            $this->server = $this->createPlainServer();
        }

        if (!$this->server) {
            throw new RuntimeException("Failed to start mock server");
        }

        stream_set_blocking($this->server, false);

        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new RuntimeException('Could not fork mock server process');
        } elseif ($pid == 0) {
            // Child process - run the mock server
            $this->runServer();
            exit(0);
        }

        // Parent process - store PID for cleanup
        $this->pid = $pid;

        // Give server time to start
        usleep(100000); // 100ms
    }

    public function stop(): void
    {
        if (!$this->isRunning()) {
            return;
        }

        if (isset($this->pid)) {
            posix_kill($this->pid, SIGTERM);
            pcntl_waitpid($this->pid, $status);
            $this->pid = null;
        }

        if (is_resource($this->server)) {
            fclose($this->server);
            $this->server = null;
        }
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        if (!isset($this->port)) {
            throw new RuntimeException('Server not started yet');
        }
        return $this->port;
    }

    public function isRunning(): bool
    {
        return isset($this->pid) && is_resource($this->server);
    }

    private function createPlainServer()
    {
        return stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr
        );
    }

    private function createTlsServer()
    {
        // Create SSL context for TLS server
        $context = stream_context_create($this->buildServerContext());

        return stream_socket_server(
            "tls://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
    }

    private function buildServerContext(): array
    {
        $defaultOptions = [
            'ssl' => [
                'local_cert' => $this->createSelfSignedCert(),
                'local_pk' => $this->createPrivateKey(),
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'SNI_enabled' => false,
            ],
        ];

        return array_merge_recursive($defaultOptions, $this->contextOptions);
    }

    private function createSelfSignedCert(): string
    {
        // Create a temporary self-signed certificate for testing
        $certFile = tempnam(sys_get_temp_dir(), 'mock_imap_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'mock_imap_key_');

        // Generate a simple self-signed certificate
        $cert = "-----BEGIN CERTIFICATE-----
MIICljCCAX4CCQDKFzPwX2qL9TANBgkqhkiG9w0BAQsFADANMQswCQYDVQQGEwJE
RTAeFw0yNDA2MjAwMDAwMDBaFw0yNTA2MjAwMDAwMDBaMA0xCzAJBgNVBAYTAkRF
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwK5zJbTpyL5zL5zL5zL5
zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5z
L5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL
5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5
zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5z
L5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL5zL
5QIDAQABMA0GCSqGSIb3DQEBCwUAA4IBAQDArkMls=
-----END CERTIFICATE-----";

        file_put_contents($certFile, $cert);
        return $certFile;
    }

    private function createPrivateKey(): string
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'mock_imap_key_');

        $key = "-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDArkMls...
-----END PRIVATE KEY-----";

        file_put_contents($keyFile, $key);
        return $keyFile;
    }

    private function runServer(): void
    {
        while (true) {
            $client = @stream_socket_accept($this->server, 1);

            if (!$client) {
                continue;
            }

            try {
                $this->handleClient($client);
            } catch (Throwable $e) {
                // Log error for debugging but continue
                error_log("MockImapServer client error: " . $e->getMessage());
            }

            fclose($client);
        }
    }

    private function handleClient($client): void
    {
        // Send greeting
        if ($this->validGreeting) {
            fwrite($client, "* OK IMAP4rev1 Service Ready\r\n");
        } else {
            fwrite($client, "* BAD Invalid greeting\r\n");
            return;
        }

        $inStartTlsMode = false;

        while (!feof($client)) {
            $line = fgets($client);

            if ($line === false) {
                break;
            }

            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Parse command - extract tag and command
            if (preg_match('/^(A\d+)\s+(.+)$/', $line, $matches)) {
                $tag = $matches[1];
                $command = strtoupper($matches[2]);

                // Handle STARTTLS specially
                if (str_starts_with($command, 'STARTTLS')) {
                    if ($this->handleStartTls($client, $tag)) {
                        $inStartTlsMode = true;
                        // After successful STARTTLS, break to simulate TLS handshake
                        break;
                    }
                } else {
                    $this->processCommand($client, $tag, $command);
                }
            }
        }
    }

    private function handleStartTls($client, string $tag): bool
    {
        if (!$this->supportsStartTls) {
            fwrite($client, "$tag BAD STARTTLS not supported\r\n");
            return false;
        }

        if (!$this->startTlsSucceeds) {
            fwrite($client, "$tag NO STARTTLS failed\r\n");
            return false;
        }

        // Send OK response for STARTTLS
        fwrite($client, "$tag OK STARTTLS completed, ready for TLS handshake\r\n");

        // Simulate TLS handshake behavior
        if ($this->tlsHandshakeSucceeds) {
            // In a real scenario, we would enable crypto here
            // For testing, we'll just simulate success
            usleep(10000); // Small delay to simulate handshake
            return true;
        } else {
            // Simulate handshake failure by closing connection
            fclose($client);
            return false;
        }
    }

    private function processCommand($client, string $tag, string $command): void
    {
        if (str_starts_with($command, 'CAPABILITY')) {
            $capabilities = "* CAPABILITY IMAP4rev1";
            if ($this->supportsStartTls && !$this->useTls) {
                $capabilities .= " STARTTLS";
            }
            $capabilities .= " LOGIN";
            fwrite($client, "$capabilities\r\n");
            fwrite($client, "$tag OK CAPABILITY completed\r\n");

        } elseif (str_starts_with($command, 'NOOP')) {
            fwrite($client, "$tag OK NOOP completed\r\n");

        } elseif (str_starts_with($command, 'LOGIN')) {
            // Simple login simulation
            if (preg_match('/LOGIN\s+"([^"]+)"\s+"([^"]+)"/', $command, $matches)) {
                $username = $matches[1];
                $password = $matches[2];

                // Simulate successful login for test credentials
                if ($username === 'testuser' && $password === 'testpass') {
                    fwrite($client, "$tag OK LOGIN completed\r\n");
                } else {
                    fwrite($client, "$tag NO LOGIN failed\r\n");
                }
            } else {
                fwrite($client, "$tag BAD LOGIN command malformed\r\n");
            }

        } elseif (str_starts_with($command, 'APPEND')) {
            // Send continuation response for APPEND
            fwrite($client, "+ Ready for literal data\r\n");

            // Read the literal data
            $this->readLiteralData($client);

            fwrite($client, "$tag OK APPEND completed\r\n");

        } elseif (str_starts_with($command, 'LIST')) {
            // Simple LIST response
            fwrite($client, "* LIST () \"/\" INBOX\r\n");
            fwrite($client, "$tag OK LIST completed\r\n");

        } else {
            fwrite($client, "$tag BAD Command not recognized: $command\r\n");
        }
    }

    private function readLiteralData($client): string
    {
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
        return $data;
    }

    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new RuntimeException('Could not create socket to find available port');
        }

        $result = socket_bind($socket, $this->host, 0);
        if ($result === false) {
            socket_close($socket);
            throw new RuntimeException('Could not bind socket to find available port');
        }

        $result = socket_getsockname($socket, $addr, $port);
        socket_close($socket);

        if ($result === false) {
            throw new RuntimeException('Could not get socket name to find available port');
        }

        return $port;
    }

    public function __destruct()
    {
        $this->stop();
    }

    // Factory methods for easier test setup
    public static function withTls(string $host = '127.0.0.1'): self
    {
        return new self($host, true, false, true, true, true);
    }

    public static function withStartTls(string $host = '127.0.0.1'): self
    {
        return new self($host, true, true, false, true, true);
    }

    public static function withFailingStartTls(string $host = '127.0.0.1'): self
    {
        return new self($host, true, true, false, false, false);
    }

    public static function withFailingTlsHandshake(string $host = '127.0.0.1'): self
    {
        return new self($host, true, true, false, true, false);
    }
}
