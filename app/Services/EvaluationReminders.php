<?php

namespace App\Services;

use App\Jobs\SendEvaluationReminderEmail;
use App\Models\Campus;
use App\Models\Department;
use App\Models\EvaluationReminder;
use App\Models\User;
use App\Notifications\BellNotification;
use App\Settings\EmailReminderSetting;
use Filament\Notifications\Actions\Action as NotificationAction;

/** Bell + email reminders from a dean, chairperson or administrator to students who still have open evaluations. */
class EvaluationReminders
{
    public const TEMPLATES = [
        'grades' => [
            'label' => 'Grades are waiting on your evaluation',
            'subject' => 'Complete your faculty evaluation to see your grades',
            'message' => 'You can\'t view your grades for this semester until you finish evaluating all your instructors. It only takes a few minutes, and your answers stay anonymous.',
        ],
        'reminder' => [
            'label' => 'Friendly reminder',
            'subject' => 'Reminder: please complete your faculty evaluation',
            'message' => 'Your honest feedback helps your instructors improve their teaching. Please finish the evaluations that are still waiting for you. Your answers stay anonymous.',
        ],
        'last_call' => [
            'label' => 'Last call before it closes',
            'subject' => 'Last call: the faculty evaluation closes soon',
            'message' => 'The evaluation period is about to close. Please complete your remaining evaluations today so your voice is counted.',
        ],
    ];

    /** "Chairperson", "Dean", ... for the sentence "Your Chairperson, Name, is asking you ...". */
    public function senderTitle(User $actor, Campus $campus): string
    {
        $departments = Department::where('campus_id', $campus->id);
        if ((clone $departments)->where('supervisor_id', $actor->id)->exists() || $actor->hasRole('dean')) {
            return 'Dean';
        }
        if ((clone $departments)->where('chairperson_id', $actor->id)->exists() || $actor->hasRole('chairperson')) {
            return 'Chairperson';
        }

        return $actor->isSuperAdmin() || $actor->hasRole('campus_admin') ? 'Administrator' : 'Instructor';
    }

    public function senderLine(User $actor, Campus $campus): string
    {
        return 'Your '.$this->senderTitle($actor, $campus).', '.$actor->name.', is asking you to complete your faculty evaluation.';
    }

    public function fill(string $text, User $student, int $remaining, User $actor, Campus $campus): string
    {
        return strtr($text, ['{first_name}' => $student->first_name ?: $student->name, '{remaining}' => (string) $remaining,
            '{sender}' => $actor->name, '{sender_title}' => $this->senderTitle($actor, $campus)]);
    }

    public function emailReady(): bool
    {
        return app(EmailReminderSetting::class)->ready();
    }

    /**
     * @param  array<int, int>  $counts  student id => open evaluations left
     * @param  array<int, int>  $sections  student id => section id (for "last reminded" on section rows)
     * @return array{students: int, bells: int, emails: int, no_address: int, skipped: int}
     */
    public function send(User $actor, Campus $campus, array $counts, array $data, array $sections = []): array
    {
        $counts = array_filter($counts);
        $result = ['students' => count($counts), 'bells' => 0, 'emails' => 0, 'no_address' => 0, 'skipped' => 0];
        if (($hours = (int) ($data['skip_hours'] ?? 0)) > 0) {
            $recent = EvaluationReminder::whereIn('user_id', array_keys($counts))->where('created_at', '>=', now()->subHours($hours))->pluck('user_id')->flip();
            $result['skipped'] = count(array_intersect_key($counts, $recent->all()));
            $counts = array_diff_key($counts, $recent->all());
        }
        $email = ($data['email'] ?? false) && $this->emailReady();
        $addresses = app(StudentEmailAddress::class);
        $subjectText = trim($data['subject']);
        $messageText = trim((string) ($data['message'] ?? ''));
        $line = $this->senderLine($actor, $campus);
        $link = route('evaluation.entry');
        foreach (User::whereKey(array_keys($counts))->get() as $student) {
            $remaining = $counts[$student->id];
            $subject = $this->fill($subjectText, $student, $remaining, $actor, $campus);
            $message = $this->fill($messageText, $student, $remaining, $actor, $campus);
            $address = $email ? $addresses->for($student) : null;
            $reminder = EvaluationReminder::create(['campus_id' => $campus->id, 'sender_id' => $actor->id, 'user_id' => $student->id,
                'academic_section_id' => $sections[$student->id] ?? null, 'remaining' => $remaining, 'subject' => $subject, 'message' => $message,
                'email' => $address, 'email_status' => $address ? 'queued' : 'none']);
            if ($data['bell'] ?? true) {
                BellNotification::make()->title($subject)->icon('heroicon-o-bell-alert')->warning()
                    ->body($line.' You still have '.$remaining.' '.str('evaluation')->plural($remaining).' to finish.'.($message !== '' ? ' '.$message : ''))
                    ->actions([NotificationAction::make('open')->label('Evaluate now')->button()->url($link)])
                    ->sendToDatabase($student);
                $result['bells']++;
            }
            if ($address) {
                SendEvaluationReminderEmail::dispatch($reminder->id);
                $result['emails']++;
            } elseif ($email) {
                $result['no_address']++;
            }
        }

        return $result;
    }

    public function summary(array $result): string
    {
        $parts = [$result['bells'].' bell '.str('notice')->plural($result['bells'])];
        if ($result['emails'] || $result['no_address']) {
            $parts[] = $result['emails'].' '.str('email')->plural($result['emails']).' queued';
        }
        if ($result['no_address']) {
            $parts[] = $result['no_address'].' without an email address';
        }
        if ($result['skipped']) {
            $parts[] = $result['skipped'].' skipped (reminded recently)';
        }

        return implode(' · ', $parts);
    }
}
