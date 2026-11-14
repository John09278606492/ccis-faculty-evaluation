<?php

namespace App\Http\Controllers;

use App\Services\StudentPins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * A student without a PIN waits for staff approval at every sign-in. Right after one approval,
 * they may choose their own PIN once, so the next sign-in needs no one to approve it.
 */
class StudentPinSetupController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $this->allowed($request)) {
            return redirect($this->next($request));
        }

        return view('auth.create-pin', ['skipUrl' => route('student.create-pin.skip')]);
    }

    public function update(Request $request): RedirectResponse
    {
        if (! $this->allowed($request)) {
            return redirect($this->next($request));
        }

        $user = $request->user();
        $data = $request->validate([
            'pin' => ['required', 'digits:'.StudentPins::LENGTH, 'confirmed'],
        ], [
            'pin.digits' => 'Your PIN must be exactly '.StudentPins::LENGTH.' digits.',
            'pin.confirmed' => 'The two PINs do not match.',
        ]);
        if (StudentPins::isEasyToGuess($data['pin'], $user->student_id)) {
            return back()->withErrors(['pin' => 'This PIN is too easy to guess. Avoid repeated or consecutive digits and your student ID.']);
        }

        $user->forceFill(['password' => Hash::make($data['pin']), 'pin_issued_at' => now(), 'must_change_password' => false])->save();
        $next = $this->next($request);
        $request->session()->forget(['student_pin_setup', 'student_pin_next']);
        $request->session()->regenerate();

        return redirect($next);
    }

    public function skip(Request $request): RedirectResponse
    {
        $next = $this->next($request);
        $request->session()->forget(['student_pin_setup', 'student_pin_next']);

        return redirect($next);
    }

    /** Only straight after an approval, and never to replace a PIN that already exists. */
    protected function allowed(Request $request): bool
    {
        $user = $request->user();

        return $user?->is_student && ! $user->pin_issued_at && $request->session()->get('student_pin_setup') === $user->id;
    }

    protected function next(Request $request): string
    {
        $next = $request->session()->get('student_pin_next');

        return is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//') ? $next : '/';
    }
}
