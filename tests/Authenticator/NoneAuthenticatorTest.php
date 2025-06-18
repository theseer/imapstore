<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoneAuthenticator::class)]
class NoneAuthenticatorTest extends TestCase {

    public function testNoneLoginJustDoesNothing(): void {
        $auth = new NoneAuthenticator();
        $auth->authenticate(new MockConnection());

        $this->assertTrue(true);
    }
}
