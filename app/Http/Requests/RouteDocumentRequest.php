<?php

namespace App\Http\Requests;

use App\Enums\RoutingAction;
use App\Models\Document;
use App\Services\RoutingCandidateResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RouteDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        if (! $document instanceof Document) {
            return false;
        }

        // DocumentPolicy::route() reads $document->routings/->recipients as
        // already-loaded relations (not fresh queries) — load them here so a
        // single-document authorize check still resolves in a bounded number
        // of queries instead of relying on N+1-prone lazy loading.
        $document->loadMissing('routings.recipients');

        return $this->user()->can('route', $document);
    }

    public function rules(): array
    {
        return [
            'is_rush' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'action' => ['required', Rule::enum(RoutingAction::class)],
            'action_other' => ['nullable', 'string', 'max:255'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'exists:users,id'],
        ];
    }

    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('action') === RoutingAction::Others->value
                && empty($this->input('action_other'))) {
                $v->errors()->add('action_other', 'Please specify the action when selecting "Others".');
            }

            $this->validateRecipientsAreEligible($v);
        });
    }

    /**
     * `user_ids.*` only checks `exists:users,id`, so this closes the gap
     * where a tampered request could submit an id outside the set of
     * candidates the requesting user is actually allowed to route to — this
     * used to only run for the narrow division-chief/unit-head case,
     * leaving the SDS/ASDS/secretary's broader candidate pool (ASDS, chiefs,
     * OSDS unit heads) completely unchecked server-side. Runs for every role
     * now, via RoutingCandidateResolver — the same source of truth
     * DocumentRoutingController::candidateUsers() uses to populate the UI
     * candidate list, so the two can never drift apart.
     */
    private function validateRecipientsAreEligible(Validator $v): void
    {
        $userIds = collect($this->input('user_ids', []))->filter()->all();

        if ($userIds === []) {
            return;
        }

        $eligibleIds = RoutingCandidateResolver::forUser($this->user())->pluck('id')->all();

        if (array_diff($userIds, $eligibleIds) !== []) {
            $v->errors()->add('user_ids', 'One or more selected recipients are not valid for this action.');
        }
    }
}
