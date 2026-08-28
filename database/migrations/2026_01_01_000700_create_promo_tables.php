<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promo dan pemakaiannya.
 *
 * Yang paling sering salah dibangun di sini: kuota tanpa lock. Tanpa penjagaan
 * yang benar, promo berkuota 50 akan dipakai 100 orang saat ada lonjakan, dan
 * selisih biayanya ditanggung platform tanpa ada yang menyadari sampai laporan
 * bulanan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createPromos();
        $this->createPromoUsages();
        $this->linkOrdersToPromos();
    }

    // -------------------------------------------------------------------------

    private function createPromos(): void
    {
        Schema::create('promos', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('code', 30);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('banner_url', 500)->nullable();
            $table->text('terms')->nullable();

            $table->string('type', 20);
            $table->bigInteger('value');
            $table->bigInteger('max_discount')->nullable();
            $table->bigInteger('min_order')->default(0);

            // Pembatas sasaran. JSONB supaya bisa difilter dengan index GIN,
            // dan supaya menambah dimensi pembatas baru tidak butuh migration.
            $table->jsonb('service_type_ids')->nullable();
            $table->jsonb('zone_ids')->nullable();
            $table->jsonb('payment_methods')->nullable();

            $table->integer('quota_total')->nullable();
            $table->integer('quota_per_user')->nullable();

            // Cache. Kebenarannya ada di tabel promo_usages, dan reservasi kuota
            // dijaga dengan SELECT FOR UPDATE pada baris ini.
            $table->integer('used_count')->default(0);

            $table->boolean('new_user_only')->default(false);
            $table->boolean('is_visible')->default(true);

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_active')->default(true);

            // Siapa yang menanggung biaya diskon. Ini yang menentukan apakah
            // promo mengurangi pendapatan platform atau pendapatan merchant,
            // dan hampir selalu terlupakan sampai merchant mengeluh.
            $table->string('cost_bearer', 10)->default('platform');
            $table->decimal('merchant_share_percent', 5, 2)->default(0);

            $table->unsignedBigInteger('approval_request_id')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_type_check
            CHECK (type IN ('percent','fixed','free_delivery','cashback'))
        ");

        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_cost_bearer_check
            CHECK (cost_bearer IN ('platform','merchant','shared'))
        ");

        DB::statement('
            ALTER TABLE promos ADD CONSTRAINT promos_period_check
            CHECK (ends_at > starts_at)
        ');

        DB::statement('
            ALTER TABLE promos ADD CONSTRAINT promos_value_check
            CHECK (
                value >= 0 AND min_order >= 0
                AND (max_discount IS NULL OR max_discount > 0)
                AND (quota_total IS NULL OR quota_total > 0)
                AND (quota_per_user IS NULL OR quota_per_user > 0)
                AND merchant_share_percent >= 0 AND merchant_share_percent <= 100
            )
        ');

        // Promo bertipe persen wajib punya batas diskon. Tanpa itu, promo 50%
        // pada order Rp 2 juta akan memberi diskon Rp 1 juta, dan itu hampir
        // selalu bukan yang dimaksud.
        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_percent_needs_cap_check
            CHECK (type <> 'percent' OR max_discount IS NOT NULL)
        ");

        DB::statement("
            ALTER TABLE promos ADD CONSTRAINT promos_percent_range_check
            CHECK (type <> 'percent' OR (value > 0 AND value <= 100))
        ");

        // Kuota terpakai tidak boleh melewati kuota total. Ini jaring terakhir
        // di database untuk race condition reservasi kuota.
        DB::statement('
            ALTER TABLE promos ADD CONSTRAINT promos_quota_check
            CHECK (quota_total IS NULL OR used_count <= quota_total)
        ');

        // Kode unik di antara promo yang belum dihapus. Kode promo lama boleh
        // dipakai ulang setelah kampanyenya diarsipkan.
        DB::statement('
            CREATE UNIQUE INDEX promos_code_unique ON promos (upper(code))
            WHERE deleted_at IS NULL
        ');

        // Promo yang bisa dipakai sekarang. Query ini dipanggil setiap kali user
        // membuka halaman checkout.
        DB::statement('
            CREATE INDEX promos_redeemable ON promos (starts_at, ends_at)
            WHERE is_active = true AND deleted_at IS NULL
        ');

        DB::statement('CREATE INDEX promos_zone_gin ON promos USING gin (zone_ids)');
        DB::statement('CREATE INDEX promos_service_gin ON promos USING gin (service_type_ids)');
    }

    // -------------------------------------------------------------------------

    private function createPromoUsages(): void
    {
        Schema::create('promo_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->bigInteger('discount_amount');

            // Bagian yang ditanggung platform dan merchant, dihitung saat
            // pemakaian. Disimpan supaya laporan biaya promo tidak perlu
            // menghitung ulang dari aturan yang mungkin sudah berubah.
            $table->bigInteger('platform_cost');
            $table->bigInteger('merchant_cost')->default(0);

            $table->timestamp('created_at')->nullable();

            // Satu promo hanya boleh terpakai sekali per order.
            $table->unique(['promo_id', 'order_id']);
        });

        DB::statement('
            ALTER TABLE promo_usages ADD CONSTRAINT promo_usages_amount_check
            CHECK (
                discount_amount >= 0 AND platform_cost >= 0 AND merchant_cost >= 0
                AND platform_cost + merchant_cost = discount_amount
            )
        ');

        // Penegakan kuota per user: hitung baris untuk pasangan (promo, user).
        DB::statement('CREATE INDEX promo_usages_promo_user ON promo_usages (promo_id, user_id)');

        // Laporan biaya dan ROI promo.
        DB::statement('CREATE INDEX promo_usages_promo_created ON promo_usages (promo_id, created_at DESC)');
    }

    // -------------------------------------------------------------------------

    /**
     * Foreign key orders.promo_id dipasang di sini, bukan di migration orders,
     * karena tabel promos baru ada setelahnya.
     */
    private function linkOrdersToPromos(): void
    {
        DB::statement('
            ALTER TABLE orders
            ADD CONSTRAINT orders_promo_id_foreign
            FOREIGN KEY (promo_id) REFERENCES promos (id) ON DELETE SET NULL
        ');

        // Order yang memakai promo, untuk laporan biaya kampanye.
        DB::statement('
            CREATE INDEX orders_promo ON orders (promo_id, created_at DESC)
            WHERE promo_id IS NOT NULL
        ');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_promo_id_foreign');
        DB::statement('DROP INDEX IF EXISTS orders_promo');

        Schema::dropIfExists('promo_usages');
        Schema::dropIfExists('promos');
    }
};
