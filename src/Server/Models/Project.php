<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $platform
 * @property string $public_key
 * @property int|null $retention_days
 * @property bool $is_active
 */
class Project extends WatchtowerModel
{
    protected $table = 'watchtower_projects';

    public static function newPublicKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build the DSN a Sentry SDK should be pointed at.
     *
     * The SDK appends `/api/<project id>` to whatever path the DSN carries, so
     * the URL prefix goes in front of the project id with no `api` segment of
     * its own — `https://key@host/watchtower/42` resolves to
     * `https://host/watchtower/api/42/envelope/`.
     */
    public function buildDsn(string $appUrl): string
    {
        $parts = parse_url($appUrl);
        $scheme = is_array($parts) && isset($parts['scheme']) ? (string) $parts['scheme'] : 'https';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'localhost';
        $port = is_array($parts) && isset($parts['port']) ? ':'.$parts['port'] : '';
        $prefix = trim((string) config('watchtower.server.path', 'watchtower'), '/');
        $path = $prefix === '' ? '' : '/'.$prefix;

        return "{$scheme}://{$this->public_key}@{$host}{$port}{$path}/{$this->getKey()}";
    }

    public function issueGroups(): HasMany
    {
        return $this->hasMany(IssueGroup::class, 'project_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'project_id');
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class, 'project_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'retention_days' => 'integer',
        ];
    }
}
