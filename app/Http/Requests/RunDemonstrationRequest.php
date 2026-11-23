<?php

namespace App\Http\Requests;

use App\Enums\DemonstrationType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Validates the single input set that is sent, unchanged, to both modes.
 *
 * Only the selected demonstration's own fields are read; any other submitted
 * field is ignored. Validation checks shape only (single-line valid UTF-8
 * text of at most 200 characters). SQL injection syntax is deliberately
 * allowed: it is the point of the demonstrations.
 *
 * The input is never altered: surrounding spaces are preserved because the
 * TrimStrings middleware skips these submissions (see isSubmission()), and
 * there is no prepareForValidation(). Rejected input is not flashed to the
 * session (see failedValidation()).
 */
class RunDemonstrationRequest extends FormRequest
{
    public const int MAX_LENGTH = 200;

    /**
     * Whether the request is a demonstration submission
     * (POST /demonstrations/{tautology|union|comment}).
     *
     * Used by the TrimStrings exception in bootstrap/app.php, which runs before
     * routing, so it matches the exact method and path instead of a route name.
     */
    public static function isSubmission(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        $paths = array_map(fn (DemonstrationType $type): string => 'demonstrations/'.$type->value, DemonstrationType::cases());

        return in_array($request->path(), $paths, true);
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * Access is enforced by the `auth` and `role:administrator,student` route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * `bail` stops at the first failure. UTF-8 is checked before the
     * control-character pattern on purpose: a `/u` pattern does not match
     * invalid UTF-8 at all, so it must never be the first line of defense.
     * `\p{Cc}` covers U+0000–U+001F (including NUL, tab, line feed and
     * carriage return), DEL and the C1 controls U+0080–U+009F.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->demonstrationType()->demonstration()->fields() as $name => $field) {
            $rules[$name] = [
                'bail',
                $field['required'] ? 'required' : 'nullable',
                'string',
                $this->validUtf8(),
                'not_regex:/\p{Cc}/u',
                'max:'.self::MAX_LENGTH,
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'not_regex' => 'The :attribute must not contain control characters.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_map(
            fn (array $field): string => strtolower($field['label']),
            $this->demonstrationType()->demonstration()->fields(),
        );
    }

    /**
     * The validated input for every field of the demonstration, exactly as
     * submitted. A missing or empty optional field becomes ''.
     *
     * @return array<string, string>
     */
    public function demonstrationInput(): array
    {
        $input = [];

        foreach (array_keys($this->demonstrationType()->demonstration()->fields()) as $name) {
            $input[$name] = (string) ($this->validated($name) ?? '');
        }

        return $input;
    }

    /**
     * Redirect back with the validation errors but WITHOUT flashing the
     * submitted fields as old input.
     *
     * Laravel normally stores rejected input in the session (which is kept in
     * the database) so the form can be refilled. Demonstration payloads must
     * not be persisted, so the form is shown empty after an error instead.
     * JSON requests keep Laravel's normal 422 response, which never flashes.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new ValidationException(
            $validator,
            redirect()->to($this->getRedirectUrl())->withErrors($validator->errors(), $this->errorBag),
        );
    }

    private function validUtf8(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $fail('The :attribute must be valid UTF-8 text.');
            }
        };
    }

    private function demonstrationType(): DemonstrationType
    {
        return $this->route('demonstration');
    }
}
