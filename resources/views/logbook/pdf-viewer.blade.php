@extends("layouts.focus") @section("title", "Viewer PDF & Anotasi") @section("head") @vite(["resources/js/pdf-viewer.jsx"])
@endsection @section("content")
<div id="pdf-viewer-root" class="h-full"></div>
@endsection @section("scripts")
<script>
    window.PDF_VIEWER_DATA = {
        title: @json($logbook->jenis === "revisi" ? "Revisi" : "Sesi " . $logbook->sesi_ke),
        draftUrl: @if ($logbook->lampiran_path)
            @json(route("logbook.pdf", $logbook))
        @else
            null
        @endif ,
        catatanUrl: @if ($logbook->catatan_perbaikan_path)
            @json(route("logbook.catatan-pdf", $logbook))
        @else
            null
        @endif ,
        hasCatatan: @json($logbook->catatan_perbaikan_path ? true : false),
        entryId: @json($logbook->id),
        csrf: @json(csrf_token()),
        commentsUrl: @json(route("logbook.pdf.comments", $logbook)),
        storeUrl: @json(route("logbook.pdf.store-comment", $logbook)),
        resolveUrl: @json(url("/pdf-comments/{id}/resolve")),
        replyUrl: @json(url("/pdf-comments/{id}/reply")),
        deleteUrl: @json(url("/pdf-comments/{id}")),
        canReply: @json($logbook->mahasiswaTa?->user_id === auth()->user()->id),
        currentUserId: @json(auth()->id()),
        burnUrl: @json(route("logbook.pdf.burn", ["logbook" => $logbook, "type" => "__TYPE__"])),
        buildFeedbackUrl: @if (auth()->user()->can('review', $logbook))
            @json(route("quick-review.build-feedback", $logbook))
        @else
            null
        @endif ,
        canReview: @json(auth()->user()->can('review', $logbook)),
        canDiscuss: @json($logbook->mahasiswaTa?->user_id === auth()->id() || auth()->user()->can('isReviewer', $logbook)),
        isOwner: @json(auth()->user()->isMahasiswa() && $logbook->mahasiswaTa?->isMember(auth()->user())),
        canAnnotate: @json(auth()->user()->can('view', $logbook)),
        canPullAnnotations: @json(auth()->user()->can('update', $logbook)),
        pullAnnotationsUrl: @json(route("logbook.annotations.pull", $logbook)),
        entryStatus: @json($logbook->status),
        entryKind: @json($logbook->jenis),
        isDraftPdf: @json($isDraftPdf ?? true),
        isCatatanPdf: @json($isCatatanPdf ?? true),
        returnUrl: @if(request()->boolean('quick_review') && auth()->user()->can('review', $logbook))
            @json(route('quick-review.index', ['item' => $logbook->id]))
        @elseif(request()->query('from') === 'create-revisi' && auth()->user()->can('update', $logbook))
            @json($wizardReturnUrl ?? route('logbook.create-revisi'))
        @else
            @json(route('logbook.show', $logbook))
        @endif ,
        returnLabel: @if(request()->query('from') === 'create-revisi' && auth()->user()->can('update', $logbook))
            @json('Kembali & Lengkapi Form')
        @else
            @json('Kembali & lengkapi')
        @endif ,
        fromCreateRevisi: @json(request()->query('from') === 'create-revisi' && auth()->user()->can('update', $logbook)),
        wizardParentId: @json($wizardParentId ?? null),
        wizardDraftId: @json($wizardDraftId ?? null),
        quickReviewUrl: @json(route('quick-review.index', ['item' => $logbook->id])),
    };
</script>
@endsection
