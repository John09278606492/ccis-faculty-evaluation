<?php

namespace App\Notifications;

use App\Events\BellNotificationsChanged;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * A Filament notification for the bell that is stored right away and shown at once.
 * Filament queues bell notifications, and the classroom server runs no queue worker,
 * so they used to wait in the jobs table and never appeared.
 */
class BellNotification extends Notification
{
    public function sendToDatabase(Model|Authenticatable|Collection|array $users, bool $isEventDispatched = true): static
    {
        if (! is_iterable($users)) {
            $users = [$users];
        }

        foreach ($users as $user) {
            $user->notifyNow($this->toDatabase());

            if ($isEventDispatched) {
                try {
                    broadcast(new BellNotificationsChanged($user->getKey()));
                } catch (\Throwable $e) {
                    Log::warning('Live update could not be delivered', ['kind' => 'bell', 'error' => $e->getMessage()]);
                }
            }
        }

        return $this;
    }
}
