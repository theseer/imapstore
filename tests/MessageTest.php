<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Message::class)]
class MessageTest extends TestCase {

    private const string CONTENT = "Subject: Test Message\r\nFrom: test@example.com\r\n\r\nThis is a test message.";

    public function testCanBeCreatedFromString(): void {
        $message = Message::fromString(self::CONTENT);
        $this->assertInstanceOf(Message::class, $message);
    }

    public function testFromStringWithEmptyStringThrowsException(): void {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Message content cannot be empty');

        Message::fromString('');
    }

    public function testFromStringWithOnlyWhitespaceThrowsException(): void {
        $this->expectException(MessageException::class);
        $this->expectExceptionMessage('Message content cannot be empty');

        Message::fromString('   ');
    }

    public function testOriginalMessageContentCanBeRetrieved(): void {
        $message = Message::fromString(self::CONTENT);

        $this->assertEquals(self::CONTENT, $message->content);
    }

    public function testSizeCanBeRetrieved(): void {
        $message = Message::fromString(self::CONTENT);

        $this->assertEquals(strlen(self::CONTENT), $message->size);
    }

}
