import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { ArrowLeft, ChevronDown, ChevronUp, Download, ListTree, Maximize, Minimize, PanelLeft, Search, Square, Type, X, Zap } from 'lucide-react';

import {
  AreaHighlight,
  LeftPanel,
  PdfHighlighter,
  PdfLoader,
  TextHighlight,
  useHighlightContainerContext,
} from 'react-pdf-highlighter-plus';
import SelectionTip from './pdf/SelectionTip.jsx';
import AnnotationSidebar from './pdf/AnnotationSidebar.jsx';
import { useAnnotationControls } from './pdf/HighlightToolbar.jsx';
import { capturePdfPosition, restorePdfPosition } from './pdf/viewPosition.js';
import { correctAreaSelection } from './pdf/areaPosition.js';
import { parseZoomPercent, ZOOM_OPTIONS } from './pdf/zoom.js';
import {
  buildPayloadFromSelection,
  statusColor,
  toAnnotation,
} from './pdf/annotationAdapter.js';

/**
 * Anotasi PDF dengan react-pdf-highlighter-plus — hanya dua fitur:
 *  - Text highlight: blok teks sebagai kutipan berkomentar.
 *  - Area highlight: seret kotak pada halaman.
 *
 * Layout: sidebar kiri (daftar kartu anotasi) + viewer PDF + tip mengambang
 * "+ Tambah komentar" + toolbar inline pada highlight terpilih.
 * Navigasi hash: klik kartu -> #highlight-{id} -> scroll + ring aktif.
 *
 * Logika kampus dipertahankan: tabs draft/catatan, anotasi tersimpan sebagai
 * W3C Web Annotation (backend tidak berubah), warna status
 * open/addressed/resolved, reply/tanggapan, resolve, hapus,
 * Jadikan Feedback, dan Unduh PDF dengan Anotasi (burn server-side).
 */

const DATA = window.PDF_VIEWER_DATA || {};
const { title, draftUrl, catatanUrl, hasCatatan, entryId, csrf, commentsUrl, storeUrl, resolveUrl, replyUrl, deleteUrl, burnUrl, buildFeedbackUrl, canReview, canReply, canDiscuss, currentUserId, returnUrl, quickReviewUrl, returnLabel, fromCreateRevisi, canPullAnnotations, pullAnnotationsUrl, entryKind, isDraftPdf, isCatatanPdf } = DATA;

