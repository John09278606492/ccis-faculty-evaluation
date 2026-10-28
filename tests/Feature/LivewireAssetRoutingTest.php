<?php

use Livewire\Mechanisms\FrontendAssets\FrontendAssets;

it('uses the same available login script URL in local and campus modes', function (bool $debug) {
    config(['app.debug' => $debug]);

    $this->get('/login')->assertOk()->assertSee('/livewire/livewire.js?id=', escape: false);
    expect(app(FrontendAssets::class)->javaScriptRoute->uri)->toBe('livewire/livewire.js');
    $this->get('/livewire/livewire.js')->assertOk();
    $this->get('/livewire/livewire.min.js')->assertOk();
})->with([true, false]);
