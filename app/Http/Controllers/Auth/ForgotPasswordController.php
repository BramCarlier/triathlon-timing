<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForgotPasswordController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        if(!app(\App\Services\AccountInvitationService::class)->configured()) {
            return back()->withErrors(['email'=>'Email sending is not connected yet. Contact your race administrator for a temporary password.']);
        }
        try {
            Password::sendResetLink(['email'=>strtolower($request->string('email')->toString()),'is_active'=>true]);
        } catch (\Throwable $exception) {
            logger()->warning('Password reset email could not be sent.', ['exception_type'=>$exception::class]);
            return back()->withErrors(['email'=>'Email could not be sent right now. Try again later or contact your race administrator.']);
        }
        return back()->with('success','If an active account matches this email, you will receive a password setup link. Check your inbox and spam folder.');
    }
}
