<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;

final class BulkIssueRequest extends FormRequest
{
    public const int MAX_IDS = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer', 'min:1'],
            'status' => [Rule::requiredIf($this->isMethod('PATCH')), Rule::in(IssueListPresenter::statuses())],
            'snooze_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
        ];
    }

    /**
     * @return list<int>
     */
    public function ids(): array
    {
        return array_values(array_unique(array_map(intval(...), (array) $this->validated('ids'))));
    }

    public function snoozeMinutes(): ?int
    {
        $minutes = $this->validated('snooze_minutes');

        return $minutes === null ? null : (int) $minutes;
    }
}
