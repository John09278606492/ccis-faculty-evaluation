<?php

namespace App\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Concerns\HasPlaceholder;
use Filament\Forms\Components\Field;

/**
 * Multi-file code field backed by the self-hosted CodeMirror bundle. Its state
 * is the {"files": [...]} JSON described in App\Support\CodeProject.
 */
class CodeEditor extends Field
{
    use HasPlaceholder;

    protected string $view = 'filament.forms.components.code-editor';

    protected bool|Closure $canManageFiles = false;

    protected bool|Closure $canRun = false;

    protected array|Closure $starterFiles = [];

    public function canManageFiles(bool|Closure $condition = true): static
    {
        $this->canManageFiles = $condition;

        return $this;
    }

    public function canRun(bool|Closure $condition = true): static
    {
        $this->canRun = $condition;

        return $this;
    }

    /**
     * Files shown while the field is still empty.
     *
     * @param  array<int, array{name: string, content: string}>|Closure  $files
     */
    public function starterFiles(array|Closure $files): static
    {
        $this->starterFiles = $files;

        return $this;
    }

    public function getCanManageFiles(): bool
    {
        return (bool) $this->evaluate($this->canManageFiles);
    }

    public function getCanRun(): bool
    {
        return (bool) $this->evaluate($this->canRun);
    }

    public function getStarterFiles(): array
    {
        return $this->evaluate($this->starterFiles) ?? [];
    }
}
