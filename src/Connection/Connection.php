<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

interface Connection {

    public function open(): void;

    public function writeCommand(string $command): string;

    public function writeData(string $content): string;

    public function close(): void;
}
