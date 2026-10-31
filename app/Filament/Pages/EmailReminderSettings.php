<?php

namespace App\Filament\Pages;

use App\Jobs\SendEvaluationReminderEmail;
use App\Mail\EvaluationReminderMail;
use App\Models\EvaluationReminder;
use App\Models\User;
use App\Services\EvaluationReminders;
use App\Services\StudentEmailAddress;
use App\Settings\EmailReminderSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as FieldAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Illuminate\Support\HtmlString;

/** Super admin: the mailbox that sends evaluation reminders and how student school emails are built. */
class EmailReminderSettings extends SettingsPage
{
    protected static string $settings = EmailReminderSetting::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Email Reminders';

    protected static ?string $title = 'Email Reminders';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['has_password'] = filled($data['smtp_password'] ?? null);
        $data['smtp_password'] = null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['smtp_password'] ?? null)) {
            $data['smtp_password'] = app(EmailReminderSetting::class)->smtp_password;
        } else {
            $data['smtp_password'] = preg_replace('/\s+/', '', $data['smtp_password']);
        }
        $data['sender_email'] = filled($data['sender_email']) ? mb_strtolower(trim($data['sender_email'])) : null;
        $data['student_domain'] = mb_strtolower(trim($data['student_domain'], " @\t"));
        unset($data['has_password']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fillEmails')->label('Fill student emails')->icon('heroicon-o-at-symbol')->color('gray')
                ->modalHeading('Fill in student school emails')
                ->modalDescription(function () {
                    $preview = app(StudentEmailAddress::class)->backfill(false, true);

                    return $preview['filled'].' students still have a placeholder email and will get their school address. '
                        .($preview['duplicate'] ? $preview['duplicate'].' share a name with another account and will be left for you to fill by hand. ' : '')
                        .'Addresses that staff typed in are never changed.';
                })
                ->form([
                    Toggle::make('rebuild')->label('Also redo addresses filled in automatically before')
                        ->helperText('Use this after changing the domain or the address format above.'),
                ])
                ->modalSubmitActionLabel('Fill emails')
                ->action(function (array $data) {
                    $result = app(StudentEmailAddress::class)->backfill((bool) ($data['rebuild'] ?? false));
                    Notification::make()->success()->persistent()->title($result['filled'].' student emails filled in')
                        ->body(collect([
                            $result['duplicate'] ? $result['duplicate'].' left as is because the address belongs to another account: '.implode('; ', array_slice($result['duplicates'], 0, 10)).(count($result['duplicates']) > 10 ? '; ...' : '') : null,
                            $result['no_name'] ? $result['no_name'].' have no first or last name' : null,
                        ])->filter()->join('. '))->send();
                }),
            Action::make('test')->label('Send test email')->icon('heroicon-o-paper-airplane')
                ->disabled(fn () => ! app(EvaluationReminders::class)->emailReady())
                ->tooltip(fn () => app(EvaluationReminders::class)->emailReady() ? 'Sends a sample reminder to the sender address' : 'Save the sender email and app password first')
                ->action(function () {
                    $settings = app(EmailReminderSetting::class);
                    $actor = auth()->user();
                    $reminder = new EvaluationReminder(['subject' => EvaluationReminders::TEMPLATES['grades']['subject'],
                        'message' => EvaluationReminders::TEMPLATES['grades']['message'], 'remaining' => 3]);
                    $reminder->setRelation('student', new User(['name' => $actor->name, 'first_name' => $actor->first_name ?: $actor->name, 'student_id' => '2024-0001']))
                        ->setRelation('sender', $actor)->setRelation('campus', filament()->getTenant());
                    try {
                        SendEvaluationReminderEmail::mailer()->to($settings->sender_email)->send(new EvaluationReminderMail($reminder));
                        Notification::make()->success()->title('Test email sent to '.$settings->sender_email)->body('Check that inbox (and Spam) to see how students will receive it.')->send();
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->persistent()->title('The test email could not be sent')
                            ->body(mb_substr($e->getMessage(), 0, 400).' Check the sender email and app password.')->send();
                    }
                }),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Who sends the emails')
                ->description('Reminders are sent from this mailbox. For a Gmail or BISU Google account, turn on 2-Step Verification and create an App Password at myaccount.google.com/apppasswords, then paste it here.')
                ->columns(2)->schema([
                    Hidden::make('has_password')->dehydrated(false),
                    TextInput::make('sender_email')->label('Sender email')->email()->placeholder('your.name@bisu.edu.ph')
                        ->helperText('Use your own super admin email so students see who it is from.')
                        ->suffixAction(FieldAction::make('mine')->icon('heroicon-o-user')->tooltip('Use my email')
                            ->action(fn ($set) => $set('sender_email', auth()->user()->email))),
                    TextInput::make('sender_name')->label('Sender name')->required()->maxLength(80),
                    TextInput::make('smtp_password')->label('App password')->password()->autocomplete('new-password')
                        ->placeholder(fn (Get $get) => $get('has_password') ? 'Saved. Leave blank to keep it.' : '16-letter app password')
                        ->helperText('Stored encrypted. It is never shown again.'),
                    Placeholder::make('status')->label('Status')->content(fn () => new HtmlString(app(EvaluationReminders::class)->emailReady()
                        ? '<span class="font-semibold text-success-600">Ready. Deans and chairpersons can send email reminders.</span>'
                        : '<span class="font-semibold text-warning-600">Not set up yet. Only bell reminders will be sent.</span>')),
                    TextInput::make('smtp_host')->label('Mail server')->required()->helperText('smtp.gmail.com for Gmail and BISU Google accounts.'),
                    TextInput::make('smtp_port')->label('Port')->numeric()->required()->helperText('587 (TLS) or 465 (SSL).'),
                ]),
            Section::make('Student email addresses')
                ->description('Built from each student\'s first and last name. Accents are removed (ñ becomes n) and spaces, dots and dashes are dropped. A school address already saved on a student is used as is.')
                ->columns(2)->schema([
                    TextInput::make('student_domain')->label('School email domain')->required()->prefix('@')->live(onBlur: true),
                    Radio::make('address_format')->label('Address format')->live()->required()
                        ->options(['first.last' => 'firstname.lastname (juan.delacruz)', 'firstlast' => 'firstnamelastname (juandelacruz)']),
                    Placeholder::make('examples')->label('Examples from your students')->columnSpanFull()->content(fn (Get $get) => $this->examples($get('student_domain'), $get('address_format'))),
                ]),
            Section::make('Recent reminders')->schema([
                Placeholder::make('stats')->hiddenLabel()->content(fn () => $this->stats()),
            ]),
        ]);
    }

    private function examples(?string $domain, ?string $format): HtmlString
    {
        $builder = app(StudentEmailAddress::class);
        $domain = mb_strtolower(trim((string) $domain, " @\t"));
        $students = User::where('is_student', true)->where(fn ($q) => $q->where('first_name', 'like', '%ñ%')->orWhere('last_name', 'like', '%ñ%')
            ->orWhere('last_name', 'like', '% %')->orWhere('last_name', 'like', '%-%'))->limit(4)->get()
            ->merge(User::where('is_student', true)->whereNotNull('first_name')->limit(2)->get());
        if ($students->isEmpty() || $domain === '') {
            return new HtmlString('<span class="text-gray-500">No students yet.</span>');
        }

        return new HtmlString('<ul class="space-y-1 text-sm">'.$students->map(fn (User $s) => '<li>'.e($s->first_name.' '.$s->last_name).' → <strong>'
            .e($builder->build($s->first_name, $s->last_name, $domain, (string) $format) ?? 'no address (missing name)').'</strong></li>')->join('').'</ul>');
    }

    private function stats(): HtmlString
    {
        $today = EvaluationReminder::where('created_at', '>=', now()->startOfDay());
        $counts = (clone $today)->selectRaw('email_status, count(*) as total')->groupBy('email_status')->pluck('total', 'email_status');
        $failed = EvaluationReminder::where('email_status', 'failed')->latest('id')->value('email_error');

        return new HtmlString('<p class="text-sm">Today: <strong>'.(clone $today)->count().'</strong> reminders · '
            .'<strong>'.($counts['sent'] ?? 0).'</strong> emails sent · <strong>'.($counts['queued'] ?? 0).'</strong> waiting · '
            .'<strong>'.($counts['failed'] ?? 0).'</strong> failed</p><p class="mt-1 text-xs text-gray-500">Gmail allows about 500 emails a day from a personal account and 2,000 from a Google Workspace account. Emails go out about 50 per minute.</p>'
            .($failed ? '<p class="mt-2 text-xs text-danger-600">Last error: '.e(mb_substr($failed, 0, 300)).'</p>' : '')
            .'<p class="mt-2 text-sm"><a class="font-medium text-primary-600 hover:underline" href="'.e(EmailLog::getUrl()).'">Open the Email Log →</a></p>');
    }
}
