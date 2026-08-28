<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Catalog\Models\ServiceType;
use App\Domain\Catalog\Models\Zone;
use App\Domain\Pricing\Calculator\FareCalculator;
use App\Domain\Pricing\DTOs\RouteResult;
use App\Domain\Shared\Support\BusinessClock;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Polyline;
use App\Domain\Support\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\Pricing\StorePricingRuleRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pengaturan tarif.
 *
 * ============================================================================
 *  TARIF TIDAK PERNAH DITIMPA, SELALU DIGANTI DENGAN YANG BARU
 * ============================================================================
 *  Tabel `pricing_rules` bersifat append-mostly: tarif lama tidak diedit dan
 *  tidak dihapus, cukup diberi `effective_until`, lalu baris baru dibuat.
 *
 *  Alasannya bukan kerapian arsip. Kalau ada sengketa ongkos tiga bulan lalu,
 *  pertanyaannya adalah "berapa tarif yang berlaku SAAT ITU" — dan tarif yang
 *  ditimpa membuat pertanyaan itu tidak terjawab selamanya. Setiap order juga
 *  menyimpan `pricing_rule_id` yang dipakainya, jadi angkanya bisa dilacak ke
 *  aturan persisnya.
 *
 *  Konsekuensi yang harus diterima: mengubah tarif berarti membuat baris baru,
 *  dan tabelnya tumbuh. Itu jauh lebih murah daripada tidak bisa menjelaskan
 *  ongkos yang pernah ditagih.
 * ============================================================================
 *
 * ============================================================================
 *  EXCLUSION CONSTRAINT MENJAGA "SATU JAWABAN"
 * ============================================================================
 *  `pricing_rules_no_overlap` mencegah dua tarif aktif dengan periode
 *  bertumpang tindih untuk pasangan (layanan, zona) yang sama.
 *
 *  Tanpa itu, "berapa tarif untuk ride_bike di Medan Kota pada tanggal X" bisa
 *  punya dua jawaban, dan yang menentukan mana yang dipakai adalah urutan baris
 *  yang dikembalikan query — yang bisa berubah setelah VACUUM.
 *
 *  Constraint ini yang membuat form di bawah bisa berperilaku sederhana:
 *  simpan, dan kalau bertumpang tindih, database menolaknya.
 * ============================================================================
 */
