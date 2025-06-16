<?php declare(strict_types=1);
namespace theseer\imapstore;

class ConnectionException extends \Exception {
    public const CONNECTION_ALREADY_OPEN = 1001;
    public const CONNECTION_FAILED = 1002;
    public const TIMEOUT_SET_FAILED = 1003;
    public const INVALID_GREETING = 1004;
    public const STARTTLS_FAILED = 1005;
    public const TLS_HANDSHAKE_FAILED = 1006;
    public const COMMAND_SEND_FAILED = 1007;
    public const DATA_SEND_FAILED = 1008;
    public const READ_FAILED = 1009;
    public const NOT_CONNECTED = 1010;
    public const CONNECTION_BROKEN = 1011;
}
