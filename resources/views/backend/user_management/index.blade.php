@extends('backend.layout.app')

@section('title', 'Staf & Role')
@section('page_heading', 'Staf & Role')
@section('page_subheading', 'Role menentukan permission. Permission ditegakkan di route, bukan di tombol')

@section('content')
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Nama</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>2FA</th>
                            <th class="pe-6">Masuk terakhir</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($staf as $s)
                            <tr>
                                <td class="ps-6 fw-bold">{{ $s->name }}</td>
                                <td class="fs-7 text-muted">{{ $s->email }}</td>

                                <td>
                                    @forelse ($s->roles as $role)
                                        <span class="badge badge-light-primary me-1">{{ $role->name }}</span>
                                    @empty
                                        {{--
                                            Staf tanpa role ditandai MERAH.

                                            Akun tanpa role tidak bisa membuka apa pun di
                                            panel — setiap halaman akan menolaknya. Yang
                                            terlihat dari sisi orangnya: panel yang rusak.
                                            Tanpa penanda ini, penyebabnya butuh waktu lama
                                            untuk ditemukan.
                                        --}}
                                        <span class="badge badge-light-danger">tanpa role</span>
                                    @endforelse
                                </td>

                                <td>
                                    <span class="badge {{ $s->status->badgeClass() }}">
                                        {{ $s->status->label() }}
                                    </span>
                                </td>

                                <td>
                                    @if ($s->two_factor_confirmed_at)
                                        <span class="badge badge-light-success">aktif</span>
                                    @else
                                        {{--
                                            2FA yang belum aktif ditandai MERAH, bukan
                                            netral.

                                            Ini satu-satunya hal yang membuat akun admin
                                            bisa dibobol hanya dengan kata sandi. Untuk role
                                            finance dan superadmin, itu berarti seluruh uang
                                            platform bergantung pada satu kata sandi.
                                        --}}
                                        <span class="badge badge-danger">belum</span>
                                    @endif
                                </td>

                                <td class="pe-6 fs-8 text-muted">
                                    @if ($s->last_login_at)
                                        {{ \App\Domain\Shared\Support\BusinessClock::at($s->last_login_at)->format('d/m/y H:i') }}
                                        <div class="fs-9">{{ $s->last_login_ip }}</div>
                                    @else
                                        belum pernah masuk
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-10">
                                    Belum ada staf.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($staf->hasPages())
            <div class="card-footer">{{ $staf->links() }}</div>
        @endif
    </div>

    <div class="card mt-6">
        <div class="card-header min-h-45px">
            <h3 class="card-title fw-bold">Menambah dan mengubah staf</h3>
        </div>
        <div class="card-body text-gray-700">
            <p>
                Pembuatan akun staf dan pengubahan role belum ada di panel, dan itu
                keputusan sadar untuk Fase 1: dengan delapan akun, jalur lewat seeder atau
                tinker lebih aman daripada form yang belum punya pengaman lengkap.
            </p>
            <p class="mb-0">
                Yang dibutuhkan sebelum form itu ada: persetujuan dua penyetuju untuk
                pemberian role finance dan superadmin, dan pemberitahuan ke seluruh
                superadmin setiap kali role berubah. Tanpa keduanya, satu akun ops yang
                bobol cukup untuk memberi dirinya wewenang penuh.
            </p>
        </div>
    </div>
@endsection
