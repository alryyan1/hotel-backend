<?php

namespace App\Http\Middleware;

use App\Models\Shift;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financial operations require an open shift so they can be attributed to it.
 * Admins are exempt (their records are attached to the shift if one is open).
 */
class EnsureOpenShift
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && !$user->is_admin && !Shift::current()) {
            return response()->json([
                'message' => 'يجب فتح وردية أولاً',
                'code' => 'shift_required',
            ], 423);
        }

        return $next($request);
    }
}
