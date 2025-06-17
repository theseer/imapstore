<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Foldername::class)]
class FoldernameTest extends TestCase {

    public function testCanBeCreatedFromString(): void {
        $foldername = Foldername::fromString('INBOX');
        $this->assertInstanceOf(Foldername::class, $foldername);
    }

    public function testFromStringWithEmptyStringThrowsException(): void {
        $this->expectException(FoldernameException::class);
        $this->expectExceptionMessage('Folder name cannot be empty');

        Foldername::fromString('');
    }

    public function testFromStringWithOnlyWhitespaceThrowsException(): void {
        $this->expectException(FoldernameException::class);
        $this->expectExceptionMessage('Folder name cannot be empty');

        Foldername::fromString('   ');
    }

    public function testCanBeConvertedBackToString(): void {
        $name = 'INBOX';
        $this->assertEquals(
            $name,
            Foldername::fromString($name)->asString()
        );
    }
}
