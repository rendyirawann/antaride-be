<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bahasa response API ditentukan oleh header Accept-Language dari app, dengan
 * fallback ke locale aplikasi.
 *
 * Ini dipisah sebagai middleware karena pesan error API dibaca langsung oleh
 * pengguna. Yang TIDAK boleh diterjemahkan adalah error.code, karena itu
 * dipakai app untuk bereaksi berbeda per kasus.
 */
class ResolveApiLocale
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->header('Accept-Language');

        if ($requested !== null) {
            // Ambil tag bahasa pertama saja, buang bobot q= dan region.
            $primary = strtolower(substr(trim(explode(',', $requested)[0]), 0, 2));

            if (in_array($primary, self::SUPPORTED, true)) {
                app()->setLocale($primary);
            }
        }

        return $next($request);
    }
}
