<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class SignDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && $this->user()->can('sign', $document);
    }

    public function rules(): array
    {
        return [
            'p12_password'                 => ['required', 'string'],
            'remarks'                      => ['nullable', 'string', 'max:2000'],
            'include_signature_details'    => ['sometimes', 'boolean'],
            'placements'                   => ['required', 'array', 'min:1'],
            'placements.*.page'            => ['required', 'integer', 'min:1'],
            'placements.*.x_percent'       => ['required', 'numeric', 'between:0,95'],
            'placements.*.y_percent'       => ['required', 'numeric', 'between:0,95'],
            'placements.*.width_percent'   => ['required', 'numeric', 'between:5,80'],
        ];
    }

    public function messages(): array
    {
        return [
            'p12_password.required' => 'Your P12 certificate password is required to sign.',
            'placements.required'   => 'Please place at least one signature on the document.',
            'placements.min'        => 'Please place at least one signature on the document.',
        ];
    }
}
