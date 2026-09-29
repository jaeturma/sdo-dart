<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDivisionRequest;
use App\Http\Requests\UpdateDivisionRequest;
use App\Models\Division;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DivisionController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Division::class);

        $search = trim((string) $request->query('search', ''));

        $divisions = Division::query()
            ->withCount(['users', 'documents', 'children', 'routingRecipients'])
            ->with('parent:id,name')
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get()
            ->map(fn (Division $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'parent' => $d->parent?->name,
                'usersCount' => $d->users_count,
                'documentsCount' => $d->documents_count,
                'childrenCount' => $d->children_count,
                'allowRdDirectRouting' => $d->allow_rd_direct_routing,
                'canDelete' => $d->users_count === 0 && $d->documents_count === 0
                    && $d->children_count === 0 && $d->routing_recipients_count === 0,
            ]);

        return Inertia::render('Divisions/Index', [
            'divisions' => $divisions,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Division::class);

        return Inertia::render('Divisions/Form', [
            'division' => null,
            'parentOptions' => $this->parentOptions(),
        ]);
    }

    public function store(StoreDivisionRequest $request): RedirectResponse
    {
        $division = Division::create([
            'name' => $request->name,
            'code' => $request->code,
            'parent_id' => $request->parent_id,
            'allow_rd_direct_routing' => $request->boolean('allow_rd_direct_routing'),
        ]);

        return redirect()->route('divisions.index')
            ->with('success', "Office \"{$division->name}\" created successfully.");
    }

    public function edit(Division $division): Response
    {
        $this->authorize('update', $division);

        return Inertia::render('Divisions/Form', [
            'division' => [
                'id' => $division->id,
                'name' => $division->name,
                'code' => $division->code,
                'parent_id' => $division->parent_id,
                'allow_rd_direct_routing' => $division->allow_rd_direct_routing,
            ],
            'parentOptions' => $this->parentOptions($division),
        ]);
    }

    public function update(UpdateDivisionRequest $request, Division $division): RedirectResponse
    {
        $division->update([
            'name' => $request->name,
            'code' => $request->code,
            'parent_id' => $request->parent_id,
            'allow_rd_direct_routing' => $request->boolean('allow_rd_direct_routing'),
        ]);

        return redirect()->route('divisions.index')
            ->with('success', "Office \"{$division->name}\" updated successfully.");
    }

    public function destroy(Division $division): RedirectResponse
    {
        $this->authorize('delete', $division);

        $blockers = $this->deletionBlockers($division);

        if ($blockers !== []) {
            return redirect()->route('divisions.index')->with(
                'error',
                "Cannot delete \"{$division->name}\" — it still has ".implode(', ', $blockers).'. Reassign or remove those first.'
            );
        }

        $name = $division->name;
        $division->delete();

        return redirect()->route('divisions.index')->with('success', "Office \"{$name}\" deleted.");
    }

    /**
     * Flat, depth-ordered list of every division for the Parent Office
     * select, matching UserController::divisionOptions()'s exact shape.
     * When editing, the division itself and its own direct children are
     * excluded — either would create a cycle in what is otherwise a
     * strictly two-level hierarchy.
     */
    private function parentOptions(?Division $excluding = null): array
    {
        $excludedIds = $excluding
            ? collect([$excluding->id])->merge($excluding->children()->pluck('id'))->all()
            : [];

        $options = [];

        Division::whereNull('parent_id')
            ->when($excludedIds !== [], fn ($q) => $q->whereNotIn('id', $excludedIds))
            ->with('children')
            ->orderBy('name')
            ->each(function (Division $div) use (&$options, $excludedIds) {
                $options[] = [
                    'id' => $div->id,
                    'label' => $div->code ? "{$div->name} ({$div->code})" : $div->name,
                    'depth' => 0,
                ];

                foreach ($div->children as $child) {
                    if (in_array($child->id, $excludedIds, true)) {
                        continue;
                    }

                    $options[] = ['id' => $child->id, 'label' => $child->name, 'depth' => 1];
                }
            });

        return $options;
    }

    /**
     * Human-readable reasons a division can't be deleted yet. Nothing that
     * references divisions.id is ever hard-blocked at the DB level — every
     * foreign key into it (users, documents, document_routing_recipients,
     * and divisions.parent_id itself) uses nullOnDelete() — so without this
     * check, deleting a division with real data would silently detach every
     * user/document/sub-office/routing assignment from it instead of
     * failing loudly.
     */
    private function deletionBlockers(Division $division): array
    {
        $blockers = [];

        if ($division->children()->exists()) {
            $blockers[] = 'sub-office(s)';
        }

        if ($division->users()->exists()) {
            $blockers[] = 'assigned user(s)';
        }

        if ($division->documents()->exists()) {
            $blockers[] = 'document(s)';
        }

        if ($division->routingRecipients()->exists()) {
            $blockers[] = 'routing history';
        }

        return $blockers;
    }
}
