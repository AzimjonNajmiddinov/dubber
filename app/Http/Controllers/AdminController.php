<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminController extends Controller
{
    public function loginForm()
    {
        if (Auth::check()) {
            return redirect()->route('admin.dubs.index');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'phone'    => 'required|string',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt(['email' => $request->input('phone'), 'password' => $request->input('password')])) {
            return back()->withErrors(['phone' => 'Incorrect phone or password'])->withInput(['phone' => $request->input('phone')]);
        }

        $request->session()->regenerate();

        return redirect()->route('admin.dubs.index');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
