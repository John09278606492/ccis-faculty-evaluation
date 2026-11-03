<?php

namespace App\Livewire;

use App\Services\DatabaseNotificationActions;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Livewire\Attributes\On;

class DatabaseNotifications extends \Filament\Livewire\DatabaseNotifications
{
    private ?array $pendingChanges = null;

    private function pending(): array
    {
        return $this->pendingChanges ??= app(DatabaseNotificationActions::class)->pending($this->getUser()->getKey());
    }

    public function hasPendingChanges(): bool
    {
        return $this->pending() !== [];
    }

    private function change(string $action, ?string $id = null): void
    {
        abort_unless($user = $this->getUser(), 401);
        app(DatabaseNotificationActions::class)->record($user->getKey(), $action, $id);
        $this->pendingChanges = null;
    }

    #[On('notificationClosed')]
    public function removeNotification(string $id): void
    {
        $this->change('dismiss', $id);
    }

    #[On('markedNotificationAsRead')]
    public function markNotificationAsRead(string $id): void
    {
        $this->change('read', $id);
    }

    #[On('markedNotificationAsUnread')]
    public function markNotificationAsUnread(string $id): void
    {
        $this->change('unread', $id);
    }

    public function clearNotifications(): void
    {
        $this->change('dismiss');
    }

    public function markAllNotificationsAsRead(): void
    {
        $this->change('read');
    }

    public function retryPendingActions(): void
    {
        abort_unless($user = $this->getUser(), 401);
        app(DatabaseNotificationActions::class)->flush($user->getKey());
        $this->pendingChanges = null;
    }

    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()->whereNotIn('id', array_keys(array_filter($this->pending(), fn ($action) => $action === 'dismiss')));
    }

    public function getNotifications(): DatabaseNotificationCollection|Paginator
    {
        $notifications = parent::getNotifications();
        foreach ($notifications as $notification) {
            $action = $this->pending()[$notification->id] ?? null;
            if (in_array($action, ['read', 'unread'], true)) {
                $notification->read_at = $action === 'read' ? now() : null;
            }
        }

        return $notifications;
    }

    public function getUnreadNotificationsCount(): int
    {
        $pending = $this->pending();

        return $this->getNotificationsQuery()->whereNotIn('id', array_keys($pending))->unread()->count()
            + $this->getNotificationsQuery()->whereIn('id', array_keys(array_filter($pending, fn ($action) => $action === 'unread')))->count();
    }

    public function render(): View
    {
        return view('livewire.database-notifications');
    }
}
