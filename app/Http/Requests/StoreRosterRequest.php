<?php

namespace App\Http\Requests;

use App\Models\Classroom;
use App\Enums\RepositoryVisibility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreRosterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $classroom = $this->route('classroom');

        abort_unless(
            $classroom instanceof Classroom && $classroom->teacher_id === $user->id,
            404,
        );

        return $classroom->hasActiveGitHubInstallation()
            && ! $classroom->rosterEntries()
                ->whereNotNull('claimed_by_user_id')
                ->where('canvas_user_id', 'not like', 'github-user-%')
                ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roster' => ['required', File::types(['csv', 'txt'])->max(2 * 1024)],
            'repository_visibility' => ['required', Rule::enum(RepositoryVisibility::class)],
        ];
    }
}
