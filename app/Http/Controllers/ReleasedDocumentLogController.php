<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReleasedDocumentLogController extends Controller
{
    /**
     * The daily Released Documents Log — every outgoing document Records
     * Section released on the selected date (defaults to today), with
     * summary statistics, for Records Section to review and print. The
     * released-documents counterpart of IncomingDocumentLogController; see
     * DocumentPolicy::viewReleasedLog() for who this is for.
     *
     * Counted by `documents.released_at` (the latest release, so a document
     * re-released after a status revert is counted once, on its final
     * release date), restricted to documents still `Released`.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewReleasedLog', Document::class);

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $date = $request->query('date') ?: now()->toDateString();
        $day = Carbon::parse($date);

        $documents = $this->released()
            ->whereDate('released_at', $date)
            ->with(['uploader:id,name', 'division:id,name', 'releasedBy:id,name'])
            ->orderBy('released_at')
            ->get([
                'id', 'title', 'control_number', 'uploaded_by', 'division_id',
                'released_at', 'released_by', 'emailed_at',
            ]);

        $emailed = $documents->whereNotNull('emailed_at')->count();

        $stats = [
            'released' => $documents->count(),
            'emailed' => $emailed,
            'awaiting_email' => $documents->count() - $emailed,
            'month' => $this->released()
                ->whereBetween('released_at', [$day->copy()->startOfMonth(), $day->copy()->endOfMonth()])
                ->count(),
            'year' => $this->released()
                ->whereBetween('released_at', [$day->copy()->startOfYear(), $day->copy()->endOfYear()])
                ->count(),
            'by_division' => $documents
                ->groupBy(fn (Document $d) => $d->division?->name ?? '—')
                ->map(fn ($group, $division) => ['division' => $division, 'count' => $group->count()])
                ->sortBy([['count', 'desc'], ['division', 'asc']])
                ->values(),
        ];

        return Inertia::render('Documents/ReleasedLog', compact('documents', 'date', 'stats'));
    }

    private function released(): Builder
    {
        return Document::query()
            ->where('type', DocumentType::Outgoing)
            ->where('status', DocumentStatus::Released)
            ->whereNotNull('released_at');
    }
}
