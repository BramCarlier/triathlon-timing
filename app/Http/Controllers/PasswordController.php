<?php
namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(): Response { return Inertia::render('Auth/Password'); }
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required','current_password'], 'password' => ['required','string','min:12','confirmed']]);
        $request->user()->forceFill(['password' => Hash::make($data['password']), 'force_password_change' => false])->save();
        return back()->with('success', 'Password updated.');
    }
}
