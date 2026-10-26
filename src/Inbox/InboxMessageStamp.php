<?php

declare(strict_types=1);

namespace App\Inbox;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

final readonly class InboxMessageStamp implements NonSendableStampInterface
{
    public function __construct(
        public string $messageId,
    ) {
    }
}
