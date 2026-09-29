<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * The Division select posts an empty string, not null, when left blank
     * — needed so a 'detailed_staff' submission (no division applicable)
     * actually resolves to null rather than failing 'exists:divisions,id'
     * against an empty string.
     */
    protected function prepareForValidation(): void
    {
        if ($this->division_id === '') {
            $this->merge(['division_id' => null]);
        }
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password'    => ['nullable', 'string', Password::default(), 'confirmed'],
            'role'        => ['required', Rule::in(['admin', 'rd', 'ard', 'division_chief', 'unit_head', 'originator', 'secretary', 'detailed_staff'])],
            'kmt_focal'   => ['sometimes', 'boolean'],
            // 'detailed_staff' isn't tied to any Regional Office division —
            // see RoleAndDivisionSeeder's comment on that role.
            'division_id' => [Rule::requiredIf(fn () => $this->input('role') !== 'detailed_staff'), 'nullable', 'integer', 'exists:divisions,id'],
        ];
    }
}
