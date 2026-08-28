<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jejak audit tingkat request untuk panel admin.
 *
 * Ini melengkapi, bukan menggantikan, pencatatan tingkat model yang menyimpan
 * nilai sebelum dan sesudah. Yang dicatat di sini adalah "siapa memanggil apa,
 * dari mana, kapan, dan berhasil atau tidak" — pertanyaan yang muncul saat ada
 * insiden dan kamu belum tahu record mana yang tersentuh.
 *
 * Hanya request yang mengubah keadaan yang dicatat. Mencatat setiap GET akan
 * membanjiri tabel dengan pembukaan halaman dashboard dan justru menenggelamkan
 * baris yang penting. Pengecualiannya: pembukaan data KYC penuh, yang dicatat
 * terpisah di lapisan cast (lihat App\Domain\Shared\Casts\MaskedNik).
 */
class LogAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldLog($request)) {
            $this->record($request, $response);
        }

        return $response;
    }

    private function shouldLog(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return false;
        }

        return Auth::guard('admin')->check();
    }

    private function record(Request $request, Response $response): void
    {
        try {
            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid7(),
                'admin_id' => Auth::guard('admin')->id(),
                'action' => $request->route()?->getName() ?? $request->method().' '.$request->path(),
                'auditable_type' => null,
                'auditable_id' => null,
                'old_values' => null,
                'new_values' => json_encode($this->safePayload($request), JSON_UNESCAPED_UNICODE),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'impersonated_by_admin_id' => null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Kegagalan menulis audit tidak boleh menjatuhkan request yang
            // sudah berhasil. Yang hilang satu baris log; yang dipertahankan
            // adalah tindakan admin yang sudah ter-commit.
        }
    }

    /**
     * Payload dicatat, tapi tidak mentah. Password, token, dan nomor rekening
     * lengkap tidak boleh mendarat di tabel audit yang bisa dibaca role
     * auditor.
     *
     * @return array<string, mixed>
     */
    private function safePayload(Request $request): array
    {
        $redacted = [
            'password',
            'password_confirmation',
            'current_password',
            'two_factor_code',
            'code',
            'token',
            '_token',
            'nik',
            'bank_account_number',
        ];

        $payload = $request->except($redacted);

        foreach ($redacted as $field) {
            if ($request->has($field)) {
                $payload[$field] = '[disunting]';
            }
        }

        return $payload;
    }
}
