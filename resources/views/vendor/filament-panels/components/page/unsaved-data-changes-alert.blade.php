@if ($this->hasUnsavedDataChangesAlert())
    @script
        <script>
            const wire = $wire
            const component = wire.__instance

            const shouldPreventNavigation = () => {
                // Draft-enabled editors have their own save-before-navigation guard.
                if (component.workspaceDraft) return false
                if (! component.el.isConnected || component.effects?.redirect) {
                    return false
                }

                const data = wire.data

                if (data === undefined || data === null) {
                    return false
                }

                return window.jsMd5(JSON.stringify(data).replace(/\\/g, '')) !== wire.savedDataHash
            }

            const onNavigate = (event) => {
                if (shouldPreventNavigation() && ! window.confirm(@js(__('filament-panels::unsaved-changes-alert.body')))) {
                    event.preventDefault()
                }
            }

            const onBeforeUnload = (event) => {
                if (! shouldPreventNavigation()) {
                    return
                }

                event.preventDefault()
                event.returnValue = true
            }

            document.addEventListener('livewire:navigate', onNavigate)
            window.addEventListener('beforeunload', onBeforeUnload)

            // SPA page changes must remove handlers referencing the previous page's form.
            component.addCleanup(() => {
                document.removeEventListener('livewire:navigate', onNavigate)
                window.removeEventListener('beforeunload', onBeforeUnload)
            })
        </script>
    @endscript
@endif
