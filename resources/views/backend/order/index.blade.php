@extends('backend.layout.app')

@section('title', 'Order')
@section('page_heading', 'Daftar Order')

@section('content')
    <div class="card">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Filter</h3>
        </div>

        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-3">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Cari</label>
                    <input type="text" id="f-cari" class="form-control form-control-sm"
                        placeholder="Nomor order, nama, atau HP" />
                </div>

                <div class="col-md-2">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach ($statusPilihan as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Layanan</label>
                    <select id="f-layanan" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach ($layanan as $l)
                            <option value="{{ $l->id }}">{{ $l->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Zona</label>
                    <select id="f-zona" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        @foreach ($zona as $z)
                            <option value="{{ $z->id }}">{{ $z->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fs-8 fw-semibold text-muted text-uppercase">Tanggal (WIB)</label>
                    <div class="d-flex gap-2">
                        <input type="date" id="f-dari" class="form-control form-control-sm" />
                        <input type="date" id="f-sampai" class="form-control form-control-sm" />
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex align-items-center gap-4">
                <label class="form-check form-check-sm form-check-custom">
                    <input class="form-check-input" type="checkbox" id="f-review" />
                    <span class="form-check-label fw-semibold text-gray-700">
                        Hanya yang perlu review ongkos
                    </span>
                </label>

                <button type="button" class="btn btn-sm btn-light" id="f-reset">Reset filter</button>
            </div>
        </div>
    </div>

    <div class="card mt-6">
        <div class="card-body">
            <table class="table table-row-bordered align-middle gy-3" id="tabel-order">
                <thead>
                    <tr class="fw-bold text-muted fs-8 text-uppercase">
                        <th>Nomor</th>
                        <th>Waktu</th>
                        <th>Status</th>
                        <th>Layanan</th>
                        <th>Zona</th>
                        <th>Penumpang</th>
                        <th>Driver</th>
                        <th>Pembayaran</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            /*
                DataTables server-side.

                `serverSide: true` dan `searching: false` sengaja dipasangkan:
                pencarian bawaan DataTables mengirim parameter `search[value]`
                pada setiap ketikan, dan tanpa debounce itu berarti satu query
                per huruf. Kolom pencarian sendiri yang disediakan di atas,
                dengan jeda 400 ms.
            */
            const tabel = new DataTable('#tabel-order', {
                serverSide: true,
                processing: true,
                searching: false,
                ordering: false,
                pageLength: 25,
                lengthMenu: [25, 50, 100],

                ajax: {
                    url: @json(route('admin.orders.data')),
                    data: function (d) {
                        d.cari = document.getElementById('f-cari').value;
                        d.status = document.getElementById('f-status').value;
                        d.service_type_id = document.getElementById('f-layanan').value;
                        d.zone_id = document.getElementById('f-zona').value;
                        d.dari = document.getElementById('f-dari').value;
                        d.sampai = document.getElementById('f-sampai').value;
                        d.needs_review = document.getElementById('f-review').checked ? 1 : 0;
                    },
                },

                language: {
                    processing: 'Memuat...',
                    zeroRecords: 'Tidak ada order yang cocok.',
                    info: 'Menampilkan _START_ sampai _END_',
                    infoEmpty: 'Tidak ada data',
                    paginate: { first: 'Awal', last: 'Akhir', next: 'Berikutnya', previous: 'Sebelumnya' },
                    lengthMenu: 'Tampilkan _MENU_ baris',
                },

                columns: [
                    {
                        data: 'nomor',
                        render: function (data, type, row) {
                            let html = '<span class="fw-bold">' + data + '</span>';

                            if (row.perlu_review) {
                                // Order yang perlu review ongkos ditandai di
                                // DAFTAR, bukan hanya di halaman detailnya.
                                // Kalau hanya di detail, barisnya harus dibuka
                                // satu per satu untuk menemukannya.
                                html += ' <span class="badge badge-light-warning fs-9">review</span>';
                            }

                            return html;
                        },
                    },
                    { data: 'waktu' },
                    {
                        data: 'status_label',
                        render: (data, type, row) =>
                            '<span class="badge ' + row.status_badge + '">' + data + '</span>',
                    },
                    { data: 'layanan' },
                    { data: 'zona' },
                    { data: 'penumpang' },
                    { data: 'driver' },
                    { data: 'pembayaran', className: 'fs-8 text-muted' },
                    { data: 'total', className: 'money' },
                    {
                        data: 'uuid',
                        className: 'text-end',
                        render: (data) =>
                            '<a href="' + @json(url(config('antaride.routing.admin_prefix') . '/orders')) +
                            '/' + data + '" class="btn btn-sm btn-light-primary">Buka</a>',
                    },
                ],
            });

            // Jeda sebelum memuat ulang, supaya mengetik tidak menghasilkan
            // satu query per huruf.
            let timer = null;

            document.getElementById('f-cari').addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(() => tabel.ajax.reload(), 400);
            });

            ['f-status', 'f-layanan', 'f-zona', 'f-dari', 'f-sampai', 'f-review'].forEach(function (id) {
                document.getElementById(id).addEventListener('change', () => tabel.ajax.reload());
            });

            document.getElementById('f-reset').addEventListener('click', function () {
                ['f-cari', 'f-status', 'f-layanan', 'f-zona', 'f-dari', 'f-sampai'].forEach(function (id) {
                    document.getElementById(id).value = '';
                });
                document.getElementById('f-review').checked = false;
                tabel.ajax.reload();
            });
        });
    </script>
@endpush
