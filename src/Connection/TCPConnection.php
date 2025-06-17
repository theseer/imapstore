<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use Throwable;
use function is_resource;

final class TCPConnection implements Connection {

    /** @var ?resource */
    private $socket;

    private int $tagCounter = 0;

    /**
     * @param array<string, array<string, mixed>> $contextOptions
     */
    public static function createPlain(
        string $host,
        int $port = 143,
        array $contextOptions = [],
        int $timeout = 30
    ): self {
        return new self($host, $port, false, $contextOptions, $timeout);
    }

    /**
     * @param array<string, array<string, mixed>> $contextOptions
     */
    public static function createTLS(
        string $host,
        int $port = 993,
        array $contextOptions = [],
        int $timeout = 30
    ): self {
        return new self($host, $port, true, $contextOptions, $timeout);
    }

    public function open(): void {
        if (is_resource($this->socket)) {
            throw new ConnectionException('Connection is already open', ConnectionException::CONNECTION_ALREADY_OPEN);
        }

        $context = stream_context_create($this->buildStreamContext());

        $socketUrl = $this->buildSocketUrl();

        /** @var int $errno */
        $errno = 0;

        /** @var string $errstr */
        $errstr = '';

        $socket = stream_socket_client(
            $socketUrl,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new ConnectionException(sprintf("Connection to %s:%d failed: [%d] %s", $this->host, $this->port, $errno, $errstr), ConnectionException::CONNECTION_FAILED);
        }

        $this->socket = $socket;

        if (!stream_set_timeout($this->socket, $this->timeout)) {
            throw new ConnectionException('Could not set stream timeout', ConnectionException::TIMEOUT_SET_FAILED);
        }

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            $this->close();
            throw new ConnectionException(sprintf("Invalid IMAP greeting: %s", $greeting), ConnectionException::INVALID_GREETING);
        }

        // Perform STARTTLS if supported by server and not already using TLS
        if (!$this->useTls && $this->supportsStartTls()) {
            $this->performStartTls();
        }
    }

    public function writeCommand(string $command): string {
        $this->ensureConnected();

        // Generate tag and add to command
        $tag = $this->generateTag();
        $command = $tag . ' ' . $command;

        $command = rtrim($command, "\r\n") . "\r\n";
        $bytesWritten = fwrite($this->socket, $command);

        if ($bytesWritten === false || $bytesWritten !== strlen($command)) {
            throw new ConnectionException('Error sending command', ConnectionException::COMMAND_SEND_FAILED);
        }

        return $this->readResponse($tag);
    }

    public function writeData(string $content): string {
        $this->ensureConnected();

        $content .= "\r\n";

        // For APPEND: send content
        $bytesWritten = fwrite($this->socket, $content);

        if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
            throw new ConnectionException('Error sending data', ConnectionException::DATA_SEND_FAILED);
        }

        return $this->readResponse($this->getCurrentTag());
    }

    public function close(): void {
        if ($this->socket === null) {
            return;
        }

        fclose($this->socket);
        $this->socket = null;
    }

    /**
     * @param array<string, array<string, mixed>> $contextOptions
     */
    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $useTls,
        private readonly array $contextOptions = [],
        private readonly int $timeout = 30
    ) {
        $this->socket = null;
    }

    private function buildSocketUrl(): string {
        $protocol = $this->useTls ? 'tls' : 'tcp';
        return "{$protocol}://{$this->host}:{$this->port}";
    }

    /**
     * @return array<string, array<string, string|bool|int>> $contextOptions
     */
    private function buildStreamContext(): array {
        $defaultOptions = [
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
                'SNI_enabled'       => true,
                'peer_name'         => $this->host,
            ],
            'tcp' => [
                'tcp_nodelay' => true,
            ]
        ];

        // Merge user-defined options
        return array_merge_recursive($defaultOptions, $this->contextOptions);
    }

    private function generateTag(): string {
        $this->tagCounter++;

        return $this->getCurrentTag();
    }

    private function getCurrentTag(): string {
        return 'A' . sprintf('%04d', $this->tagCounter);
    }

    private function supportsStartTls(): bool {
        try {
            $response = $this->writeCommand('CAPABILITY');
            return str_contains(strtoupper($response), 'STARTTLS');
        } catch (Throwable) {
            return false;
        }
    }

    private function performStartTls(): void {
        // Send STARTTLS command
        $response = $this->writeCommand('STARTTLS');

        if (!str_contains($response, ' OK')) {
            throw new ConnectionException(sprintf("STARTTLS failed: %s", $response), ConnectionException::STARTTLS_FAILED);
        }

        // Perform TLS handshake
        $context = stream_context_create($this->buildStreamContext());

        $result = stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
            $context
        );

        if (!$result) {
            throw new ConnectionException('TLS handshake after STARTTLS failed', ConnectionException::TLS_HANDSHAKE_FAILED);
        }
    }

    private function readLine(): string {
        $this->ensureConnected();

        $line = fgets($this->socket);

        if ($line === false) {
            throw new ConnectionException('Error reading response', ConnectionException::READ_FAILED);
        }

        return rtrim($line, "\r\n");
    }

    private function readResponse(string $expectedTag): string {
        $response = '';

        while (true) {
            $line = $this->readLine();
            $response .= $line . "\n";

            // Continuation Response for APPEND/IDLE etc.
            if (str_starts_with($line, '+')) {
                break;
            }

            // Tagged Response - end of response for this command
            if (preg_match('/^([A-Z]\d+)\s+(OK|NO|BAD)/', $line, $matches)) {
                $responseTag = $matches[1];

                // Check if it's our expected tag
                if ($responseTag === $expectedTag) {
                    break;
                }

                // Different tag - this shouldn't happen in synchronous processing
                // but we'll continue reading until we get our expected tag
            }

            // Untagged Responses (* OK, * STATUS, etc.) are also collected
            // but they don't terminate the response loop
        }

        return rtrim($response);
    }

    private function ensureConnected(): void {
        if (!is_resource($this->socket)) {
            throw new ConnectionException('Not connected. Call open() first.', ConnectionException::NOT_CONNECTED);
        }

        // Check if connection is still active
        $meta = stream_get_meta_data($this->socket);
        if ($meta['eof']) {
            $this->socket = null;
            throw new ConnectionException('Connection was broken', ConnectionException::CONNECTION_BROKEN);
        }
    }
}
