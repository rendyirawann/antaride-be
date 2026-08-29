<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Identity\Actions\DemoLogin;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Akun demo: daftar dan masuk tanpa OTP.
 *
 * ============================================================================
 *  KENAPA ENDPOINT INI ADA
 * ============================================================================
 *  OTP di proyek ini TIDAK dikirim ke mana pun — satu-satunya pengirim yang
 *  terpasang `LogSmsSender`, yang menulis kodenya ke berkas log. Dan di
 *  produksi kode itu pun disembunyikan.
 *
 *  Akibatnya di server yang sudah ter-deploy: tidak ada seorang pun yang bisa
 *  masuk. Bukan sulit — tidak bisa.
 *
 *  Endpoint ini yang membuat aplikasinya bisa diuji sebelum gateway SMS
 *  dipasang. Dia MATI secara bawaan; lihat `DemoLogin` untuk tiga lapis
 *  penjagaannya.
 * ============================================================================
 */
class DemoController extends Controller
{
    /**
     * Daftar akun demo untuk satu aplikasi.
     *
     * ========================================================================
     *  DAFTAR KOSONG SAAT FITURNYA MATI, BUKAN GALAT
     * ========================================================================
     *  Aplikasi memanggil endpoint ini di layar masuk, sebelum pengguna
     *  melakukan apa pun. Kalau fiturnya mati dan endpoint-nya menjawab galat,
     *  yang muncul adalah pesan merah di layar pertama yang dilihat pengguna —
     *  untuk sesuatu yang bukan masalahnya.
     *
     *  Dengan daftar kosong, aplikasi cukup menyembunyikan bagian akun demo dan
     *  layar masuknya tampil normal.
     * ========================================================================
     */
    public function index(Request $request, DemoLogin $action): JsonResponse
    {
        $role = (string) $request->query('role', 'customer');

        if (! in_array($role, ['customer', 'driver', 'merchant'], true)) {
            return ApiResponse::success(['accounts' => [], 'enabled' => false]);
        }

        if (! $action->aktif()) {
            return ApiResponse::success(['accounts' => [], 'enabled' => false]);
        }

        $akun = $action->daftar($role)->map(
            fn (User $u): array => [
                'uuid' => (string) $u->uuid,
                'name' => (string) $u->name,

                /*
                 * Nomor HP ditampilkan APA ADANYA, tidak disamarkan.
                 *
                 * Di seluruh API lain nomor selalu disamarkan — tapi ini akun
                 * demo yang memang untuk dibagikan, dan penguji perlu nomornya
                 * untuk mencoba jalur OTP biasa juga.
                 */
                'phone' => (string) $u->phone,

                'note' => $u->demo_note,
                'role' => (string) $u->demo_role,
            ],
        )->all();

        return ApiResponse::success([
            'accounts' => $akun,
            'enabled' => true,
        ]);
    }

    /**
     * Masuk sebagai akun demo.
     */
    public function login(Request $request, DemoLogin $action): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['required', 'string', 'uuid'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'platform' => [
                'sometimes',
                'nullable',
                Rule::in(['android', 'ios', 'web']),
            ],
        ]);

        $sesi = $action->handle(
            uuid: (string) $data['uuid'],
            deviceId: $data['device_id'] ?? null,
            ip: $request->ip(),
        );

        return ApiResponse::success([
            'token' => $sesi->token,
            'token_type' => 'Bearer',
            'is_new_user' => $sesi->isNewUser,
            'user' => new UserResource($sesi->user),
        ]);
    }
}
