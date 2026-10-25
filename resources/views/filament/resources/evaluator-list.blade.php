<div class="fi-resource-relation-manager flex flex-col gap-y-6">
    <x-filament-panels::resources.tabs />
    @if ($workspaceDraftContext !== '')
        @include('filament.partials.workspace-draft')
    @endif
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE, scopes: $this->getRenderHookScopes()) }}
    @php($supervisors = $this->getOwnerRecord()->supervisorEvaluators()->get())
    @if($supervisors->isNotEmpty())
        <p class="mb-3 text-sm text-gray-600 dark:text-gray-300"><strong>Supervisor evaluators:</strong> {{ $supervisors->pluck('name')->join(', ') }}. Assigned to this faculty evaluation only.</p>
    @endif
    {{ $this->table }}
    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER, scopes: $this->getRenderHookScopes()) }}
    <x-filament-panels::unsaved-action-changes-alert />
</div>
