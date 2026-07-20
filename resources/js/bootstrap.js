/**
 * We've replaced Axios with the native Fetch API to eliminate ~14KB from the bundle.
 * Filament and Livewire use their own request mechanisms, so Axios is not needed.
 *
 * If you ever need AJAX calls, use:
 *   fetch('/api/endpoint', {
 *       headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content }
 *   })
 */
