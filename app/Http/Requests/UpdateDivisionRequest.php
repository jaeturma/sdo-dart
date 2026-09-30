<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('division'));
    }

    /**
     * The Parent Office select posts an empty string, not null, when left
     * blank — same reason as StoreUserRequest's division_id handling.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->code !== null && $this->code !== '' ? strtoupper((string) $this->code) : null,
            'parent_id' => $this->parent_id === '' ? null : $this->parent_id,
        ]);
    }

    public function rules(): array
    {
        $division = $this->route('division');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable', 'string', 'max:20', Rule::unique('divisions', 'code')->ignore($division->id),
                function ($attribute, $value, $fail) use ($division) {
                    // Renaming this code (e.g. to "OSDS") would silently
                    // strip the SDS's secretary of her routing powers and
                    // change OSDS units' review chain — see Division::isOsds().
                    if ($division->isOsds() && $value !== $division->code) {
                        $fail("This office's code is used internally to identify the Office of the SDS and can't be changed.");
                    }
                },
            ],
            'parent_id' => [
                'nullable', 'integer', 'exists:divisions,id',
                Rule::notIn([$division->id]),
                function ($attribute, $value, $fail) use ($division) {
                    // This app's real hierarchy is strictly two levels
                    // (top-level office -> sub-office) — picking one of this
                    // division's own children as its new parent would form a
                    // cycle, so it's blocked explicitly rather than left to
                    // silently produce an unreachable branch of the tree.
                    if ($value !== null && $division->children()->whereKey($value)->exists()) {
                        $fail('An office cannot be made a sub-office of its own sub-office.');
                    }
                },
            ],
            'allow_sds_direct_routing' => ['sometimes', 'boolean'],
            'is_records_section' => ['sometimes', 'boolean'],
        ];
    }
}
