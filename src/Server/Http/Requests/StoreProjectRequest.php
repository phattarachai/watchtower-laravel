<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Phattarachai\WatchtowerLaravel\Server\Ui\ProjectPresenter;

final class StoreProjectRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(ProjectPresenter::PLATFORMS)],
        ];
    }
}
