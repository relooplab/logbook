import React, { useMemo, useState } from 'react';
import {
  CheckCheck,
  ChevronDown,
  FileText,
  MessageSquare,
  Reply,
  RotateCcw,
  Search,
  Square,
  Trash2,
} from 'lucide-react';
import { STATUS_META, resolveButtonLabel } from './HighlightToolbar.jsx';
import AnnotationConversation from './AnnotationConversation.jsx';

/**
 * Sidebar daftar anotasi ala example-app:
 * header (ikon + jumlah) → search + filter + sort → daftar kartu
 * dikelompokkan per halaman (PageGroup collapsible) → footer aksi.
 *
 * Kartu (HighlightCard): ikon tipe + badge halaman + badge status,
 * preview kutipan/komentar, balasan, tombol aksi sesuai peran
 * (balas / selesai / hapus). Klik kartu = lompat ke highlight di PDF.
 */

const TYPE_META = {
  text: { icon: FileText, label: 'Teks', chip: 'bg-blue-100 text-blue-700' },
  area: { icon: Square, label: 'Area', chip: 'bg-purple-100 text-purple-700' },
};

function PageGroup({ pageNumber, highlightCount, children, forceOpen }) {
  const [isOpen, setIsOpen] = useState(true);
  const open = forceOpen || isOpen;
  return (
    <div className="mb-1">
      <button
        type="button"
        onClick={() => setIsOpen((v) => !v)}
        className="flex w-full items-center justify-between rounded-md px-3 py-2 text-sm font-medium hover:bg-bg-hover"
      >
        <span className="flex items-center gap-2">
          <ChevronDown className={`h-4 w-4 transition-transform ${open ? '' : '-rotate-90'}`} />
          <span>Halaman {pageNumber}</span>
        </span>
        <span className="text-xs px-1.5 py-0.5 rounded bg-bg-panel border border-border">{highlightCount}</span>
      </button>
      {open && <div className="space-y-2 py-1 pl-3 pr-1">{children}</div>}
    </div>
  );
}

function HighlightCard({ annotation: a, isActive, canReview, canDiscuss, currentUserId, onOpen, onReply, onToggleResolve, onDelete }) {
  const [replying, setReplying] = useState(false);
  const [draft, setDraft] = useState('');
  const [busy, setBusy] = useState(null);

  const type = TYPE_META[a.type] || TYPE_META.area;
  const Icon = type.icon;
  const status = STATUS_META[a.resolutionStatus] || STATUS_META.open;
  const showReply = canDiscuss;

  async function sendReply() {
    const text = draft.trim();
    if (!text || busy) return;
    setBusy('reply');
    const ok = await onReply(a.id, text);
    setBusy(null);
    if (ok) {
      setDraft('');
      setReplying(false);
    }
  }

  async function run(fn, key) {
    if (busy) return;
    setBusy(key);
    await fn(a.id);
    setBusy(null);
  }

  return (
    <div
      onClick={() => onOpen(a)}
      className={`group cursor-pointer rounded-lg border border-border bg-bg-surface p-3 transition-shadow hover:shadow-md ${isActive ? 'ring-2 ring-brand ring-offset-1' : ''}`}
    >
      <div className="mb-2 flex items-center justify-between gap-2">
        <span className="flex items-center gap-2 min-w-0">
          <span className={`rounded-md p-1.5 ${type.chip}`}>
            <Icon className="h-3.5 w-3.5" />
          </span>
          <span className="text-sm font-medium text-text-secondary">{type.label}</span>
          <span className="text-[10px] px-1.5 py-0.5 rounded text-white shrink-0" style={{ backgroundColor: status.bg }}>
            {status.label}
          </span>
        </span>
        <span className="text-xs px-1.5 py-0.5 rounded bg-bg-panel border border-border shrink-0">Hal. {a.page}</span>
      </div>

      {a.type === 'text' && a.quote && (
        <p className="mb-2 break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm italic text-text-secondary">“{a.quote}”</p>
      )}

      <div className="mb-1 flex items-start gap-2 rounded-md bg-bg-panel/60 p-2">
        <MessageSquare className="mt-0.5 h-3.5 w-3.5 shrink-0 text-text-secondary" />
        <div className="min-w-0 flex-1">
          {a.user && <p className="text-[11px] font-semibold text-text-secondary">{a.user}</p>}
          <p className="break-words [overflow-wrap:anywhere] whitespace-pre-wrap text-sm">{a.comment}</p>
        </div>
      </div>

      <AnnotationConversation annotation={a} currentUserId={currentUserId} />

      {replying ? (
        <div className="mt-2" onClick={(e) => e.stopPropagation()}>
          <textarea
            rows={2}
            autoFocus
            value={draft}
            maxLength={2000}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendReply();
              } else if (e.key === 'Escape') {
                setReplying(false);
              }
            }}
            placeholder="Tulis balasan… lalu Enter"
            className="w-full rounded-md border border-border bg-bg-surface px-2.5 py-1.5 text-sm placeholder:text-text-secondary focus:outline-none focus:ring-2 focus:ring-brand"
          />
          <div className="mt-1 flex gap-2">
            <button
              type="button"
              onClick={sendReply}
              disabled={busy === 'reply' || !draft.trim()}
              className="px-2.5 py-1 rounded-md bg-brand text-white text-xs font-semibold disabled:opacity-50"
            >
              {busy === 'reply' ? 'Mengirim…' : 'Kirim'}
            </button>
            <button type="button" onClick={() => setReplying(false)} className="px-2.5 py-1 rounded-md bg-bg-panel text-xs">
              Batal
            </button>
          </div>
        </div>
      ) : (
        <div className="mt-2 flex flex-wrap items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
          {showReply && (
            <button
              type="button"
              onClick={() => setReplying(true)}
              className="flex items-center gap-1 px-2.5 py-1 rounded-md bg-brand text-white text-xs font-semibold"
            >
              <Reply className="h-3 w-3" /> Balas
            </button>
          )}
          <button
            type="button"
            onClick={() => run(onToggleResolve, 'resolve')}
            disabled={busy === 'resolve'}
            title={resolveButtonLabel(a, canReview)}
            className="flex items-center gap-1 px-2.5 py-1 rounded-md bg-sand text-white text-xs font-semibold disabled:opacity-50"
          >
            {a.resolutionStatus === 'open' ? <CheckCheck className="h-3 w-3" /> : <RotateCcw className="h-3 w-3" />}
            {busy === 'resolve' ? '…' : resolveButtonLabel(a, canReview)}
          </button>
          <button
            type="button"
            onClick={() => run(onDelete, 'delete')}
            disabled={busy === 'delete'}
            title="Hapus anotasi"
            className="flex items-center gap-1 px-2.5 py-1 rounded-md bg-status-danger text-white text-xs font-semibold disabled:opacity-50"
          >
            <Trash2 className="h-3 w-3" /> {busy === 'delete' ? '…' : 'Hapus'}
          </button>
        </div>
      )}
    </div>
  );
}

