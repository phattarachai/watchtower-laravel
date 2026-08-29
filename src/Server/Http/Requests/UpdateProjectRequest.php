<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProjectRequest extends FormRequest
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
            'is_active' => ['required', 'boolean'],
        ];
    }
}
