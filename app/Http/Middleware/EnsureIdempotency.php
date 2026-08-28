<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency untuk setiap endpoint yang membuat uang bergerak: buat order,
 * top up, tarik saldo (blueprint bagian 5).
 *
 * Tanpa ini, driver atau penumpang dengan sinyal jelek yang menekan tombol dua
 * kali akan membuat dua order, dua top up, atau dua penarikan. Itu bukan kasus
 * langka; di lapangan itu kejadian harian.
 *
 * Cara kerjanya:
 *
 *   1. Client menghasilkan UUID dan mengirimnya di header Idempotency-Key.
 *   2. Backend mencoba INSERT baris kunci itu dengan ON CONFLICT DO NOTHING.
 *      INSERT yang berhasil berarti kita pemegang eksekusi.
 *   3. INSERT yang kalah berarti ada request lain dengan kunci sama. Kalau
 *      request pertama sudah selesai, response-nya dikembalikan apa adanya.
 *      Kalau masih berjalan, balas 409 supaya client menunggu, bukan mengulang.
 *      Kalau klaimnya sudah mati (lihat TAKEOVER di bawah), klaim diambil alih.
 *   4. Setelah selesai, response disimpan dan dikembalikan sama untuk setiap
 *      pemanggilan berikutnya dengan kunci yang sama.
 *
 * ============================================================================
 *  KUNCI SELALU MILIK SESEORANG
 * ============================================================================
 *  Kunci tidak pernah global. Dia hanya berarti di dalam ruang pemiliknya, dan
 *  pemiliknya ikut masuk ke request_hash. Tanpa itu, dua pengguna yang secara
 *  kebetulan (atau sengaja) memakai kunci yang sama untuk permintaan yang
 *  bentuknya sama akan saling menerima response satu sama lain. Penjelasan
 *  lengkapnya ada di migration `idempotency_keys`.
 *
 *  Karena itu middleware ini menuntut request terautentikasi. Endpoint yang
 *  memindahkan uang memang selalu begitu; menolak di sini membuat asumsinya
 *  eksplisit, bukan bergantung pada urutan middleware yang bisa berubah.
 * ============================================================================
 *
 * ============================================================================
 *  TAKEOVER: KLAIM YANG MATI HARUS BISA DIAMBIL ALIH
 * ============================================================================
 *  Blok `catch` di bawah melepas kunci saat eksekusi melempar exception. Yang
 *  TIDAK dia tangkap adalah cara proses PHP berhenti tanpa exception: worker
 *  Octane di-restart, `max_execution_time` habis, proses kena OOM, atau server
 *  mati. Dalam semua kasus itu, baris klaimnya tertinggal dengan response_body
 *  NULL dan tidak ada yang pernah membereskannya.
 *
 *  Dulu artinya kunci itu terkunci 409 sampai kadaluarsa 24 jam. Sekarang klaim
 *  yang locked_at-nya lebih tua dari `idempotency.lock_ttl_seconds` dianggap
 *  mati dan boleh diambil alih — lewat satu UPDATE bersyarat, sehingga kalau
 *  ada dua request yang sama-sama mencoba, tepat satu yang menang.
 * ============================================================================
 *
 * request_hash ikut diperiksa. Kunci yang sama dengan payload berbeda adalah
 * bug di sisi client, dan membiarkannya lolos berarti mengembalikan response
 * order A untuk permintaan order B.
 */
