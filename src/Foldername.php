<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

final readonly class Foldername {

    public static function fromString(string $name): self {
        return new self(trim($name));
    }

    public function asString(): string {
        return $this->name;
    }

    private function __construct(
        private string $name
    ) {
        $this->ensureNameNotEmpty($name);
    }

    private function ensureNameNotEmpty(string $name): void {
        if (empty($name)) {
            throw new FoldernameException('Folder name cannot be empty');
        }
    }

}
