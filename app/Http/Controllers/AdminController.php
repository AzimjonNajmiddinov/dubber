<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function loginForm()
    {
        if (session('admin_logged_in')) {
            return redirect()->route('admin.dubs.index');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        $correct = config('dubber.admin_password');

        if (!$correct || $request->input('password') !== $correct) {
            return back()->withErrors(['password' => 'Incorrect password']);
        }

        $request->session()->put('admin_logged_in', true);

        return redirect()->route('admin.dubs.index');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_logged_in');

        return redirect()->route('admin.login');
    }
}
