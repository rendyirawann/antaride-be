@extends('backend.layout.app')

@section('title', 'Kill Switch')
@section('page_heading', 'Kill Switch & Feature Flag')
@section('page_subheading', 'Perubahan di sini berlaku SEKARANG, tanpa deploy')

@section('content')
    @if ($jumlahMati > 0)
        <div class="alert alert-warning mb-6">
            <span class="fw-bold">{{ $jumlahMati }} flag sedang dimatikan.</span>
            Yang mati diurutkan paling atas.
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-row-bordered align-middle mb-0">
                    <thead class="bg-light">
                        <tr class="fw-bold text-muted fs-8 text-uppercase">
                            <th class="ps-6">Flag</th>
                            <th>Keterangan</th>
                            <th>Terakhir diubah</th>
                            <th class="text-center">Keadaan</th>
                            <th class="text-end pe-6">Tindakan</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($flags as $flag)
                            <tr class="{{ $flag->is_enabled ? '' : 'bg-light-danger' }}">
                                <td class="ps-6">
                                    <code class="fs-7">{{ $flag->key }}</code>
                                </td>

                                <td>
                                    <div class="text-gray-700 fs-7">{{ $flag->description }}</div>

                                    @if (! $flag->is_enabled && $flag->last_change_reason)
                                        {{--
                                            Alasan mematikan ditampilkan di baris yang mati.

                                            Ini yang menjawab pertanyaan yang selalu muncul
                                            saat menemukan switch mati: "apakah ini masih
                                            perlu, atau lupa dinyalakan?"
                                        --}}
                                        <div class="text-danger fs-8 mt-1 fst-italic">
                                            "{{ $flag->last_change_reason }}"
                                        </div>
                                    @endif

                                    @if ($flag->isOverdueForRevert())
                                        <div class="mt-1">
                                            <span class="badge badge-light-warning fs-9">
                                                Seharusnya sudah kembali
                                                {{ $flag->auto_revert_at->diffForHumans() }}
                                            </span>
                                        </div>
                                    @endif
                                </td>

                                <td class="text-muted fs-8">
                                    @if ($flag->updated_by_admin_id)
                                        {{ $flag->updatedBy?->name ?? 'admin #' . $flag->updated_by_admin_id }}
                                        <br>
                                        {{ \App\Domain\Shared\Support\BusinessClock::at($flag->updated_at)->format('d/m/Y H:i') }}
                                    @else
                                        <span class="text-muted">belum pernah diubah</span>
                                    @endif
                                </td>

                                <td class="text-center">
                                    @if ($flag->is_enabled)
                                        <span class="badge badge-light-success">menyala</span>
                                    @else
                                        <span class="badge badge-danger">MATI</span>
                                    @endif
                                </td>

                                <td class="text-end pe-6">
                                    @if ($flag->is_enabled)
                                        {{--
                                            Mematikan menuntut alasan, jadi butuh form dengan
                                            input — bukan tombol satu klik.

                                            Gesekan ini disengaja. Kill switch yang bisa
                                            dimatikan dengan satu klik tanpa keterangan akan
                                            dimatikan untuk mencoba-coba, dan yang tersisa
                                            adalah switch mati yang tidak ada yang tahu
                                            kenapa.
                                        --}}
                                        <button type="button" class="btn btn-sm btn-light-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modal-matikan-{{ $loop->index }}">
                                            Matikan
                                        </button>

                                        <div class="modal fade" id="modal-matikan-{{ $loop->index }}" tabindex="-1">
                                            <div class="modal-dialog">
                                                <form method="POST"
                                                    action="{{ route('admin.settings.flags.toggle', $flag->key) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="enabled" value="0">

                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h3 class="modal-title">Matikan {{ $flag->key }}?</h3>
                                                            <button type="button" class="btn-close"
                                                                data-bs-dismiss="modal"></button>
                                                        </div>

                                                        <div class="modal-body">
                                                            <div class="alert alert-warning">
                                                                {{ $flag->description }}
                                                            </div>

                                                            <label class="form-label fw-semibold">
                                                                Alasan mematikan
                                                            </label>
                                                            <textarea name="reason" class="form-control" rows="3"
                                                                required
                                                                placeholder="Contoh: banjir order dari satu IP, sedang diselidiki"></textarea>
                                                            <div class="form-text">
                                                                Orang berikutnya harus bisa menilai apakah
                                                                ini masih perlu.
                                                            </div>
                                                        </div>

                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-light"
                                                                data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-danger">
                                                                Matikan sekarang
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    @else
                                        {{-- Menyalakan kembali tidak menuntut alasan. --}}
                                        <form method="POST"
                                            action="{{ route('admin.settings.flags.toggle', $flag->key) }}"
                                            class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="enabled" value="1">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                Nyalakan
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
