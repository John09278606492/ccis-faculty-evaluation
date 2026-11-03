@php
    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $pending = $this->hasPendingChanges();
@endphp
<div
    @if($pending) wire:poll.5s="retryPendingActions"
    @elseif($pollingInterval = $this->getPollingInterval()) wire:poll.{{ $pollingInterval }} @endif
    class="flex items-center gap-1"
>
    @if($trigger = $this->getTrigger())
        <x-filament-notifications::database.trigger>
            {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
        </x-filament-notifications::database.trigger>
    @endif
    @if($pending)
        <span role="status" class="text-xs text-warning-600" title="Your notification changes are saved and will finish syncing automatically when the import releases the database.">Saving…</span>
    @endif
    <x-filament-notifications::database.modal :notifications="$notifications" :unread-notifications-count="$unreadNotificationsCount" />
    @if($broadcastChannel = $this->getBroadcastChannel())
        <x-filament-notifications::database.echo :channel="$broadcastChannel" />
    @endif
</div>
