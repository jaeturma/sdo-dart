<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Traits\DocumentScopesTrait;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use DocumentScopesTrait;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Each scope is built once and cloned before every divergent terminal
        // operation (count vs. get/selectRaw) — outgoingScope()/incomingScope()/
        // forActionScope()/visibleScope() each re-run Division::selfAndChildrenIds()
        // sub-queries internally, so calling the scope method itself twice (as
        // this used to) re-runs that sub-query twice for no reason.
        $outgoingBase = $this->outgoingScope(Document::query(), $user);
        $incomingBase = $this->incomingScope(Document::query(), $user);
        $forActionBase = $this->forActionScope(Document::query(), $user);
        $visibleBase = $this->visibleScope(Document::query(), $user);

        $stats = [
            'totalOutgoing' => (clone $outgoingBase)->count(),
            'totalIncoming' => (clone $incomingBase)->count(),
            'forActionCount' => (clone $forActionBase)->count(),
            // whereBetween (rather than whereMonth/whereYear, which wrap the
            // column in a function and defeat any index on it) over an
            // explicit month range.
            'completedThisMonth' => (clone $visibleBase)
                ->where('status', DocumentStatus::Completed)
                ->whereBetween('updated_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'outgoingByStatus' => (clone $outgoingBase)
                ->selectRaw('status, count(*) as cnt')
                ->groupBy('status')
                ->pluck('cnt', 'status'),
            'incomingByStatus' => (clone $incomingBase)
                ->selectRaw('status, count(*) as cnt')
                ->groupBy('status')
                ->pluck('cnt', 'status'),
        ];

        $pendingItems = (clone $forActionBase)
            ->with(['uploader:id,name', 'division:id,name'])
            ->withExists(['routings as has_rush_routing' => fn ($q) => $q->where('is_rush', true)])
            ->latest()
            ->limit(5)
            ->get();

        $recentDocs = (clone $visibleBase)
            ->with(['uploader:id,name', 'division:id,name'])
            ->withExists(['routings as has_rush_routing' => fn ($q) => $q->where('is_rush', true)])
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('Dashboard', compact('stats', 'pendingItems', 'recentDocs'));
    }
}
