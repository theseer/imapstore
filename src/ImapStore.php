<?php declare(strict_types=1);
namespace theseer\imapstore;

use function array_map;
use function implode;
use function sprintf;
use function str_contains;

final readonly class ImapStore {
    public function __construct(
        private Connection $connection,
        private Authenticator $authenticator
    ) {}

    public function __destruct() {
        $this->connection->close();
    }

    public function store(Message $message, Foldername $folder, MessageFlag ...$flags): void {
        try {
            $this->connection->open();
            $this->authenticator->authenticate($this->connection);

            if (!$this->folderExists($folder)) {
                throw new ImapStoreException(
                    sprintf("Folder '%s' not found", $folder->asString()),
                    ImapStoreException::FOLDER_NOT_FOUND
                );
            }

            $this->writeMessage($folder, $message, ...$flags);
        } catch (AuthenticatorException $e) {
            throw new ImapStoreException(
                'Authentication failed',
                ImapStoreException::AUTH_FAILED,
                $e
            );
        } finally {
            $this->connection->close();
        }
    }

    private function flagsToString(MessageFlag ...$flags): string {
        if (empty($flags)) {
            return '';
        }

        $flagValues = array_map(fn(MessageFlag $flag) => $flag->value, $flags);
        return ' (' . implode(' \\', $flagValues) . ')';
    }

    private function folderExists(Foldername $folder): bool {
        $response = $this->connection->writeCommand(
            sprintf('LIST "" "%s"', $folder->asString())
        );

        return str_contains($response, ' LIST');
    }

    private function writeMessage(Foldername $folder, Message $message, MessageFlag ...$flags): void {
        $command = sprintf(
            'APPEND "%s"%s {%s}',
            $folder->asString(),
            $this->flagsToString(...$flags),
            $message->size
        );

        $response = $this->connection->writeCommand($command);

        if (!str_contains($response, '+')) {
            throw new ImapStoreException(
                sprintf("Server did not accept our APPEND request to folder '%s': %s", $folder->asString(), $response),
                ImapStoreException::MESSAGE_STORE_FAILED
            );
        }

        $response = $this->connection->writeData($message->content);

        if (!str_contains($response, "OK") && !str_contains($response, "APPEND completed")) {
            throw new ImapStoreException(
                sprintf(
                    "Failed to store message in folder '%s': %s",
                    $folder->asString(),
                    $response
                ),
                ImapStoreException::MESSAGE_STORE_FAILED
            );
        }
    }
}
