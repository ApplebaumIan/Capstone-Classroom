<?php

namespace App\Http\Requests;

use App\Models\Classroom;
use App\RepositoryVisibility;
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
        $classroom = $user->classroom;

        return $classroom instanceof Classroom
            && $user->can('update', $classroom)
            && $classroom->github_installation_id !== null
            && ! $classroom->rosterEntries()->whereNotNull('claimed_by_user_id')->exists();
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
