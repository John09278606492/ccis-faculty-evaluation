<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Same message Filament's bell listens for, sent without the queue. */
class BellNotificationsChanged implements ShouldBroadcastNow
{
    public function __construct(public int $userId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'database-notifications.sent';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
