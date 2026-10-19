/**
 * Adapter antara format penyimpanan backend (W3C Web Annotation di
 * `pdf_comments.payload`, kolom flat ternormalisasi 0-1) dan format
 * `react-pdf-highlighter-plus` (ScaledPosition).
 *
 * Kontrak backend TIDAK berubah:
 *  - `target.selector.value` tetap "page=N&xywh=normalized:x1,y1,x2,y2"
 *    sehingga `PdfComment::syncFromPayload()`, `burnPdf` (FPDI), dan
 *    `buildFeedback` tetap jalan tanpa modifikasi server.
 *  - Koordinat presisi (multi-rect untuk text highlight) disimpan tambahan
 *    di `target.selector.native` dalam ruang 0-1, dirender kembali sebagai
 *    ruang 0-100 agar rasio tetap benar di semua zoom.
 */

export const NATIVE_SPACE = 100;

export function parseSelector(value) {
  // value = "page=N&xywh=normalized:x1,y1,x2,y2"
  const parts = (value || '').split('&');
  let page = null;
  let coords = null;
  for (const p of parts) {
    if (p.startsWith('page=')) page = parseInt(p.slice(5), 10);
    else if (p.startsWith('xywh=normalized:')) coords = p.slice(17).split(',').map(Number);
  }
  return { page, x1: coords?.[0], y1: coords?.[1], x2: coords?.[2], y2: coords?.[3] };
}

function toSpace(n) {
  const v = Number(n);
  if (!Number.isFinite(v)) return 0;
  return Math.min(Math.max(v, 0), 1) * NATIVE_SPACE;
}

// Ubah satu rect ternormalisasi 0-1 menjadi rect ruang native 0-100.
function nativeRect(r) {
  return {
    x1: toSpace(r.x1),
    y1: toSpace(r.y1),
    x2: toSpace(r.x2),
    y2: toSpace(r.y2),
    width: NATIVE_SPACE,
    height: NATIVE_SPACE,
  };
}

// Ubah satu Scaled rect (unit absolut + dimensi halaman) menjadi 0-1.
export function scaledRectToNormalized(rect) {
  const w = rect.width || 1;
  const h = rect.height || 1;
  return {
    x1: rect.x1 / w,
    y1: rect.y1 / h,
    x2: rect.x2 / w,
    y2: rect.y2 / h,
  };
}

// Ubah item komentar API (payload W3C) menjadi anotasi + highlight lib.
export function toAnnotation(item) {
  const payload = item.payload || {};
  const selector = payload.target?.selector || {};
  const { page, x1, y1, x2, y2 } = parseSelector(selector.value);
  const body = Array.isArray(payload.body) ? payload.body[0] : {};
  const resolutionStatus = item.resolution_status || (body.resolved ? 'resolved' : 'open');
  const native = selector.native && typeof selector.native === 'object' ? selector.native : null;

  const fallback = {
    x1: Number.isFinite(x1) ? x1 : 0,
    y1: Number.isFinite(y1) ? y1 : 0,
    x2: Number.isFinite(x2) ? x2 : 0,
    y2: Number.isFinite(y2) ? y2 : 0,
  };
  const type = native?.type === 'text' ? 'text' : 'area';
  const nativeRects =
    Array.isArray(native?.rects) && native.rects.length
      ? native.rects.map(nativeRect)
      : [nativeRect(native?.boundingRect || fallback)];
  const nativeBox = native?.boundingRect ? nativeRect(native.boundingRect) : nativeRect(fallback);

  const position = {
    boundingRect: { ...nativeBox, pageNumber: page },
    rects: nativeRects.map((r) => ({ ...r, pageNumber: page })),
  };

  const comment = body.value || '';
  const quote = typeof native?.selectedText === 'string' ? native.selectedText.slice(0, 500) : '';

  return {
    id: item.id,
    page,
    x1: fallback.x1,
    y1: fallback.y1,
    x2: fallback.x2,
    y2: fallback.y2,
    comment,
    quote,
    reply: item.reply || '',
    replies: item.replies || [],
    resolved: resolutionStatus === 'resolved',
    resolutionStatus,
    isDosen: !!item.is_dosen,
    user: item.user?.name || '',
    userId: item.user?.id ?? null,
    created: item.created_at,
    type,
    highlight: {
      id: String(item.id),
      type,
      position,
      content: { text: comment },
    },
  };
}

// Bangun payload W3C dari hasil seleksi lib (area maupun text).
export function buildPayloadFromSelection(entryId, fileType, selection, comment) {
  const pos = selection.position || {};
  const box = pos.boundingRect || {};
  const page = box.pageNumber;
  const n = scaledRectToNormalized(box);
  const x1 = Math.min(n.x1, n.x2);
  const y1 = Math.min(n.y1, n.y2);
  const x2 = Math.max(n.x1, n.x2);
  const y2 = Math.max(n.y1, n.y2);

  const rects = Array.isArray(pos.rects) && pos.rects.length
    ? pos.rects.map(scaledRectToNormalized)
    : [n];
  const type = selection.type === 'text' ? 'text' : 'area';

  return {
    '@context': 'http://www.w3.org/ns/anno.jsonld',
    type: 'Annotation',
    motivation: 'commenting',
    body: [
      {
        type: 'TextualBody',
        value: comment,
        purpose: 'commenting',
        resolved: false,
        resolution_status: 'open',
      },
    ],
    target: {
      type: 'SpecificResource',
      source: `urn:logbook-ta:entry:${entryId}:${fileType}`,
      selector: {
        type: 'FragmentSelector',
        conformsTo: 'http://www.w3.org/TR/media-frags/',
        value: `page=${page}&xywh=normalized:${x1},${y1},${x2},${y2}`,
        native: {
          type,
          boundingRect: { x1, y1, x2, y2 },
          rects,
          // Teks asli hasil seleksi (khusus text highlight), untuk konteks.
          ...(selection.content?.text ? { selectedText: selection.content.text } : {}),
        },
      },
    },
  };
}

// Warna highlight mengikuti status resolusi (paritas viewer lama).
export function statusColor(resolutionStatus, alpha = '66') {
  const base =
    resolutionStatus === 'resolved'
      ? '#7C9473'
      : resolutionStatus === 'addressed'
        ? '#D97706'
        : '#C9A97E';
  return base + alpha;
}
