<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComparisonHistoryRequest;
use App\Models\ComparisonRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Personal demonstration history (both roles, `role:administrator,student`).
 *
 * The list always contains only the signed-in user's own records, also for
 * administrators; everyone's history is Admin\HistoryController. Records are
 * read-only and contain metadata only.
 */
class HistoryController extends Controller
{
    /**
     * List the signed-in user's own comparisons, newest first.
     */
    public function index(ComparisonHistoryRequest $request): View
    {
        Gate::authorize('viewAny', ComparisonRecord::class);

        $filters = $request->filters();

        return view('history.index', [
            'records' => ComparisonRecord::query()
                ->ownedBy($request->user())
                ->filtered($filters)
                ->newestFirst()
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    /**
     * Show one comparison: administrators any record, others only their own (404 otherwise).
     */
    public function show(Request $request, ComparisonRecord $comparisonRecord): View
    {
        Gate::authorize('view', $comparisonRecord);

        $viewerIsAdministrator = $request->user()->isAdministrator();

        if ($viewerIsAdministrator) {
            $comparisonRecord->load('user:id,name,email,is_active');
        }

        $classification = $comparisonRecord->storedClassification();
        $fieldLabels = array_map(fn (array $field): string => $field['label'], $comparisonRecord->demonstration_type->demonstration()->fields());

        return view('history.show', [
            'record' => $comparisonRecord,
            'classification' => $classification,
            'classificationRules' => $classification->displayRules($fieldLabels),
            'showOwner' => $viewerIsAdministrator,
        ]);
    }
}