class EnsureIdempotency
{
    private const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (blank($key)) {
            return $this->error(
                'IDEMPOTENCY_KEY_REQUIRED',
                'Header Idempotency-Key wajib dikirim untuk permintaan ini.',
                400,
            );
        }

        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', (string) $key)) {
            return $this->error(
                'IDEMPOTENCY_KEY_INVALID',
                'Header Idempotency-Key harus 16 sampai 64 karakter alfanumerik.',
                400,
            );
        }

        $owner = $this->ownerKey($request);

        if ($owner === null) {
            return $this->error(
                'IDEMPOTENCY_REQUIRES_AUTH',
                'Permintaan ini harus terautentikasi.',
                401,
            );
        }

        $requestHash = $this->requestHash($owner, $request);
        $now = now();

        // Klaim kunci. Yang menang INSERT adalah yang mengeksekusi.
        $claimed = DB::table('idempotency_keys')->insertOrIgnore([
            'owner_key' => $owner,
            'key' => $key,
            'endpoint' => substr($request->method().' '.$request->path(), 0, 120),
            'request_hash' => $requestHash,
            'locked_at' => $now,
            'created_at' => $now,
            'expires_at' => $now->copy()->addHours($this->retentionHours()),
        ]) === 1;

        if (! $claimed) {
            $decided = $this->replayConflictOrTakeover($owner, (string) $key, $requestHash, $now);

            // Bukan null berarti sudah ada jawaban: response yang diputar ulang,
            // atau error. Null berarti klaim mati berhasil diambil alih dan
            // eksekusi boleh lanjut seperti pemegang klaim pertama.
            if ($decided !== null) {
                return $decided;
            }
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Eksekusi gagal, jadi kunci dilepas. Kalau tidak, client tidak
            // akan pernah bisa mencoba lagi dengan kunci yang sama walaupun
            // tidak ada apa pun yang tersimpan.
            $this->rowFor($owner, (string) $key)->delete();

            throw $e;
        }

        // Hanya response sukses yang dikunci. Kegagalan validasi harus boleh
        // diperbaiki dan dikirim ulang dengan kunci yang sama.
        if ($response->getStatusCode() < 400) {
            $this->rowFor($owner, (string) $key)->update([
                'response_body' => $response->getContent(),
                'status_code' => $response->getStatusCode(),
                'locked_at' => null,
            ]);
        } else {
            $this->rowFor($owner, (string) $key)->delete();
        }

        return $response;
    }

    // -------------------------------------------------------------------------

    /**
     * Siapa pemilik kunci ini: "user:123" atau "admin:7".
     *
     * Guard disebut eksplisit, bukan hanya id-nya. Tanpa itu, user id 7 dan
     * admin id 7 akan berbagi ruang kunci yang sama.
     */
    private function ownerKey(Request $request): ?string
    {
        foreach (['sanctum', 'web', 'admin'] as $guard) {
            $id = auth($guard)->id();

            if ($id !== null) {
                $prefix = $guard === 'admin' ? 'admin' : 'user';

                return "{$prefix}:{$id}";
            }
        }

        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : "user:{$id}";
    }

    private function requestHash(string $owner, Request $request): string
    {
        return hash('sha256', implode('|', [
            // Pemilik ikut masuk. Ini yang membuat kunci milik pengguna A tidak
            // pernah bisa cocok dengan permintaan pengguna B, bahkan kalau
            // isinya identik.
            $owner,
            $request->method(),
            $request->path(),
            json_encode($request->except(['_token']), JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * @return Builder
     */
    private function rowFor(string $owner, string $key)
    {
        return DB::table('idempotency_keys')
            ->where('owner_key', $owner)
            ->where('key', $key);
    }

    /**
     * @return Response|null null berarti klaim mati berhasil diambil alih
     */
    private function replayConflictOrTakeover(
        string $owner,
        string $key,
        string $requestHash,
        Carbon $now,
    ): ?Response {
        $existing = $this->rowFor($owner, $key)->first();

        if ($existing === null) {
            // Balapan yang sangat sempit: baris terhapus antara INSERT gagal
            // dan SELECT ini. Client aman mencoba lagi.
            return $this->error(
                'IDEMPOTENCY_RETRY',
                'Permintaan sedang diproses. Coba lagi sesaat.',
                409,
            );
        }

        if ($existing->request_hash !== $requestHash) {
            return $this->error(
                'IDEMPOTENCY_KEY_REUSED',
                'Idempotency-Key ini sudah dipakai untuk permintaan dengan isi berbeda.',
                422,
            );
        }

        if ($existing->response_body !== null) {
            return response(
                $existing->response_body,
                (int) ($existing->status_code ?? 200),
            )->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true');
        }

        // Klaim masih menggantung. Apakah masih hidup, atau sudah mati?
        $deadline = $now->copy()->subSeconds($this->lockTtlSeconds());
        $lockedAt = $existing->locked_at === null ? null : Carbon::parse($existing->locked_at);

        if ($lockedAt !== null && $lockedAt->greaterThan($deadline)) {
            return $this->error(
                'IDEMPOTENCY_IN_PROGRESS',
                'Permintaan dengan kunci yang sama sedang diproses.',
                409,
            );
        }

        /*
         * Sudah mati. Ambil alih secara atomik.
         *
         * Syarat di WHERE yang membuat ini aman saat dua request bersamaan
         * sampai ke sini: yang pertama mengubah locked_at menjadi sekarang,
         * sehingga WHERE milik yang kedua tidak lagi cocok dan UPDATE-nya
         * mengenai nol baris. Tepat satu yang mengeksekusi.
         */
        $taken = $this->rowFor($owner, $key)
            ->whereNull('response_body')
            ->where(function ($query) use ($deadline): void {
                $query->whereNull('locked_at')
                    ->orWhere('locked_at', '<=', $deadline);
            })
            ->update(['locked_at' => $now]);

        if ($taken === 1) {
            return null;
        }

        // Kalah balapan takeover. Yang menang sedang mengeksekusi.
        return $this->error(
            'IDEMPOTENCY_IN_PROGRESS',
            'Permintaan dengan kunci yang sama sedang diproses.',
            409,
        );
    }

    private function lockTtlSeconds(): int
    {
        return (int) config('antaride.idempotency.lock_ttl_seconds', 60);
    }

    private function retentionHours(): int
    {
        return (int) config('antaride.idempotency.retention_hours', 24);
    }

    private function error(string $code, string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => []],
        ], $status);
    }
}
