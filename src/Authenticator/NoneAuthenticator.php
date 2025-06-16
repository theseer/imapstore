<?php declare(strict_types=1);
namespace theseer\imapstore;

class NoneAuthenticator implements Authenticator {

    public function authenticate(Connection $connection): void {
        // explicitly do nothing here
    }
}
