<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Contracts\ZoneResolver;
use App\Domain\Catalog\Models\PricingRule;
use App\Domain\Driver\Models\Driver;
use App\Domain\Driver\Models\DriverDocument;
use App\Domain\Identity\Models\Admin;
use App\Domain\Identity\Models\User;
use App\Domain\Matching\Contracts\DriverLocationIndex;
use App\Domain\Merchant\Models\Merchant;
use App\Domain\Ordering\Contracts\OrderLock;
use App\Domain\Ordering\Models\Order;
use App\Domain\Pricing\Contracts\QuoteStore;
use App\Domain\Pricing\Contracts\RoutingService;
use App\Domain\Promo\Models\Promo;
use App\Domain\Shared\Contracts\RealtimePublisher;
use App\Domain\Shared\Contracts\SmsSender;
use App\Domain\Support\Models\FeatureFlag;
use App\Domain\Wallet\Models\Topup;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\Withdrawal;
use App\Infrastructure\Geo\NativeZoneResolver;
use App\Infrastructure\Geo\PostGisZoneResolver;
use App\Infrastructure\Realtime\CentrifugoPublisher;
use App\Infrastructure\Redis\Geo\RedisDriverLocationIndex;
use App\Infrastructure\Redis\Locks\RedisOrderLock;
use App\Infrastructure\Redis\Stores\RedisQuoteStore;
use App\Infrastructure\Routing\OsrmRoutingService;
use App\Infrastructure\Sms\LogSmsSender;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Tempat Domain disambungkan ke Infrastructure.
 *
 * Ini satu-satunya file yang tahu bahwa ZoneResolver kebetulan diimplementasikan
 * dengan PostGIS, dan bahwa DriverLocationIndex kebetulan memakai Redis. Kode
 * di app/Domain hanya melihat interface-nya.
 *
 * Manfaat nyatanya bukan teori: Redis 5.0 di Windows tidak punya GEOSEARCH,
 * dan produksi nanti memakai Redis 7 yang punya. Perbedaan itu selesai di satu
 * adapter, bukan menyebar ke matching engine.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bindGeo();
        $this->bindInfrastructure();
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureMorphMap();
    }

    // -------------------------------------------------------------------------
    // Binding
    // -------------------------------------------------------------------------

    /**
     * Resolusi zona punya dua implementasi.
     *
     * postgis  ST_Contains dengan index GiST. Yang dipakai di produksi.
     * native   ray-casting di PHP dengan polygon zona di-cache di Redis.
     *          Ada supaya pengembangan tidak terhalang saat ekstensi PostGIS
     *          belum terpasang, dan supaya test unit bisa jalan tanpa PostGIS.
     *
     * Keduanya wajib memberi jawaban yang sama untuk titik yang sama. Ada test
     * kontrak yang membandingkan keduanya pada polygon zona sungguhan.
     */
    private function bindGeo(): void
    {
        $this->app->singleton(ZoneResolver::class, function ($app) {
            return match (config('antaride.geo.zone_driver', 'postgis')) {
                'native' => $app->make(NativeZoneResolver::class),
                default => $app->make(PostGisZoneResolver::class),
            };
        });
    }

    private function bindInfrastructure(): void
    {
        $this->app->singleton(DriverLocationIndex::class, RedisDriverLocationIndex::class);
        $this->app->singleton(OrderLock::class, RedisOrderLock::class);
        $this->app->singleton(QuoteStore::class, RedisQuoteStore::class);
        $this->app->singleton(RoutingService::class, OsrmRoutingService::class);
        $this->app->singleton(RealtimePublisher::class, CentrifugoPublisher::class);

        /*
         * SMS: bawaannya menulis ke log.
         *
         * Provider sungguhan belum dipilih, dan itu keputusan bisnis yang bisa
         * memakan waktu. Binding ini membuat seluruh alur autentikasi lengkap
         * dan bisa diuji sejak sekarang; menggantinya nanti cukup menukar satu
         * baris di sini, tanpa menyentuh satu pun Action.
         *
         * Kalau nanti sudah ada provider, `SMS_DRIVER` yang memilihnya, dan
         * `LogSmsSender` tetap dipakai untuk environment test.
         */
        $this->app->singleton(SmsSender::class, function (): SmsSender {
            return match ((string) config('antaride.sms.driver', 'log')) {
                default => new LogSmsSender,
            };
        });
    }

    // -------------------------------------------------------------------------
    // Konfigurasi Eloquent
    // -------------------------------------------------------------------------

    private function configureModels(): void
    {
        /*
         * Strict mode aktif di luar produksi.
         *
         * preventLazyLoading yang paling berharga di sini. Halaman daftar order
         * di panel admin yang lazy-load relasi driver akan menghasilkan N+1
         * query, dan gejalanya baru muncul saat tabelnya sudah besar. Dengan
         * ini, N+1 gagal keras saat pengembangan, bukan melambat perlahan
         * setelah enam bulan.
         *
         * Di produksi tidak dilempar exception, karena satu relasi yang lupa
         * di-eager-load tidak boleh menjatuhkan penerimaan order.
         */
        $strict = ! $this->app->isProduction();

        Model::preventLazyLoading($strict);
        Model::preventSilentlyDiscardingAttributes($strict);
        Model::preventAccessingMissingAttributes($strict);

        // Kolom uang selalu BIGINT Rupiah utuh. Tidak ada float, tidak pernah.
        // Selisih satu rupiah yang tidak bisa dijelaskan akan muncul, dan
        // mencarinya memakan berhari-hari.
        Model::unguard(false);
    }

    /**
     * Morph map eksplisit untuk relasi polimorfik.
     *
     * Tanpa ini, kolom owner_type di tabel wallets akan menyimpan nama class
     * lengkap seperti "App\Domain\Identity\Models\User". Konsekuensinya: satu
     * kali refactor namespace, dan seluruh riwayat ledger jadi tidak bisa
     * di-resolve. Ledger itu append-only dan tidak boleh disentuh, jadi
     * memperbaikinya berarti UPDATE massal pada tabel yang justru paling tidak
     * boleh di-UPDATE.
     *
     * Nilai-nilai di bawah ini adalah bagian dari kontrak data. Setelah ada
     * baris di produksi, jangan pernah diubah.
     */
    private function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'admin' => Admin::class,
            'driver' => Driver::class,
            'merchant' => Merchant::class,
            'order' => Order::class,
            'topup' => Topup::class,
            'withdrawal' => Withdrawal::class,
            'promo' => Promo::class,

            /*
             * Empat model di bawah masuk daftar ini karena AUDIT LOG.
             *
             * `audit_logs.auditable_type` polimorfik, dan `enforceMorphMap`
             * menolak model yang tidak terdaftar dengan
             * ClassMorphViolationException. Konsekuensinya: setiap pencatatan
             * audit untuk model yang belum ada di sini GAGAL — dan yang gagal
             * bukan hanya barisnya, tapi seluruh request yang mencoba
             * mencatatnya.
             *
             * Yang membuatnya berbahaya: `enforceMorphMap` justru dipasang untuk
             * melindungi ledger dari refactor namespace, dan efek sampingnya
             * adalah setiap tindakan admin pada model baru menjadi 500. Bug itu
             * hanya muncul saat tindakan itu benar-benar dilakukan — misalnya
             * saat ada insiden dan seseorang menekan kill switch.
             *
             * Aturan yang harus dipegang: model apa pun yang bisa muncul di
             * `AuditLog::record(auditable: ...)` WAJIB ada di daftar ini.
             */
            'driver_document' => DriverDocument::class,
            'pricing_rule' => PricingRule::class,
            'feature_flag' => FeatureFlag::class,
            'wallet' => Wallet::class,
        ]);
    }
}
