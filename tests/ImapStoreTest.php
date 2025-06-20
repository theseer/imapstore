<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImapStore::class)]
#[UsesClass(Message::class)]
#[UsesClass(Foldername::class)]
class ImapStoreTest extends TestCase {

    private Connection $mockConnection;
    private ImapStore $store;

    private Authenticator|MockObject $authenticator;
    protected function setUp(): void {
        $this->mockConnection = new MockConnection();
        $this->authenticator = $this->createMock(Authenticator::class);
        $this->authenticator->expects($this->once())
            ->method('authenticate')
            ->with($this->mockConnection);

        $this->store = new ImapStore(
            $this->mockConnection,
            $this->authenticator
        );
    }

    public function testCanStoreMessage() {
        $this->mockConnection->setResponse(
            'LIST "" "INBOX"',
            '* LIST (\HasChildren) "." INBOX',
            'A0000 OK List completed.'
        );
        $this->mockConnection->setResponse(
            'APPEND "INBOX" {4}',
            '+ OK'
        );

        $this->mockConnection->setResponse(
            'DATA',
            'A0003 OK [APPENDUID 1203293220 34544] Append completed.'
        );

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('INBOX')
        );
    }

    public function testCanStoreMessageWithSingleFlag() {
        $this->mockConnection->setResponse(
            'LIST "" "INBOX"',
            '* LIST (\HasChildren) "." INBOX',
            'A0000 OK List completed.'
        );
        $this->mockConnection->setResponse(
            'APPEND "INBOX" (\Seen) {4}',
            '+ OK'
        );

        $this->mockConnection->setResponse(
            'DATA',
            'A0003 OK [APPENDUID 1203293220 34544] Append completed.'
        );

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('INBOX'),
            MessageFlag::SEEN
        );
    }

    public function testCanStoreMessageWithMultipleFlags() {
        $this->mockConnection->setResponse(
            'LIST "" "INBOX"',
            '* LIST (\HasChildren) "." INBOX',
            'A0000 OK List completed.'
        );
        $this->mockConnection->setResponse(
            'APPEND "INBOX" (\Seen \Flagged) {4}',
            '+ OK'
        );

        $this->mockConnection->setResponse(
            'DATA',
            'A0003 OK [APPENDUID 1203293220 34544] Append completed.'
        );

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('INBOX'),
            MessageFlag::SEEN,
            MessageFlag::FLAGGED
        );
    }

    public function testTryingToWriteToNotExistingFolderThrowsException(): void {
        $this->mockConnection->setResponse(
            'LIST "" "NOT-EXISTING"',
            'A0000 OK List completed.'
        );

        $this->expectException(ImapStoreException::class);
        $this->expectExceptionCode(ImapStoreException::FOLDER_NOT_FOUND);

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('NOT-EXISTING')
        );
    }

    public function testFailingAuthenticationThrowsException(): void {
        $this->authenticator->method('authenticate')
            ->willThrowException(new AuthenticatorException('...'));

        $this->expectException(ImapStoreException::class);
        $this->expectExceptionCode(ImapStoreException::AUTH_FAILED);

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('INBOX')
        );
    }

    public function testFailingToWriteToFolderThrowsException() {
        $this->mockConnection->setResponse(
            'LIST "" "INBOX"',
            '* LIST (\HasChildren) "." INBOX',
            'A0000 OK List completed.'
        );
        $this->mockConnection->setResponse(
            'APPEND "INBOX" {4}',
            'Some Error happend - maybe details here'
        );

        $this->expectException(ImapStoreException::class);
        $this->expectExceptionCode(ImapStoreException::MESSAGE_STORE_FAILED);

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('INBOX')
        );
    }

    public function testFailingStoreMessageAfterSendingThrowsException() {
        $this->mockConnection->setResponse(
            'LIST "" "INBOX"',
            '* LIST (\HasChildren) "." INBOX',
            'A0000 OK List completed.'
        );
        $this->mockConnection->setResponse(
            'APPEND "INBOX" {4}',
            '+ OK'
        );

        $this->mockConnection->setResponse(
            'DATA',
            'A0003 BAD Something failed here.'
        );

        $this->expectException(ImapStoreException::class);
        $this->expectExceptionCode(ImapStoreException::MESSAGE_STORE_FAILED);

        $this->store->store(
            Message::fromString('test'),
            Foldername::fromString('INBOX')
        );
    }

}
