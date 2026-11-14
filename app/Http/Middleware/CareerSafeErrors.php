<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Do not send SQL bindings, passwords, recovery codes or private documents
 * into generic exception logs. Operational signals use type and route pattern.
 */
class CareerSafeErrors
{
    public function handle(Request $r, Closure $next)
    {
        try {
            return $next($r);
        } catch (ValidationException|HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            logger()->error('Career workflow request failed.', ['error_type' => get_class($e), 'route_pattern' => $r->route()?->uri()]);
            if ($r->expectsJson() || str_starts_with($r->path(), 'api/')) {
                return response()->json([
                    'success' => false, 'code' => 'CAREER_SERVICE_UNAVAILABLE', 'message' => 'Career service temporarily unavailable. Please retry.'], 503)
                    ->header('Cache-Control', 'private, no-store');
            }

            return response('Career administration temporarily unavailable. Please retry or contact support.', 503);
        }
    }
}
