<?php

namespace App\Http\Controllers;

use App\Filament\Resources\AcknowledgementFormResource;
use App\Models\AcknowledgementForm;
use App\Services\AcknowledgementForms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class AcknowledgementPrintController extends Controller
{
    public function __invoke(Request $request, AcknowledgementForms $service)
    {
        $ids = $request->validate([
            'forms' => ['required'],
            'forms.*' => ['integer'],
        ])['forms'];

        $forms = AcknowledgementForm::whereKey((array) $ids)->orderBy('year_section')->orderBy('date_received')->get();
        abort_if($forms->isEmpty(), 404);
        foreach ($forms as $form) {
            abort_unless($request->user()->can('view', $form), 403);
            $service->syncRoster($form);
        }
        $forms->load(['recipients' => fn ($query) => $query->orderBy('student_name'), 'courseOffering.semester.schoolYear']);

        return response()
            ->view('reports.acknowledgement-receipt', [
                'pages' => $service->printPages($forms),
                'letterhead' => config('acknowledgements.letterhead'),
                'footer' => config('acknowledgements.footer'),
                'backUrl' => $this->backUrl($forms),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    /** The printout opens in its own tab: this leads back into the system, to the receipt or to the list. */
    protected function backUrl(Collection $forms): string
    {
        $campusId = $forms->first()->courseOffering?->semester?->schoolYear?->campus_id;

        if (! $campusId) {
            return url('/');
        }

        return $forms->count() === 1
            ? AcknowledgementFormResource::getUrl('edit', ['record' => $forms->first(), 'tenant' => $campusId])
            : AcknowledgementFormResource::getUrl('index', ['tenant' => $campusId]);
    }
}
