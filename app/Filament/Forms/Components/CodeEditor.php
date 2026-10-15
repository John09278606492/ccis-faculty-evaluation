<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Concerns\HasPlaceholder;
use Filament\Forms\Components\Field;

/**
 * Syntax-highlighted code field backed by the self-hosted CodeMirror bundle.
 */
class CodeEditor extends Field
{
    use HasPlaceholder;

    protected string $view = 'filament.forms.components.code-editor';

    protected string|Closure $language = 'plaintext';

    public function language(string|Closure $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->evaluate($this->language) ?: 'plaintext';
    }
}
