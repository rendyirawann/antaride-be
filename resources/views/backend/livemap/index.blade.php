@extends('backend.layout.app')

@section('title', 'Live Map')
@section('page_heading', 'Live Map')
@section('page_subheading', 'Driver dan order yang sedang berjalan')

@push('stylesheets')
    {{--
        Leaflet dari CDN.

        Dipilih Leaflet, bukan Google Maps: panel ini dibuka staf ops sepanjang
        hari dengan refresh tiap lima detik, dan Google Maps menagih per pemuatan
        peta. Leaflet dengan tile OpenStreetMap tidak menagih apa pun, dan untuk
        menampilkan titik di peta kota, keduanya sama baik.
    --}}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        #peta {
            height: calc(100vh - 320px);
            min-height: 480px;
            border-radius: .625rem;
        }
    </style>
@endpush

@section('content')
    <div class="row g-5 mb-5">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Driver terlihat</div>
                    <div class="fs-2 fw-bolder" id="stat-driver">—</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Order berjalan</div>
                    <div class="fs-2 fw-bolder" id="stat-order">—</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Macet cari driver</div>
                    <div class="fs-2 fw-bolder text-danger" id="stat-macet">—</div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-4">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase mb-1">Layanan</label>
                    <select id="f-layanan" class="form-select form-select-sm">
                        <option value="">Semua layanan</option>
                        @foreach ($layanan as $l)
                            <option value="{{ $l->code }}">{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Peta</h3>
            <div class="card-toolbar gap-3 align-items-center">
                <span class="text-muted fs-8" id="waktu-muat">memuat...</span>

                <label class="form-check form-check-sm form-check-custom mb-0">
                    <input class="form-check-input" type="checkbox" id="f-auto" checked />
                    <span class="form-check-label fw-semibold text-gray-700 fs-8">
                        Muat ulang otomatis
                    </span>
                </label>
            </div>
        </div>

        <div class="card-body">
            <div id="peta"></div>

            <div class="d-flex flex-wrap gap-4 mt-4 fs-8 text-muted">
                <span><span class="badge badge-circle badge-success w-10px h-10px"></span> driver tersedia</span>
                <span><span class="badge badge-circle badge-primary w-10px h-10px"></span> order berjalan</span>
                <span><span class="badge badge-circle badge-danger w-10px h-10px"></span> macet cari driver</span>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const pusat = @json($pusat);
            const intervalMs = @json($intervalRefreshMs);
            const urlData = @json(route('admin.livemap.data'));
            const urlOrder = @json(url(config('antaride.routing.admin_prefix') . '/orders'));
            const zona = @json($zona);

            const peta = L.map('peta').setView([pusat.lat, pusat.lng], pusat.zoom);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(peta);

            // Batas zona digambar sekali; dia tidak berubah antar refresh.
            zona.forEach(function (z) {
                if (!z.polygon_geojson) {
                    return;
                }

                try {
                    L.geoJSON(JSON.parse(z.polygon_geojson), {
                        style: { color: '#a1a5b7', weight: 1, fillOpacity: 0.03 },
                    }).addTo(peta).bindTooltip(z.name, { sticky: true });
                } catch (e) {
                    // Zona dengan GeoJSON rusak diabaikan, bukan menjatuhkan
                    // seluruh peta. Yang hilang satu batas zona; yang
                    // dipertahankan adalah peta yang tetap bisa dipakai.
                }
            });

            const lapisanDriver = L.layerGroup().addTo(peta);
            const lapisanOrder = L.layerGroup().addTo(peta);

            function muat() {
                const batas = peta.getBounds();

                const params = new URLSearchParams({
                    sw_lat: batas.getSouth(),
                    sw_lng: batas.getWest(),
                    ne_lat: batas.getNorth(),
                    ne_lng: batas.getEast(),
                });

                const layanan = document.getElementById('f-layanan').value;

                if (layanan) {
                    params.append('service_code', layanan);
                }

                fetch(urlData + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                })
                    .then((r) => r.json())
                    .then(gambar)
                    .catch(function () {
                        document.getElementById('waktu-muat').textContent = 'gagal memuat';
                    });
            }

            function gambar(data) {
                lapisanDriver.clearLayers();
                lapisanOrder.clearLayers();

                let jumlahDriver = 0;

                if (data.driver.mode === 'cluster') {
                    /*
                        Di atas batas marker, yang digambar adalah agregat per grid.

                        Lima ratus marker berarti lima ratus elemen DOM yang
                        diperbarui tiap lima detik — bedanya antara 60 fps dan 4 fps,
                        dan pada 4 fps peta ini tidak bisa dipakai untuk apa pun.
                    */
                    data.driver.grid.forEach(function (kotak) {
                        jumlahDriver += kotak.jumlah;

                        L.circleMarker([kotak.lat, kotak.lng], {
                            radius: Math.min(30, 8 + Math.sqrt(kotak.jumlah) * 3),
                            color: '#50cd89',
                            fillColor: '#50cd89',
                            fillOpacity: 0.5,
                            weight: 2,
                        })
                            .bindTooltip(kotak.jumlah + ' driver', { permanent: true, direction: 'center' })
                            .addTo(lapisanDriver);
                    });
                } else {
                    data.driver.items.forEach(function (d) {
                        jumlahDriver++;

                        L.circleMarker([d.lat, d.lng], {
                            radius: 5,
                            color: d.kualitas_rendah ? '#ffc700' : '#50cd89',
                            fillColor: d.kualitas_rendah ? '#ffc700' : '#50cd89',
                            fillOpacity: 0.9,
                            weight: 1,
                        })
                            .bindPopup(
                                'Driver #' + d.driver_id +
                                (d.usia_detik !== null ? '<br>ping ' + d.usia_detik + ' detik lalu' : '') +
                                (d.kualitas_rendah ? '<br><b>akurasi GPS rendah</b>' : '')
                            )
                            .addTo(lapisanDriver);
                    });
                }

                let jumlahMacet = 0;

                data.order.forEach(function (o) {
                    if (o.macet) {
                        jumlahMacet++;
                    }

                    L.circleMarker([o.lat, o.lng], {
                        radius: o.macet ? 9 : 6,
                        color: o.macet ? '#f1416c' : '#009ef7',
                        fillColor: o.macet ? '#f1416c' : '#009ef7',
                        fillOpacity: 0.85,
                        weight: 2,
                    })
                        .bindPopup(
                            '<b>' + o.nomor + '</b><br>' + o.label +
                            '<br>menunggu ' + Math.round(o.menunggu_detik / 60) + ' menit' +
                            '<br><a href="' + urlOrder + '/' + o.uuid + '">Buka order</a>'
                        )
                        .addTo(lapisanOrder);
                });

                document.getElementById('stat-driver').textContent = jumlahDriver;
                document.getElementById('stat-order').textContent = data.order.length;
                document.getElementById('stat-macet').textContent = jumlahMacet;
                document.getElementById('waktu-muat').textContent =
                    'dimuat ' + new Date().toLocaleTimeString('id-ID');
            }

            muat();

            let timer = setInterval(function () {
                if (document.getElementById('f-auto').checked) {
                    muat();
                }
            }, intervalMs);

            // Menggeser peta memuat data untuk kotak pandang yang baru. Tanpa
            // ini, staf yang menggeser ke daerah lain melihat peta kosong sampai
            // refresh berikutnya.
            peta.on('moveend', muat);

            document.getElementById('f-layanan').addEventListener('change', muat);
        });
    </script>
@endpush
