<?php declare(strict_types=1);
namespace theseer\imapstore;

final readonly class LoginAuthenticator implements Authenticator {
    public function __construct(
        private string $username,
        private string $password
    ) {
    }

    /**
     * @throws AuthenticatorException
     */
    public function authenticate(Connection $connection): void {

        $response = $connection->writeCommand(
            sprintf('LOGIN "%s" "%s"', $this->username, $this->password)
        );
        if (!str_contains($response, "OK")) {
            throw new AuthenticatorException(
                "Authentication failed: $response"
            );
        }
    }
}
