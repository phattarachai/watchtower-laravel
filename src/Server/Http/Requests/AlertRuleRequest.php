<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Phattarachai\WatchtowerCore\Alerts\AlertType;
use Phattarachai\WatchtowerLaravel\Server\Ui\AlertRulePresenter;

final class AlertRuleRequest extends FormRequest
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
            'project_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'exists:'.$this->projectsTable().',id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AlertType::class)],
            'environment' => ['nullable', 'string', 'max:255'],
            'min_level' => ['required', Rule::in(AlertRulePresenter::LEVELS)],
            'threshold_count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'threshold_window_seconds' => ['nullable', 'integer', 'min:60', 'max:604800'],
            'cooldown_seconds' => ['required', 'integer', 'min:0', 'max:604800'],
            'emails' => ['required', 'array', 'min:1'],
            'emails.*' => ['email'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{name: string, type: string, environment: string|null, min_level: string, threshold_count: int|null, threshold_window_seconds: int|null, cooldown_seconds: int, targets: array{emails: list<string>}, is_active: bool}
     */
    public function attributesForRule(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'type' => (string) $this->validated('type'),
            'environment' => $this->nullableString('environment'),
            'min_level' => (string) $this->validated('min_level'),
            'threshold_count' => $this->nullableInt('threshold_count'),
            'threshold_window_seconds' => $this->nullableInt('threshold_window_seconds'),
            'cooldown_seconds' => (int) $this->validated('cooldown_seconds'),
            'targets' => ['emails' => $this->emails()],
            'is_active' => $this->boolean('is_active'),
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->validated($key);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return list<string>
     */
    private function emails(): array
    {
        $emails = $this->validated('emails');

        return array_values(array_map(
            fn (mixed $email): string => is_scalar($email) ? (string) $email : '',
            is_array($emails) ? $emails : [],
        ));
    }

    private function projectsTable(): string
    {
        $connection = config('watchtower.server.connection');

        return ($connection === null ? '' : $connection.'.').'watchtower_projects';
    }
}
