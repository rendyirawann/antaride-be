<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\User;
use App\Infrastructure\Geo\NominatimPlaceSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Area layanan dan pencarian alamat.
 *
 * ============================================================================
 *  YANG DIJAGA BERKAS INI
 * ============================================================================
 *  Pencarian alamat adalah KEMUDAHAN, bukan syarat memesan: pengguna tetap
 *  bisa menggeser peta untuk memilih titik. Karena itu bentuk kegagalannya
 *  sama pentingnya dengan bentuk keberhasilannya — geocoder yang mati harus
 *  menghasilkan daftar kosong, BUKAN galat yang membuat layar pemilih rute
 *  menampilkan pesan merah dan menghentikan pemesanan.
 * ============================================================================
 */
class PlacesAndConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    // -------------------------------------------------------------------------
    //  Konfigurasi area
    // -------------------------------------------------------------------------

    public function test_konfigurasi_terbuka_tanpa_login(): void
    {
        // Aplikasi membutuhkannya SEBELUM ada sesi — layar sambutan dan pemilih
        // rute bisa terbuka sebelum pengguna masuk.
        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.area.lat', (float) config('antaride.area.lat'))
            ->assertJsonPath('data.area.lng', (float) config('antaride.area.lng'))
            ->assertJsonStructure([
                'data' => [
                    'area' => ['lat', 'lng', 'radius_km', 'zoom', 'label'],
                    'places_enabled',
                ],
            ]);
    }

    public function test_area_mengikuti_konfigurasi_bukan_angka_tertanam(): void
    {
        /*
         * Inti dari endpoint ini: area digeser lewat .env, tanpa membangun ulang
         * APK. Kalau nilainya tertanam di kode, seluruh alasan endpoint ini ada
         * ikut hilang — dan yang menemukannya adalah pengguna yang petanya
         * membuka kota yang salah.
         */
        config(['antaride.area.lat' => 1.234, 'antaride.area.lng' => 5.678]);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.area.lat', 1.234)
            ->assertJsonPath('data.area.lng', 5.678);
    }

    public function test_pencarian_alamat_mati_secara_bawaan(): void
    {
        // Fail closed: server yang belum memasang Nominatim melaporkan fiturnya
        // mati, jadi aplikasi menyembunyikan kolom pencarian alih-alih
        // menampilkan kolom yang tidak pernah menemukan apa pun.
        config(['services.nominatim.enabled' => false]);

        $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('data.places_enabled', false);
    }

    // -------------------------------------------------------------------------
    //  Pencarian alamat
    // -------------------------------------------------------------------------

    public function test_pencarian_alamat_butuh_login(): void
    {
        // Yang dijaga bukan datanya — alamat bukan rahasia — melainkan kuota
        // geocoder di belakangnya.
        $this->getJson('/api/v1/places/search?q=lubuk pakam')->assertUnauthorized();
        $this->getJson('/api/v1/places/reverse?lat=3.5&lng=98.8')->assertUnauthorized();
    }

    public function test_geocoder_mati_menghasilkan_daftar_kosong_bukan_galat(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Persis keadaan server yang belum memasang Nominatim.
        Http::fake(fn () => throw new \RuntimeException('Connection refused'));

        $this->getJson('/api/v1/places/search?q=lubuk pakam')
            ->assertOk()
            ->assertJsonPath('data.places', []);

        $this->getJson('/api/v1/places/reverse?lat=3.5497&lng=98.8756')
            ->assertOk()
            ->assertJsonPath('data.place', null);
    }

    public function test_hasil_nominatim_dipetakan_jadi_nama_dan_alamat_terpisah(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            '*/search*' => Http::response([[
                'name' => 'Stasiun Lubuk Pakam',
                'display_name' => 'Stasiun Lubuk Pakam, Jalan Stasiun, Lubuk Pakam, Deli Serdang',
                'lat' => '3.5601',
                'lon' => '98.8712',
                'address' => ['road' => 'Jalan Stasiun'],
            ]]),
        ]);

        /*
         * Nama pendek DIPISAH dari alamat lengkap, dan itu bukan hiasan.
         *
         * Daftar saran menampilkan keduanya bertingkat: nama tebal di atas,
         * alamat kecil di bawahnya. Kalau digabung jadi satu kalimat panjang,
         * pemotongan "..." jatuh tepat di nama tempatnya — bagian yang justru
         * dicari pembaca.
         */
        $this->getJson('/api/v1/places/search?q=stasiun')
            ->assertOk()
            ->assertJsonPath('data.places.0.name', 'Stasiun Lubuk Pakam')
            ->assertJsonPath('data.places.0.lat', 3.5601)
            ->assertJsonPath('data.places.0.lng', 98.8712)
            ->assertJsonPath(
                'data.places.0.address',
                'Stasiun Lubuk Pakam, Jalan Stasiun, Lubuk Pakam, Deli Serdang',
            );
    }

    public function test_kata_kunci_terlalu_pendek_tidak_memanggil_geocoder(): void
    {
        Sanctum::actingAs(User::factory()->create());

        Http::fake();

        // Dua huruf mengembalikan setengah kota dan tidak berguna — memanggil
        // geocoder untuknya hanya menghabiskan kuota pada setiap ketikan
        // pertama setiap pengguna.
        $this->getJson('/api/v1/places/search?q=lu')
            ->assertOk()
            ->assertJsonPath('data.places', []);

        Http::assertNothingSent();
    }

    public function test_pencarian_dibatasi_area_layanan(): void
    {
        Sanctum::actingAs(User::factory()->create());

        config([
            'antaride.area.lat' => 3.5697,
            'antaride.area.lng' => 98.7748,
            'antaride.area.radius_km' => 35,
        ]);

        Http::fake(['*' => Http::response([])]);

        $this->getJson('/api/v1/places/search?q=jalan merdeka')->assertOk();

        /*
         * `bounded=1` MEMBUANG hasil di luar kotak, bukan sekadar menurunkan
         * peringkatnya. Tanpa itu, "jalan merdeka" mengembalikan Jalan Merdeka
         * di seluruh Indonesia — dan yang teratas belum tentu yang di kota ini.
         */
        Http::assertSent(function ($request): bool {
            $q = $request->data();

            if (($q['bounded'] ?? null) != 1) {
                return false;
            }

            // Kotak harus melingkupi titik tengah area.
            [$kiri, $atas, $kanan, $bawah] = array_map(
                'floatval',
                explode(',', (string) ($q['viewbox'] ?? ',,,')),
            );

            return $kiri < 98.7748 && $kanan > 98.7748
                && $bawah < 3.5697 && $atas > 3.5697;
        });
    }

    public function test_jawaban_kosong_hanya_disimpan_lima_menit(): void
    {
        /*
         * Kalau geocoder sedang mati, setiap pencarian mengembalikan kosong.
         * Menyimpannya selama masa cache penuh (tiga hari) berarti fiturnya
         * tetap mati berjam-jam SETELAH geocoder-nya hidup lagi — kegagalan
         * sementara berubah menjadi kegagalan panjang tanpa ada yang
         * menyadarinya, karena tidak ada satu pun galat yang tercatat lagi.
         *
         * Yang diperiksa di sini KEPUTUSAN TTL-nya secara langsung. Memeriksa
         * lewat jam dinding menuntut test menunggu lima menit.
         */
        config(['services.nominatim.cache_hours' => 72]);

        Http::fake(fn () => throw new \RuntimeException('mati'));

        Cache::shouldReceive('get')->once()->andReturn(null);

        Cache::shouldReceive('put')->once()->withArgs(
            fn (string $kunci, mixed $nilai, int $detik): bool => $nilai === []
                && $detik === 300,
        );

        app(NominatimPlaceSearch::class)->search('lubuk pakam');
    }

    public function test_jawaban_berisi_disimpan_penuh(): void
    {
        config(['services.nominatim.cache_hours' => 72]);

        Http::fake([
            '*/search*' => Http::response([[
                'name' => 'Lubuk Pakam',
                'display_name' => 'Lubuk Pakam, Deli Serdang',
                'lat' => '3.56',
                'lon' => '98.87',
            ]]),
        ]);

        Cache::shouldReceive('get')->once()->andReturn(null);

        // Alamat jarang berubah, dan hasil yang sama diminta berulang kali oleh
        // orang berbeda di kota yang sama — itu yang membuat cache panjang di
        // sini menghemat kuota geocoder, bukan menyembunyikan data basi.
        Cache::shouldReceive('put')->once()->withArgs(
            fn (string $kunci, mixed $nilai, int $detik): bool => is_array($nilai)
                && count($nilai) === 1
                && $detik === 72 * 3600,
        );

        $hasil = app(NominatimPlaceSearch::class)->search('lubuk pakam');

        $this->assertSame('Lubuk Pakam', $hasil[0]['name']);
    }
}
