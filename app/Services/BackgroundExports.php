<?php

namespace App\Services;

use App\Filament\Resources\UserResource;
use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\Department;
use App\Models\User;

class BackgroundExports
{
    public function query(BackgroundTask $task, User $actor, Campus $campus)
    {
        return match ($task->kind) {
            'staff-export' => UserResource::getEloquentQuery(),
            'faculty-export' => User::query()->whereIn('id', app(ReportService::class)->offerings($task->payload['context'])->select('user_id')),
            'department-export' => app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->where('is_active', true),
            default => abort(403),
        };
    }

    public function generate(BackgroundTask $task, User $actor, Campus $campus): array
    {
        $tasks = app(BackgroundTasks::class);
        $ids = array_values(array_unique(array_map('intval', $task->payload['ids'])));
        $query = $this->query($task, $actor, $campus)->whereKey($ids);
        abort_unless((clone $query)->count() === count($ids), 403, 'Selected records or access changed before export.');
        $rows = 0;
        $tasks->csv($task, $task->kind.'-'.now()->format('Y-m-d').'.csv', function ($handle) use ($query, $task, $tasks, &$rows) {
            $tasks->row($handle, match ($task->kind) {
                'staff-export' => ['ID', 'Name', 'Email', 'Employee ID', 'Department', 'Updated At'],
                'faculty-export' => ['Faculty', 'Instrument', 'Mean / 5', 'Rating / 100', 'Complete Responses', 'Assignments', 'Student Completion %'],
                'department-export' => ['Department', 'Instrument', 'Faculty', 'Complete Responses', 'Mean / 5', 'Rating / 100', 'Student Completion %'],
            });
            $query->with($task->kind === 'staff-export' ? ['instructorDepartment'] : [])->orderBy('id')->chunkById(100, function ($records) use ($task, $tasks, $handle, &$rows) {
                foreach ($records as $record) {
                    if ($task->kind === 'staff-export') {
                        $tasks->row($handle, [$record->id, $record->name, $record->email, $record->employee_id, $record->instructorDepartment?->code, $record->updated_at]);
                        $rows++;

                        continue;
                    }
                    $summary = $task->kind === 'faculty-export'
                        ? app(ReportService::class)->getEvaluateeSummary($record, null, null, $task->payload['context'])
                        : app(ReportService::class)->getDepartmentSummary($record, $task->payload['semester_id'] ?? null);
                    foreach ($summary['instruments'] as $instrument => $score) {
                        $tail = [$score['mean_5'] !== null ? number_format($score['mean_5'], 2) : '',
                            $score['percentage'] !== null ? number_format($score['percentage'], 2) : '', $summary['completion_rate']];
                        $tasks->row($handle, $task->kind === 'faculty-export'
                            ? [$record->name, $instrument, $tail[0], $tail[1], $score['count'], $summary['total_courses'], $tail[2]]
                            : [$record->name, $instrument, $summary['total_evaluatees'], $score['count'], ...$tail]);
                        $rows++;
                    }
                }
            });
        });

        return ['scope_ids' => $ids, 'row_count' => $rows, 'message' => $rows.' rows exported. Your private download is ready and remains available for 7 days.'];
    }

    public function authorizeDownload(BackgroundTask $task, User $actor, Campus $campus): void
    {
        $ids = $task->result['scope_ids'] ?? [];
        if ($ids) {
            abort_unless($this->query($task, $actor, $campus)->whereKey($ids)->count() === count($ids), 403);
        }
    }
}
