# ImapStore

A minimalistic PHP IMAP client library specifically designed for storing email messages on IMAP servers. This library provides a clean, object-oriented interface for connecting to IMAP servers and storing messages with appropriate flags.

## Features

- **Lightweight**: Focused solely on storing messages, not retrieving them
- **Secure**: Supports TLS/SSL connections
- **Type-safe**: Built with strict PHP type declarations
- **Simple API**: Easy-to-use object-oriented interface
- **Flexible Authentication**: Currently supports LOGIN authentication with extensible architecture for custom authentication methods
- **Message Flags**: Set message flags (like SEEN) when storing

## Installation

Install via Composer:

```bash
composer require theseer/imapstore
```

## Requirements

- PHP 8.4 or higher
- No additional PHP extensions required

## Usage

### Basic Usage

```php
<?php declare(strict_types=1);

use theseer\imapstore\Foldername;
use theseer\imapstore\ImapStore;
use theseer\imapstore\LoginAuthenticator;
use theseer\imapstore\Message;
use theseer\imapstore\MessageFlag;
use theseer\imapstore\TCPConnection;

require __DIR__ . '/vendor/autoload.php';

// Create a complete email message
$emailData = "From: sender@example.com\r\n";
$emailData .= "To: recipient@example.com\r\n";
$emailData .= "Subject: Test Message\r\n";
$emailData .= "Date: " . date('r') . "\r\n";
$emailData .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
$emailData .= "This is the message body.\r\n";

// Create the IMAP store connection
$store = new ImapStore(
    TCPConnection::createTLS('imap.example.com'),
    new LoginAuthenticator('username@example.com', 'password')
);

// Store the message in the INBOX folder with SEEN flag
$store->store(
    Message::fromString($emailData),
    Foldername::fromString('INBOX'),
    MessageFlag::SEEN
);
```

### Advanced Usage

#### Different Folder Destinations

```php
// Store in different folders
$store->store(
    Message::fromString($emailData),
    Foldername::fromString('Sent'),
    MessageFlag::SEEN
);

$store->store(
    Message::fromString($emailData),
    Foldername::fromString('Drafts'),
    MessageFlag::DRAFT
);
```

#### Non-TLS Connections

```php
// For non-TLS connections (not recommended for production)
// Uses default port 143
$store = new ImapStore(
    TCPConnection::createPlain('imap.example.com'),
    new LoginAuthenticator('username@example.com', 'password')
);
```

#### Custom Ports and Connections

```php
// Using custom ports
$store = new ImapStore(
    TCPConnection::createTLS('imap.example.com', 1993),
    new LoginAuthenticator('username@example.com', 'password')
);

// Custom connection implementations can be created if needed
// by implementing the Connection interface
```

#### Custom Authentication

```php
// The library currently supports LOGIN authentication
// Custom authentication methods can be implemented
// by implementing the Authenticator interface

$customAuth = new CustomAuthenticator($credentials);
$store = new ImapStore(
    TCPConnection::createTLS('imap.example.com'),
    $customAuth
);
```

#### Multiple Message Flags

```php
// Store message with multiple flags using variadics
$store->store(
    Message::fromString($emailData),
    Foldername::fromString('INBOX'),
    MessageFlag::SEEN,
    MessageFlag::FLAGGED
);
```

#### Using PHPMailer to Generate Messages

```php
use PHPMailer\PHPMailer\PHPMailer;

$mail = new PHPMailer();

// ...

$mail->setFrom('sender@example.com', 'John Doe');
$mail->addAddress('recipient@example.com', 'Jane Smith');
$mail->Subject = 'Generated Email';
$mail->Body = 'This email was generated using PHPMailer and stored via ImapStore.';

// Potentially sent your mail using PHPMailer here 

// Store it using ImapStore
$store->store(
    Message::fromString($mail->getSentMIMEMessage()),
    Foldername::fromString('Sent'),
    MessageFlag::SEEN
);
```

## Message Flags

The library supports the following message flags that can be set when storing messages:

- `MessageFlag::SEEN` - Mark message as read
- `MessageFlag::ANSWERED` - Mark message as answered  
- `MessageFlag::FLAGGED` - Mark message as flagged/important
- `MessageFlag::DELETED` - Mark message as deleted
- `MessageFlag::DRAFT` - Mark message as draft


## Error Handling

The library may throw exceptions for various error conditions. It's recommended to wrap your code in try-catch blocks:

```php
try {
    $store->store(
        Message::fromString($emailData),
        Foldername::fromString('INBOX'),
        MessageFlag::SEEN
    );
    echo "Message stored successfully\n";
} catch (Exception $e) {
    echo "Error storing message: " . $e->getMessage() . "\n";
}
```

## Security Considerations

- Always use TLS/SSL connections when possible (`TCPConnection::createTLS()`)
- Store credentials securely (consider using environment variables)
- Use strong authentication methods

## Alternatives and Complementary Libraries

- **[DirectoryTree/ImapEngine](https://packagist.org/packages/directorytree/imapengine)** - Full-featured IMAP client when you need comprehensive email operations (reading, searching, managing)
- **[ddeboer/imap](https://github.com/ddeboer/imap)** - Alternative full IMAP client library for reading and managing emails
- **[PHPMailer](https://github.com/PHPMailer/PHPMailer)** - Ideal for generating properly formatted email messages that can then be stored using ImapStore