const parseIdFromHash = () => {
  const m = (document.location.hash || '').match(/^#highlight-(.+)$/);
  return m ? m[1] : null;
};
const resetHash = () => {
  if (document.location.hash) document.location.hash = '';
};

// ---------------------------------------------------------------------------
// Container render tiap highlight. Toolbar aksi kampus menempel lewat slot
// extraButtons/extraPanel bawaan lib (pola example-app).
// ---------------------------------------------------------------------------
function HighlightContainer({ annotationsById, onReply, onToggleResolve, onDelete, onSkipNext, hasNext }) {
  const { highlight, isScrolledTo } = useHighlightContainerContext();
  const meta = annotationsById[highlight.id];
  const color = statusColor(meta?.resolutionStatus);
  const controls = useAnnotationControls(highlight, meta, {
    canReview,
    canReply,
    canDiscuss,
    currentUserId,
    onReply,
    onToggleResolve,
    onDelete,
    onSkipNext,
    hasNext,
  });
  const copyText = highlight.type === 'text' ? highlight.content?.text : meta?.comment || highlight.content?.text;

  if (highlight.type === 'text') {
    return (
      <TextHighlight
        highlight={highlight}
        isScrolledTo={isScrolledTo}
        highlightColor={color}
        copyText={copyText}
        extraButtons={controls.button}
        extraPanel={controls.panel}
      />
    );
  }
  return (
    <AreaHighlight
      highlight={highlight}
      isScrolledTo={isScrolledTo}
      highlightColor={color}
      copyText={copyText}
      extraButtons={controls.button}
      extraPanel={controls.panel}
    />
  );
}

// ---------------------------------------------------------------------------
// View dalam PdfLoader: PdfHighlighter text + area saja.
// ---------------------------------------------------------------------------
function HighlighterView({
  pdfDocument,
  highlights,
  annotationsById,
  actions,
  hasNext,
  areaMode,
  scale,
  initialPage,
  onPageChange,
  onScaleChange,
  onSaveSelection,
  onDocumentReady,
  onTextDetect,
  onUtilsReady,
  leftOpen,
  onLeftOpenChange,
  isMobile,
  utilsRef,
}) {
  const [utilsReady, setUtilsReady] = useState(false);
  const readyRef = useRef(false);
  useEffect(() => {
    if (utilsReady) return onUtilsReady();
  }, [utilsReady, onUtilsReady]);
  useEffect(() => {
    if (!pdfDocument) return;
    let cancelled = false;
    onDocumentReady(pdfDocument.numPages || 0);
    // Deteksi apakah PDF punya lapisan teks yang bisa diseleksi.
    // PDF hasil pindaian (gambar) tidak punya teks -> mode teks tak berguna.
    (async () => {
      try {
        const n = Math.min(pdfDocument.numPages || 0, 3);
        let found = 0;
        for (let p = 1; p <= n && found === 0; p++) {
          const page = await pdfDocument.getPage(p);
          if (cancelled) return;
          const tc = await page.getTextContent();
          if (cancelled) return;
          found += (tc.items || []).filter((it) => (it.str || '').trim()).length;
        }
        if (!cancelled) onTextDetect(found > 0);
      } catch (e) {
        /* abaikan: anggap teks tersedia */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [pdfDocument, onDocumentReady, onTextDetect]);

  return (
    <div className="relative flex h-full w-full">
      {isMobile && leftOpen && (
        <button aria-label="Tutup navigasi PDF" onClick={() => onLeftOpenChange(false)} className="absolute inset-0 z-30 bg-black/40" />
      )}
      {utilsReady && leftOpen && utilsRef.current && (
        <div className={isMobile ? 'absolute inset-y-0 left-0 z-40 w-64 bg-bg-surface shadow-xl' : 'shrink-0'} style={isMobile ? undefined : { width: 260 }}>
          <LeftPanel
            pdfDocument={pdfDocument}
            mode="light"
            viewer={utilsRef.current.getViewer()}
            linkService={utilsRef.current.getLinkService()}
            eventBus={utilsRef.current.getEventBus()}
            goToPage={(p) => utilsRef.current.goToPage(p)}
            onPageSelect={() => { if (isMobile) onLeftOpenChange(false); }}
            isOpen={leftOpen}
            onOpenChange={onLeftOpenChange}
            width={260}
            defaultTab="outline"
          />
        </div>
      )}
      <div className="relative min-w-0 flex-1">
        <PdfHighlighter
          pdfDocument={pdfDocument}
          highlights={highlights}
          enableAreaSelection={() => areaMode}
          areaSelectionMode={areaMode}
          pdfScaleValue={scale}
          initialPage={initialPage}
          onPageChange={onPageChange}
          onZoomChange={(s) => onScaleChange(Math.min(4, Math.max(0.1, Math.round(s * 100) / 100)))}
          onScrollAway={resetHash}
          utilsRef={(u) => {
            utilsRef.current = u;
            if (u && !readyRef.current) {
              readyRef.current = true;
              setUtilsReady(true);
            }
          }}
          selectionTip={<SelectionTip onSave={onSaveSelection} />}
          style={{ height: '100%' }}
        >
          <HighlightContainer
            annotationsById={annotationsById}
            onReply={actions.reply}
            onToggleResolve={actions.toggleResolve}
            onDelete={actions.remove}
            onSkipNext={actions.skipNext}
            hasNext={hasNext}
          />
        </PdfHighlighter>
      </div>
    </div>
  );
}

function PdfViewerApp() {
  const [activeType, setActiveType] = useState('draft');
  const [annotations, setAnnotations] = useState([]);
  const [numPages, setNumPages] = useState(0);
  // Skala awal "page-width" (pas lebar panel, seperti viewer lama) agar tajam;
  // setelah pengguna zoom, onZoomChange mengisinya dengan angka.
  const [scale, setScale] = useState('page-width');
  const [zoomInput, setZoomInput] = useState('Pas');
  const [error, setError] = useState(null);
  const [areaMode, setAreaMode] = useState(false); // false = blok teks (default), true = seret area
  const [hasSelectableText, setHasSelectableText] = useState(null); // null = memeriksa, false = PDF pindaian
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [scrolledId, setScrolledId] = useState(null);
  const [allResponded, setAllResponded] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [searchCount, setSearchCount] = useState(null);
  const [leftOpen, setLeftOpen] = useState(false);
  const [searchReady, setSearchReady] = useState(false);
  const [pulling, setPulling] = useState(false);
  const [pullMessage, setPullMessage] = useState(null);
  const [pullCount, setPullCount] = useState(0);
  const sidebarCloseRef = useRef(null);
  const spread = 0; // Selalu satu halaman (mode spread dimatikan agar fokus & stabil).

  const searchQueryRef = useRef('');
  searchQueryRef.current = searchQuery;

  const utilsRef = useRef(null);
  const positionsRef = useRef({ draft: null, catatan: null });
  const pendingRestoreRef = useRef(null);

  useEffect(() => {
    setZoomInput(typeof scale === 'number' ? `${Math.round(scale * 100)}%` : 'Pas');
  }, [scale]);

  function commitZoom(value) {
    const next = value.trim().toLowerCase() === 'pas' ? 'page-width' : parseZoomPercent(value);
    if (next === null) {
      setZoomInput(typeof scale === 'number' ? `${Math.round(scale * 100)}%` : 'Pas');
      return;
    }
    setScale(next);
    setZoomInput(typeof next === 'number' ? `${Math.round(next * 100)}%` : 'Pas');
  }

  function switchFile(type) {
    if (type === activeType) return;
    positionsRef.current[activeType] = capturePdfPosition(utilsRef.current?.getViewer()) || positionsRef.current[activeType];
    pendingRestoreRef.current = positionsRef.current[type] ? { type, position: positionsRef.current[type] } : null;
    setActiveType(type);
  }

  const restorePending = useCallback((page) => {
    const pending = pendingRestoreRef.current;
    if (!pending || pending.type !== activeType || pending.position.page !== page) return;
    const viewer = utilsRef.current?.getViewer();
    if (!viewer || !viewer.pagesCount) return;
    // PDF.js may adjust the scroll position while it lays out the page/zoom.
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (pendingRestoreRef.current !== pending) return;
      if (restorePdfPosition(viewer, pending.position)) pendingRestoreRef.current = null;
    }));
  }, [activeType]);

  const pdfUrl = activeType === 'catatan' ? catatanUrl : draftUrl;

  // Esc menutup mode fullscreen.
  useEffect(() => {
    if (!isFullscreen) return;
    const onKey = (e) => {
      if (e.key === 'Escape') setIsFullscreen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [isFullscreen]);

  useEffect(() => {
    const mq = window.matchMedia('(max-width: 768px)');
    const update = () => setIsMobile(mq.matches);
    update();
    mq.addEventListener('change', update);
    return () => mq.removeEventListener('change', update);
  }, []);

  useEffect(() => {
    if (isMobile) {
      setSidebarOpen(false);
    }
  }, [isMobile]);

  // Fokus: saat sidebar dibuka (terutama mobile), pindahkan fokus ke tombol tutup
  // agar pengguna keyboard/pembaca layar langsung berada di panel anotasi.
  useEffect(() => {
    if (sidebarOpen) sidebarCloseRef.current?.focus();
  }, [sidebarOpen]);

  useEffect(() => {
    const onModeShortcut = (event) => {
      if (event.defaultPrevented || event.isComposing || event.ctrlKey || event.altKey || event.metaKey || event.shiftKey) return;
      const target = event.target;
      if (target instanceof HTMLElement && (target.isContentEditable || target.closest('input, textarea, select, [role="textbox"]'))) return;
      const key = event.key.toLowerCase();
      const textShortcut = key === 't';
      const areaShortcut = key === 'a';
      if (!textShortcut && !areaShortcut) return;
      if (textShortcut && hasSelectableText === false) return;
      event.preventDefault();
      setAreaMode(areaShortcut);
    };
    document.addEventListener('keydown', onModeShortcut);
    return () => document.removeEventListener('keydown', onModeShortcut);
  }, [hasSelectableText]);

  // (Mode dua halaman dimatikan — satu halaman penuh agar fokus & stabil di mobile.)

  function doSearch(q) {
    searchQueryRef.current = q;
    setSearchQuery(q);
    if (!q.trim()) {
      setSearchCount(null);
      utilsRef.current?.clearSearch?.();
      return;
    }
    setSearchCount(null);
    utilsRef.current?.search?.(q);
  }
  function clearSearchBox() {
    searchQueryRef.current = '';
    setSearchQuery('');
    setSearchCount(null);
    utilsRef.current?.clearSearch?.();
  }

  const handleUtilsReady = useCallback(() => {
    const utils = utilsRef.current;
    const bus = utils?.getEventBus();
    if (!bus) return;
    setSearchReady(true);
    const restoreFirstPage = () => restorePending(1);
    bus.on('pagesinit', restoreFirstPage);
    if (utils.getViewer()?.pagesCount) restoreFirstPage();
    const onCount = (e) => {
      if (!searchQueryRef.current.trim()) return;
      if (e.rawQuery != null && e.rawQuery !== searchQueryRef.current) return;
      if (e.state === 1) {
        setSearchCount({ current: 0, total: 0 });
        return;
      }
      if (e.state === 3) return;
      setSearchCount(e.matchesCount || { current: 0, total: 0 });
    };
    bus.on('updatefindmatchescount', onCount);
    bus.on('updatefindcontrolstate', onCount);
    return () => {
      bus.off('updatefindmatchescount', onCount);
      bus.off('updatefindcontrolstate', onCount);
      bus.off('pagesinit', restoreFirstPage);
      utils.clearSearch();
    };
  }, [restorePending]);

  // ---------------------------------------------------------------- muat anotasi
  useEffect(() => {
    let cancelled = false;
    setError(null);
    setAnnotations([]);
    setAllResponded(false);
    setNumPages(0);
    setScrolledId(null);
    setHasSelectableText(null);
    searchQueryRef.current = '';
    setSearchQuery('');
    setSearchCount(null);
    setSearchReady(false);
    setScale(positionsRef.current[activeType]?.scale ?? 'page-width');
    utilsRef.current = null;
    if (!pdfUrl) {
      setError('Tidak ada file PDF untuk ditampilkan.');
      return;
    }
    // Edge non-PDF (doc/docx bila institusi mengizinkan): jangan tampilkan kanvas
    // kosong — arahkan unduh file asli.
    const activeIsPdf = activeType === 'catatan' ? isCatatanPdf !== false : isDraftPdf !== false;
    if (!activeIsPdf) {
      setError('NON_PDF');
      return;
    }
    fetch(commentsUrl + '?type=' + activeType, { credentials: 'same-origin' })
      .then((r) => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then((list) => {
        if (!cancelled) {
          setAnnotations(list.map(toAnnotation));
        }
      })
      .catch(() => {
        if (!cancelled) {
          setError('Gagal memuat anotasi.');
        }
      });
    return () => {
      cancelled = true;
    };
  }, [activeType, pdfUrl]);

  const highlights = useMemo(() => annotations.map((a) => a.highlight), [annotations]);
  const annotationsById = useMemo(() => {
    const map = {};
    annotations.forEach((a) => {
      map[String(a.id)] = a;
    });
    return map;
  }, [annotations]);

  // ---------------------------------------------------------------- navigasi hash ala demo
  const scrollToAnnotation = useCallback((a) => {
    const target = annotationsById[String(a.id)] || a;
    try {
      if (utilsRef.current && target.highlight) {
        utilsRef.current.scrollToHighlight(target.highlight);
        return;
      }
    } catch (e) {
      /* abaikan, highlight belum siap */
    }
  }, [annotationsById]);

  const getAnnotationById = useCallback((id) => annotations.find((x) => String(x.id) === String(id)) || null, [annotations]);

  // PDF pindaian tak punya teks yang bisa diblok -> paksa mode Area; selain itu tetap mode Teks.
  useEffect(() => {
    if (hasSelectableText === false) setAreaMode(true);
  }, [hasSelectableText]);

  const handleTextDetect = useCallback((v) => setHasSelectableText(v), []);

  function zoomIn() {
    const currentScale = utilsRef.current?.getViewer()?.currentScale || 1;
    setScale(Math.min(4, Math.round((currentScale + 0.2) * 100) / 100));
  }
  function zoomOut() {
    const currentScale = utilsRef.current?.getViewer()?.currentScale || 1;
    setScale(Math.max(0.1, Math.round((currentScale - 0.2) * 100) / 100));
  }

  useEffect(() => {
    const onHashChange = () => {
      const id = parseIdFromHash();
      setScrolledId(id);
      if (id) {
        const found = getAnnotationById(id);
        if (found) scrollToAnnotation(found);
      }
    };
    window.addEventListener('hashchange', onHashChange);
    onHashChange();
    return () => window.removeEventListener('hashchange', onHashChange);
  }, [getAnnotationById, scrollToAnnotation]);

  function openAnnotation(a) {
    pendingRestoreRef.current = null;
    const next = `#highlight-${a.id}`;
    if (document.location.hash === next) {
      setScrolledId(String(a.id));
      scrollToAnnotation(a);
    } else {
      document.location.hash = next; // memicu hashchange -> scroll + ring
    }
    if (isMobile) setSidebarOpen(false);
  }

  // ---------------------------------------------------------------- antrean tanggapan
  const unrespondedDosen = useMemo(() => (canReply
    ? annotations
        .filter((a) => a.isDosen && a.resolutionStatus === 'open' && !a.reply)
        .sort((x, y) => (x.page - y.page) || (y.y1 - x.y1) || (x.x1 - x.x1))
    : []), [annotations, canReply]);

  function nextUnresponded(excludeId) {
    return annotations
      .filter((x) => x.isDosen && x.resolutionStatus === 'open' && !x.reply && x.id !== excludeId)
      .sort((x, y) => (x.page - y.page) || (y.y1 - x.y1) || (y.x1 - x.x1))[0] || null;
  }

  // ---------------------------------------------------------------- simpan (dari SelectionTip)
  async function saveAnnotation(selection, commentText) {
    if (!selection || !selection.position) return false;
    const comment = (commentText || '').trim() || 'Tandai area';
    const corrected = correctAreaSelection(selection, utilsRef.current?.getViewer());
    const payload = buildPayloadFromSelection(entryId, activeType, corrected, comment);
    try {
      const res = await fetch(storeUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ file_type: activeType, payload }),
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const saved = await res.json();
      setAnnotations((a) => [...a, toAnnotation({ ...saved, is_dosen: canReview ? true : saved.is_dosen })]);
      return true;
    } catch (e) {
      alert('Gagal menyimpan anotasi.');
      return false;
    }
  }

  // ---------------------------------------------------------------- reply / resolve / hapus
  async function saveReply(id, reply) {
    if (!replyUrl || !String(reply || '').trim()) return false;
    try {
      const res = await fetch(replyUrl.replace('{id}', id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ reply }),
      });
      if (!res.ok) {
        alert('Gagal menyimpan balasan. Status: ' + res.status);
        return false;
      }
      const d = await res.json();
      setAnnotations((a) => a.map((x) => (x.id === id ? {
        ...x,
        reply: d.reply || '',
        replies: d.replies || [],
        resolutionStatus: d.resolution_status || x.resolutionStatus,
        resolved: (d.resolution_status || x.resolutionStatus) === 'resolved',
      } : x)));
      // Lanjut otomatis ke anotasi dosen berikutnya yang belum ditanggapi.
      const next = nextUnresponded(id);
      if (next) openAnnotation(next);
      else setAllResponded(true);
      return true;
    } catch (e) {
      alert('Gagal menyimpan balasan.');
      return false;
    }
  }

  async function toggleResolve(id) {
    try {
      const res = await fetch(resolveUrl.replace('{id}', id), {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, credentials: 'same-origin',
      });
      if (!res.ok) {
        alert('Gagal mengubah status anotasi. Status: ' + res.status);
        return false;
      }
      const d = await res.json();
      setAnnotations((a) => a.map((x) => (x.id === id ? {
        ...x,
        resolved: d.resolution_status === 'resolved',
        resolutionStatus: d.resolution_status,
      } : x)));
      return true;
    } catch (e) {
      alert('Gagal mengubah status anotasi.');
      return false;
    }
  }

  async function removeAnnotation(id) {
    const res = await fetch(deleteUrl.replace('{id}', id), {
      method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, credentials: 'same-origin',
    });
    if (!res.ok) {
      alert('Gagal menghapus anotasi. Status: ' + res.status);
      return false;
    }
    setAnnotations((a) => a.filter((x) => x.id !== id));
    if (String(scrolledId) === String(id)) {
      setScrolledId(null);
      resetHash();
    }
    return true;
  }

  function skipToNext(excludeId) {
    const next = nextUnresponded(excludeId);
    if (next) openAnnotation(next);
  }

  async function pullToTable() {
    if (!pullAnnotationsUrl || pulling) return;
    setPulling(true);
    setPullMessage(null);
    try {
      const res = await fetch(pullAnnotationsUrl, {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, credentials: 'same-origin',
      });
      const d = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(d.message || ('HTTP ' + res.status));
      var skipNote = d.skipped_empty > 0 ? ' ' + d.skipped_empty + ' anotasi kosong dilewati.' : '';
      if (d.pulled > 0) {
        setPullCount(d.pulled);
        setPullMessage(d.pulled + (entryKind === 'revisi' ? ' perbaikan terisi otomatis dari anotasi.' : ' poin terisi otomatis ke ringkasan.') + ' Kembali untuk melengkapi yang bertanda perlu dilengkapi.' + skipNote);
      } else {
        setPullMessage('Belum ada anotasi baru — tandai dulu di PDF, lalu klik lagi.' + skipNote);
      }
    } catch (e) {
      setPullMessage('Gagal menarik anotasi.');
    } finally {
      setPulling(false);
    }
  }

  const actions = useMemo(() => ({
    reply: saveReply,
    toggleResolve,
    remove: removeAnnotation,
    skipNext: skipToNext,
  }), [annotations]); // eslint-disable-line react-hooks/exhaustive-deps

  // Kembali ke halaman revisi (manual, via tombol di banner sukses).
  function goBackToRevision() {
    if (returnUrl) window.location.href = returnUrl;
  }

  // Kembali ke wizard create-revisi: beri sinyal ke tab wizard agar auto-pull
  // lalu tutup tab ini (kembali fokus ke tab wizard). Bila tab viewer dibuka
  // manual (tanpa opener), fallback ke navigasi returnUrl seperti biasa.
  function goBackFromWizard() {
    try { localStorage.setItem('lbta-revisi-autopull:' + entryId, String(Date.now())); } catch (e) { /* abaikan */ }
    if (window.opener) { window.close(); return; }
    if (returnUrl) window.location.href = returnUrl;
  }

  // ---------------------------------------------------------------- build feedback
  async function buildFeedback() {
    if (!buildFeedbackUrl) return;
    try {
      const res = await fetch(buildFeedbackUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (!res.ok) {
        alert('Gagal membuat feedback. Status: ' + res.status + '. Pastikan Anda adalah pembimbing entri ini.');
        return;
      }
      const d = await res.json();
      if (!d.feedback) {
        alert('Tidak ada komentar yang belum resolve.');
        return;
      }
      window.location.href = quickReviewUrl || '/quick-review';
    } catch (e) {
      alert('Gagal membuat feedback. Periksa koneksi atau coba lagi.');
    }
  }

  const sidebarClass = isMobile
    ? `absolute inset-y-0 left-0 z-40 w-80 max-w-[85vw] bg-bg-surface border-r border-border transition-transform duration-300 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`
    : `flex-shrink-0 border-r border-border transition-all duration-300 overflow-hidden ${sidebarOpen ? 'w-80' : 'w-0 border-r-0'}`;

  return (
    <div className={isFullscreen
      ? 'fixed inset-0 z-[60] bg-bg-base flex flex-col gap-2 p-2'
      : 'h-full flex flex-col gap-2 p-2 md:p-3'}>
      {/* Bar compact: kembali | judul | anotasi | outline | file | mode | zoom || aksi */}
      <div className="flex items-center gap-1.5 md:gap-2 rounded-lg border border-border bg-bg-surface px-2 py-1.5 overflow-x-auto shrink-0">
        {fromCreateRevisi ? (
          <button onClick={goBackFromWizard} title="Kembali ke wizard"
            className="flex items-center gap-1 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap bg-bg-panel hover:bg-bg-hover shrink-0">
            <ArrowLeft className="h-3.5 w-3.5" /><span className="hidden sm:inline">Kembali</span>
          </button>
        ) : (
          <a href={returnUrl} title="Kembali ke detail"
            className="flex items-center gap-1 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap bg-bg-panel hover:bg-bg-hover shrink-0">
            <ArrowLeft className="h-3.5 w-3.5" /><span className="hidden sm:inline">Kembali</span>
          </a>
        )}
        <span className="text-sm font-bold whitespace-nowrap truncate" title={`Anotasi PDF · ${title || ''}`}>
          Anotasi PDF · {title}
        </span>
        <button onClick={() => setSidebarOpen((v) => !v)}
          aria-label="Tampilkan/sembunyikan panel anotasi"
          aria-pressed={sidebarOpen}
          title="Tampilkan/sembunyikan panel anotasi"
          className={`flex items-center gap-1 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap shrink-0 ${sidebarOpen ? 'bg-brand text-white' : 'bg-bg-panel hover:bg-bg-hover'}`}>
          <PanelLeft className="h-3.5 w-3.5" /> {annotations.length}
        </button>
        <button
          onClick={() => setLeftOpen((v) => !v)}
          title="Outline & halaman"
          className={`flex items-center gap-1 px-2 py-1 rounded-md text-xs font-semibold whitespace-nowrap shrink-0 ${leftOpen ? 'bg-brand text-white' : 'bg-bg-panel hover:bg-bg-hover'}`}
        >
          <ListTree className="h-3.5 w-3.5" />
        </button>
        <div className="flex items-center gap-0.5 rounded-md bg-bg-panel p-0.5 shrink-0" role="group" aria-label="File">
          <button onClick={() => switchFile('draft')}
            className={`px-2 py-1 rounded text-xs font-semibold whitespace-nowrap ${activeType === 'draft' ? 'bg-brand text-white shadow' : 'hover:bg-bg-hover'}`}>
            Draft
          </button>
          {hasCatatan && (
            <button onClick={() => switchFile('catatan')}
              className={`px-2 py-1 rounded text-xs font-semibold whitespace-nowrap ${activeType === 'catatan' ? 'bg-brand text-white shadow' : 'hover:bg-bg-hover'}`}>
              Catatan
            </button>
          )}
        </div>
        <span className="w-px h-5 bg-border shrink-0" />
        <div className="flex items-center gap-1 rounded-md bg-bg-panel p-0.5 shrink-0" role="search">
          <Search className="h-3.5 w-3.5 mx-1 text-text-secondary" />
          <input
            value={searchQuery}
            aria-label="Cari di PDF"
            disabled={!searchReady || !!error}
            onChange={(e) => doSearch(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Escape') {
                e.stopPropagation();
                clearSearchBox();
              }
              if (e.key === 'Enter' && searchCount?.total > 0) {
                e.preventDefault();
                e.shiftKey ? utilsRef.current?.findPrevious?.() : utilsRef.current?.findNext?.();
              }
            }}
            placeholder="Cari di PDF…"
            className="w-28 md:w-40 bg-transparent text-xs outline-none placeholder:text-text-secondary"
          />
          {searchQuery.trim() && (
            <span role="status" aria-live="polite" className="text-xs tabular-nums text-text-secondary whitespace-nowrap">
              {searchCount ? `${searchCount.current}/${searchCount.total}` : 'Mencari…'}
            </span>
          )}
          <button onClick={() => utilsRef.current?.findPrevious?.()} disabled={!searchReady || !searchCount?.total || !!error}
            title="Hasil sebelumnya" className="px-1 py-0.5 rounded hover:bg-bg-hover disabled:opacity-40">
            <ChevronUp className="h-3.5 w-3.5" />
          </button>
          <button onClick={() => utilsRef.current?.findNext?.()} disabled={!searchReady || !searchCount?.total || !!error}
            title="Hasil berikutnya" className="px-1 py-0.5 rounded hover:bg-bg-hover disabled:opacity-40">
            <ChevronDown className="h-3.5 w-3.5" />
          </button>
          {searchQuery && (
            <button onClick={clearSearchBox} title="Hapus pencarian" className="px-1 py-0.5 rounded hover:bg-bg-hover">
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
        <div className="flex items-center gap-0.5 rounded-md bg-bg-panel p-0.5 shrink-0" role="group" aria-label="Mode anotasi">
          <button onClick={() => setAreaMode(false)}
            disabled={hasSelectableText === false}
            aria-keyshortcuts="t"
            aria-pressed={!areaMode}
            title={hasSelectableText === false ? 'PDF pindaian: teks tidak dapat diblok' : 'Mode teks (T)'}
            className={`flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold ${!areaMode ? 'bg-brand text-white shadow' : 'hover:bg-bg-hover'} disabled:opacity-40`}>
            <Type className="h-3.5 w-3.5" /> Teks
          </button>
          <button onClick={() => setAreaMode(true)}
            aria-keyshortcuts="a"
            aria-pressed={areaMode}
            title="Mode area (A)"
            className={`flex items-center gap-1 px-2 py-1 rounded text-xs font-semibold ${areaMode ? 'bg-brand text-white shadow' : 'hover:bg-bg-hover'}`}>
            <Square className="h-3.5 w-3.5" /> Area
          </button>
        </div>
        <div className="flex items-center gap-0.5 rounded-md bg-bg-panel p-0.5 shrink-0" aria-label="Zoom">
          <button onClick={zoomOut} title="Perkecil"
            className="px-2 py-1 rounded text-xs font-bold leading-none hover:bg-bg-hover">−</button>
          <input
            type="text"
            inputMode="decimal"
            aria-label="Zoom PDF dalam persen"
            title="Ketik zoom (10–400%), Enter untuk menerapkan"
            value={zoomInput}
            onChange={(e) => setZoomInput(e.target.value)}
            onBlur={(e) => commitZoom(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') { e.currentTarget.blur(); }
              if (e.key === 'Escape') {
                e.preventDefault();
                setZoomInput(typeof scale === 'number' ? `${Math.round(scale * 100)}%` : 'Pas');
              }
            }}
            className="w-16 rounded bg-transparent px-1 py-0.5 text-center text-xs font-medium tabular-nums outline-none focus:ring-2 focus:ring-brand"
          />
          <select
            aria-label="Pilihan zoom PDF"
            title="Pilih persentase zoom"
            value={scale === 'page-width' ? 'Pas' : ZOOM_OPTIONS.includes(Math.round(scale * 100)) && Math.abs(scale * 100 - Math.round(scale * 100)) < 0.001 ? `${Math.round(scale * 100)}%` : ''}
            onChange={(e) => commitZoom(e.target.value)}
            className="w-5 bg-transparent text-xs outline-none cursor-pointer"
          >
            <option value="" disabled>Zoom</option>
            <option value="Pas">Pas lebar</option>
            {ZOOM_OPTIONS.map((n) => <option key={n} value={`${n}%`}>{n}%</option>)}
          </select>
          <button onClick={zoomIn} title="Perbesar"
            className="px-2 py-1 rounded text-xs font-bold leading-none hover:bg-bg-hover">+</button>
        </div>
        <span className="hidden lg:inline text-xs text-text-secondary whitespace-nowrap shrink-0">{numPages || '…'} hal</span>
        <div className="ml-auto flex items-center gap-1.5 shrink-0">
          {buildFeedbackUrl && (
            <button onClick={buildFeedback} title="Kompilasi komentar menjadi feedback"
              className="flex items-center gap-1 px-2 py-1 rounded-md bg-brand-fill hover:bg-brand-fill-hover text-white text-xs font-semibold whitespace-nowrap">
              <Zap className="h-3.5 w-3.5" /><span className="hidden md:inline">Feedback</span>
            </button>
          )}
          {canPullAnnotations && (
            <button onClick={pullToTable} disabled={pulling} title="Isi otomatis dari anotasi yang Anda tandai"
              className="flex min-h-11 items-center gap-1.5 rounded-md border border-brand/40 bg-brand/10 px-3 py-2 text-xs font-semibold text-brand whitespace-nowrap disabled:opacity-50">
              <ListTree className="h-3.5 w-3.5" /><span className="hidden md:inline">{pulling ? 'Mengisi…' : 'Isi otomatis dari anotasi' + (pullCount > 0 ? ' (' + pullCount + ')' : '')}</span><span className="md:hidden">{pulling ? '…' : 'Isi otomatis' + (pullCount > 0 ? ' (' + pullCount + ')' : '')}</span>
            </button>
          )}
          {burnUrl && (
            <a href={burnUrl.replace('__TYPE__', activeType)} target="_blank" rel="noopener" title="Unduh PDF dengan anotasi"
              className="flex items-center gap-1 px-2 py-1 rounded-md bg-bg-panel hover:bg-bg-hover text-xs font-semibold whitespace-nowrap">
              <Download className="h-3.5 w-3.5" /><span className="hidden md:inline">PDF Anotasi</span>
            </a>
          )}
          <button onClick={() => setIsFullscreen((v) => !v)}
            title={isFullscreen ? 'Keluar layar penuh (Esc)' : 'Layar penuh'}
            className={`flex items-center px-2 py-1 rounded-md text-xs font-semibold ${isFullscreen ? 'bg-brand text-white' : 'bg-bg-panel hover:bg-bg-hover'}`}>
            {isFullscreen ? <Minimize className="h-3.5 w-3.5" /> : <Maximize className="h-3.5 w-3.5" />}
          </button>
        </div>
      </div>

      {/* Banner sukses menanggapi */}
      {canReply && allResponded && (
        <div className="rounded-lg border border-status-success/40 bg-status-success/10 px-3 py-2 flex flex-wrap items-center justify-between gap-2 shrink-0">
          <p className="text-sm">
            <span className="font-semibold">Semua komentar dosen telah Anda tanggapi. </span>
            <span className="text-text-secondary">{unrespondedDosen.length === 0 ? 'Tidak ada lagi yang menunggu tanggapan.' : 'Anda dapat menanggapi sisa komentar.'}</span>
          </p>
          <button onClick={goBackToRevision}
            className="px-3 py-1.5 rounded-lg bg-brand text-[#0b1420] text-xs font-semibold hover:opacity-90">
            Kembali ke Revisi
          </button>
        </div>
      )}

      {pullMessage && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-brand/30 bg-brand/10 px-3 py-2 text-xs shrink-0" role="status">
          <span>{pullMessage}</span>
          {pullCount > 0 && (returnUrl || fromCreateRevisi) && (
            <button onClick={fromCreateRevisi ? goBackFromWizard : () => { window.location.href = returnUrl; }} className="inline-flex min-h-11 items-center gap-1 rounded-lg bg-brand px-3 py-2 font-semibold text-[#0b1420] hover:opacity-90">
              {returnLabel || 'Kembali & lengkapi'} →
            </button>
          )}
        </div>
      )}

      {/* PDF pindaian: tidak ada teks yang bisa diblok */}
      {hasSelectableText === false && !error && (
        <div className="rounded-lg border border-status-pending/40 bg-status-pending/10 px-3 py-2 text-xs shrink-0" role="status">
          <span className="font-semibold">PDF ini tampaknya hasil pindaian (gambar)</span>
          <span className="text-text-secondary"> — teks tidak dapat diblok. Mode Area sudah diaktifkan otomatis; seret kotak pada halaman untuk menandai.</span>
        </div>
      )}

      {/* Main flex-fill: sidebar anotasi + viewer PDF */}
      {/* Mobile: sidebar menjadi drawer + tombol tutup yang bisa difokus (Esc menutup). */}
      <div className="relative flex flex-1 min-h-0 overflow-hidden rounded-lg border border-border bg-bg-surface">
        {isMobile && sidebarOpen && (
          <div className="absolute inset-0 z-30 bg-black/40" onClick={() => setSidebarOpen(false)} />
        )}
        {isMobile && sidebarOpen && (
          <button
            ref={sidebarCloseRef}
            type="button"
            onClick={() => setSidebarOpen(false)}
            onKeyDown={(e) => { if (e.key === 'Escape') setSidebarOpen(false); }}
            aria-label="Tutup panel anotasi"
            className="absolute right-2 top-2 z-50 rounded-lg bg-bg-surface px-3 py-2 text-xs font-semibold shadow-lg border border-border"
          >
            Tutup ✕ (Esc)
          </button>
        )}

        <div className={sidebarClass}>
          <div className="h-full w-80 max-w-[85vw]">
            <AnnotationSidebar
              annotations={annotations}
              scrolledId={scrolledId}
              canReview={canReview}
              canReply={canReply}
              canDiscuss={canDiscuss}
              currentUserId={currentUserId}
              unrespondedCount={unrespondedDosen.length}
              onOpen={openAnnotation}
              onReply={saveReply}
              onToggleResolve={toggleResolve}
              onDelete={removeAnnotation}
              onBuildFeedback={buildFeedback}
              // Footer feedback sidebar disembunyikan: tombolnya sudah ada di bar compact.
              buildFeedbackUrl={null}
            />
          </div>
        </div>

        <div className="relative min-w-0 flex-1">
          {error === 'NON_PDF' ? (
            <div className="flex flex-col items-center justify-center gap-3 p-8 text-center text-sm">
              <p className="font-semibold">File ini bukan PDF sehingga tidak bisa dianotasi di sini.</p>
              <p className="text-text-secondary">Unduh file asli, perbaiki, lalu unggah ulang sebagai PDF bila perlu anotasi.</p>
              <a href={pdfUrl} download className="rounded-lg bg-brand px-4 py-2 font-semibold text-[#0b1420] hover:opacity-90">
                Unduh file asli
              </a>
            </div>
          ) : error ? (
            <div className="flex items-center justify-center p-8 text-center text-sm text-status-danger">
              {error}
            </div>
          ) : null}
          {!error && pdfUrl && (
            <PdfLoader
              key={activeType}
              document={pdfUrl}
              disableAutoFetch={false}
              beforeLoad={() => (
                <div className="flex items-center justify-center p-8 text-center text-sm text-text-secondary">
                  Memuat PDF…
                </div>
              )}
              errorMessage={() => (
                <div className="flex items-center justify-center p-8 text-center text-sm text-status-danger">
                  Gagal memuat PDF. Pastikan file tersedia dan aset frontend ter-build (lihat README).
                </div>
              )}
            >
              {(pdfDocument) => (
                <HighlighterView
                  pdfDocument={pdfDocument}
                  highlights={highlights}
                  annotationsById={annotationsById}
                  actions={actions}
                  hasNext={unrespondedDosen.length > 0}
                  areaMode={areaMode}
                  scale={scale}
                  initialPage={positionsRef.current[activeType]?.page}
                  onPageChange={restorePending}
                  onScaleChange={setScale}
                  onSaveSelection={saveAnnotation}
                  onDocumentReady={setNumPages}
                  onTextDetect={handleTextDetect}
                  onUtilsReady={handleUtilsReady}
                  leftOpen={leftOpen}
                  onLeftOpenChange={setLeftOpen}
                  isMobile={isMobile}
                  utilsRef={utilsRef}
                />
              )}
            </PdfLoader>
          )}
        </div>
      </div>
    </div>
  );
}

const rootEl = document.getElementById('pdf-viewer-root');
if (rootEl) createRoot(rootEl).render(<PdfViewerApp />);
