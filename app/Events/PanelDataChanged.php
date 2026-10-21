<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class PanelDataChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public string $kind, public bool $staffOnly = false) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(app()->environment().'.panel'.($this->staffOnly ? '.staff' : ''))];
    }

    public function broadcastAs(): string
    {
        return 'panel.changed';
    }

    public function broadcastWith(): array
    {
        // Invalidation only: each page reloads what its viewer is allowed to see.
        return ['kind' => $this->kind];
    }
}
