<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ComparisonHistoryRequest;
use App\Models\ComparisonRecord;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Every user's demonstration history. Routes are in the `role:administrator`
 * group; the list is also authorized against ComparisonRecordPolicy.
 */
class HistoryController extends Controller
{
    /**
     * List all users' comparisons, newest first, with their owners eager-loaded.
     */
    public function index(ComparisonHistoryRequest $request): View
    {
        Gate::authorize('viewAll', ComparisonRecord::class);

        $filters = $request->filters();

        return view('admin.history.index', [
            'records' => ComparisonRecord::query()
                ->with('user:id,name,email,is_active')
                ->filtered($filters)
                ->newestFirst()
                ->paginate(15)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }
}
