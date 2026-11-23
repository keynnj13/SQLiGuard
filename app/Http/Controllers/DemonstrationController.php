<?php

namespace App\Http\Controllers;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\Comparison;
use App\Demonstrations\ComparisonRecorder;
use App\Demonstrations\DemonstrationRunner;
use App\Demonstrations\VulnerableMode;
use App\Enums\DemonstrationType;
use App\Http\Requests\RunDemonstrationRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Shared demonstration interface for Administrators and Students/Testers
 * (`role:administrator,student`). The comparison is returned directly as
 * response data; only its metadata is saved to the history
 * (ComparisonRecorder), never the input, SQL or rows.
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
     * Run the same input through Vulnerable Mode and Secure Mode, label it,
     * then save its history record.
     *
     * The comparison runs first and receives the validated input unchanged.
     * The classifier is applied afterwards to the same values; its label is
     * shown next to the observed effect and never influences execution.
     * History is saved only once both are complete, for the authenticated
     * user; if saving fails, the completed comparison is still shown.
     */
    public function run(RunDemonstrationRequest $request, DemonstrationType $demonstration, DemonstrationRunner $runner, PayloadClassifier $classifier, ComparisonRecorder $recorder): View
    {
        $user = $request->user();
        $input = $request->demonstrationInput();
        $comparison = $runner->compare($demonstration, $input);
        $classification = $classifier->classify($demonstration, $input);
        $historyRecorded = $recorder->record($user, $comparison, $classification) !== null;

        return $this->page($demonstration, $input, $comparison, $classification, $historyRecorded);
    }

    /**
     * @param  array<string, string>  $values
     * @param  bool|null  $historyRecorded  whether the comparison's history was saved; null when nothing ran
     */
    private function page(DemonstrationType $type, array $values, ?Comparison $comparison = null, ?Classification $classification = null, ?bool $historyRecorded = null): View
    {
        $fieldLabels = array_map(fn (array $field): string => $field['label'], $type->demonstration()->fields());

        return view('demonstrations.show', [
            'type' => $type,
            'types' => DemonstrationType::cases(),
            'demonstration' => $type->demonstration(),
            'values' => $values,
            'comparison' => $comparison,
            'classification' => $classification,
            'classificationRules' => $classification?->displayRules($fieldLabels) ?? [],
            'historyRecorded' => $historyRecorded,
            'vulnerableModeAvailable' => VulnerableMode::isAvailable(),
        ]);
    }
}
