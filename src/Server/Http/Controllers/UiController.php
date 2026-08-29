<?php

declare(strict_types=1);

namespace Phattarachai\WatchtowerLaravel\Server\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Response;
use Phattarachai\WatchtowerLaravel\Server\Models\IssueGroup;
use Phattarachai\WatchtowerLaravel\Server\Ui\AlertRulePresenter;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueDetailPresenter;
use Phattarachai\WatchtowerLaravel\Server\Ui\IssueListPresenter;
use Phattarachai\WatchtowerLaravel\Server\Ui\ProjectPresenter;
use Phattarachai\WatchtowerLaravel\Server\Ui\UiPage;

final class UiController extends Controller
{
    public function issues(Request $request): Response
    {
        return UiPage::render('issues', IssueListPresenter::props($request));
    }

    public function issue(Request $request, IssueGroup $group): Response
    {
        return UiPage::render('issue', IssueDetailPresenter::props($request, $group));
    }

    public function alerts(): Response
    {
        return UiPage::render('alerts', AlertRulePresenter::props());
    }

    public function settings(): Response
    {
        return UiPage::render('settings', ProjectPresenter::props());
    }
}
