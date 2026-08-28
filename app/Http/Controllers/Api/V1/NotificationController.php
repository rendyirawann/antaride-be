<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Driver\Models\Driver;
use App\Domain\Support\Models\Notification;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi in-app.
 *
 * ============================================================================
 *  SATU CONTROLLER UNTUK PENUMPANG DAN DRIVER
 * ============================================================================
 *  Bentuk datanya identik, dan aturannya identik: seseorang membaca
 *  notifikasinya sendiri. Yang berbeda hanya `recipient_type`, dan itu
 *  disimpulkan dari parameter `as` — bukan dari dua controller yang isinya sama.
 *
 *  Kenapa `as` diminta secara eksplisit, bukan disimpulkan dari akun: satu orang
 *  bisa jadi penumpang DAN driver dengan akun yang sama, dan itu wajar — driver
 *  memesan ojek saat kendaraannya di bengkel.
 *
 *  Kalau `recipient_type` disimpulkan dari "apakah akun ini punya baris di tabel
 *  drivers", maka setiap driver yang memesan ojek akan melihat notifikasi
 *  drivernya di aplikasi penumpang — dan tidak akan pernah melihat notifikasi
 *  penumpangnya.
 * ============================================================================
 */
class NotificationController extends Controller
{
    /**
     * Daftar notifikasi, terbaru dulu.
     */
    public function index(Request $request): JsonResponse
    {
        [$type, $id] = $this->penerima($request);

        if ($id === null) {
            return $this->bukanPenerima();
        }

        $notifications = Notification::query()
            ->forRecipient($type, $id)
            ->latest('created_at')

            /*
             * Cursor pagination, sama seperti riwayat order.
             *
             * Notifikasi tumbuh terus dan tidak pernah dihapus pengguna, jadi
             * `OFFSET` akan memindai baris yang dibuang. Index
             * `notifications_recipient_idx` melayani cursor ini sepenuhnya.
             */
            ->cursorPaginate(
                perPage: min(50, (int) $request->integer('per_page', 20)),
            );

        return ApiResponse::success(
            collect($notifications->items())->map(
                fn (Notification $n): array => $this->bentuk($n),
            )->all(),
            meta: [
                'per_page' => $notifications->perPage(),
                'next_cursor' => $notifications->nextCursor()?->encode(),
                'has_more' => $notifications->hasMorePages(),

                /*
                 * Jumlah yang belum dibaca ikut di setiap halaman.
                 *
                 * Supaya lencana di ikon lonceng bisa diperbarui dari response
                 * yang SAMA — tanpa request kedua. Aplikasi memuat daftarnya dan
                 * lencananya sekaligus.
                 *
                 * Dilayani index parsial `notifications_unread_idx`, yang hanya
                 * memuat baris yang belum dibaca — jadi ukurannya tetap kecil
                 * walaupun tabelnya tumbuh terus.
                 */
                'unread_count' => $this->jumlahBelumDibaca($type, $id),
            ],
        );
    }

    /**
     * Jumlah yang belum dibaca saja.
     *
     * Endpoint tersendiri karena aplikasi memanggilnya jauh lebih sering
     * daripada daftarnya: lencana di beranda diperbarui setiap kali aplikasi
     * kembali ke depan, sementara daftarnya hanya dibuka kalau lonceng ditekan.
     *
     * Response-nya satu angka, bukan dua puluh baris notifikasi.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        [$type, $id] = $this->penerima($request);

        if ($id === null) {
            return $this->bukanPenerima();
        }

        return ApiResponse::success([
            'unread_count' => $this->jumlahBelumDibaca($type, $id),
        ]);
    }

    /**
     * Tandai satu notifikasi sudah dibaca.
     */
    public function markRead(Request $request, string $uuid): JsonResponse
    {
        [$type, $id] = $this->penerima($request);

        if ($id === null) {
            return $this->bukanPenerima();
        }

        /*
         * Difilter penerima, bukan hanya uuid.
         *
         * Tanpa itu, siapa pun yang punya token bisa menandai notifikasi orang
         * lain sudah dibaca. Kerugiannya kecil — tidak ada data yang bocor —
         * tapi 404 di sini lebih murah daripada mengandalkan uuid yang tidak
         * praktis ditebak sebagai kontrol akses.
         */
        $notification = Notification::query()
            ->forRecipient($type, $id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $notification->markRead();

        return ApiResponse::success([
            'unread_count' => $this->jumlahBelumDibaca($type, $id),
        ]);
    }

    /**
     * Tandai semua sudah dibaca.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        [$type, $id] = $this->penerima($request);

        if ($id === null) {
            return $this->bukanPenerima();
        }

        /*
         * Satu UPDATE, bukan memuat lalu menyimpan satu per satu.
         *
         * Pengguna yang punya dua ratus notifikasi belum dibaca akan
         * menghasilkan dua ratus query kalau lewat Eloquent — dan tombol "tandai
         * semua" justru paling sering ditekan oleh orang yang menumpuk paling
         * banyak.
         *
         * `whereNull` di sini bukan sekadar optimasi: tanpa itu, `read_at` pada
         * notifikasi yang SUDAH dibaca akan ditulis ulang, dan waktu baca
         * pertamanya hilang.
         */
        $jumlah = Notification::query()
            ->forRecipient($type, $id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return ApiResponse::success([
            'marked' => $jumlah,
            'unread_count' => 0,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Tentukan penerima dari parameter `as` dan akun yang login.
     *
     * @return array{0: string, 1: int|null} Null berarti akun ini bukan
     *                                       penerima jenis itu.
     */
    private function penerima(Request $request): array
    {
        $as = (string) $request->query('as', 'user');

        if ($as === 'driver') {
            $driverId = Driver::query()
                ->where('user_id', $request->user()->getKey())
                ->value('id');

            return ['driver', $driverId === null ? null : (int) $driverId];
        }

        // Bawaannya `user`. Nilai `as` yang tidak dikenali diperlakukan sebagai
        // user, bukan ditolak — aplikasi versi baru yang mengirim jenis yang
        // belum dikenal backend lama tetap mendapat notifikasi penumpangnya,
        // bukan galat.
        return ['user', (int) $request->user()->getKey()];
    }

    private function jumlahBelumDibaca(string $type, int $id): int
    {
        return (int) DB::table('notifications')
            ->where('recipient_type', $type)
            ->where('recipient_id', $id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function bentuk(Notification $n): array
    {
        return [
            'uuid' => (string) $n->uuid,
            'type' => (string) $n->type,
            'title' => (string) $n->title,
            'body' => (string) $n->body,

            // `action` dibawa apa adanya. Aplikasi yang menerjemahkannya ke
            // navigasi, jadi struktur layarnya bisa berubah tanpa membuat
            // notifikasi lama menunjuk ke layar yang tidak ada lagi.
            'action' => $n->action,

            'is_read' => $n->isRead(),
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }

    private function bukanPenerima(): JsonResponse
    {
        return ApiResponse::error(
            'NOT_A_DRIVER',
            'Akun Anda bukan akun driver.',
            403,
        );
    }
}
