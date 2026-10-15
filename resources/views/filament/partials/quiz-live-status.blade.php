<div role="status">
    @if ($changedElsewhere)
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-900" style="position:fixed;bottom:16px;left:16px;right:16px;z-index:40">
            This quiz changed on another device. Your unsaved edits are preserved. Copy anything you want to keep, then reload this page to load the latest version before saving.
        </div>
    @else
        <p class="text-xs text-gray-500">Changes from other devices arrive automatically. Unsaved edits stay on this device.</p>
    @endif
</div>
