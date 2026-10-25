<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\User;
use App\Models\WorkspaceDraft;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private working copies. Never treated as published data or evaluation responses. */
class WorkspaceDrafts
{
    public static function hash(array $state): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            return array_map(fn ($item) => is_array($item) ? $sort($item) : $item, $value);
        };

        return hash('sha256', json_encode($sort($state), JSON_THROW_ON_ERROR));
    }

    public function find(User $user, Campus $campus, string $context): ?WorkspaceDraft
    {
        abort_unless($user->canAccessTenant($campus), 403);

        return WorkspaceDraft::where('user_id', $user->id)->where('campus_id', $campus->id)->where('context', hash('sha256', $context))->first();
    }

    public function write(User $user, Campus $campus, string $context, array $payload, string $base, ?string $revision, bool $completed = false): WorkspaceDraft
    {
        abort_unless($user->canAccessTenant($campus), 403);
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 512000) {
            throw ValidationException::withMessages(['workspaceDraft' => 'This draft is too large. Save your work before leaving this page.']);
        }
        $existing = $this->find($user, $campus, $context);
        $values = ['payload' => $payload, 'base_revision' => $base, 'revision' => (string) Str::uuid(), 'expires_at' => now()->addDays(30)];
        if (! $existing) {
            if ($revision !== null && ! $completed) {
                $this->conflict();
            }
            try {
                return WorkspaceDraft::create(['user_id' => $user->id, 'campus_id' => $campus->id, 'context' => hash('sha256', $context), ...$values]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                $this->conflict();
            }
        }
        if (! $completed && $existing->revision !== $revision) {
            $this->conflict();
        }
        // Keep an empty versioned marker after Save/Discard. An older request from
        // another tab must not recreate a draft that has already been completed.
        $replacement = new WorkspaceDraft($values);
        $updated = WorkspaceDraft::whereKey($existing->id)->where('revision', $existing->revision)->update($replacement->getAttributes() + ['updated_at' => now()]);
        if (! $updated) {
            $this->conflict();
        }

        return $existing->fresh();
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['workspaceDraft' => 'This draft changed in another tab. Reload this page to use the latest draft.']);
    }
}
