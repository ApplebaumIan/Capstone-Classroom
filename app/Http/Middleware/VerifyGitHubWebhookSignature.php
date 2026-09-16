<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyGitHubWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.github.webhook_secret');
        abort_if($secret === '', 503, 'GitHub webhook secret is not configured.');

        $expectedSignature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        $providedSignature = (string) $request->header('X-Hub-Signature-256');

        abort_unless(hash_equals($expectedSignature, $providedSignature), 401);

        return $next($request);
    }
}
