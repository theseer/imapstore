<?php declare(strict_types=1);
namespace TheSeer\ImapStore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageFlag::class)]
class MessageFlagTest extends TestCase
{
    public function testSeenFlagConstants(): void
    {
        $this->assertEquals('\\Seen', MessageFlag::SEEN->value);
    }

    public function testAnsweredFlagConstants(): void
    {
        $this->assertEquals('\\Answered', MessageFlag::ANSWERED->value);
    }

    public function testFlaggedFlagConstants(): void
    {
        $this->assertEquals('\\Flagged', MessageFlag::FLAGGED->value);
    }

    public function testDeletedFlagConstants(): void
    {
        $this->assertEquals('\\Deleted', MessageFlag::DELETED->value);
    }

    public function testDraftFlagConstants(): void
    {
        $this->assertEquals('\\Draft', MessageFlag::DRAFT->value);
    }

    public function testRecentFlagConstants(): void
    {
        $this->assertEquals('\\Recent', MessageFlag::RECENT->value);
    }
}
