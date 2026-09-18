<?php

namespace App\Http\Controllers;

use App\Mail\TeacherAccessApproved;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class TeacherAccessApprovalController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        abort_if($user->teacher_access_requested_at === null, 404);

        return Inertia::render('auth/teacher-access-approval', [
            'teacher' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'approved' => $user->teacher_access_approved_at !== null,
            'approval_url' => $request->fullUrl(),
        ]);
    }

    public function store(User $user): RedirectResponse
    {
        abort_if($user->teacher_access_requested_at === null, 404);

        DB::transaction(function () use ($user): void {
            $approved = $user->newQuery()
                ->whereKey($user->getKey())
                ->whereNull('teacher_access_approved_at')
                ->update(['teacher_access_approved_at' => now()]);

            if ($approved === 1) {
                $user->refresh();
                Mail::to($user)->send(new TeacherAccessApproved($user));
            }
        });

        return back()->with('success', 'Teacher access approved.');
    }
}
