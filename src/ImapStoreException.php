<?php declare(strict_types=1);
namespace theseer\imapstore;

use Exception;

final class ImapStoreException extends Exception {
    public const CONNECTION_FAILED = 1;

    public const AUTH_FAILED = 2;
    public const FOLDER_NOT_FOUND = 3;
    public const MESSAGE_STORE_FAILED = 4;

}
