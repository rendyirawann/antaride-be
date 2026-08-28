<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant, jam buka, dan menu.
 *
 * Vertikal food berbeda dari ride karena ada pihak ketiga yang harus setuju
 * sebelum order berjalan. Konsekuensinya: harga menu bisa berubah kapan saja,
 * jadi setiap order menyimpan snapshot harga, bukan referensi ke menu_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createMerchantCategories();
        $this->createMerchants();
        $this->createMerchantStaff();
        $this->createOperatingHours();
        $this->createMenuCategories();
        $this->createMenuItems();
        $this->createMenuItemOptions();
    }

    // -------------------------------------------------------------------------

    private function createMerchantCategories(): void
    {
        Schema::create('merchant_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->string('icon_url', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    // -------------------------------------------------------------------------

    private function createMerchants(): void
    {
        Schema::create('merchants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()
                ->constrained('merchant_categories')->nullOnDelete();

            $table->string('name', 150);
            $table->string('slug', 170)->unique();
            $table->text('description')->nullable();

            $table->text('address');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phone', 20)->nullable();
            $table->string('photo_url', 500)->nullable();
            $table->string('banner_url', 500)->nullable();

            $table->string('status', 20)->default('draft');

            // Dua flag berbeda yang sering keliru disatukan:
            //   is_open        toggle manual oleh merchant, untuk tutup dadakan
            //   status=active  hasil verifikasi admin
            // Merchant yang belum diverifikasi tidak boleh menerima order
            // walaupun dia menekan tombol buka.
            $table->boolean('is_open')->default(false);

            $table->decimal('commission_percent', 5, 2)->default(20.00);
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedSmallInteger('prep_time_minutes')->default(15);

            // Rekening bank dienkripsi. Yang bisa melihat penuh hanya role
            // finance, dan setiap pembukaannya dicatat.
            $table->string('bank_name', 60)->nullable();
            $table->text('bank_account_number')->nullable();
            $table->string('bank_account_name', 120)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_admin_id')->nullable()
                ->constrained('admins')->nullOnDelete();
            $table->text('rejection_note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("
            ALTER TABLE merchants ADD CONSTRAINT merchants_status_check
            CHECK (status IN ('draft','pending_review','active','suspended','closed','rejected'))
        ");

        DB::statement('
            ALTER TABLE merchants ADD CONSTRAINT merchants_commission_check
            CHECK (commission_percent >= 0 AND commission_percent <= 100)
        ');

        // Daftar merchant yang benar-benar bisa menerima order sekarang. Ini
        // query yang dipanggil setiap kali user membuka halaman food, jadi
        // index-nya dibuat persis untuk itu.
        DB::statement("
            CREATE INDEX merchants_orderable ON merchants (zone_id, category_id)
            WHERE status = 'active' AND is_open = true AND deleted_at IS NULL
        ");

        DB::statement("
            CREATE INDEX merchants_review_queue ON merchants (created_at)
            WHERE status = 'pending_review'
        ");

        DB::statement('CREATE INDEX merchants_name_trgm ON merchants USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX merchants_owner ON merchants (owner_user_id)');
    }

    // -------------------------------------------------------------------------

    /**
     * Staf merchant: bisa menerima order dan mengubah ketersediaan menu, tapi
     * TIDAK boleh melihat keuangan atau menarik saldo.
     *
     * Pemisahan ini penting karena pemilik warung biasanya memberi akses ke
     * karyawan, dan akses itu tidak boleh membawa kemampuan memindahkan uang.
     */
    private function createMerchantStaff(): void
    {
        Schema::create('merchant_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20)->default('staff');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['merchant_id', 'user_id']);
        });

        DB::statement("
            ALTER TABLE merchant_staff ADD CONSTRAINT merchant_staff_role_check
            CHECK (role IN ('owner','manager','staff'))
        ");

        DB::statement('
            CREATE INDEX merchant_staff_user ON merchant_staff (user_id)
            WHERE is_active = true
        ');
    }

    // -------------------------------------------------------------------------

    private function createOperatingHours(): void
    {
        Schema::create('merchant_operating_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('day_of_week');
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->boolean('is_closed')->default(false);

            $table->timestamps();

            $table->unique(['merchant_id', 'day_of_week']);
        });

        DB::statement('
            ALTER TABLE merchant_operating_hours ADD CONSTRAINT merchant_hours_dow_check
            CHECK (day_of_week BETWEEN 0 AND 6)
        ');

        // Hari yang buka wajib punya jam buka dan tutup.
        DB::statement('
            ALTER TABLE merchant_operating_hours ADD CONSTRAINT merchant_hours_shape_check
            CHECK (
                is_closed = true
                OR (open_time IS NOT NULL AND close_time IS NOT NULL)
            )
        ');
    }

    // -------------------------------------------------------------------------

    private function createMenuCategories(): void
    {
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        DB::statement('CREATE INDEX menu_categories_merchant ON menu_categories (merchant_id, sort_order)');
    }

    // -------------------------------------------------------------------------

    private function createMenuItems(): void
    {
        Schema::create('menu_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()
                ->constrained('menu_categories')->nullOnDelete();

            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('photo_url', 500)->nullable();

            $table->bigInteger('price');
            $table->bigInteger('discount_price')->nullable();

            $table->boolean('is_available')->default(true);

            // NULL berarti stok tidak dilacak. Nol berarti habis.
            $table->integer('stock')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('
            ALTER TABLE menu_items ADD CONSTRAINT menu_items_price_check
            CHECK (price >= 0)
        ');

        // Harga diskon harus benar-benar lebih murah. Tanpa ini, "diskon" yang
        // lebih mahal dari harga asli akan tampil sebagai promo di app.
        DB::statement('
            ALTER TABLE menu_items ADD CONSTRAINT menu_items_discount_check
            CHECK (discount_price IS NULL OR (discount_price >= 0 AND discount_price < price))
        ');

        DB::statement('
            ALTER TABLE menu_items ADD CONSTRAINT menu_items_stock_check
            CHECK (stock IS NULL OR stock >= 0)
        ');

        // Menu yang bisa dipesan sekarang.
        DB::statement('
            CREATE INDEX menu_items_orderable ON menu_items (merchant_id, category_id, sort_order)
            WHERE is_available = true AND deleted_at IS NULL
        ');

        DB::statement('CREATE INDEX menu_items_name_trgm ON menu_items USING gin (name gin_trgm_ops)');
    }

    // -------------------------------------------------------------------------

    /**
     * Varian menu: level pedas, ukuran, topping.
     */
    private function createMenuItemOptions(): void
    {
        Schema::create('menu_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();

            $table->string('group_name', 60);
            $table->string('name', 80);
            $table->bigInteger('extra_price')->default(0);

            $table->boolean('is_required')->default(false);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
        });

        DB::statement('
            ALTER TABLE menu_item_options ADD CONSTRAINT menu_item_options_price_check
            CHECK (extra_price >= 0)
        ');

        DB::statement('CREATE INDEX menu_item_options_item ON menu_item_options (menu_item_id, group_name, sort_order)');
    }

    // -------------------------------------------------------------------------

    public function down(): void
    {
        Schema::dropIfExists('menu_item_options');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menu_categories');
        Schema::dropIfExists('merchant_operating_hours');
        Schema::dropIfExists('merchant_staff');
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('merchant_categories');
    }
};
