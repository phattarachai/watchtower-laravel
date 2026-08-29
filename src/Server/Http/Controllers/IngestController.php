<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Phattarachai\WatchtowerCore\Sentry\AuthHeader;
use Phattarachai\WatchtowerLaravel\Server\EnvelopeAccepter;
use Phattarachai\WatchtowerLaravel\Server\Models\Project;

class IngestController
{
    public function __construct(private readonly EnvelopeAccepter $accepter) {}

    public function __invoke(Request $request): JsonResponse
    {
        $project = $request->attributes->get('watchtower_project');

        if (! $project instanceof Project) {
            return new JsonResponse(['error' => 'project_missing'], 500);
        }

        $envelope = $this->accepter->parse($this->accepter->body($request));

        return new JsonResponse([
            'id' => $this->accepter->ingest($project, $envelope, $this->sdkName($request, $envelope['header'])),
        ]);
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function sdkName(Request $request, array $header): ?string
    {
        $auth = $request->attributes->get('watchtower_sentry_auth');

        if ($auth instanceof AuthHeader && $auth->client !== null) {
            // sentry_client looks like "sentry.php.laravel/4.0.0" — keep the name.
            return explode('/', $auth->client)[0];
        }

        return $this->accepter->sdkNameFromEnvelope($header);
    }
}
