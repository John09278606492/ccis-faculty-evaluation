<?php

namespace App\Livewire;

use App\Services\GuidedHelp;
use JibayMcs\FilamentTour\Livewire\FilamentTourWidget;
use JibayMcs\FilamentTour\Tour\HasTour;
use Livewire\Attributes\On;

class GuidedTours extends FilamentTourWidget
{
    use HasTour;

    private ?string $context = null;

    public function tours(): array
    {
        return $this->context ? [app(GuidedHelp::class)->tour($this->context)] : [];
    }

    #[On('filament-tour::load-elements')]
    public function load(): void
    {
        // Load only requested, permission-checked instructions. No panel-wide scan,
        // record mounting, first-tenant guessing or automatic modal opening.
        $this->tours = $this->constructTours(static::class);
        $this->highlights = [];
        $this->dispatch('filament-tour::loaded-elements', only_visible_once: true, tours: $this->tours, highlights: []);
    }

    #[On('campus-guide::start')]
    public function startGuide(string $context): void
    {
        abort_unless(app(GuidedHelp::class)->allowed($context), 403);
        $this->context = $context;
        $this->load();
        $this->dispatch('filament-tour::open-tour', id: 'campus-'.$context);
    }
}
