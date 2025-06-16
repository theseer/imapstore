<?php declare(strict_types=1);
namespace theseer\imapstore;

enum MessageFlag: string {
    case SEEN = '\Seen';
    case ANSWERED = '\Answered';
    case FLAGGED = '\Flagged';
    case DELETED = '\Deleted';
    case DRAFT = '\Draft';
    case RECENT = '\Recent';
}
