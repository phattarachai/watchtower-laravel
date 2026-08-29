<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Phattarachai\WatchtowerLaravel\Server\Mcp\Tools\Concerns\ScopesToProject;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;

/**
 * Resolve / ignore / unresolve differ only in the status they write, so the
 * whole tool body lives here and each subclass names its target status.
 */
abstract class IssueStatusTool extends Tool
{
    use ScopesToProject;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issue_id' => $schema->integer()
                ->required()
                ->description('Numeric issue group id (from list_issues).'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $args = $request->validate(['issue_id' => 'required|integer|min:1']);

        $issue = $this->findIssueInProject((int) $args['issue_id']);

        if ($issue === null) {
            return Response::error('Issue not found in this project.');
        }

        return Response::structured([
            'issue' => IssueListPresenter::row($this->changeStatus($issue, $this->targetStatus())),
        ]);
    }

    abstract protected function targetStatus(): string;
}