export default function AnnotationSidebar({
  annotations,
  scrolledId,
  canReview,
  canReply,
  canDiscuss,
  currentUserId,
  unrespondedCount,
  onOpen,
  onReply,
  onToggleResolve,
  onDelete,
  onBuildFeedback,
  buildFeedbackUrl,
}) {
  const [query, setQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('all'); // all | text | area
  const [statusFilter, setStatusFilter] = useState('all'); // all | open | addressed | resolved | unresponded
  const [sortBy, setSortBy] = useState('page'); // page | newest

  const filtered = useMemo(() => {
    let list = [...annotations];
    const q = query.trim().toLowerCase();
    if (q) {
      list = list.filter(
        (a) =>
          (a.comment || '').toLowerCase().includes(q) ||
          (a.user || '').toLowerCase().includes(q) ||
          (a.reply || '').toLowerCase().includes(q) ||
          (a.replies || []).some((message) => (message.body || '').toLowerCase().includes(q)),
      );
    }
    if (typeFilter !== 'all') list = list.filter((a) => (a.type || 'area') === typeFilter);
    if (statusFilter === 'unresponded') {
      list = list.filter((a) => a.isDosen && a.resolutionStatus === 'open' && !a.reply);
    } else if (statusFilter !== 'all') {
      list = list.filter((a) => a.resolutionStatus === statusFilter);
    }
    if (sortBy === 'newest') list.reverse();
    else list.sort((x, y) => x.page - y.page || x.y1 - y.y1 || x.x1 - y.x1);
    return list;
  }, [annotations, query, typeFilter, statusFilter, sortBy]);

  const byPage = useMemo(() => {
    const groups = {};
    filtered.forEach((a) => {
      const p = a.page || 0;
      if (!groups[p]) groups[p] = [];
      groups[p].push(a);
    });
    return groups;
  }, [filtered]);
  const pages = Object.keys(byPage).map(Number).sort((x, y) => x - y);

  const chip = (active) =>
    `px-2 py-1 rounded-md text-xs font-medium border ${active ? 'bg-brand text-white border-brand' : 'bg-bg-panel border-border hover:bg-bg-hover'}`;

  return (
    <div className="flex h-full flex-col bg-bg-surface">
      {/* Header ala demo */}
      <div className="flex-shrink-0 border-b border-border p-4">
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-md bg-brand">
            <MessageSquare className="h-4 w-4 text-white" />
          </div>
          <div>
            <h2 className="text-sm font-semibold">Tandaan</h2>
            <p className="text-xs text-text-secondary">
              {annotations.length} komentar
              {canReply && unrespondedCount > 0 && ` · ${unrespondedCount} belum dibalas`}
            </p>
          </div>
        </div>
      </div>

      {/* Search + filter + sort ala demo */}
      <div className="flex-shrink-0 border-b border-border p-3 space-y-2">
        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-text-secondary" />
          <input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Cari komentar…"
            className="w-full rounded-md border border-border bg-bg-surface pl-8 pr-2.5 py-1.5 text-sm placeholder:text-text-secondary focus:outline-none focus:ring-2 focus:ring-brand"
          />
        </div>
        <div className="flex flex-wrap gap-1">
          {[
            ['all', 'Semua'],
            ['text', 'Teks'],
            ['area', 'Area'],
          ].map(([v, label]) => (
            <button key={v} type="button" onClick={() => setTypeFilter(v)} className={chip(typeFilter === v)}>
              {label}
            </button>
          ))}
          <span className="mx-0.5 w-px bg-border" />
          {[
            ['page', 'Per halaman'],
            ['newest', 'Terbaru'],
          ].map(([v, label]) => (
            <button key={v} type="button" onClick={() => setSortBy(v)} className={chip(sortBy === v)}>
              {label}
            </button>
          ))}
        </div>
        <div className="flex flex-wrap gap-1">
          {[
            ['all', 'Semua status'],
            ['open', 'Baru'],
            ['addressed', 'Dibalas'],
            ['resolved', 'Selesai'],
          ].map(([v, label]) => (
            <button key={v} type="button" onClick={() => setStatusFilter(v)} className={chip(statusFilter === v)}>
              {label}
            </button>
          ))}
          {canReply && (
            <button type="button" onClick={() => setStatusFilter('unresponded')} className={chip(statusFilter === 'unresponded')}>
              Belum dibalas
            </button>
          )}
        </div>
      </div>

      {/* Daftar kartu */}
      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="p-2">
          {filtered.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-8 text-center px-4">
              <MessageSquare className="mb-2 h-10 w-10 text-text-secondary/50" />
              <p className="text-sm text-text-secondary">
                {annotations.length === 0 ? 'Belum ada yang ditandai' : 'Tidak ada yang cocok'}
              </p>
              <p className="mt-1 text-xs text-text-secondary">
                {annotations.length === 0
                  ? 'Blok tulisan atau seret kotak di PDF untuk menandai'
                  : 'Coba ubah kata kunci atau filter'}
              </p>
            </div>
          ) : sortBy === 'page' ? (
            pages.map((p) => (
              <PageGroup key={p} pageNumber={p} highlightCount={byPage[p].length} forceOpen={byPage[p].some((a) => String(a.id) === String(scrolledId))}>
                {byPage[p].map((a) => (
                  <HighlightCard
                    key={a.id}
                    annotation={a}
                    isActive={String(a.id) === String(scrolledId)}
                    canReview={canReview}
                    canReply={canReply}
                    canDiscuss={canDiscuss}
                    currentUserId={currentUserId}
                    onOpen={onOpen}
                    onReply={onReply}
                    onToggleResolve={onToggleResolve}
                    onDelete={onDelete}
                  />
                ))}
              </PageGroup>
            ))
          ) : (
            <div className="space-y-2 p-1">
              {filtered.map((a) => (
                <HighlightCard
                  key={a.id}
                  annotation={a}
                  isActive={String(a.id) === String(scrolledId)}
                  canReview={canReview}
                  canReply={canReply}
                  canDiscuss={canDiscuss}
                  currentUserId={currentUserId}
                  onOpen={onOpen}
                  onReply={onReply}
                  onToggleResolve={onToggleResolve}
                  onDelete={onDelete}
                />
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Footer aksi kampus */}
      {buildFeedbackUrl && (
        <div className="flex-shrink-0 border-t border-border p-3">
          <button
            type="button"
            onClick={onBuildFeedback}
            className="w-full px-3 py-2 rounded-md bg-brand-fill hover:bg-brand-fill-hover text-white text-sm font-semibold"
          >
            ⚡ Jadikan Feedback
          </button>
        </div>
      )}
    </div>
  );
}
