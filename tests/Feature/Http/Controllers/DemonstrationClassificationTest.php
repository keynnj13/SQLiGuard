<?php

namespace Tests\Feature\Http\Controllers;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\Comparison;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Testing\TestView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Payload classification shown next to (never instead of) the observed
 * comparison result. Tests marked lab-database run real comparisons.
 */
class DemonstrationClassificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string DISCLAIMER = 'Payload classification is an educational, rule-based aid. It does not detect every SQL injection technique and is not a security boundary. Parameterized queries provide the primary protection against SQL injection.';

    private const string SAME_RECORDS_NOTE = 'The classifier recognized an attack-like pattern, but both modes returned the same records for this input. This does not necessarily mean the classification was incorrect; the query may have changed without affecting the returned results.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * POST a real comparison in the local environment with a valid CSRF token.
     *
     * @param  array<string, string>  $input
     */
    private function runLocally(DemonstrationType $type, array $input): TestResponse
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        return $this->actingAs(User::factory()->create())
            ->withSession(['_token' => 'phase4-t3-token'])
            ->post(route('demonstrations.run', $type), [...$input, '_token' => 'phase4-t3-token']);
    }

    /**
     * Render the comparison page with hand-built results, exactly as the controller passes them.
     *
     * @param  array<string, string>  $input
     */
    private function renderComparison(DemonstrationType $type, array $input, ModeResult $vulnerable, ModeResult $secure, Classification $classification): TestView
    {
        $definition = $type->demonstration();
        $fieldLabels = array_map(fn (array $field): string => $field['label'], $definition->fields());

        return $this->withViewErrors([])->view('demonstrations.show', [
            'type' => $type,
            'types' => DemonstrationType::cases(),
            'demonstration' => $definition,
            'values' => $input,
            'comparison' => new Comparison($type, $definition, $input, $vulnerable, $secure),
            'classification' => $classification,
            'classificationRules' => $classification->displayRules($fieldLabels),
            'vulnerableModeAvailable' => true,
        ]);
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private function executed(string $mode, array $rows): ModeResult
    {
        return new ModeResult(mode: $mode, status: ModeResult::EXECUTED, sql: 'SELECT 1', rows: $rows, summary: count($rows).' row(s).');
    }

    /**
     * The classification panel only, so assertions cannot match the input card or result panels.
     */
    private function classificationPanel(string $html): string
    {
        preg_match('~<section class="card mb-3 border-info-subtle".*?</section>~s', $html, $match);

        return $match[0] ?? '';
    }

    public function test_the_form_page_shows_no_classification_before_a_run(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('demonstrations.show', DemonstrationType::Tautology));

        $response->assertOk()->assertDontSee('Payload classification');
    }

    public function test_a_normal_label_does_not_hide_an_altered_vulnerable_query(): void
    {
        $privateRow = ['id' => '2', 'title' => 'Fake private', 'owner_team' => 'team-alpha', 'visibility' => 'private'];

        $view = $this->renderComparison(
            DemonstrationType::Comment,
            ['team' => 'team-alpha'],
            $this->executed(ModeResult::VULNERABLE, [$privateRow]),
            $this->executed(ModeResult::SECURE, []),
            new Classification(PayloadCategory::Normal, [], [], PayloadClassifier::VERSION),
        );

        $view->assertSee('data-classification="normal"', false)
            ->assertSee('Normal input')
            ->assertSee('Input altered the vulnerable query')
            ->assertSee('The classifier did not recognize an attack pattern, but the vulnerable query was still altered.')
            ->assertSee('No classification rule matched this input.');
    }

    public function test_forged_explanations_and_unknown_rules_are_never_displayed(): void
    {
        $forged = new Classification(
            category: PayloadCategory::Comment,
            rulesByField: ['team' => ['comment.line', 'not.a.rule'], 'ghost_field' => ['union.select']],
            explanations: ['comment.line' => 'FORGED-EXPLANATION', 'not.a.rule' => 'ARBITRARY-EXPLANATION'],
            version: PayloadClassifier::VERSION,
        );

        $view = $this->renderComparison(DemonstrationType::Comment, ['team' => 'x'], $this->executed(ModeResult::VULNERABLE, []), $this->executed(ModeResult::SECURE, []), $forged);

        $panel = $this->classificationPanel((string) $view);
        $this->assertStringContainsString(e(PayloadClassifier::EXPLANATIONS['comment.line']), $panel);
        foreach (['FORGED-EXPLANATION', 'ARBITRARY-EXPLANATION', 'not.a.rule', 'ghost_field', 'union.select'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $panel);
        }
    }

    public function test_the_disclaimer_is_shown_with_every_classification(): void
    {
        $view = $this->renderComparison(DemonstrationType::Union, ['category' => 'stationery'], $this->executed(ModeResult::VULNERABLE, []), $this->executed(ModeResult::SECURE, []), new Classification(PayloadCategory::Normal, [], [], 1));

        $view->assertSee(self::DISCLAIMER)->assertSee('Classifier version 1');
    }

    /**
     * @return array<string, array{DemonstrationType, string, PayloadCategory, string}>
     */
    public static function attackSamples(): array
    {
        return [
            'tautology username' => [DemonstrationType::Tautology, 'always-true-username', PayloadCategory::Tautology, 'tautology.equal_literals'],
            'tautology password' => [DemonstrationType::Tautology, 'always-true-password', PayloadCategory::Tautology, 'tautology.equal_literals'],
            'union' => [DemonstrationType::Union, 'union-internal-projects', PayloadCategory::Union, 'union.select'],
            'union against users' => [DemonstrationType::Union, 'union-application-users', PayloadCategory::Union, 'union.select'],
            'comment' => [DemonstrationType::Comment, 'comment-out-visibility', PayloadCategory::Comment, 'comment.line'],
        ];
    }

    #[DataProvider('attackSamples')]
    #[Group('lab-database')]
    public function test_each_demonstration_displays_the_category_of_its_attack_sample(DemonstrationType $type, string $sample, PayloadCategory $category, string $ruleId): void
    {
        $response = $this->runLocally($type, $type->demonstration()->samples()[$sample]['values']);

        $response->assertOk()
            ->assertSee('data-classification="'.$category->value.'"', false)
            ->assertSee($category->label())
            ->assertSee($ruleId)
            ->assertSee(PayloadClassifier::EXPLANATIONS[$ruleId])
            ->assertSee(self::DISCLAIMER);
    }

    /**
     * @return array<string, array{DemonstrationType, array<string, string>}>
     */
    public static function normalInputs(): array
    {
        return [
            'tautology' => [DemonstrationType::Tautology, ['username' => 'demo.analyst', 'password' => 'Analyst-Lab-2026']],
            'union' => [DemonstrationType::Union, ['category' => 'stationery']],
            'comment' => [DemonstrationType::Comment, ['team' => 'team-alpha']],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('normalInputs')]
    #[Group('lab-database')]
    public function test_normal_input_displays_the_normal_category(DemonstrationType $type, array $input): void
    {
        $response = $this->runLocally($type, $input);

        $response->assertOk()
            ->assertSee('data-classification="normal"', false)
            ->assertSee('No classification rule matched this input.')
            ->assertSee('Same behavior in both modes');
    }

    #[Group('lab-database')]
    public function test_supporting_signals_display_unknown_next_to_the_observed_alteration(): void
    {
        $response = $this->runLocally(DemonstrationType::Comment, ['team' => "O'Brien"]);

        $response->assertOk()
            ->assertSee('data-classification="unknown"', false)
            ->assertSee('Unknown / unclassified')
            ->assertSee('signal.quote')
            ->assertSee('Input altered the vulnerable query')
            ->assertSee('The classifier did not recognize an attack pattern, but the vulnerable query was still altered.');
    }

    #[Group('lab-database')]
    public function test_mixed_patterns_show_the_primary_category_and_every_matched_explanation(): void
    {
        $response = $this->runLocally(DemonstrationType::Union, [
            'category' => "none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects --",
        ]);

        $response->assertOk()
            ->assertSee('data-classification="union"', false)
            ->assertSeeInOrder(['union.select', 'comment.line', 'signal.quote', 'signal.sql_keyword'])
            ->assertSee(PayloadClassifier::EXPLANATIONS['union.select'])
            ->assertSee(PayloadClassifier::EXPLANATIONS['comment.line'])
            ->assertSee('FAKE Project Kestrel');
    }

    #[Group('lab-database')]
    public function test_rules_are_grouped_under_their_input_field(): void
    {
        $response = $this->runLocally(DemonstrationType::Tautology, ['username' => "demo.analyst' --", 'password' => "x') OR ('1'='1"]);

        $panel = $this->classificationPanel($response->getContent());
        $this->assertMatchesRegularExpression('~<h3[^>]*>Username</h3>\s*<ul[^>]*>.*comment\.line.*?</ul>~s', $panel);
        $this->assertMatchesRegularExpression('~<h3[^>]*>Password</h3>\s*<ul[^>]*>.*tautology\.equal_literals.*?</ul>~s', $panel);
        $response->assertSee('data-classification="tautology"', false);
    }

    #[Group('lab-database')]
    public function test_a_false_positive_label_is_shown_next_to_unchanged_behavior(): void
    {
        $response = $this->runLocally(DemonstrationType::Comment, ['team' => 'rock -- roll']);

        $response->assertOk()
            ->assertSee('data-classification="comment"', false)
            ->assertSee('Same behavior in both modes')
            ->assertSee(self::SAME_RECORDS_NOTE);
    }

    /**
     * A real injection that changes the vulnerable SQL structure but returns
     * the same (empty) records as Secure Mode must not be called a false positive.
     */
    #[Group('lab-database')]
    public function test_an_injection_with_no_visible_effect_is_not_called_a_false_positive(): void
    {
        $response = $this->runLocally(DemonstrationType::Comment, ['team' => "x' AND 1=0 --"]);

        $response->assertOk()
            ->assertSee('data-classification="comment"', false)
            ->assertSee('Same behavior in both modes')
            ->assertSee(self::SAME_RECORDS_NOTE);

        $comparison = $response->viewData('comparison');
        $this->assertSame(ModeResult::EXECUTED, $comparison->vulnerable->status);
        $this->assertSame(ModeResult::EXECUTED, $comparison->secure->status);
        $this->assertCount(count($comparison->secure->rows), $comparison->vulnerable->rows);
        $this->assertFalse($comparison->inputAlteredQuery());
        $this->assertStringContainsString("owner_team = 'x' AND 1=0 --'", $comparison->vulnerable->sql);

        $panel = $this->classificationPanel($response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('false positive', $panel);
        $this->assertStringNotContainsString('behaved the same as Secure Mode', $panel);
    }

    #[Group('lab-database')]
    public function test_classification_does_not_change_the_comparison_results(): void
    {
        $response = $this->runLocally(DemonstrationType::Tautology, ['username' => "' OR '1'='1", 'password' => 'anything']);

        $response->assertOk()
            ->assertSee('4 accounts matched.')
            ->assertSee('No matching account: the fake login is refused.')
            ->assertSee('<span class="mark px-0">&#039; OR &#039;1&#039;=&#039;1</span>', false)
            ->assertSee('<code class="text-break">&#039; OR &#039;1&#039;=&#039;1</code>', false)
            ->assertSeeInOrder(['Payload classification', 'Vulnerable Mode', 'Secure Mode']);
    }

    #[Group('lab-database')]
    public function test_classification_output_is_escaped_and_never_echoes_the_input(): void
    {
        $response = $this->runLocally(DemonstrationType::Comment, ['team' => "<script>alert('ZQXT3')</script> --"]);

        $response->assertOk()->assertDontSee("<script>alert('ZQXT3')", false);
        $panel = $this->classificationPanel($response->getContent());
        $this->assertNotSame('', $panel);
        $this->assertStringNotContainsString('ZQXT3', $panel);
        $this->assertStringNotContainsString('<script', $panel);
        $this->assertStringContainsString('data-classification="comment"', $panel);
    }
}
