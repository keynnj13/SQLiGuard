<?php

namespace Tests\Feature\Http\Requests;

use App\Enums\DemonstrationType;
use App\Http\Requests\RunDemonstrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RunDemonstrationRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string CONTROL_MESSAGE = 'must not contain control characters.';

    /**
     * Validate a payload exactly as the controller would, without running the
     * demonstration. Returns the input handed to the runner.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function validatedInput(DemonstrationType $type, array $data): array
    {
        $request = RunDemonstrationRequest::create('/demonstrations/'.$type->value, 'POST', $data);
        $route = (new Route('POST', 'demonstrations/{demonstration}', []))->bind($request);
        $route->setParameter('demonstration', $type);
        $request->setRouteResolver(fn (): Route => $route);
        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));

        $request->validateResolved();

        return $request->demonstrationInput();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    private function validationErrors(DemonstrationType $type, array $data): array
    {
        try {
            $this->validatedInput($type, $data);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }

    /**
     * Every validated field, with valid values for the demonstration's other fields.
     *
     * @return array<string, array{DemonstrationType, string, array<string, string>}>
     */
    public static function fields(): array
    {
        return [
            'tautology username' => [DemonstrationType::Tautology, 'username', ['password' => 'x']],
            'tautology password' => [DemonstrationType::Tautology, 'password', ['username' => 'demo.analyst']],
            'union category' => [DemonstrationType::Union, 'category', []],
            'comment team' => [DemonstrationType::Comment, 'team', []],
        ];
    }

    /**
     * @param  array<string, string>  $others
     */
    #[DataProvider('fields')]
    public function test_values_up_to_200_characters_are_accepted_and_201_are_rejected(DemonstrationType $type, string $field, array $others): void
    {
        $characters = ['ascii' => 'a', 'two-byte' => 'é', 'three-byte' => '€', 'four-byte' => '😀'];

        foreach ($characters as $kind => $character) {
            $accepted = $this->validatedInput($type, [...$others, $field => str_repeat($character, 200)]);
            $rejected = $this->validationErrors($type, [...$others, $field => str_repeat($character, 201)]);

            $this->assertSame(200, mb_strlen($accepted[$field]), "200 {$kind} characters must be accepted.");
            $this->assertSame(["The {$field} field must not be greater than 200 characters."], $rejected[$field] ?? [], "201 {$kind} characters must be rejected.");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUtf8(): array
    {
        return [
            'lone continuation byte' => ["team-\x80alpha"],
            'invalid lead byte' => ["team-\xFFalpha"],
            'truncated sequence' => ["team-alpha\xE2\x82"],
            'overlong encoding' => ["team-\xC0\xAFalpha"],
            'encoded surrogate' => ["team-\xED\xA0\x80alpha"],
        ];
    }

    #[DataProvider('invalidUtf8')]
    public function test_invalid_utf8_is_rejected(string $value): void
    {
        $errors = $this->validationErrors(DemonstrationType::Comment, ['team' => $value]);

        $this->assertSame(['The team must be valid UTF-8 text.'], $errors['team'] ?? []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function controlCharacters(): array
    {
        return [
            'NUL' => ["\0"],
            'start of heading' => ["\x01"],
            'escape' => ["\x1B"],
            'tab' => ["\t"],
            'line feed' => ["\n"],
            'carriage return' => ["\r"],
            'CRLF' => ["\r\n"],
            'vertical tab' => ["\x0B"],
            'form feed' => ["\x0C"],
            'delete' => ["\x7F"],
            'C1 next line' => ["\u{85}"],
            'C1 control sequence introducer' => ["\u{9B}"],
        ];
    }

    #[DataProvider('controlCharacters')]
    public function test_control_characters_are_rejected_in_every_field(string $character): void
    {
        foreach (self::fields() as [$type, $field, $others]) {
            $errors = $this->validationErrors($type, [...$others, $field => "demo{$character}value' --"]);

            $this->assertSame(["The {$field} ".self::CONTROL_MESSAGE], $errors[$field] ?? []);
        }
    }

    public function test_printable_unicode_is_accepted(): void
    {
        $input = $this->validatedInput(DemonstrationType::Comment, ['team' => "Café ☕ 😀 Ωmega O'Brien"]);

        $this->assertSame(['team' => "Café ☕ 😀 Ωmega O'Brien"], $input);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function emptyOptionalPasswords(): array
    {
        return [
            'empty string' => [['username' => 'demo.analyst', 'password' => '']],
            'null' => [['username' => 'demo.analyst', 'password' => null]],
            'missing' => [['username' => 'demo.analyst']],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('emptyOptionalPasswords')]
    public function test_an_empty_optional_password_becomes_an_empty_string(array $data): void
    {
        $input = $this->validatedInput(DemonstrationType::Tautology, $data);

        $this->assertSame(['username' => 'demo.analyst', 'password' => ''], $input);
    }

    /**
     * @return array<string, array{DemonstrationType, string, array<string, string>}>
     */
    public static function requiredFields(): array
    {
        return [
            'tautology username' => [DemonstrationType::Tautology, 'username', ['password' => 'x']],
            'union category' => [DemonstrationType::Union, 'category', []],
            'comment team' => [DemonstrationType::Comment, 'team', []],
        ];
    }

    /**
     * @param  array<string, string>  $others
     */
    #[DataProvider('requiredFields')]
    public function test_required_fields_must_be_present_and_not_blank(DemonstrationType $type, string $field, array $others): void
    {
        $message = ["The {$field} field is required."];

        $this->assertSame($message, $this->validationErrors($type, $others)[$field] ?? []);
        $this->assertSame($message, $this->validationErrors($type, [...$others, $field => ''])[$field] ?? []);
        $this->assertSame($message, $this->validationErrors($type, [...$others, $field => '   '])[$field] ?? []);
    }

    public function test_every_phase_3_sample_still_passes_validation_unchanged(): void
    {
        foreach (DemonstrationType::cases() as $type) {
            $definition = $type->demonstration();

            foreach ($definition->samples() as $key => $sample) {
                $expected = array_map(fn (string $name): string => $sample['values'][$name] ?? '', array_combine(array_keys($definition->fields()), array_keys($definition->fields())));

                $this->assertSame($expected, $this->validatedInput($type, $sample['values']), "Sample {$type->value}:{$key} must pass unchanged.");
            }
        }
    }

    public function test_surrounding_spaces_are_kept_exactly(): void
    {
        $input = $this->validatedInput(DemonstrationType::Tautology, ['username' => "  ' OR '1'='1  ", 'password' => ' x ']);

        $this->assertSame(['username' => "  ' OR '1'='1  ", 'password' => ' x '], $input);
    }

    public function test_fields_of_other_demonstrations_are_ignored(): void
    {
        $input = $this->validatedInput(DemonstrationType::Tautology, [
            'username' => 'demo.analyst',
            'password' => 'x',
            'category' => "invalid\0control",
            'team' => str_repeat('a', 500),
            'unexpected' => 'ignored',
        ]);

        $this->assertSame(['username' => 'demo.analyst', 'password' => 'x'], $input);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function submissionMatches(): array
    {
        return [
            'tautology submission' => ['POST', '/demonstrations/tautology', true],
            'union submission' => ['POST', '/demonstrations/union', true],
            'comment submission' => ['POST', '/demonstrations/comment', true],
            'demonstration page (GET)' => ['GET', '/demonstrations/union', false],
            'unknown demonstration' => ['POST', '/demonstrations/stacked', false],
            'nested path' => ['POST', '/demonstrations/union/extra', false],
            'demonstration list' => ['POST', '/demonstrations', false],
            'account creation' => ['POST', '/admin/users', false],
            'login' => ['POST', '/login', false],
        ];
    }

    #[DataProvider('submissionMatches')]
    public function test_only_demonstration_submissions_are_exempt_from_trimming(string $method, string $uri, bool $isSubmission): void
    {
        $this->assertSame($isSubmission, RunDemonstrationRequest::isSubmission(Request::create($uri, $method)));
    }

    public function test_surrounding_spaces_are_not_trimmed_before_validation(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)
            ->from(route('demonstrations.show', DemonstrationType::Comment))
            ->post(route('demonstrations.run', DemonstrationType::Comment), ['team' => ' '.str_repeat('a', 200)]);

        $response->assertSessionHasErrors(['team' => 'The team field must not be greater than 200 characters.']);
    }

    public function test_rejected_input_is_not_flashed_to_the_session(): void
    {
        $student = User::factory()->create();
        $payload = "team-alpha\t' -- REJECTED-PAYLOAD-MARKER";

        $response = $this->actingAs($student)
            ->from(route('demonstrations.show', DemonstrationType::Comment))
            ->post(route('demonstrations.run', DemonstrationType::Comment), ['team' => $payload, 'unexpected' => 'OTHER-FIELD-MARKER']);

        $response->assertRedirect(route('demonstrations.show', DemonstrationType::Comment))
            ->assertSessionHasErrors(['team' => 'The team '.self::CONTROL_MESSAGE])
            ->assertSessionMissing('_old_input');
        $session = serialize(session()->all());
        $this->assertStringNotContainsString('REJECTED-PAYLOAD-MARKER', $session);
        $this->assertStringNotContainsString('OTHER-FIELD-MARKER', $session);
    }

    public function test_form_shows_the_error_with_an_empty_field_after_rejection(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)
            ->from(route('demonstrations.show', DemonstrationType::Comment))
            ->followingRedirects()
            ->post(route('demonstrations.run', DemonstrationType::Comment), ['team' => "REJECTED\nVALUE"]);

        $response->assertOk()
            ->assertSee('The team '.self::CONTROL_MESSAGE)
            ->assertSee('value=""', false)
            ->assertDontSee('REJECTED');
    }

    public function test_json_requests_receive_a_422_without_flashing(): void
    {
        $student = User::factory()->create();

        $response = $this->actingAs($student)
            ->postJson(route('demonstrations.run', DemonstrationType::Union), ['category' => "x\0y"]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['category' => 'The category '.self::CONTROL_MESSAGE]);
        $this->assertNull(session('_old_input'));
    }

    #[Group('lab-database')]
    public function test_the_exact_submitted_value_reaches_both_modes(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        $student = User::factory()->create();
        $value = "  team-alpha' --  ";

        $response = $this->actingAs($student)
            ->withSession(['_token' => 'phase4-csrf-token'])
            ->post(route('demonstrations.run', DemonstrationType::Comment), ['team' => $value, '_token' => 'phase4-csrf-token']);

        $escaped = e($value);
        $response->assertOk()
            ->assertSee('<span class="mark px-0">'.$escaped.'</span>', false)
            ->assertSee('<code class="text-break">'.$escaped.'</code>', false);
    }
}
