<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Driver\Models\Driver;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\StateMachine\OrderStateMachine;
use App\Domain\Ordering\StateMachine\OrderTransition;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Jobs\MatchDriverJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Order di panel admin.
 *
 * ============================================================================
 *  INTERVENSI SELALU LEWAT ACTION YANG SAMA DENGAN ALUR NORMAL
 * ============================================================================
 *  Saat admin membatalkan order atau memaksa assign driver, controller ini
 *  memanggil `CancelOrder` dan `OrderStateMachine` — Action yang SAMA dengan
 *  yang dipakai aplikasi mobile.
 *
 *  Alternatifnya, dan yang paling sering terjadi di panel yang dibangun cepat,
 *  adalah menulis UPDATE query sendiri di sini. Konsekuensinya: pembatalan lewat
 *  panel tidak melepas dana yang ditahan, tidak mencatat ke order_status_logs,
 *  dan tidak mengirim pemberitahuan realtime — karena ketiganya ada di dalam
 *  Action.
 *
 *  Yang terlihat di lapangan: order yang dibatalkan CS tetap muncul di aplikasi
 *  penumpang sebagai berjalan, dan saldonya tidak kembali. Dan karena jalurnya
 *  berbeda, bug itu tidak akan pernah tertangkap test alur normal.
 * ============================================================================
 */
