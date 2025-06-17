<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

interface Authenticator {
    /**
     * @throws AuthenticatorException If authentication fails
     */
    public function authenticate(Connection $connection): void;
}
