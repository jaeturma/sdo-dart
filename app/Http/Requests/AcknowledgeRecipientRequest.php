<?php

namespace App\Http\Requests;

use App\Models\DocumentRoutingRecipient;
use Illuminate\Foundation\Http\FormRequest;

class AcknowledgeRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recipient = $this->route('recipient');

        return $recipient instanceof DocumentRoutingRecipient
            && $this->user()->can('acknowledge', $recipient);
    }

    public function rules(): array
    {
        return [
            'response_remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
