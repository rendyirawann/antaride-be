<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detail order untuk vertikal food.
 *
 * Dipisah dari `orders` karena hanya sebagian order punya merchant. Menaruh
 * kolom ini di tabel orders akan menambah selusin kolom yang NULL untuk setiap
 * order antar-jemput.
 *
 * Yang membedakan food dari ride: ada pihak ketiga yang harus setuju dulu.
 * Alurnya jadi punya satu simpul kegagalan tambahan, yaitu merchant menolak
 * atau tidak merespons, dan itu terjadi setelah user sudah membayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createOrderFood();
        $this->createOrderFoodItems();
    }

    // -------------------------------------------------------------------------

    private function createOrderFood(): void
    {
        Schema::create('order_food', function (Blueprint $table): void {
            // Primary key sekaligus foreign key. Satu order punya paling
            // banyak satu detail food, jadi tidak perlu id sendiri.
            $table->foreignId('order_id')->primary()
                ->constrained()->cascadeOnDelete();

            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();

            $table->bigInteger('subtotal');
            $table->bigInteger('merchant_discount')->default(0);
            $table->bigInteger('delivery_fee')->default(0);
            $table->bigInteger('packaging_fee')->default(0);

            // Komisi merchant dibekukan saat order dibuat. Kalau tidak, mengubah
            // komisi merchant hari ini akan mengubah bagi hasil order bulan lalu
            // saat laporan dihitung ulang.
            $table->decimal('commission_percent', 5, 2);
            $table->bigInteger('merchant_earning');

            $table->text('merchant_note')->nullable();
            $table->text('customer_note')->nullable();

            $table->string('merchant_status', 12)->default('pending');

            $table->timestamp('merchant_accepted_at')->nullable();
            $table->timestamp('cooking_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();

            $table->foreignId('rejection_reason_id')->nullable();
            $table->text('rejection_note')->nullable();

            // Batas waktu merchant merespons. Lewat dari ini, order dibatalkan
            // otomatis dan dana user dilepas kembali.
            $table->timestamp('respond_deadline_at')->nullable();

            $table->timestamps();
        });

        DB::statement("
            ALTER TABLE order_food ADD CONSTRAINT order_food_merchant_status_check
            CHECK (merchant_status IN ('pending','accepted','rejected','cooking',
                                       'ready','handed_over','expired'))
        ");

        /*
         * Order yang menunggu merchant WAJIB punya batas waktu.
         *
         * Tanpa constraint ini, satu jalur pembuatan order yang lupa mengisi
         * respond_deadline_at menghasilkan order yang menggantung SELAMANYA:
         * merchant tidak pernah merespons, job pembatal otomatis mencari
         * `respond_deadline_at < now()` dan tidak menemukan baris ini karena
         * NULL tidak pernah lebih kecil dari apa pun, dan dana penumpang tetap
         * tertahan tanpa ada yang tahu.
         *
         * Bentuk keluhannya di lapangan: "saldo saya berkurang tapi tidak ada
         * pesanan". Dan tidak ada satu pun error di log.
         */
        DB::statement("
            ALTER TABLE order_food ADD CONSTRAINT order_food_pending_needs_deadline
            CHECK (merchant_status <> 'pending' OR respond_deadline_at IS NOT NULL)
        ");

        // Penolakan harus punya alasan yang bisa dilaporkan ke merchant dan
        // dihitung untuk statistik. Kalau tidak, kolom rejection_reason_id akan
        // separuh terisi dan laporan "alasan penolakan tersering" jadi bohong.
        DB::statement("
            ALTER TABLE order_food ADD CONSTRAINT order_food_rejected_needs_reason
            CHECK (merchant_status <> 'rejected' OR rejection_reason_id IS NOT NULL)
        ");

        DB::statement('
            ALTER TABLE order_food ADD CONSTRAINT order_food_money_check
            CHECK (
                subtotal >= 0 AND merchant_discount >= 0
                AND delivery_fee >= 0 AND packaging_fee >= 0
                AND merchant_earning >= 0
                AND commission_percent >= 0 AND commission_percent <= 100
            )
        ');

        // Merchant yang sudah menerima wajib punya cap waktunya.
        DB::statement("
            ALTER TABLE order_food ADD CONSTRAINT order_food_accepted_shape_check
            CHECK (
                merchant_status IN ('pending','rejected','expired')
                OR merchant_accepted_at IS NOT NULL
            )
        ");

        // Antrean kerja merchant di app: order masuk yang belum direspons.
        DB::statement("
            CREATE INDEX order_food_merchant_queue ON order_food (merchant_id, created_at)
            WHERE merchant_status = 'pending'
        ");

        // Order yang lewat batas waktu respons, untuk job pembatalan otomatis.
        DB::statement("
            CREATE INDEX order_food_respond_deadline ON order_food (respond_deadline_at)
            WHERE merchant_status = 'pending'
        ");

        // Laporan penjualan merchant.
        DB::statement('CREATE INDEX order_food_merchant_created ON order_food (merchant_id, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    /**
     * Isi pesanan, disimpan sebagai SNAPSHOT.
     *
     * `name_snapshot`, `price_snapshot`, dan `options_snapshot` sengaja
     * menduplikasi data dari menu_items. Ini bukan denormalisasi yang malas.
     *
     * Merchant boleh mengubah harga dan nama menu kapan saja, dan boleh
     * menghapus item. Kalau order menyimpan referensi saja, struk order bulan
     * lalu akan berubah sendiri saat harga naik, dan item yang dihapus akan
     * hilang dari riwayat pembelian pelanggan.
     */
    private function createOrderFoodItems(): void
    {
        Schema::create('order_food_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Boleh NULL kalau menunya sudah dihapus merchant. Snapshot di
            // bawah yang menjaga riwayatnya tetap terbaca.
            $table->foreignId('menu_item_id')->nullable()
                ->constrained('menu_items')->nullOnDelete();

            $table->string('name_snapshot', 150);
            $table->bigInteger('price_snapshot');

            $table->unsignedSmallInteger('quantity');
            $table->jsonb('options_snapshot')->nullable();
            $table->string('note', 255)->nullable();

            $table->bigInteger('subtotal');

            // Merchant bisa menandai item habis setelah menerima order. User
            // memutuskan lanjut tanpa item itu atau batal seluruhnya.
            $table->boolean('is_unavailable')->default(false);

            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE order_food_items ADD CONSTRAINT order_food_items_quantity_check
            CHECK (quantity > 0)
        ');

        DB::statement('
            ALTER TABLE order_food_items ADD CONSTRAINT order_food_items_money_check
            CHECK (price_snapshot >= 0 AND subtotal >= 0)
        ');

        DB::statement('CREATE INDEX order_food_items_order ON order_food_items (order_id)');

        // Menu terlaris untuk laporan merchant.
        DB::statement('CREATE INDEX order_food_items_menu ON order_food_items (menu_item_id, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('order_food_items');
        Schema::dropIfExists('order_food');
    }
};
