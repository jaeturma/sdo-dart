<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IncomingDocumentLogController extends Controller
{
    /**
     * The daily Incoming Documents Log — every incoming document uploaded
     * on the selected date (defaults to today), for Records Section to
     * review and print. See DocumentPolicy::viewIncomingLog() for exactly
     * who this is for and why it's a broader grant than their normal
     * document-list visibility.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewIncomingLog', Document::class);

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $date = $request->query('date') ?: now()->toDateString();

        $documents = Document::query()
            ->where('type', DocumentType::Incoming)
            ->whereDate('created_at', $date)
            ->with(['uploader:id,name', 'division:id,name'])
            ->orderBy('created_at')
            ->get([
                'id', 'title', 'control_number', 'reference_number', 'source_origin',
                'uploaded_by', 'division_id', 'status', 'created_at',
            ]);

        return Inertia::render('Documents/IncomingLog', compact('documents', 'date'));
    }
}
