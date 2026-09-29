<?php

namespace App\Http\Requests;

use App\Models\DocumentRoutingRecipient;
use Illuminate\Foundation\Http\FormRequest;

class CompleteRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recipient = $this->route('recipient');

        return $recipient instanceof DocumentRoutingRecipient
            && $this->user()->can('complete', $recipient);
    }

    public function rules(): array
    {
        return [
            'response_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
