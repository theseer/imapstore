<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use function strlen;

final class Message {

    public static function fromString(string $content): self {
        return new self(trim($content));
    }

    public int $size {
        get => strlen($this->content);
    }

    private function ensureMessageNotEmpty(string $content): void {
        if (empty($content)) {
            throw new MessageException('Message content cannot be empty');
        }
    }

    private function __construct(
        public readonly string $content
    ) {
        $this->ensureMessageNotEmpty($content);
    }
}
