<?php

namespace App\Jobs;

use App\Mail\EvaluationReminderMail;
use App\Models\EvaluationReminder;
use App\Services\EvaluationReminders;
use App\Settings\EmailReminderSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendEvaluationReminderEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(public int $reminderId) {}

    public function handle(): void
    {
        $reminder = EvaluationReminder::with(['student', 'sender', 'campus'])->find($this->reminderId);
        if (! $reminder || $reminder->email_status !== 'queued' || ! $reminder->email || ! $reminder->student) {
            return;
        }
        try {
            static::mailer()->to($reminder->email, $reminder->student->name)->send(new EvaluationReminderMail($reminder));
            $reminder->update(['email_status' => 'sent', 'emailed_at' => now(), 'email_error' => null]);
        } catch (\Throwable $e) {
            $reminder->update(['email_status' => 'failed', 'email_error' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    /** The super admin's mailbox from Email Reminders settings, used only for these emails. */
    public static function mailer(): Mailer
    {
        $settings = app(EmailReminderSetting::class);
        abort_unless(app(EvaluationReminders::class)->emailReady(), 422, 'Email reminders are not set up.');
        config(['mail.mailers.evaluation_reminders' => [
            'transport' => 'smtp', 'host' => $settings->smtp_host, 'port' => $settings->smtp_port,
            'encryption' => $settings->smtp_port === 465 ? 'ssl' : 'tls', 'username' => $settings->sender_email,
            'password' => $settings->smtp_password, 'timeout' => 30,
        ]]);
        app('mail.manager')->purge('evaluation_reminders');

        return Mail::mailer('evaluation_reminders');
    }
}
