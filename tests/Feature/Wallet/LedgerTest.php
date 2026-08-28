<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Wallet\Actions\HoldFunds;
use App\Domain\Wallet\Actions\PostLedgerEntries;
use App\Domain\Wallet\Actions\ReleaseFunds;
use App\Domain\Wallet\Actions\SettleOrder;
use App\Domain\Wallet\DTOs\LedgerEntry;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Exceptions\UnbalancedLedgerException;
use App\Domain\Wallet\Exceptions\WalletFrozenException;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buku besar diuji terhadap PostgreSQL sungguhan.
 *
 * Constraint trigger `wallet_transactions_balanced` dan CHECK
 * `wallet_transactions_arithmetic_check` adalah bagian dari yang diuji di sini.
 * Menggantinya dengan mock berarti menguji bahwa saya memanggil INSERT, bukan
 * bahwa database menolak pembukuan yang tidak seimbang.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private PostLedgerEntries $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSeeder::class);
        $this->post = app(PostLedgerEntries::class);
    }

    // -------------------------------------------------------------------------
    // Double-entry
    // -------------------------------------------------------------------------

    public function test_peristiwa_seimbang_tersimpan(): void
    {
        $a = $this->wallet('user', 1, balance: 100_000);
        $b = $this->wallet('driver', 1, balance: 0);

        $result = $this->post->handle([
            LedgerEntry::debit((int) $a->getKey(), 'ride_payment', Money::of(30_000)),
            LedgerEntry::credit((int) $b->getKey(), 'ride_earning', Money::of(30_000)),
        ]);

        $this->assertCount(2, $result['transactions']);

        $this->assertSame(70_000, (int) $a->fresh()->balance);
        $this->assertSame(30_000, (int) $b->fresh()->balance);

        // Kedua baris punya group_uuid yang sama.
        $groups = WalletTransaction::query()->pluck('group_uuid')->unique();
        $this->assertCount(1, $groups);
    }

    public function test_peristiwa_tiga_sisi_seimbang(): void
    {
        $user = $this->wallet('user', 1, balance: 100_000);
        $driver = $this->wallet('driver', 1);
        $platform = Wallet::platform(Wallet::PLATFORM_REVENUE);

        $this->post->handle([
            LedgerEntry::debit((int) $user->getKey(), 'ride_payment', Money::of(30_000)),
            LedgerEntry::credit((int) $driver->getKey(), 'ride_earning', Money::of(25_000)),
            LedgerEntry::credit((int) $platform->getKey(), 'commission', Money::of(5_000)),
        ]);

        $this->assertSame(70_000, (int) $user->fresh()->balance);
        $this->assertSame(25_000, (int) $driver->fresh()->balance);
        $this->assertSame(5_000, (int) $platform->fresh()->balance);
    }

    /**
     * Peristiwa yang tidak seimbang ditolak SEBELUM menyentuh database.
     */
    public function test_peristiwa_tidak_seimbang_ditolak(): void
    {
        $a = $this->wallet('user', 1, balance: 100_000);
        $b = $this->wallet('driver', 1);

        $this->expectException(UnbalancedLedgerException::class);

        $this->post->handle([
            LedgerEntry::debit((int) $a->getKey(), 'ride_payment', Money::of(30_000)),
            LedgerEntry::credit((int) $b->getKey(), 'ride_earning', Money::of(25_000)),
        ]);
    }

    /**
     * Dan kalau pemeriksaan PHP dilewati, DATABASE yang menolaknya.
     *
     * Ini membuktikan constraint trigger `wallet_transactions_balanced`
     * berfungsi sebagai jaring terakhir, bukan hanya ada di skema.
     *
     * ========================================================================
     *  KENAPA ADA `SET CONSTRAINTS ALL IMMEDIATE`
     * ========================================================================
     *  Trigger-nya DEFERRABLE INITIALLY DEFERRED, jadi normalnya baru jalan
     *  saat COMMIT. Tapi RefreshDatabase membungkus setiap test dalam satu
     *  transaksi yang di-rollback di akhir, sehingga COMMIT sungguhan TIDAK
     *  PERNAH terjadi dan trigger-nya tidak pernah dievaluasi.
     *
     *  Tanpa baris itu, test ini akan LULUS tanpa menguji apa pun, dan kita
     *  akan percaya punya jaring pengaman yang sebenarnya belum pernah diuji.
     *
     *  `SET CONSTRAINTS ALL IMMEDIATE` memaksa evaluasi seketika, jadi
     *  perilakunya bisa diuji di dalam transaksi test.
     * ========================================================================
     */
    public function test_database_menolak_peristiwa_tidak_seimbang_walau_php_dilewati(): void
    {
        $wallet = $this->wallet('driver', 1);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->expectExceptionMessageMatches('/tidak seimbang|Ledger/i');

        // INSERT langsung, melewati seluruh action dan pemeriksaannya.
        DB::table('wallet_transactions')->insert([
            'uuid' => (string) Str::uuid7(),
            'wallet_id' => $wallet->getKey(),
            'type' => 'ride_earning',
            'direction' => 'credit',
            'amount' => 25_000,
            'balance_before' => 0,
            'balance_after' => 25_000,
            'group_uuid' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);
    }

    /**
     * Dan trigger yang sama MENERIMA peristiwa yang seimbang.
     *
     * Tanpa pasangan test ini, test di atas bisa lulus hanya karena
     * trigger-nya menolak segala sesuatu.
     */
    public function test_database_menerima_peristiwa_seimbang_dalam_mode_immediate(): void
    {
        $a = $this->wallet('user', 1, balance: 100_000);
        $b = $this->wallet('driver', 1);
        $group = (string) Str::uuid7();

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        DB::table('wallet_transactions')->insert([
            [
                'uuid' => (string) Str::uuid7(),
                'wallet_id' => $a->getKey(),
                'type' => 'ride_payment',
                'direction' => 'debit',
                'amount' => 25_000,
                'balance_before' => 100_000,
                'balance_after' => 75_000,
                'group_uuid' => $group,
                'created_at' => now(),
            ],
            [
                'uuid' => (string) Str::uuid7(),
                'wallet_id' => $b->getKey(),
                'type' => 'ride_earning',
                'direction' => 'credit',
                'amount' => 25_000,
                'balance_before' => 0,
                'balance_after' => 25_000,
                'group_uuid' => $group,
                'created_at' => now(),
            ],
        ]);

        $this->assertSame(2, WalletTransaction::query()->where('group_uuid', $group)->count());
    }

    /**
     * CHECK constraint aritmetika juga ditegakkan database.
     */
    public function test_database_menolak_aritmetika_saldo_yang_salah(): void
    {
        $wallet = $this->wallet('user', 1, balance: 100_000);

        $this->expectExceptionMessageMatches('/arithmetic|check constraint/i');

        // Arah debit tapi saldo bertambah.
        DB::table('wallet_transactions')->insert([
            'uuid' => (string) Str::uuid7(),
            'wallet_id' => $wallet->getKey(),
            'type' => 'penalty',
            'direction' => 'debit',
            'amount' => 5_000,
            'balance_before' => 100_000,
            'balance_after' => 105_000,
            'group_uuid' => (string) Str::uuid7(),
            'created_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Hold dan release
    // -------------------------------------------------------------------------

    public function test_hold_memindahkan_dana_ke_held_balance(): void
    {
        $wallet = $this->wallet('user', 1, balance: 100_000);

        app(HoldFunds::class)->handle($wallet, Money::of(25_000), 'order', 1);

        $fresh = $wallet->fresh();
        $this->assertSame(75_000, (int) $fresh->balance);
        $this->assertSame(25_000, (int) $fresh->held_balance);

        // Total tetap. Dana tidak hilang, hanya berpindah kolom.
        $this->assertSame(100_000, $fresh->totalBalance()->amount);
    }

    public function test_release_mengembalikan_dana_ke_balance(): void
    {
        $wallet = $this->wallet('user', 1, balance: 100_000);

        app(HoldFunds::class)->handle($wallet, Money::of(25_000), 'order', 1);
        app(ReleaseFunds::class)->handle($wallet->fresh(), Money::of(25_000), 'order', 1);

        $fresh = $wallet->fresh();
        $this->assertSame(100_000, (int) $fresh->balance);
        $this->assertSame(0, (int) $fresh->held_balance);
    }

    /**
     * Melepas lebih dari yang ditahan harus gagal keras.
     *
     * Kalau dibiarkan, held_balance jadi negatif dan CHECK constraint
     * `wallets_non_negative_check` yang menolaknya dengan pesan yang tidak
     * menjelaskan apa pun. Pesan dari sini menyebut angkanya.
     */
    public function test_release_melebihi_yang_ditahan_gagal(): void
    {
        $wallet = $this->wallet('user', 1, balance: 100_000);

        app(HoldFunds::class)->handle($wallet, Money::of(10_000), 'order', 1);

        $this->expectExceptionMessageMatches('/hanya menahan|release ganda/i');

        app(ReleaseFunds::class)->handle($wallet->fresh(), Money::of(25_000), 'order', 1);
    }

    public function test_hold_melebihi_saldo_ditolak(): void
    {
        $wallet = $this->wallet('user', 1, balance: 10_000);

        $this->expectException(InsufficientBalanceException::class);

        app(HoldFunds::class)->handle($wallet, Money::of(25_000), 'order', 1);
    }

    /**
     * Pesan saldo tidak cukup menyebutkan angkanya.
     */
    public function test_pesan_saldo_tidak_cukup_menyebut_angka(): void
    {
        $wallet = $this->wallet('user', 1, balance: 12_000);

        try {
            app(HoldFunds::class)->handle($wallet, Money::of(25_000), 'order', 1);
            $this->fail('Seharusnya melempar InsufficientBalanceException.');
        } catch (InsufficientBalanceException $e) {
            $this->assertStringContainsString('Rp 12.000', $e->getMessage());
            $this->assertStringContainsString('Rp 13.000', $e->getMessage());
            $this->assertSame(13_000, $e->details()['shortfall']);
        }
    }

    // -------------------------------------------------------------------------
    // Dompet dibekukan
    // -------------------------------------------------------------------------

    /**
     * Dompet dibekukan tidak bisa mengeluarkan, TAPI tetap bisa menerima.
     *
     * Pendapatan driver yang sedang diselidiki tidak boleh hilang.
     */
    public function test_dompet_dibekukan_tidak_bisa_debit_tapi_bisa_credit(): void
    {
        $frozen = $this->wallet('driver', 1, balance: 50_000);
        $frozen->update(['is_frozen' => true, 'frozen_reason' => 'Dugaan lokasi palsu']);

        // Lawan transaksi perlu punya saldo, kalau tidak yang gagal adalah
        // saldo lawan yang kosong, bukan pembekuan yang sedang diuji.
        $platform = Wallet::platform(Wallet::PLATFORM_REVENUE);
        $platform->forceFill(['balance' => 100_000])->save();

        // Kredit LOLOS.
        $this->post->handle([
            LedgerEntry::debit((int) $platform->getKey(), 'settlement', Money::of(1_000)),
            LedgerEntry::credit((int) $frozen->getKey(), 'ride_earning', Money::of(1_000)),
        ]);

        $this->assertSame(51_000, (int) $frozen->fresh()->balance);

        // Debit DITOLAK.
        $this->expectException(WalletFrozenException::class);

        $this->post->handle([
            LedgerEntry::debit((int) $frozen->fresh()->getKey(), 'withdrawal', Money::of(10_000)),
            LedgerEntry::credit((int) $platform->getKey(), 'settlement', Money::of(10_000)),
        ]);
    }

    // -------------------------------------------------------------------------
    // Aritmetika dan integritas
    // -------------------------------------------------------------------------

    public function test_balance_before_dan_after_konsisten_dengan_arah(): void
    {
        $a = $this->wallet('user', 1, balance: 100_000);
        $b = $this->wallet('driver', 1, balance: 5_000);

        $this->post->handle([
            LedgerEntry::debit((int) $a->getKey(), 'ride_payment', Money::of(30_000)),
            LedgerEntry::credit((int) $b->getKey(), 'ride_earning', Money::of(30_000)),
        ]);

        $debit = WalletTransaction::query()->where('direction', 'debit')->firstOrFail();
        $credit = WalletTransaction::query()->where('direction', 'credit')->firstOrFail();

        $this->assertSame(100_000, (int) $debit->balance_before);
        $this->assertSame(70_000, (int) $debit->balance_after);

        $this->assertSame(5_000, (int) $credit->balance_before);
        $this->assertSame(35_000, (int) $credit->balance_after);
    }

    /**
     * Saldo dompet HARUS sama dengan akumulasi ledger-nya.
     *
     * Ini invariant yang paling penting di seluruh sistem: kolom balance hanya
     * cache, dan kalau dia menyimpang dari akumulasi transaksi maka tidak ada
     * lagi yang bisa dipercaya.
     */
    public function test_saldo_selalu_sama_dengan_akumulasi_ledger(): void
    {
        $user = $this->wallet('user', 1, balance: 500_000);
        $driver = $this->wallet('driver', 1);
        $platform = Wallet::platform(Wallet::PLATFORM_REVENUE);

        // Dua puluh peristiwa acak.
        for ($i = 0; $i < 20; $i++) {
            $amount = Money::of(random_int(1_000, 20_000));
            $driverShare = $amount->percentage('80');
            $platformShare = $amount->minus($driverShare);

            $entries = [
                LedgerEntry::debit((int) $user->getKey(), 'ride_payment', $amount, 'order', $i + 1),
                LedgerEntry::credit((int) $driver->getKey(), 'ride_earning', $driverShare, 'order', $i + 1),
            ];

            if ($platformShare->isPositive()) {
                $entries[] = LedgerEntry::credit(
                    (int) $platform->getKey(), 'commission', $platformShare, 'order', $i + 1,
                );
            }

            $this->post->handle($entries);
        }

        foreach ([$user, $driver, $platform] as $wallet) {
            $fresh = $wallet->fresh();

            $accumulated = (int) WalletTransaction::query()
                ->where('wallet_id', $fresh->getKey())
                ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END), 0) AS net")
                ->value('net');

            $openingBalance = match ($fresh->owner_type) {
                'user' => 500_000,
                default => 0,
            };

            $this->assertSame(
                $openingBalance + $accumulated,
                (int) $fresh->balance,
                "Saldo dompet {$fresh->owner_type} menyimpang dari akumulasi ledger.",
            );
        }
    }

    /**
     * Total kredit di seluruh ledger harus sama dengan total debit.
     *
     * Kalau tidak, ada uang yang muncul atau hilang dari sistem.
     */
    public function test_total_kredit_sama_dengan_total_debit(): void
    {
        $user = $this->wallet('user', 1, balance: 200_000);
        $driver = $this->wallet('driver', 1);
        $platform = Wallet::platform(Wallet::PLATFORM_REVENUE);

        for ($i = 0; $i < 10; $i++) {
            $this->post->handle([
                LedgerEntry::debit((int) $user->getKey(), 'ride_payment', Money::of(10_000), 'order', $i + 1),
                LedgerEntry::credit((int) $driver->getKey(), 'ride_earning', Money::of(8_000), 'order', $i + 1),
                LedgerEntry::credit((int) $platform->getKey(), 'commission', Money::of(2_000), 'order', $i + 1),
            ]);
        }

        $totals = DB::table('wallet_transactions')
            ->whereNotIn('type', ['hold', 'release'])
            ->selectRaw("
                COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END), 0) AS kredit,
                COALESCE(SUM(CASE WHEN direction='debit'  THEN amount ELSE 0 END), 0) AS debit
            ")
            ->first();

        $this->assertSame(
            (int) $totals->kredit,
            (int) $totals->debit,
            'Total kredit tidak sama dengan total debit. Ada uang yang muncul atau hilang.',
        );
    }

    // -------------------------------------------------------------------------
    // Idempotency settlement
    // -------------------------------------------------------------------------

    /**
     * Settlement order yang sama dua kali harus gagal di percobaan kedua.
     *
     * Ditegakkan partial unique index
     * `wallet_transactions_no_duplicate_settlement`. Ini yang mencegah job
     * settlement yang di-retry membayar driver dua kali.
     */
    public function test_settlement_ganda_untuk_order_yang_sama_ditolak(): void
    {
        $user = $this->wallet('user', 1, balance: 100_000);
        $driver = $this->wallet('driver', 1);

        $entries = [
            LedgerEntry::debit((int) $user->getKey(), 'ride_payment', Money::of(25_000), 'order', 42),
            LedgerEntry::credit((int) $driver->getKey(), 'ride_earning', Money::of(25_000), 'order', 42),
        ];

        $this->post->handle($entries);

        $this->expectExceptionMessageMatches('/duplicate key|unique/i');

        $this->post->handle([
            LedgerEntry::debit((int) $user->fresh()->getKey(), 'ride_payment', Money::of(25_000), 'order', 42),
            LedgerEntry::credit((int) $driver->fresh()->getKey(), 'ride_earning', Money::of(25_000), 'order', 42),
        ]);
    }

    // -------------------------------------------------------------------------

    public function test_entry_kosong_ditolak(): void
    {
        $this->expectException(UnbalancedLedgerException::class);

        $this->post->handle([]);
    }

    public function test_dompet_yang_tidak_ada_ditolak(): void
    {
        $this->expectExceptionMessageMatches('/Dompet tidak ditemukan/');

        $this->post->handle([
            LedgerEntry::debit(999_999, 'ride_payment', Money::of(1_000)),
            LedgerEntry::credit(999_998, 'ride_earning', Money::of(1_000)),
        ]);
    }

    public function test_container_menyerahkan_action(): void
    {
        $this->assertInstanceOf(PostLedgerEntries::class, app(PostLedgerEntries::class));
        $this->assertInstanceOf(HoldFunds::class, app(HoldFunds::class));
        $this->assertInstanceOf(ReleaseFunds::class, app(ReleaseFunds::class));
        $this->assertInstanceOf(SettleOrder::class, app(SettleOrder::class));
    }

    // -------------------------------------------------------------------------

    private function wallet(string $ownerType, int $ownerId, int $balance = 0): Wallet
    {
        $wallet = Wallet::forOwner($ownerType, $ownerId);

        if ($balance > 0) {
            // Saldo awal disetel langsung, bukan lewat ledger.
            //
            // Ini SATU-SATUNYA tempat yang boleh melakukannya, dan hanya di
            // test: menyiapkan saldo awal lewat ledger membutuhkan lawan
            // transaksi yang tidak ada artinya untuk hal yang sedang diuji.
            $wallet->forceFill(['balance' => $balance])->save();
        }

        return $wallet->fresh();
    }
}