class PricingController extends Controller
{
    public function index(Request $request): View
    {
        $aturan = PricingRule::query()
            ->with(['serviceType', 'zone'])
            ->orderByDesc('is_active')
            ->orderBy('service_type_id')
            ->orderBy('zone_id')
            ->orderByDesc('effective_from')
            ->paginate(30);

        return view('backend.pricing.index', [
            'aturan' => $aturan,
            'layanan' => ServiceType::query()->orderBy('sort_order')->get(),
            'zona' => Zone::query()->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('backend.pricing.form', [
            'aturan' => null,
            'layanan' => ServiceType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'zona' => Zone::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * Simpan tarif baru.
     *
     * ========================================================================
     *  MENUTUP TARIF LAMA DAN MEMBUKA YANG BARU HARUS SATU TRANSAKSI
     * ========================================================================
     *  Kalau dipisah, ada satu keadaan yang membuat quote gagal untuk seluruh
     *  kota: tarif lama sudah ditutup tapi yang baru belum tersimpan.
     *
     *  `ResolvePricingRule` melempar `PricingRuleNotFoundException` saat tidak
     *  ada tarif aktif, dan itu berarti setiap permintaan estimasi harga
     *  menghasilkan error. Jendelanya mungkin hanya beberapa milidetik — tapi
     *  pada jam sibuk itu tetap puluhan penumpang yang tidak bisa memesan, dan
     *  penyebabnya tidak akan terlihat di log karena tarifnya sudah benar saat
     *  ada yang memeriksa.
     * ========================================================================
     */
    public function store(StorePricingRuleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $aturanBaru = DB::transaction(function () use ($data): PricingRule {
                $this->tutupTarifLama(
                    serviceTypeId: (int) $data['service_type_id'],
                    zoneId: $data['zone_id'] === null ? null : (int) $data['zone_id'],
                    mulaiBerlaku: $data['effective_from'],
                );

                $aturan = PricingRule::create($data + ['is_active' => true]);

                AuditLog::record(
                    action: 'pricing.rule_created',
                    auditable: $aturan,
                    newValues: $data,
                );

                return $aturan;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'pricing_rules_no_overlap')) {
                /*
                 * Penolakan dari exclusion constraint diterjemahkan ke pesan
                 * yang bisa ditindaklanjuti.
                 *
                 * Pesan asli PostgreSQL menyebut nama constraint dan operator
                 * GiST — informasi yang benar tapi tidak berarti apa pun bagi
                 * staf ops yang sedang mencoba mengubah tarif.
                 */
                return back()
                    ->withInput()
                    ->withErrors([
                        'effective_from' => 'Sudah ada tarif aktif untuk layanan dan zona ini pada '
                            .'periode yang bertumpang tindih. Tutup dulu tarif itu, atau geser '
                            .'tanggal mulai berlakunya.',
                    ]);
            }

            throw $e;
        }

        return redirect()
            ->route('admin.pricing.index')
            ->with('success', 'Tarif baru dibuat dan berlaku dari '
                .BusinessClock::at($aturanBaru->effective_from)->format('d/m/Y H:i').' WIB.');
    }

    /**
     * Halaman simulator tarif.
     *
     * ========================================================================
     *  KENAPA SIMULATOR ADA, DAN KENAPA DI SAMPING EDITORNYA
     * ========================================================================
     *  Tarif adalah satu-satunya pengaturan di panel ini yang salahnya langsung
     *  terasa oleh setiap penumpang di kota, dan tidak bisa dibatalkan untuk
     *  order yang sudah jalan.
     *
     *  Angka tarif per kilometer sendiri tidak memberi tahu apa pun tentang
     *  ongkos yang akan ditagih: hasilnya bergantung pada tarif dasar, minimum,
     *  batas regulasi, biaya aplikasi, dan surge — enam angka yang berinteraksi.
     *  Satu-satunya cara mengetahui dampaknya adalah menghitungnya.
     *
     *  Simulator ini memakai `FareCalculator` YANG SAMA dengan yang menghitung
     *  ongkos sungguhan. Kalau memakai perhitungan terpisah, simulatornya akan
     *  benar dan produksinya salah — dan tidak ada yang akan menyadarinya sampai
     *  ada penumpang yang mengeluh.
     * ========================================================================
     */
    public function simulator(Request $request, FareCalculator $calculator): View
    {
        $hasil = null;
        $galat = null;

        if ($request->filled('service_type_id')) {
            try {
                $hasil = $this->hitungSimulasi($request, $calculator);
            } catch (\Throwable $e) {
                $galat = $e->getMessage();
            }
        }

        return view('backend.pricing.simulator', [
            'layanan' => ServiceType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'zona' => Zone::query()->where('is_active', true)->orderBy('name')->get(),
            'hasil' => $hasil,
            'galat' => $galat,
            'masukan' => $request->all(),
        ]);
    }

    /**
     * Nonaktifkan sebuah tarif.
     *
     * Tarifnya TIDAK dihapus. `is_active = false` membuatnya keluar dari
     * exclusion constraint dan dari pencarian tarif aktif, tapi barisnya tetap
     * ada untuk menjelaskan order yang pernah memakainya.
     */
    public function deactivate(int $id): RedirectResponse
    {
        $aturan = PricingRule::query()->findOrFail($id);

        $sebelum = $aturan->only(['is_active', 'effective_until']);

        $aturan->forceFill([
            'is_active' => false,
            'effective_until' => $aturan->effective_until ?? now(),
        ])->save();

        AuditLog::record(
            action: 'pricing.rule_deactivated',
            auditable: $aturan,
            oldValues: $sebelum,
            newValues: $aturan->only(['is_active', 'effective_until']),
        );

        return back()->with('success', 'Tarif dinonaktifkan.');
    }

    public function zones(): View
    {
        return view('backend.pricing.zones', [
            'zona' => Zone::query()->orderBy('priority', 'desc')->orderBy('name')->get(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Tutup tarif aktif yang akan bertabrakan dengan yang baru.
     *
     * Menutupnya berarti mengisi `effective_until` tepat pada saat tarif baru
     * mulai berlaku — bukan menonaktifkannya. Bedanya penting: tarif yang
     * ditutup tetap bisa ditanyakan "berapa tarif pada tanggal X" untuk tanggal
     * sebelum penutupan, sementara yang dinonaktifkan kehilangan periodenya.
     */
    private function tutupTarifLama(int $serviceTypeId, ?int $zoneId, mixed $mulaiBerlaku): void
    {
        PricingRule::query()
            ->where('service_type_id', $serviceTypeId)
            ->where(function ($q) use ($zoneId): void {
                // Zona NULL berarti tarif berlaku untuk semua zona. Tarif itu
                // juga harus ditutup kalau tarif baru menggantikannya.
                $zoneId === null
                    ? $q->whereNull('zone_id')
                    : $q->where('zone_id', $zoneId);
            })
            ->where('is_active', true)
            ->where(function ($q) use ($mulaiBerlaku): void {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $mulaiBerlaku);
            })
            ->update([
                'effective_until' => $mulaiBerlaku,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hitungSimulasi(Request $request, FareCalculator $calculator): array
    {
        $request->validate([
            'service_type_id' => ['required', 'integer', 'exists:service_types,id'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'distance_km' => ['required', 'numeric', 'min:0.1', 'max:200'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'surge' => ['nullable', 'numeric', 'min:1', 'max:3'],
        ], [
            'distance_km.max' => 'Jarak lebih dari 200 km bukan skenario yang dilayani.',
        ]);

        $aturan = PricingRule::query()
            ->where('service_type_id', $request->integer('service_type_id'))
            ->where('is_active', true)
            ->where(function ($q) use ($request): void {
                $zoneId = $request->input('zone_id');

                $zoneId === null
                    ? $q->whereNull('zone_id')
                    : $q->where('zone_id', $zoneId)->orWhereNull('zone_id');
            })
            ->where('effective_from', '<=', now())
            ->where(function ($q): void {
                $q->whereNull('effective_until')->orWhere('effective_until', '>', now());
            })

            // Tarif spesifik zona menang atas tarif semua-zona.
            ->orderByRaw('zone_id IS NULL')
            ->first();

        if ($aturan === null) {
            throw new \RuntimeException(
                'Tidak ada tarif aktif untuk kombinasi layanan dan zona itu. '
                .'Buat tarifnya dulu, atau pilih zona lain.'
            );
        }

        $surge = (string) number_format((float) ($request->input('surge') ?? 1.0), 2, '.', '');

        $rincian = $calculator->calculate(
            new RouteResult(
                distanceMeters: (int) round((float) $request->input('distance_km') * 1000),
                durationSeconds: (int) $request->integer('duration_minutes') * 60,
                polyline: Polyline::empty(),
            ),
            $aturan,
            $surge,
        );

        return [
            'aturan' => $aturan,
            'rincian' => $rincian,

            /*
             * Perbandingan dengan beberapa jarak lain sekaligus.
             *
             * Satu angka untuk satu jarak tidak cukup untuk menilai tarif:
             * yang perlu dilihat adalah BENTUK KURVANYA. Tarif yang wajar di 5
             * km bisa menembus batas regulasi di 40 km, dan itu hanya terlihat
             * kalau beberapa jarak dihitung bersamaan.
             */
            'kurva' => $this->kurvaJarak($calculator, $aturan, $surge),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kurvaJarak(
        FareCalculator $calculator,
        PricingRule $aturan,
        string $surge,
    ): array {
        $hasil = [];

        foreach ([1, 3, 5, 10, 15, 20, 30, 50] as $km) {
            $rincian = $calculator->calculate(
                new RouteResult(
                    distanceMeters: $km * 1000,

                    // Durasi diperkirakan dari jarak dengan kecepatan rata-rata
                    // 20 km/jam — kecepatan wajar di Medan pada jam biasa.
                    // Angkanya hanya untuk kurva pembanding, bukan estimasi
                    // yang ditagih.
                    durationSeconds: (int) round($km / 20 * 3600),

                    polyline: Polyline::empty(),
                ),
                $aturan,
                $surge,
            );

            $hasil[] = [
                'km' => $km,
                'total' => $rincian->total,
                'per_km' => $km > 0
                    ? Money::of(
                        (int) round($rincian->total->amount / $km)
                    )
                    : null,

                // Ditandai kalau kena batas regulasi. Ini yang paling sering
                // tidak disadari saat mengubah tarif per-km.
                'kena_regulasi' => ! $rincian->regulatoryAdjustment->isZero(),
            ];
        }

        return $hasil;
    }
}
