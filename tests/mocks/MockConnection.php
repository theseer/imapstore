<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use function implode;

class MockConnection implements Connection {

    private bool $connected = false;

    private array $responses;

    public function isConnected(): bool {
        return $this->connected;
    }

    public function setResponse(string $command, string ...$responses) {
        $this->responses[$command] = implode("\r\n", $responses);
    }

    public function open(): void {
        $this->connected = true;
    }

    public function writeCommand(string $command): string {
        return $this->responses[$command];
    }

    public function writeData(string $content): string {
        return $this->responses['DATA'];
    }

    public function close(): void {
        $this->connected = false;
    }

}
