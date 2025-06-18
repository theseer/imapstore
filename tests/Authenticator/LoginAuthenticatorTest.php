<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LoginAuthenticator::class)]
class LoginAuthenticatorTest extends TestCase {

    public function testLoginWithValidCredentialsSucceeds(): void {
        $connection = new MockConnection();
        $connection->setResponse(
            'LOGIN "username" "secret"',
            'A0001 OK [CAPABILITY INFO HERE] Logged in'
        );

        $inst = new LoginAuthenticator('username', 'secret');
        $inst->authenticate($connection);

        $this->assertTrue(true);
    }

    public function testLoginWithInvalidCredentialsFails(): void {
        $connection = new MockConnection();
        $connection->setResponse(
            'LOGIN "username" "wrong-password"',
            'A0001 NO [AUTHENTICATIONFAILED] Authentication failed.'
        );

        $inst = new LoginAuthenticator('username', 'wrong-password');

        $this->expectException(AuthenticatorException::class);
        $inst->authenticate($connection);
    }

}
