<?php declare(strict_types=1);
namespace theseer\imapstore;

interface Authenticator {
    /**
     * @throws AuthenticatorException If authentication fails
     */
    public function authenticate(Connection $connection): void;
}
