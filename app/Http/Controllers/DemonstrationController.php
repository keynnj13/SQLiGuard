<?php

namespace App\Http\Controllers;

use App\Demonstrations\Comparison;
use App\Demonstrations\DemonstrationRunner;
use App\Demonstrations\VulnerableMode;
use App\Enums\DemonstrationType;
use App\Http\Requests\RunDemonstrationRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shared demonstration interface for Administrators and Students/Testers
 * (`role:administrator,student`). The comparison is returned directly as
 * temporary response data; nothing is stored in Phase 3.
 */
class DemonstrationController extends Controller
{
    /**
     * List the three demonstrations.
     */
    public function index(): View
    {
        return view('demonstrations.index', [
            'types' => DemonstrationType::cases(),
            'vulnerableModeAvailable' => VulnerableMode::isAvailable(),
        ]);
    }

    /**
     * Show a demonstration's form, optionally prefilled with a predefined sample.
     */
    public function show(Request $request, DemonstrationType $demonstration): View
    {
        $sample = $demonstration->demonstration()->samples()[$request->string('sample')->toString()] ?? null;

        return $this->page($demonstration, $sample['values'] ?? []);
    }

    /**
     * Run the same input through Vulnerable Mode and Secure Mode.
     */
    public function run(RunDemonstrationRequest $request, DemonstrationType $demonstration, DemonstrationRunner $runner): View
    {
        $input = $request->demonstrationInput();

        return $this->page($demonstration, $input, $runner->compare($demonstration, $input));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function page(DemonstrationType $type, array $values, ?Comparison $comparison = null): View
    {
        return view('demonstrations.show', [
            'type' => $type,
            'types' => DemonstrationType::cases(),
            'demonstration' => $type->demonstration(),
            'values' => $values,
            'comparison' => $comparison,
            'vulnerableModeAvailable' => VulnerableMode::isAvailable(),
        ]);
    }
}
