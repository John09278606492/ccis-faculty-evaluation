<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ChangePasswordController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect('/');
        }

        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();
        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a password different from the starting password.']);
        }

        $user->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false])->save();
        $request->session()->regenerate();

        return redirect('/');
    }
}
