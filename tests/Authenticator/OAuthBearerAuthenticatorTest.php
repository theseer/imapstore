<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use function base64_encode;
use function random_bytes;
use function sprintf;

#[CoversClass(OAuthBearerAuthenticator::class)]
class OAuthBearerAuthenticatorTest extends TestCase {

    public function testLoginWithValidCredentialsSucceeds(): void {
        $accessToken = random_bytes(10);
        $oauthString = base64_encode(
            sprintf("user=%s\x01auth=Bearer %s\x01\x01",
                'username',
                $accessToken
            )
        );

        $connection = new MockConnection();
        $connection->setResponse(
            'AUTHENTICATE XOAUTH2 ' . $oauthString,
            'A0001 OK [CAPABILITY INFO HERE] Logged in'
        );

        $inst = new OAuthBearerAuthenticator('username', $accessToken);
        $inst->authenticate($connection);

        $this->assertTrue(true);
    }

    public function testLoginWithInvalidCredentialsFails(): void {
        $accessToken = 'ToBeConsideredInvalidTokenString';
        $oauthString = base64_encode(
            sprintf("user=%s\x01auth=Bearer %s\x01\x01",
                'username',
                $accessToken
            )
        );

        $connection = new MockConnection();
        $connection->setResponse(
            'AUTHENTICATE XOAUTH2 ' . $oauthString,
            'A0001 NO [AUTHENTICATIONFAILED] Authentication failed.'
        );

        $inst = new OAuthBearerAuthenticator('username', $accessToken);

        $this->expectException(AuthenticatorException::class);
        $inst->authenticate($connection);
    }

}
