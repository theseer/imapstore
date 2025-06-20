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

    public function __construct(
        string $host = '127.0.0.1',
        bool $validGreeting = true,
        bool $supportsStartTls = false
    ) {
        $this->host = $host;
        $this->validGreeting = $validGreeting;
        $this->supportsStartTls = $supportsStartTls;
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            throw new RuntimeException('Mock server is already running');
        }

        $this->port = $this->findAvailablePort();
        $this->server = stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr
        );

        if (!$this->server) {
            throw new RuntimeException("Failed to start mock server: $errstr");
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
                // Ignore client errors
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

                $this->processCommand($client, $tag, $command);
            }
        }
    }

    private function processCommand($client, string $tag, string $command): void
    {
        if (str_starts_with($command, 'CAPABILITY')) {
            $capabilities = "* CAPABILITY IMAP4rev1";
            if ($this->supportsStartTls) {
                $capabilities .= " STARTTLS";
            }
            fwrite($client, "$capabilities\r\n");
            fwrite($client, "$tag OK CAPABILITY completed\r\n");

        } elseif (str_starts_with($command, 'NOOP')) {
            fwrite($client, "$tag OK NOOP completed\r\n");

        } elseif (str_starts_with($command, 'APPEND')) {
            // Send continuation response for APPEND
            fwrite($client, "+ Ready for literal data\r\n");

            // Read the literal data
            $this->readLiteralData($client);

            fwrite($client, "$tag OK APPEND completed\r\n");

        } elseif (str_starts_with($command, 'STARTTLS')) {
            if ($this->supportsStartTls) {
                // Pretend STARTTLS is OK, but don't actually enable crypto
                // This will cause the subsequent stream_socket_enable_crypto to fail
                fwrite($client, "$tag OK STARTTLS completed\r\n");
                // Exit to let TLS handshake fail in client
                return;
            } else {
                fwrite($client, "$tag BAD STARTTLS not supported\r\n");
            }

        } else {
            fwrite($client, "$tag BAD Command not recognized\r\n");
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
}