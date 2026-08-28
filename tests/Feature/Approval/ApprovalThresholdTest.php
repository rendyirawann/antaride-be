<?php

declare(strict_types=1);

namespace Tests\Feature\Approval;

use App\Domain\Approval\Actions\ResolveApprovalThreshold;
use App\Domain\Wallet\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ============================================================================
 *  YANG DIUJI DI SINI ADALAH BATAS, BUKAN BAGIAN TENGAHNYA
 * ============================================================================
 *  Ambang approval hampir selalu benar untuk nominal di tengah rentang. Yang
 *  salah adalah nominal yang tepat DI batas — dan itu justru nominal yang paling
 *  sering muncul, karena orang menarik angka bulat.
 *
 *  Rp 500.000 tepat adalah kasusnya. Tabel `approval_thresholds` memakai
 *  int8range '[)' (batas atas eksklusif), jadi Rp 500.000 seharusnya masuk
 *  rentang KEDUA dan butuh satu penyetuju. Kode lamanya memakai `<=` dan
 *  menempatkannya di rentang pertama, yang berarti cair otomatis.
 * ============================================================================
 */
class ApprovalThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedWithdrawalThresholds();
    }

    // =========================================================================
    //  Batas rentang
    // =========================================================================

    /**
     * @return array<string, array{int, int, ?string}>
     */
    public static function nominalCases(): array
    {
        return [
            'nol' => [0, 0, null],
            'kecil' => [50_000, 0, null],
            'tepat di bawah batas pertama' => [499_999, 0, null],

            // INI kasusnya.
            'TEPAT di batas pertama' => [500_000, 1, 'finance'],

            'sedikit di atas batas pertama' => [500_001, 1, 'finance'],
            'tengah rentang kedua' => [2_000_000, 1, 'finance'],
            'tepat di bawah batas kedua' => [4_999_999, 1, 'finance'],
            'TEPAT di batas kedua' => [5_000_000, 2, 'super-admin'],
            'jauh di atas batas kedua' => [50_000_000, 2, 'super-admin'],
        ];
    }

    #[DataProvider('nominalCases')]
    public function test_ambang_memakai_batas_atas_eksklusif(
        int $amount,
        int $expectedApprovers,
        ?string $expectedRole,
    ): void {
        $threshold = app(ResolveApprovalThreshold::class)->handle('withdrawal', $amount);

        $this->assertSame(
            $expectedApprovers,
            $threshold->requiredApprovers,
            "Rp {$amount} seharusnya butuh {$expectedApprovers} penyetuju."
        );

        $this->assertSame($expectedRole, $threshold->requiredRole);
    }

    public function test_ambang_dibaca_dari_tabel_bukan_config(): void
    {
        // Kalau tim finance mengubah ambang di panel, perubahannya HARUS berlaku
        // tanpa deploy. Itu satu-satunya alasan tabel ini ada.
        DB::table('approval_thresholds')
            ->where('type', 'withdrawal')
            ->where('min_amount', 0)
            ->update(['max_amount' => 100_000]);

        DB::table('approval_thresholds')
            ->where('type', 'withdrawal')
            ->where('min_amount', 500_000)
            ->update(['min_amount' => 100_000]);

        $threshold = app(ResolveApprovalThreshold::class)->handle('withdrawal', 200_000);

        $this->assertTrue(
            $threshold->fromDatabase,
            'Ambang harus dibaca dari tabel approval_thresholds.'
        );

        $this->assertSame(
            1,
            $threshold->requiredApprovers,
            'Rp 200.000 sekarang di atas ambang otomatis yang baru, jadi butuh penyetuju.'
        );
    }

    public function test_jatuh_ke_config_kalau_tabelnya_kosong(): void
    {
        DB::table('approval_thresholds')->where('type', 'withdrawal')->delete();

        $threshold = app(ResolveApprovalThreshold::class)->handle('withdrawal', 100_000);

        $this->assertFalse($threshold->fromDatabase);
        $this->assertSame(0, $threshold->requiredApprovers);
    }

    public function test_jenis_yang_tidak_dikenal_menuntut_penyetuju_terbanyak(): void
    {
        // Nominal yang tidak masuk aturan mana pun tidak boleh lolos otomatis.
        $threshold = app(ResolveApprovalThreshold::class)
            ->handle('jenis_yang_belum_ada', 999_000_000);

        $this->assertSame(2, $threshold->requiredApprovers);
        $this->assertFalse($threshold->isAutomatic());
    }

    // =========================================================================
    //  Kill switch
    // =========================================================================

    public function test_penarikan_kecil_cair_otomatis_saat_kill_switch_menyala(): void
    {
        $this->setAutoApproveFlag(true);

        $withdrawal = new Withdrawal(['amount' => 100_000]);

        $this->assertTrue($withdrawal->qualifiesForAutoApproval());
    }

    public function test_kill_switch_mati_memaksa_semua_penarikan_lewat_review(): void
    {
        $this->setAutoApproveFlag(false);

        $withdrawal = new Withdrawal(['amount' => 100_000]);

        $this->assertFalse(
            $withdrawal->qualifiesForAutoApproval(),
            'Mematikan withdrawal.auto_approve harus benar-benar menghentikan '
            .'persetujuan otomatis, bukan hanya mengubah tampilan panel.'
        );
    }

    public function test_flag_yang_tidak_ada_dianggap_mati(): void
    {
        DB::table('feature_flags')->where('key', 'withdrawal.auto_approve')->delete();
        cache()->forget('feature:withdrawal.auto_approve');

        $withdrawal = new Withdrawal(['amount' => 100_000]);

        $this->assertFalse(
            $withdrawal->qualifiesForAutoApproval(),
            'Kalau flag-nya hilang, yang aman adalah menolak, bukan meloloskan.'
        );
    }

    public function test_nominal_besar_tidak_pernah_otomatis_walau_kill_switch_menyala(): void
    {
        $this->setAutoApproveFlag(true);

        $withdrawal = new Withdrawal(['amount' => 10_000_000]);

        $this->assertFalse($withdrawal->qualifiesForAutoApproval());
    }

    // =========================================================================

    private function seedWithdrawalThresholds(): void
    {
        $rows = [
            [0, 500_000, null, 0],
            [500_000, 5_000_000, 'finance', 1],
            [5_000_000, null, 'super-admin', 2],
        ];

        foreach ($rows as [$min, $max, $role, $approvers]) {
            DB::table('approval_thresholds')->insert([
                'type' => 'withdrawal',
                'min_amount' => $min,
                'max_amount' => $max,
                'required_role' => $role,
                'required_approvers' => $approvers,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function setAutoApproveFlag(bool $enabled): void
    {
        DB::table('feature_flags')->updateOrInsert(
            ['key' => 'withdrawal.auto_approve'],
            [
                'description' => 'Setujui otomatis penarikan di bawah ambang',
                'is_enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        // Flag di-cache 30 detik, jadi cache harus dibuang supaya test menguji
        // nilai barunya, bukan nilai yang tersisa dari test sebelumnya.
        cache()->forget('feature:withdrawal.auto_approve');
    }
}
