<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request)
    {
        return $request->user() ? redirect($this->destination($request)) : view('accounting.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255']);
        if (! Auth::attempt($data + ['is_active' => true], false)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect, or this account is inactive.']);
        }
        $request->session()->regenerate();

        return redirect($this->destination($request));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    private function destination(Request $request): string
    {
        return $request->user()->allowed('platform.staff.access') ? '/admin' : '/account';
    }
}
