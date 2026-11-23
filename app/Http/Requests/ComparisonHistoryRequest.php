<?php

namespace App\Http\Requests;

use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * GET filters for the history lists (`/history` and `/admin/history`).
 *
 * Only the supported filters are read, from validated data. The `user`
 * search exists on the administrator list only and is prohibited on the
 * personal list, so it can never widen a user's own history. Invalid filters
 * receive a 422 page instead of a redirect, and nothing is flashed.
 */
class ComparisonHistoryRequest extends FormRequest
{
    public const array ALTERED = ['yes', 'no', 'not_compared'];

    public const array VULNERABLE_STATUSES = [ModeResult::EXECUTED, ModeResult::FAILED, ModeResult::REFUSED];

    public const array SECURE_STATUSES = [ModeResult::EXECUTED, ModeResult::FAILED];

    /**
     * Determine if the user is authorized to make this request.
     *
     * Access is enforced by the route middleware and ComparisonRecordPolicy in the controllers.
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
        return [
            'type' => ['nullable', Rule::enum(DemonstrationType::class)],
            'category' => ['nullable', Rule::enum(PayloadCategory::class)],
            'vulnerable_status' => ['nullable', Rule::in(self::VULNERABLE_STATUSES)],
            'secure_status' => ['nullable', Rule::in(self::SECURE_STATUSES)],
            'altered' => ['nullable', Rule::in(self::ALTERED)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'user' => $this->allowsUserSearch() ? ['nullable', 'string', 'max:100'] : ['prohibited'],
        ];
    }

    /**
     * Whether this list may search by user (the administrator list only).
     */
    public function allowsUserSearch(): bool
    {
        return $this->routeIs('admin.history.index');
    }

    /**
     * The validated, non-empty filters.
     *
     * @return array{type?: string, category?: string, vulnerable_status?: string, secure_status?: string, altered?: string, from?: string, to?: string, user?: string}
     */
    public function filters(): array
    {
        return array_filter($this->validated(), fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    /**
     * Respond with 422 and the filter errors instead of redirecting back.
     * The rejected values are not flashed to the session.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new ValidationException($validator, response()->view('history.invalid-filters', [
            'messages' => $validator->errors()->all(),
            'clearUrl' => $this->url(),
        ], 422));
    }
}
