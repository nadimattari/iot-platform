<?php

declare(strict_types=1);

namespace App\Entity;

enum CommandStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Acked = 'acked';
    case Failed = 'failed';
}
