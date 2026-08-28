@extends('backend.layout.app')

@section('title', 'Penarikan')
@section('page_heading', 'Antrean Penarikan')
@section('page_subheading', 'Terlama menunggu dulu, bukan nominal terbesar dulu')

@section('content')
    <div class="row g-5 mb-6">
        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Menunggu persetujuan</div>
                    <div class="fs-2 fw-bolder text-warning">{{ $statistik['menunggu'] }}</div>
                    @if ($statistik['tertua'])
                        <div class="text-muted fs-8 mt-1">
                            Tertua menunggu
                            <span class="fw-bold text-danger">
                                {{ $statistik['tertua']->diffForHumans(null, true) }}
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Nilai yang menunggu</div>
                    <div class="fs-2 fw-bolder">{{ $statistik['nilai_menunggu']->format() }}</div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body py-4">
                    <div class="text-muted fs-8 text-uppercase fw-semibold">Sedang dikirim</div>
                    <div class="fs-2 fw-bolder text-primary">{{ $statistik['diproses'] }}</div>
                    {{--
                        Penarikan yang menggantung di status ini adalah uang yang sudah
                        dipotong dari saldo driver tapi belum sampai ke rekeningnya —
                        dan itu yang paling cepat memicu keluhan.
                    --}}
                    <div class="text-muted fs-8 mt-1">sudah dipotong, belum sampai</div>
                </div>
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger mb-6">
            @foreach ($errors->all() as $pesan)
                <div>{{ $pesan }}</div>
            @endforeach
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle gy-3 mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Menunggu</th>
                            <th>Pemilik</th>
                            <th>Rekening</th>
                            <th class="text-end">Diminta</th>
                            <th class="text-end">Diterima</th>
                            <th class="pe-6 text-end">Tindakan</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($penarikan as $p)
                            <tr>
                                <td class="ps-6">
                                    @php $lama = $p->created_at->diffInHours(now()); @endphp
                                    <span
                                        class="badge badge-light-{{ $lama > 48 ? 'danger' : ($lama > 12 ? 'warning' : 'secondary') }}">
                                        {{ $p->created_at->diffForHumans(null, true) }}
                                    </span>
                                </td>

                                <td>
                                    <div class="fw-bold">{{ $pemilik[$p->wallet_id] ?? '—' }}</div>
                                    <div class="text-muted fs-9">
                                        {{ $p->wallet?->owner_type }} #{{ $p->wallet?->owner_id }}
                                    </div>
                                </td>

                                <td class="fs-7">
                                    <div class="fw-semibold">{{ $p->bank_name }}</div>
                                    <div class="text-muted">
                                        {{--
                                            Nomor rekening DISAMARKAN di daftar.

                                            Staf finance memeriksa nominal dan pemiliknya,
                                            bukan menyalin nomor rekeningnya. Nomor penuh
                                            hanya bisa dibuka role dengan kyc.view_full, dan
                                            pembukaannya dicatat.
                                        --}}
                                        {{ $p->bankAccountMasked() }}
                                    </div>
                                    <div class="text-muted fs-9">{{ $p->bank_account_name }}</div>
                                </td>

                                <td class="money">{{ $p->amount()->format() }}</td>

                                <td class="money fw-bold">
                                    {{ $p->netAmount()->format() }}
                                    <div class="text-muted fs-9 fw-normal">
                                        biaya
                                        {{ \App\Domain\Shared\ValueObjects\Money::of((int) $p->fee)->format() }}
                                    </div>
                                </td>

                                <td class="pe-6 text-end">
                                    <button type="button" class="btn btn-sm btn-success"
                                        data-bs-toggle="modal" data-bs-target="#modal-setujui-{{ $loop->index }}">
                                        Setujui
                                    </button>

                                    <button type="button" class="btn btn-sm btn-light-danger"
                                        data-bs-toggle="modal" data-bs-target="#modal-tolak-{{ $loop->index }}">
                                        Tolak
                                    </button>
                                </td>
                            </tr>

                            {{-- Modal setujui --}}
                            <div class="modal fade" id="modal-setujui-{{ $loop->index }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <form method="POST"
                                        action="{{ route('admin.finance.withdrawals.approve', $p->uuid) }}">
                                        @csrf
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h3 class="modal-title">Setujui penarikan?</h3>
                                                <button type="button" class="btn-close"
                                                    data-bs-dismiss="modal"></button>
                                            </div>

                                            <div class="modal-body">
                                                {{--
                                                    Dampaknya disebut dengan ANGKA dan NAMA.

                                                    Blueprint admin bagian 12. Staf finance
                                                    yang memproses dua puluh penarikan
                                                    berurutan akan menekan tombol tanpa
                                                    membaca kalau isinya "Anda yakin?".
                                                --}}
                                                <div class="alert alert-warning">
                                                    {{ $p->netAmount()->format() }} akan dikirim ke
                                                    <span class="fw-bold">{{ $p->bank_name }}
                                                        {{ $p->bankAccountMasked() }}</span>
                                                    milik
                                                    <span class="fw-bold">{{ $p->bank_account_name }}</span>.
                                                </div>

                                                <label class="form-label fw-semibold">
                                                    Ketik ulang kata sandi Anda
                                                </label>
                                                <input type="password" name="password"
                                                    class="form-control mb-2" required
                                                    autocomplete="current-password" />
                                                <div class="form-text mb-4">
                                                    {{--
                                                        Kenapa kata sandi diminta lagi.

                                                        Bukan ketidakpercayaan pada stafnya,
                                                        tapi pada komputernya: panel finance
                                                        dibuka di komputer yang ditinggalkan
                                                        tidak terkunci saat makan siang.
                                                    --}}
                                                    Melindungi dari sesi yang ditinggalkan terbuka
                                                    di komputer bersama.
                                                </div>

                                                <label class="form-label fw-semibold fs-7">
                                                    Catatan (opsional)
                                                </label>
                                                <input type="text" name="note" class="form-control form-control-sm" />
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light"
                                                    data-bs-dismiss="modal">Batal</button>
                                                <button type="submit" class="btn btn-success">
                                                    Setujui {{ $p->netAmount()->format() }}
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            {{-- Modal tolak --}}
                            <div class="modal fade" id="modal-tolak-{{ $loop->index }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <form method="POST"
                                        action="{{ route('admin.finance.withdrawals.reject', $p->uuid) }}">
                                        @csrf
                                        <div class="modal-content">
                                            <div class="modal-header">
                                                <h3 class="modal-title">Tolak penarikan?</h3>
                                                <button type="button" class="btn-close"
                                                    data-bs-dismiss="modal"></button>
                                            </div>

                                            <div class="modal-body">
                                                <div class="alert alert-info">
                                                    Saldo {{ $p->amount()->format() }} akan
                                                    <span class="fw-bold">dikembalikan</span> ke dompet
                                                    {{ $pemilik[$p->wallet_id] ?? '—' }}.
                                                </div>

                                                <label class="form-label fw-semibold">
                                                    Ketik ulang kata sandi Anda
                                                </label>
                                                <input type="password" name="password"
                                                    class="form-control mb-4" required
                                                    autocomplete="current-password" />

                                                <label class="form-label fw-semibold">Alasan penolakan</label>
                                                <textarea name="reason" class="form-control" rows="3" required
                                                    minlength="20"
                                                    placeholder="Contoh: nama rekening tidak cocok dengan nama pendaftaran"></textarea>
                                                <div class="form-text">
                                                    Dikirim ke driver. Harus bisa dia tindaklanjuti
                                                    (minimal 20 karakter).
                                                </div>
                                            </div>

                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-light"
                                                    data-bs-dismiss="modal">Batal</button>
                                                <button type="submit" class="btn btn-danger">Tolak</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-15">
                                    <div class="fs-5">Antrean kosong.</div>
                                    <div class="fs-7 mt-1">Tidak ada penarikan yang menunggu persetujuan.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($penarikan->hasPages())
            <div class="card-footer">{{ $penarikan->links() }}</div>
        @endif
    </div>
@endsection
