<?php

namespace App\Http\Requests;

use App\Models\Division;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Division::class);
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('divisions', 'code')],
            'parent_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'allow_rd_direct_routing' => ['sometimes', 'boolean'],
        ];
    }
}