class OrderController extends Controller
{
    public function index(): View
    {
        return view('backend.order.index', [
            'statusPilihan' => OrderStatus::cases(),
            'zona' => DB::table('zones')->orderBy('name')->get(['id', 'name']),
            'layanan' => DB::table('service_types')->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    /**
     * Data untuk DataTables, server-side.
     *
     * ========================================================================
     *  CURSOR PAGINATION, DAN TIDAK ADA COUNT(*)
     * ========================================================================
     *  DataTables biasanya menuntut `recordsTotal` — jumlah seluruh baris — dan
     *  itu berarti `COUNT(*)` pada tabel orders setiap kali staf mengetik satu
     *  huruf di kolom pencarian.
     *
     *  Pada tabel order berjuta baris dengan filter tanggal, COUNT(*) memakan
     *  detik. Dan karena DataTables memanggilnya per ketikan, satu staf yang
     *  mencari nomor order bisa menjalankan sepuluh COUNT(*) dalam lima detik.
     *
     *  Yang dikirim di sini adalah perkiraan dari statistik tabel PostgreSQL,
     *  bukan hitungan pasti. Tidak ada yang benar-benar butuh angka pastinya —
     *  yang dibutuhkan adalah tahu apakah masih ada halaman berikutnya.
     * ========================================================================
     */
    public function data(Request $request): JsonResponse
    {
        $panjang = min(100, max(10, (int) $request->integer('length', 25)));
        $mulai = max(0, (int) $request->integer('start', 0));

        $query = Order::query()
            ->leftJoin('zones', 'zones.id', '=', 'orders.zone_id')
            ->leftJoin('service_types', 'service_types.id', '=', 'orders.service_type_id')
            ->leftJoin('drivers', 'drivers.id', '=', 'orders.driver_id')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->select([
                'orders.uuid',
                'orders.order_number',
                'orders.status',
                'orders.payment_method',
                'orders.payment_status',
                'orders.total_fare',
                'orders.requested_at',
                'orders.needs_fare_review',
                'zones.name AS zona',
                'service_types.name AS layanan',
                'drivers.full_name AS driver',
                'users.name AS penumpang',
            ]);

        $this->terapkanFilter($query, $request);

        $baris = $query
            ->orderByDesc('orders.requested_at')
            ->offset($mulai)

            /*
             * Diambil SATU lebih banyak dari yang diminta.
             *
             * Itu yang membuat "masih ada halaman berikutnya" bisa dijawab tanpa
             * COUNT(*): kalau yang kembali lebih banyak dari panjang halaman,
             * berarti masih ada.
             */
            ->limit($panjang + 1)
            ->get();

        $adaLagi = $baris->count() > $panjang;
        $baris = $baris->take($panjang);

        return response()->json([
            'draw' => (int) $request->integer('draw'),

            // Perkiraan, bukan hitungan pasti. Lihat penjelasan di docblock.
            'recordsTotal' => $this->perkiraanJumlahOrder(),
            'recordsFiltered' => $mulai + $baris->count() + ($adaLagi ? 1 : 0),

            'data' => $baris->map(fn ($o): array => [
                'nomor' => $o->order_number,
                'uuid' => $o->uuid,
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'status_badge' => $o->status->badgeClass(),
                'layanan' => $o->layanan ?? '—',
                'zona' => $o->zona ?? '—',
                'penumpang' => $o->penumpang ?? '—',
                'driver' => $o->driver ?? '—',
                'pembayaran' => $o->payment_method.' / '.$o->payment_status,
                'total' => Money::of((int) $o->total_fare)->format(),
                'waktu' => BusinessClock::at($o->requested_at)->format('d/m H:i'),
                'perlu_review' => (bool) $o->needs_fare_review,
            ])->values(),
        ]);
    }

    public function show(string $uuid): View
    {
        $order = Order::query()
            ->where('uuid', $uuid)
            ->with([
                'serviceType',
                'zone',
                'user',
                'driver.vehicles',
                'driver.user',
                'statusLogs',
                'offers',
                'promo',
                'pricingRule',
                'cancellationReason',
            ])
            ->firstOrFail();

        return view('backend.order.show', [
            'order' => $order,

            /*
             * Penawaran ditampilkan LENGKAP dengan rincian skornya.
             *
             * Ini halaman yang dibuka saat driver mengeluh "saya tidak pernah
             * dapat order", dan rincian skor adalah satu-satunya jawaban yang
             * berupa angka, bukan dugaan.
             */
            'penawaran' => $order->offers->sortBy('wave'),
        ]);
    }

    /**
     * Admin membatalkan order.
     */
    public function cancel(Request $request, CancelOrder $action, string $uuid): RedirectResponse
    {
        $request->validate([
            /*
             * Catatan WAJIB, minimal 20 karakter.
             *
             * Sama seperti alasan pengajuan approval. Intervensi admin pada order
             * orang lain adalah tindakan yang harus bisa dijelaskan enam bulan
             * kemudian, dan catatan sepanjang "ok" tidak menjelaskan apa pun.
             */
            'note' => ['required', 'string', 'min:20', 'max:500'],
            'reason_code' => ['nullable', 'string'],
        ], [
            'note.required' => 'Jelaskan alasan pembatalan.',
            'note.min' => 'Alasannya harus cukup jelas untuk dibaca orang lain nanti (minimal 20 karakter).',
        ]);

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();

        $action->handle(
            order: $order,
            actorType: 'admin',
            actorId: (int) auth('admin')->id(),
            reasonCode: $request->input('reason_code'),
            note: (string) $request->input('note'),
        );

        return redirect()
            ->route('admin.orders.show', $uuid)
            ->with('success', 'Order dibatalkan.');
    }

    /**
     * Paksa assign driver ke order yang tidak dapat driver.
     *
     * ========================================================================
     *  KENAPA INI ADA, DAN KENAPA DIBATASI
     * ========================================================================
     *  Dipakai saat penumpang menelepon CS karena ordernya tidak dapat driver
     *  padahal ada driver yang dia lihat di dekatnya. Tanpa jalur ini, satu-satunya
     *  jawaban CS adalah "coba pesan lagi".
     *
     *  Yang dibatasi: hanya order yang masih MENCARI, dan hanya driver yang
     *  sedang tidak memegang order lain. Keduanya ditegakkan lewat state machine
     *  dan partial unique index yang sama, jadi jalur ini tidak bisa melanggar
     *  invariant yang dijaga jalur normal.
     * ========================================================================
     */
    public function forceAssign(
        Request $request,
        OrderStateMachine $stateMachine,
        string $uuid,
    ): RedirectResponse {
        $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'note' => ['required', 'string', 'min:20', 'max:500'],
        ], [
            'driver_id.required' => 'Pilih driver.',
            'note.required' => 'Jelaskan alasan assign manual.',
            'note.min' => 'Alasannya harus cukup jelas untuk dibaca orang lain nanti (minimal 20 karakter).',
        ]);

        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        $driverId = (int) $request->integer('driver_id');

        if ($order->status !== OrderStatus::Searching) {
            return back()->with('error', 'Order ini sudah tidak mencari driver.');
        }

        $stateMachine->apply(
            $order,
            OrderTransition::byAdmin(
                to: OrderStatus::Accepted,
                adminId: (int) auth('admin')->id(),
                note: (string) $request->input('note'),

                // driver_id lewat metadata karena aktornya ADMIN, bukan driver.
                // State machine mengambil driver_id dari aktor kalau aktornya
                // driver; untuk jalur admin, drivernya harus disebut eksplisit.
                metadata: ['driver_id' => $driverId],
            ),
        );

        AuditLog::record(
            action: 'order.force_assign',
            auditable: $order,
            newValues: ['driver_id' => $driverId, 'note' => $request->input('note')],
        );

        return redirect()
            ->route('admin.orders.show', $uuid)
            ->with('success', 'Driver di-assign manual.');
    }

    /**
     * Jalankan ulang pencarian driver.
     *
     * Untuk order yang sudah `no_driver` tapi penumpangnya masih mau menunggu.
     * Lebih baik daripada meminta dia memesan ulang: nomor ordernya tetap sama,
     * dan riwayat percobaan sebelumnya tidak hilang.
     */
    public function retryMatching(
        OrderStateMachine $stateMachine,
        string $uuid,
    ): RedirectResponse {
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();

        if ($order->status !== OrderStatus::NoDriver) {
            return back()->with('error', 'Hanya order yang tidak dapat driver bisa dicari ulang.');
        }

        $stateMachine->apply(
            $order,
            OrderTransition::byAdmin(
                to: OrderStatus::Searching,
                adminId: (int) auth('admin')->id(),
                note: 'Pencarian driver dijalankan ulang oleh admin.',
            ),
        );

        MatchDriverJob::dispatch((int) $order->getKey());

        return redirect()
            ->route('admin.orders.show', $uuid)
            ->with('success', 'Pencarian driver dijalankan ulang.');
    }

    // -------------------------------------------------------------------------

    private function terapkanFilter(mixed $query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('orders.status', $request->string('status'));
        }

        if ($request->filled('zone_id')) {
            $query->where('orders.zone_id', $request->integer('zone_id'));
        }

        if ($request->filled('service_type_id')) {
            $query->where('orders.service_type_id', $request->integer('service_type_id'));
        }

        if ($request->boolean('needs_review')) {
            $query->where('orders.needs_fare_review', true);
        }

        /*
         * Rentang tanggal memakai zona BISNIS.
         *
         * Staf ops mengetik "27 Agustus" dan yang dia maksud 27 Agustus WIB.
         * Tanpa konversi, hasilnya bergeser tujuh jam dan order jam 6 pagi
         * masuk ke tanggal sebelumnya — yang paling terasa saat merekonsiliasi
         * keluhan penumpang dengan jam yang dia sebutkan.
         */
        if ($request->filled('dari')) {
            [$mulai] = BusinessClock::dayRange(
                Carbon::parse((string) $request->string('dari'))
            );
            $query->where('orders.requested_at', '>=', $mulai);
        }

        if ($request->filled('sampai')) {
            [, $selesai] = BusinessClock::dayRange(
                Carbon::parse((string) $request->string('sampai'))
            );
            $query->where('orders.requested_at', '<=', $selesai);
        }

        $cari = trim((string) $request->input('search.value', $request->input('cari', '')));

        if ($cari !== '') {
            /*
             * Pencarian memakai index trigram, bukan LIKE biasa di banyak kolom.
             *
             * `users_name_trgm` dan `users_phone_trgm` ada supaya
             * `ILIKE '%budi%'` tetap terindeks. Tanpa memanfaatkannya, pencarian
             * CS berarti sequential scan pada tabel users setiap ketikan.
             *
             * Nomor order dicocokkan dengan prefix, bukan trigram: formatnya
             * berawalan tetap, dan pencarian prefix bisa memakai unique index-nya.
             */
            $query->where(function ($q) use ($cari): void {
                $q->where('orders.order_number', 'ilike', $cari.'%')
                    ->orWhere('users.name', 'ilike', '%'.$cari.'%')
                    ->orWhere('users.phone', 'ilike', '%'.$cari.'%')
                    ->orWhere('drivers.full_name', 'ilike', '%'.$cari.'%');
            });
        }
    }

    /**
     * Perkiraan jumlah order dari statistik tabel PostgreSQL.
     *
     * `reltuples` diperbarui autovacuum, jadi angkanya bisa tertinggal beberapa
     * persen. Untuk mengisi kolom "dari sekitar N baris" di DataTables, itu
     * cukup — dan biayanya satu pembacaan katalog, bukan pemindaian tabel.
     */
    private function perkiraanJumlahOrder(): int
    {
        $baris = DB::selectOne("
            SELECT GREATEST(reltuples::bigint, 0) AS perkiraan
            FROM pg_class
            WHERE oid = 'orders'::regclass
        ");

        return (int) ($baris->perkiraan ?? 0);
    }
}
