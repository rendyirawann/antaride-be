<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menjaga kesesuaian antara enum PHP dan constraint database.
 *
 * Kenapa test ini ada: invariant "satu driver, satu order berjalan" ditegakkan
 * di DUA tempat yang harus sepakat — partial unique index
 * `orders_one_active_per_driver`, dan OrderStatus::activeStatuses().
 *
 * Kalau keduanya berbeda, kegagalannya SENYAP. Misalkan seseorang menambah
 * status baru 'driver_waiting' ke enum dan ke daftar active, tapi lupa mengubah
 * index. Database akan mengizinkan driver menerima order kedua saat order
 * pertamanya berstatus 'driver_waiting', dan tidak ada satu pun error muncul.
 * Yang terjadi di lapangan: penumpang melihat driver bergerak ke arah yang
 * salah.
 *
 * Test ini membaca definisi index langsung dari katalog PostgreSQL, jadi tidak
 * bisa lolos hanya karena kodenya terlihat benar.
 */
class OrderStatusMatchesDatabaseTest extends TestCase
{
    public function test_daftar_status_aktif_driver_sama_dengan_partial_unique_index(): void
    {
        $indexDefinition = $this->indexDefinition('orders_one_active_per_driver');

        $this->assertNotNull(
            $indexDefinition,
            'Index orders_one_active_per_driver tidak ada. Invariant satu order '
            .'per driver tidak ditegakkan database sama sekali.',
        );

        $inIndex = $this->extractStatusList($indexDefinition);
        $inEnum = OrderStatus::activeValues();

        sort($inIndex);
        sort($inEnum);

        $this->assertSame(
            $inEnum,
            $inIndex,
            "Daftar status di OrderStatus::activeStatuses() berbeda dari partial \n"
            ."unique index orders_one_active_per_driver.\n\n"
            .'  enum  : '.implode(', ', $inEnum)."\n"
            .'  index : '.implode(', ', $inIndex)."\n\n"
            ."Selaraskan keduanya. Kalau tidak, driver bisa memegang dua order \n"
            .'sekaligus tanpa satu pun error muncul.',
        );
    }

    public function test_daftar_status_pemblokir_user_sama_dengan_partial_unique_index(): void
    {
        $indexDefinition = $this->indexDefinition('orders_one_active_per_user');

        $this->assertNotNull($indexDefinition, 'Index orders_one_active_per_user tidak ada.');

        $inIndex = $this->extractStatusList($indexDefinition);
        $inEnum = OrderStatus::userBlockingValues();

        sort($inIndex);
        sort($inEnum);

        $this->assertSame($inEnum, $inIndex, 'Daftar status pemblokir user tidak sinkron dengan index.');
    }

    /**
     * Setiap nilai enum harus lolos CHECK constraint di kolom status, dan
     * sebaliknya CHECK constraint tidak boleh mengizinkan nilai yang tidak
     * dikenal enum.
     */
    public function test_semua_nilai_enum_diizinkan_check_constraint(): void
    {
        $definition = DB::selectOne("
            SELECT pg_get_constraintdef(oid) AS def
            FROM pg_constraint
            WHERE conname = 'orders_status_check'
        ");

        $this->assertNotNull($definition, 'CHECK constraint orders_status_check tidak ada.');

        $inConstraint = $this->extractStatusList($definition->def);
        $inEnum = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        sort($inConstraint);
        sort($inEnum);

        $this->assertSame(
            $inEnum,
            $inConstraint,
            "Nilai OrderStatus berbeda dari CHECK constraint orders_status_check.\n"
            .'  enum      : '.implode(', ', $inEnum)."\n"
            .'  constraint: '.implode(', ', $inConstraint),
        );
    }

    /**
     * Status akhir tidak boleh punya jalan keluar. Ini yang mencegah order
     * selesai kembali jadi mencari driver.
     */
    public function test_status_akhir_tidak_punya_transisi(): void
    {
        $final = [
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::NoDriver,
            OrderStatus::Expired,
        ];

        foreach ($final as $status) {
            $this->assertTrue(
                $status->isFinal(),
                "Status {$status->value} seharusnya final, tapi masih punya transisi: "
                .implode(', ', array_map(fn ($s) => $s->value, $status->allowedTransitions())),
            );
        }
    }

    /**
     * Tidak boleh ada status yang bisa kembali ke Created atau Searching.
     *
     * Order yang sudah punya driver lalu kembali ke pencarian berarti driver
     * itu tetap terikat pada order sementara sistem menawarkannya ke orang
     * lain, dan dua driver akan menuju titik jemput yang sama.
     */
    public function test_tidak_ada_transisi_kembali_ke_pencarian(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status === OrderStatus::Created) {
                continue;
            }

            $targets = $status->allowedTransitions();

            $this->assertNotContains(
                OrderStatus::Created,
                $targets,
                "Status {$status->value} tidak boleh bisa kembali ke created.",
            );

            if ($status !== OrderStatus::Created) {
                $this->assertNotContains(
                    OrderStatus::Searching,
                    array_filter($targets, fn ($t) => $status->isActiveForDriver()),
                    "Status aktif {$status->value} tidak boleh kembali ke searching.",
                );
            }
        }
    }

    /**
     * Setiap status non-final harus bisa dibatalkan.
     *
     * Tanpa jalan keluar ini, satu bug di alur normal akan meninggalkan order
     * macet permanen yang tidak bisa dibersihkan bahkan oleh admin.
     */
    public function test_setiap_status_berjalan_bisa_dibatalkan(): void
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status->isFinal()) {
                continue;
            }

            $this->assertTrue(
                $status->canTransitionTo(OrderStatus::Cancelled),
                "Status {$status->value} tidak punya jalan ke cancelled. Order bisa macet permanen.",
            );
        }
    }

    // -------------------------------------------------------------------------

    private function indexDefinition(string $indexName): ?string
    {
        $row = DB::selectOne(
            'SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = ?',
            ['public', $indexName],
        );

        return $row?->indexdef;
    }

    /**
     * Mengambil daftar status dari klausa WHERE index atau CHECK constraint.
     *
     * PostgreSQL menuliskan ulang predikatnya, jadi bentuknya bisa
     * `status = ANY (ARRAY['accepted'::character varying, ...])`. Yang diambil
     * hanya literal di dalam kutip tunggal.
     *
     * @return array<int, string>
     */
    private function extractStatusList(string $definition): array
    {
        // Ambil hanya bagian setelah kata kunci status, supaya nama kolom lain
        // yang kebetulan punya literal tidak ikut terbaca.
        $position = stripos($definition, 'status');

        if ($position !== false) {
            $definition = substr($definition, $position);
        }

        preg_match_all("/'([a-z_]+)'/", $definition, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
