<?php

namespace App\Http\Controllers;

use App\Mail\TeacherAccessRequested;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class TeacherAccessRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canRequestTeacherAccess(), 403);

        $approverEmail = config('services.teacher_access.approver_email');
        abort_unless(
            is_string($approverEmail) && filled($approverEmail),
            503,
            'Teacher access approvals are not configured.',
        );

        DB::transaction(function () use ($user, $approverEmail): void {
            $requested = $user->newQuery()
                ->whereKey($user->getKey())
                ->whereNull('teacher_access_requested_at')
                ->update(['teacher_access_requested_at' => now()]);

            if ($requested === 1) {
                $user->refresh();
                $approvalUrl = URL::signedRoute('teacher-access.approvals.show', ['user' => $user]);

                Mail::to($approverEmail)->send(new TeacherAccessRequested($user, $approvalUrl));
            }
        });

        return to_route('dashboard')->with('success', 'Your teacher access request is awaiting approval.');
    }
}
