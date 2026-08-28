<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\AdminIpAllowlist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role finance dan superadmin hanya boleh login dari IP kantor atau lewat VPN
 * (blueprint admin bagian 3).
 *
 * Ini merepotkan, dan itu memang tujuannya. Yang dilindungi di sini adalah
 * kemampuan menyetujui penarikan uang, dan kredensial yang bocor lewat phishing
 * tidak akan bisa dipakai dari luar jaringan yang dikenal.
 */
class EnsureAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('antaride.security.ip_allowlist_enabled')) {
            return $next($request);
        }

        $admin = Auth::guard('admin')->user();

        if ($admin === null) {
            return $next($request);
        }

        $guardedRoles = (array) config('antaride.security.ip_allowlist_roles', []);

        if (! $admin->hasAnyRole($guardedRoles)) {
            return $next($request);
        }

        if ($this->isAllowed($admin->id, (string) $request->ip())) {
            return $next($request);
        }

        // Sengaja tidak menyebutkan IP mana yang diizinkan. Pesan error bukan
        // tempat membocorkan topologi jaringan.
        abort(403, 'Akses dari jaringan ini tidak diizinkan untuk akun Anda. Hubungi administrator sistem.');
    }

    private function isAllowed(int $adminId, string $ip): bool
    {
        /** @var array<int, string> $entries */
        $entries = Cache::remember(
            "admin:ip_allowlist:{$adminId}",
            now()->addMinutes(5),
            fn () => AdminIpAllowlist::query()
                ->where('admin_id', $adminId)
                ->where('is_active', true)
                ->pluck('cidr')
                ->all(),
        );

        foreach ($entries as $cidr) {
            if ($this->ipMatchesCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mendukung IP tunggal maupun notasi CIDR (mis. 103.10.20.0/24), supaya
     * satu baris cukup untuk seluruh kantor.
     */
    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
