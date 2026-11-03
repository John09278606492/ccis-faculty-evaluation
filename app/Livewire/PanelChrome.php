<?php

namespace App\Livewire;

use Filament\Navigation\NavigationItem;
use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Keep navigation, tenant labels and the user menu live independently of editors. */
class PanelChrome extends Component
{
    #[Locked]
    public string $area;

    #[Locked]
    public string $pageUrl;

    public function mount(string $area, string $pageUrl): void
    {
        abort_unless(in_array($area, ['sidebar', 'topbar'], true), 404);
        $this->area = $area;
        $this->pageUrl = $pageUrl;
    }

    public function render()
    {
        $campus = filament()->getTenant();
        abort_unless(auth()->user() && (! $campus || auth()->user()->canAccessTenant($campus)), 403);
        $request = request();
        $originalRoute = $request->route();
        try {
            // A Livewire update uses /livewire/update. Evaluate active links against
            // the signed original page URL, without navigating away from its drafts.
            $route = app('router')->getRoutes()->match(Request::create($this->pageUrl));
            $request->setRouteResolver(fn () => $route);
            $navigation = filament()->getNavigation();
            $freezeActive = function (NavigationItem $item) use (&$freezeActive): void {
                $active = $item->isActive();
                $item->isActiveWhen(fn () => $active);
                foreach ($item->getChildItems() as $child) {
                    $freezeActive($child);
                }
            };
            foreach ($navigation as $group) {
                foreach ($group->getItems() as $item) {
                    $freezeActive($item);
                }
            }
        } finally {
            $request->setRouteResolver(fn () => $originalRoute);
        }

        return view('livewire.panel-chrome', compact('navigation'));
    }
}
