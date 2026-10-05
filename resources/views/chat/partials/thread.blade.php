@php
    $other = $active->other; $ta = $active->program;
    // Prefill kutipan catatan seminar (balas catatan_keterangan): hanya saat
    // komposer masih kosong (tidak menimpa referensi/entri yang sudah dipilih).
    $seminarPrefill = isset($contextSeminar, $contextQuote) && $contextSeminar && $contextQuote && ! $contextEntry && ! old('body');
    $quoteText = $seminarPrefill
        ? collect(explode("\n", \Illuminate\Support\Str::limit($contextQuote, 1000)))->map(fn ($line) => '> '.trim($line))->implode("\n")."\n\n"
        : '';
@endphp
<header class="flex min-w-0 flex-wrap items-center gap-3 border-b border-border p-3 sm:p-4">
    <a href="{{ route('chat.index') }}" class="btn-ghost inline-flex h-9 w-9 items-center justify-center md:hidden" aria-label="Kembali ke daftar percakapan"><span class="material-symbols-outlined icon-sm" aria-hidden="true">arrow_back</span></a>
    <span class="avatar h-10 w-10 shrink-0 overflow-hidden font-bold" aria-hidden="true">@if ($other->photoUrl())<img src="{{ $other->photoUrl() }}" alt="" class="h-full w-full object-cover">@else{{ $other->initials() }}@endif</span>
    <div class="min-w-0 flex-1"><h2 class="truncate text-sm font-bold">{{ $other->name }}</h2>@if ($ta)<p class="truncate text-xs text-text-secondary">{{ $ta->jenisLabel() }} · {{ $user->isDosen() ? $ta->dosenRoleLabel($user) : 'Dosen' }} · {{ $ta->faseLabel() }}</p>@endif @if ($other->nim || $other->nidn)<p class="font-mono text-[11px] text-text-secondary">{{ $other->nim ? 'NIM '.$other->nim : 'NIDN '.$other->nidn }}</p>@endif</div>
    @if ($user->isAdmin() || $user->hasDirectRelation($other))<a href="{{ route('profile.show', $other) }}" class="btn-ghost rounded-control px-2 py-1.5 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lihat Profil</a>@endif
    @if ($ta && $user->isDosen() && $ta->user_id === $other->id)<a href="{{ route($ta->isKp() ? 'mahasiswa-kp.show' : 'mahasiswa-ta.show', $ta) }}" class="btn-ghost rounded-control px-2 py-1.5 text-xs font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lihat Program</a>@endif
</header>
<div id="message-list" class="min-h-0 flex-1 space-y-3 overflow-y-auto overscroll-contain bg-bg-panel/40 p-3 sm:p-5" role="log" aria-label="Riwayat pesan">
    @if ($viewingOlder ?? false)<p class="text-center"><a href="{{ route('chat.show', $conversation) }}" class="text-xs font-semibold text-brand underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Kembali ke pesan terbaru</a></p>@endif
    @if (($hasOlder ?? false) && $messages->isNotEmpty())<p class="text-center"><a href="{{ route('chat.show', ['conversation' => $conversation, 'before' => $messages->first()->id]) }}" class="text-xs font-semibold text-brand underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Lihat pesan sebelumnya</a></p>@endif
    @forelse ($messages as $m)
        @if ($loop->first || !$m->created_at->isSameDay($messages[$loop->index - 1]->created_at))
            <div class="py-2 text-center"><span class="rounded-full border border-border bg-bg-surface px-3 py-1 text-xs text-text-secondary">{{ $m->created_at->isToday() ? 'Hari ini' : ($m->created_at->isYesterday() ? 'Kemarin' : $m->created_at->locale('id')->translatedFormat('d M Y')) }}</span></div>
        @endif
        @php $mine = $m->sender_id === $user->id; @endphp
        <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}" data-message-id="{{ $m->id }}"><div class="max-w-[85%] min-w-0 rounded-control px-3 py-2 text-[15px] leading-relaxed sm:max-w-[75%] {{ $mine ? 'bg-brand-light text-text-primary' : 'border border-border bg-bg-surface text-text-primary' }}">
            <p class="mb-1 text-xs font-semibold {{ $mine ? 'text-brand' : 'text-text-secondary' }}">{{ $mine ? 'Anda' : $m->sender?->name }} · <time datetime="{{ $m->created_at->toIso8601String() }}">{{ $m->created_at->format('H:i') }}</time></p>
            @if (! ($m->attachable_type === \App\Models\WorkspaceFile::class && $m->workspaceFiles->isNotEmpty()))
                @include('chat.partials.attachment', ['m' => $m])
            @endif
            @foreach ($m->workspaceFiles as $uploaded)
                @if ($uploaded->file)
                    <a href="{{ $uploaded->file->isPdf() ? route('workspace.preview', $uploaded->file) : route('workspace.download', $uploaded->file) }}" class="mb-2 flex min-w-0 items-center gap-2 rounded-control border border-border bg-bg-surface/70 p-2 text-xs font-semibold text-text-primary underline decoration-brand hover:bg-bg-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"><span class="material-symbols-outlined icon-sm text-brand" aria-hidden="true">description</span><span class="min-w-0 break-all">{{ $uploaded->original_name }}</span><span class="sr-only">Lihat file di workspace</span></a>
                @else
                    <span class="mb-2 flex min-w-0 items-center gap-2 rounded-control border border-border bg-bg-panel p-2 text-xs text-text-secondary"><span class="material-symbols-outlined icon-sm" aria-hidden="true">draft</span><span class="min-w-0 break-all">{{ $uploaded->original_name }} · File telah dihapus</span></span>
                @endif
            @endforeach
            <p class="message-body whitespace-pre-wrap break-words">{{ $m->body }}</p>
            @if ($mine && $m->isEditable() && $m->body !== '')<button type="button" data-edit="{{ $m->id }}" class="edit-link mt-1 text-xs font-semibold text-brand underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Edit</button>@endif
            @if ($m->edited_at)<span class="ml-1 text-[11px] text-text-secondary">· diedit</span>@endif
        </div></div>
    @empty
        <div class="flex h-full flex-col items-center justify-center text-center text-text-secondary"><span class="material-symbols-outlined text-3xl" aria-hidden="true">chat_bubble_outline</span><p class="mt-2 font-semibold text-text-primary">Belum ada percakapan.</p><p class="mt-1 text-sm">Mulai percakapan dengan mengirim pesan pertama.</p></div>
    @endforelse
