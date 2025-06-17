<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use function base64_encode;
use function sprintf;
use function str_contains;

final readonly class OAuthBearerAuthenticator implements Authenticator {

    public function __construct(
        private string $username,
        private string $accessToken
    ) {
    }

    public function authenticate(Connection $connection): void {
        $xoauth = base64_encode(
            sprintf("user=%s\x01auth=Bearer %s\x01\x01",
                $this->username,
                $this->accessToken
            )
        );

        $response = $connection->writeCommand(
            sprintf('AUTHENTICATE XOAUTH2 %s', $xoauth)
        );
        if (!str_contains($response, "OK")) {
            throw new AuthenticatorException(
                "Authentication failed: $response"
            );
        }
    }
}
