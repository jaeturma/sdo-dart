<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class StoreReleaseStampRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && $this->user()->can('release', $document);
    }

    public function rules(): array
    {
        return [
            'p12_password'          => ['required', 'string'],
            'remarks'               => ['nullable', 'string', 'max:2000'],
            'stamp_page'            => ['required', 'integer', 'min:1'],
            'stamp_x_percent'       => ['required', 'numeric', 'between:0,95'],
            'stamp_y_percent'       => ['required', 'numeric', 'between:0,95'],
            'stamp_width_percent'   => ['required', 'numeric', 'between:5,80'],
        ];
    }

    public function messages(): array
    {
        return [
            'p12_password.required' => 'Your P12 certificate password is required to release.',
            'stamp_page.required'   => 'Please place the release stamp on the document.',
        ];
    }
}
