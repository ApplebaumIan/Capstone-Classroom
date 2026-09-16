<?php

namespace App\Http\Controllers;

use App\Actions\ProcessGitHubWebhook;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, ProcessGitHubWebhook $processGitHubWebhook): Response
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(400, 'Invalid JSON payload.');
        }

        abort_unless(is_array($payload), 400, 'Invalid JSON payload.');

        $processGitHubWebhook->handle(
            (string) $request->header('X-GitHub-Event'),
            (string) $request->header('X-GitHub-Delivery'),
            $payload,
        );

        return response()->noContent();
    }
}
