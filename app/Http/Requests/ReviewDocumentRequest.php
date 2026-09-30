<?php

namespace App\Http\Requests;

use App\Enums\DocumentStatus;
use App\Enums\ReviewAction;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        if (! $document instanceof Document) {
            return false;
        }

        // DocumentPolicy::review() reads $document->division as an
        // already-loaded relation (not a fresh query) — load it here so a
        // single-document authorize check still resolves in one query.
        $document->loadMissing('division');

        return $this->user()->can('review', $document);
    }

    public function rules(): array
    {
        $document = $this->route('document');

        // The SDS's own remarks alone always imply "send it back" (see
        // DocumentReviewController::store()'s remarks-win rule, which
        // already overrides an explicit Approve pick the same way) — so at
        // her own review stage specifically, she may submit remarks with no
        // action selected at all, rather than being forced to pick one just
        // to satisfy this rule. Every other stage/role still must pick one.
        $isSdsStage = $document instanceof Document && $document->status === DocumentStatus::PendingSdsReview;
        $hasRemarks = trim((string) $this->input('remarks')) !== '';

        // 'for_comment' means there's a comment to make — an empty remarks
        // field would leave the originator with nothing to act on, same
        // reasoning as returning/rejecting.
        $requiresRemarks = in_array($this->input('action'), [
            ReviewAction::ForComment->value,
            ReviewAction::ReturnedForRevision->value,
            ReviewAction::Rejected->value,
        ]);

        return [
            'action' => [$isSdsStage && $hasRemarks ? 'nullable' : 'required', Rule::enum(ReviewAction::class)],
            'remarks' => [
                $requiresRemarks ? 'required' : 'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'remarks.required' => 'Remarks are required when returning, rejecting, or sending a document for comment.',
        ];
    }
}
