<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;

final class IssueStatusRequest extends FormRequest
{
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
            'status' => ['required', Rule::in(IssueListPresenter::statuses())],
            'snooze_minutes' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'project_id' => ['nullable', 'integer'],
        ];
    }
}