</div>
@if ($errors->any())<div class="border-t border-border px-4 py-2 text-sm text-status-danger" role="alert">{{ $errors->first() }}</div>@endif
<div id="attach-panel" class="hidden border-t border-border p-3"><div class="flex items-center justify-between"><p class="text-sm font-semibold">Sematkan referensi karya</p><button type="button" id="attach-close" class="btn-ghost rounded-control p-1" aria-label="Tutup pilihan referensi"><span class="material-symbols-outlined icon-sm" aria-hidden="true">close</span></button></div><div id="attach-list" class="mt-2 max-h-40 space-y-1 overflow-y-auto text-sm"></div></div>
<form id="message-form" method="POST" action="{{ route('chat.store', $conversation) }}" enctype="multipart/form-data" class="flex items-end gap-2 border-t border-border p-3 sm:p-4">@csrf
    <input type="hidden" name="attachable_type" id="attach-type" value="{{ $contextEntry ? 'logbook' : ($seminarPrefill ? 'seminar' : '') }}"><input type="hidden" name="attachable_id" id="attach-id" value="{{ $contextEntry?->id ?? ($seminarPrefill ? $contextSeminar->id : '') }}">
    <button type="button" id="attach-btn" class="btn-ghost rounded-control p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" aria-label="Sematkan referensi karya (bukan unggah file)" title="Sematkan referensi karya yang sudah ada" aria-expanded="false" aria-controls="attach-panel"><span class="material-symbols-outlined icon-sm" aria-hidden="true">bookmark_add</span></button>
    @if ($canUploadFiles)
        <button type="button" id="upload-btn" class="btn-ghost rounded-control p-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand" aria-label="Unggah file baru ke workspace mahasiswa" title="Unggah file baru ke workspace mahasiswa"><span class="material-symbols-outlined icon-sm" aria-hidden="true">upload_file</span></button>
        <input id="chat-files" name="files[]" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx" multiple class="sr-only" aria-label="Pilih file untuk diunggah ke workspace mahasiswa">
    @endif
    <div class="min-w-0 flex-1"><label for="msg-body" class="sr-only">Tulis pesan</label><div id="selected-attach" class="{{ ($contextEntry || $seminarPrefill) ? '' : 'hidden' }} pb-1 text-xs text-brand">@if($contextEntry)Referensi: {{ $contextEntry->jenis === 'revisi' ? 'Revisi r'.$contextEntry->revision_round : 'Entri #'.$contextEntry->sesi_ke }} <button type="button" class="clear-attach underline" aria-label="Hapus referensi terpilih">Hapus</button>@elseif($seminarPrefill)Referensi: Seminar · {{ $contextSeminar->jenisLabel() }} <button type="button" class="clear-attach underline" aria-label="Hapus referensi terpilih">Hapus</button>@endif</div><div id="selected-files" class="hidden pb-2 text-xs text-text-secondary" role="status" aria-live="polite"></div><textarea id="msg-body" name="body" maxlength="5000" rows="1" placeholder="Tulis pesan..." class="block w-full resize-none rounded-control border border-border bg-bg-panel px-3 py-2 text-sm text-text-primary placeholder:text-text-secondary focus:outline-none focus:ring-2 focus:ring-brand/50">{{ old('body', $quoteText) }}</textarea></div>
    <button id="send-btn" type="submit" class="btn-primary rounded-control px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand">Kirim</button>
</form>
<div id="edit-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-bg-base/80 p-4" role="dialog" aria-modal="true" aria-labelledby="edit-title"><div class="card w-full max-w-md p-4"><h3 id="edit-title" class="mb-3 font-semibold">Edit Pesan</h3><form method="POST" id="edit-form">@csrf @method('PUT')<label for="edit-body" class="sr-only">Isi pesan</label><textarea id="edit-body" name="body" required maxlength="5000" rows="3" class="w-full rounded-control border border-border bg-bg-panel p-3 text-sm"></textarea><div class="mt-3 flex justify-end gap-2"><button type="button" id="edit-cancel" class="btn-secondary px-3 py-2 text-sm">Batal</button><button class="btn-primary px-3 py-2 text-sm">Simpan</button></div></form></div></div>