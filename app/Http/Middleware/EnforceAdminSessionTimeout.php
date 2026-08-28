<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dua timeout, bukan satu (blueprint admin bagian 3):
 *
 *   idle      2 jam tanpa aktivitas
 *   absolute  12 jam sejak login, seaktif apa pun
 *
 * Yang absolute itu yang sering dilupakan. Tanpa dia, tab admin yang dibiarkan
 * terbuka dengan polling dashboard akan memperpanjang sesinya sendiri tanpa
 * batas, dan "sesi 2 jam" jadi tidak berarti apa-apa.
 */
class EnforceAdminSessionTimeout
{
    private const KEY_LAST_ACTIVITY = 'admin.last_activity_at';

    private const KEY_LOGIN_AT = 'admin.login_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            return $next($request);
        }

        $now = now()->getTimestamp();
        $idleLimit = (int) config('antaride.security.session_idle_minutes') * 60;
        $absoluteLimit = (int) config('antaride.security.session_absolute_minutes') * 60;

        $lastActivity = $request->session()->get(self::KEY_LAST_ACTIVITY);
        $loginAt = $request->session()->get(self::KEY_LOGIN_AT, $now);

        $expiredByIdle = $lastActivity !== null && ($now - (int) $lastActivity) > $idleLimit;
        $expiredByAbsolute = ($now - (int) $loginAt) > $absoluteLimit;

        if ($expiredByIdle || $expiredByAbsolute) {
            return $this->expire($request, $expiredByAbsolute ? 'absolute' : 'idle');
        }

        $request->session()->put(self::KEY_LAST_ACTIVITY, $now);
        $request->session()->put(self::KEY_LOGIN_AT, $loginAt);

        return $next($request);
    }

    private function expire(Request $request, string $reason): Response
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = $reason === 'absolute'
            ? 'Sesi berakhir karena sudah melewati batas 12 jam. Silakan masuk kembali.'
            : 'Sesi berakhir karena tidak ada aktivitas selama 2 jam. Silakan masuk kembali.';

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'SESSION_EXPIRED', 'message' => $message],
            ], 401);
        }

        return redirect()
            ->route('admin.login')
            ->with('warning', $message);
    }

    /**
     * Dipanggil dari controller login supaya jam absolute dihitung dari login
     * yang sebenarnya, bukan dari request pertama setelahnya.
     */
    public static function markLogin(Request $request): void
    {
        $now = now()->getTimestamp();
        $request->session()->put(self::KEY_LOGIN_AT, $now);
        $request->session()->put(self::KEY_LAST_ACTIVITY, $now);
    }
}
