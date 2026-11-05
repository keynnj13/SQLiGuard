<?php

namespace App\Http\Requests;

use App\Enums\DemonstrationType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the single input set that is sent to both modes.
 *
 * Only the selected demonstration's own fields are accepted. Phase 3 checks
 * shape only (type, length, no control characters); payload classification
 * is Phase 4. Attack strings are deliberately allowed: they are the point.
 */
class RunDemonstrationRequest extends FormRequest
{
    public const int MAX_LENGTH = 200;

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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->demonstrationType()->demonstration()->fields() as $name => $field) {
            $rules[$name] = [
                $field['required'] ? 'required' : 'nullable',
                'string',
                'max:'.self::MAX_LENGTH,
                'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
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
     * The validated input for every field of the demonstration, as strings.
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

    private function demonstrationType(): DemonstrationType
    {
        return $this->route('demonstration');
    }
}
