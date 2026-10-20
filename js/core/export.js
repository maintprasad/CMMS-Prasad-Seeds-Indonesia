// Ekspor laporan gabungan (hasil api report/build) ke XLSX, PowerPoint, dan cetak/PDF.
// Pustaka (SheetJS, PptxGenJS) disimpan lokal di assets/vendor dan dimuat saat dibutuhkan.
import { esc } from './ui.js';

const loaded = {};
const loadScript = (src, probe) => loaded[src] ||= new Promise((resolve, reject) => {
  if (window[probe]) return resolve();
  const s = document.createElement('script');
  s.src = src;
  s.onload = resolve;
  s.onerror = () => reject(new Error('Gagal memuat pustaka ekspor: ' + src));
  document.head.append(s);
});

const stamp = (r) => `${r.meta.from}_${r.meta.to}`;
const modName = (r) => r.sections.map((s) => s.key.toUpperCase()).join('-');
export const reportFileName = (r, ext) => `laporan-${modName(r)}-${stamp(r)}.${ext}`;
const periodText = (m) => `${m.from} s/d ${m.to} · ${m.unit}`;

const save = (blob, name) => {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 5000);
};

const widths = (head, rows) => head.map((hd, i) => ({ wch: Math.min(48, Math.max(String(hd).length, ...rows.slice(0, 200).map((r) => String(r[i] ?? '').length)) + 2) }));

export async function exportXlsx(report) {
  await loadScript('assets/vendor/xlsx.full.min.js', 'XLSX');
  const X = window.XLSX;
  const wb = X.utils.book_new();
  const sum = [['LAPORAN CMMS'], ['Periode', periodText(report.meta)], ['Dibuat', report.meta.generated_at], []];
  for (const s of report.sections) {
    sum.push([s.title.toUpperCase()]);
    s.kpis.forEach(([k, v]) => sum.push([k, v]));
    sum.push([]);
  }
  const ws0 = X.utils.aoa_to_sheet(sum);
  ws0['!cols'] = [{ wch: 38 }, { wch: 34 }];
  X.utils.book_append_sheet(wb, ws0, 'Ringkasan');
  const used = new Set(['Ringkasan']);
  for (const s of report.sections) {
    for (const t of s.tables) {
      let name = `${s.key.toUpperCase()} ${t.name}`.replace(/[\\/?*[\]:]/g, '-').slice(0, 31);
      for (let i = 2; used.has(name.toLowerCase()) || used.has(name); i++) name = name.slice(0, 28) + ' ' + i;
      used.add(name);
      const ws = X.utils.aoa_to_sheet([t.head, ...t.rows]);
      ws['!cols'] = widths(t.head, t.rows);
      ws['!autofilter'] = { ref: X.utils.encode_range({ s: { r: 0, c: 0 }, e: { r: Math.max(t.rows.length, 1), c: t.head.length - 1 } }) };
      X.utils.book_append_sheet(wb, ws, name);
    }
  }
  const out = X.write(wb, { bookType: 'xlsx', type: 'array' });
  save(new Blob([out], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), reportFileName(report, 'xlsx'));
}

const PPT = { blue: '1478F0', ink: '1F2937', muted: '6B7280', line: 'E5E7EB', soft: 'F3F4F6' };
const MOD_COLOR = { wo: '1478F0', mw: '30CF46', pm: 'F5A524', dm: '7C5CFC' };

export async function exportPptx(report) {
  await loadScript('assets/vendor/pptxgen.bundle.js', 'PptxGenJS');
  const pptx = new window.PptxGenJS();
  pptx.layout = 'LAYOUT_WIDE'; // 13.33 x 7.5 in
  pptx.title = 'Laporan CMMS';
  const font = 'Calibri';
  const footer = (slide, label) => {
    slide.addText(`CMMS · ${periodText(report.meta)}`, { x: 0.5, y: 7.05, w: 9, h: 0.3, fontFace: font, fontSize: 10, color: PPT.muted });
    slide.addText(label, { x: 9.8, y: 7.05, w: 3, h: 0.3, fontFace: font, fontSize: 10, color: PPT.muted, align: 'right' });
  };

  const cover = pptx.addSlide();
  cover.background = { color: PPT.ink };
  cover.addText('Laporan Maintenance', { x: 0.8, y: 2.3, w: 11.5, h: 1, fontFace: font, fontSize: 44, bold: true, color: 'FFFFFF' });
  cover.addText(report.sections.map((s) => s.title).join('  ·  '), { x: 0.8, y: 3.4, w: 11.5, h: 0.5, fontFace: font, fontSize: 20, color: '9CA3AF' });
  cover.addText(`Periode ${periodText(report.meta)}`, { x: 0.8, y: 4.1, w: 11.5, h: 0.4, fontFace: font, fontSize: 16, color: 'FFFFFF' });
  cover.addText(`Dibuat ${report.meta.generated_at}`, { x: 0.8, y: 4.55, w: 11.5, h: 0.4, fontFace: font, fontSize: 14, color: '9CA3AF' });

  for (const s of report.sections) {
    const color = MOD_COLOR[s.key] || PPT.blue;
    const k = pptx.addSlide();
    k.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 13.33, h: 0.9, fill: { color } });
    k.addText(s.title, { x: 0.5, y: 0.1, w: 12, h: 0.7, fontFace: font, fontSize: 28, bold: true, color: 'FFFFFF' });
    const n = s.kpis.length;
    const cw = Math.min(2.9, (12.3 - (n - 1) * 0.2) / n);
    s.kpis.forEach(([label, value], i) => {
      const x = 0.5 + i * (cw + 0.2);
      k.addShape(pptx.ShapeType.roundRect, { x, y: 1.5, w: cw, h: 1.7, fill: { color: PPT.soft }, line: { color: PPT.line }, rectRadius: 0.1 });
      k.addText(String(value), { x, y: 1.65, w: cw, h: 0.8, fontFace: font, fontSize: 32, bold: true, color, align: 'center' });
      k.addText(label, { x: x + 0.1, y: 2.45, w: cw - 0.2, h: 0.65, fontFace: font, fontSize: 13, color: PPT.muted, align: 'center', valign: 'top' });
    });
    footer(k, s.title);

    // tabel ringkas (≤ 5 kolom, ≤ 15 baris) → slide; tabel dua kolom berangka juga digambar sebagai grafik batang
    for (const t of s.tables) {
      if (!t.rows.length || t.head.length > 5 || t.total > 15) continue;
      const sl = pptx.addSlide();
      sl.addText(t.name, { x: 0.5, y: 0.35, w: 12.3, h: 0.6, fontFace: font, fontSize: 24, bold: true, color: PPT.ink });
      sl.addShape(pptx.ShapeType.rect, { x: 0.5, y: 0.95, w: 1.2, h: 0.05, fill: { color } });
      const chartable = t.head.length === 2 && t.rows.every((r) => Number.isFinite(Number(r[1]))) && t.rows.length >= 2;
      const tw = chartable ? 5.6 : 12.3;
      const rows = [t.head.map((c) => ({ text: String(c), options: { bold: true, color: 'FFFFFF', fill: { color } } })),
        ...t.rows.map((r) => r.map((c) => ({ text: String(c ?? '—'), options: { color: PPT.ink } })))];
      sl.addTable(rows, { x: 0.5, y: 1.25, w: tw, fontFace: font, fontSize: 13, border: { type: 'solid', pt: 0.5, color: PPT.line }, autoPage: false, rowH: 0.34 });
      if (chartable) {
        sl.addChart(pptx.charts.BAR, [{ name: t.head[1], labels: t.rows.map((r) => String(r[0]).slice(0, 28)), values: t.rows.map((r) => Number(r[1])) }],
          { x: 6.4, y: 1.2, w: 6.4, h: 5.6, barDir: 'bar', chartColors: [color], showValue: true, catAxisLabelFontSize: 11, valAxisLabelFontSize: 10, dataLabelFontSize: 10, valGridLine: { color: PPT.line, size: 0.5 }, catAxisOrientation: 'maxMin' });
      }
      footer(sl, s.title);
    }
    const big = s.tables.filter((t) => t.total > 15 || t.head.length > 5);
    if (big.length) {
      const sl = pptx.addSlide();
      sl.addText(`${s.title} — data rinci`, { x: 0.5, y: 0.35, w: 12.3, h: 0.6, fontFace: font, fontSize: 24, bold: true, color: PPT.ink });
      sl.addShape(pptx.ShapeType.rect, { x: 0.5, y: 0.95, w: 1.2, h: 0.05, fill: { color } });
      sl.addText(big.map((t) => ({ text: `${t.name}: ${t.total} baris`, options: { bullet: true, breakLine: true } })), { x: 0.6, y: 1.3, w: 12, h: 3.5, fontFace: font, fontSize: 18, color: PPT.ink, valign: 'top' });
      sl.addText('Data lengkap ada di file Excel laporan yang sama.', { x: 0.6, y: 5.2, w: 12, h: 0.5, fontFace: font, fontSize: 14, italic: true, color: PPT.muted });
      footer(sl, s.title);
    }
  }
  await pptx.writeFile({ fileName: reportFileName(report, 'pptx') });
}

/** Cetak / simpan PDF lewat dialog cetak browser dengan dokumen khusus (semua baris ikut). */
export function printReport(report) {
  const w = window.open('', '_blank');
  if (!w) throw new Error('Pop-up diblokir browser — izinkan pop-up untuk mencetak laporan.');
  const sec = (s) => `<section><h2>${esc(s.title)}</h2>
    <div class="kpis">${s.kpis.map(([k, v]) => `<div><b>${esc(v)}</b><span>${esc(k)}</span></div>`).join('')}</div>
    ${s.tables.filter((t) => t.rows.length).map((t) => `<h3>${esc(t.name)} <small>(${t.total} baris${t.truncated ? `, ${t.rows.length} ditampilkan` : ''})</small></h3>
      <table><thead><tr>${t.head.map((c) => `<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>${t.rows.map((r) => `<tr>${r.map((c) => `<td>${esc(c ?? '')}</td>`).join('')}</tr>`).join('')}</tbody></table>`).join('')}</section>`;
  w.document.write(`<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${esc(reportFileName(report, 'pdf').replace('.pdf', ''))}</title><style>
    body{font:12px/1.4 Calibri,Arial,sans-serif;color:#1f2937;margin:18px} h1{margin:0 0 2px;font-size:22px} .sub{color:#6b7280;margin-bottom:14px}
    h2{font-size:17px;margin:22px 0 8px;padding:5px 9px;background:#1f2937;color:#fff;border-radius:4px} h3{font-size:13px;margin:14px 0 4px} h3 small{font-weight:400;color:#6b7280}
    .kpis{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px} .kpis div{border:1px solid #d1d5db;border-radius:6px;padding:6px 12px;min-width:110px} .kpis b{display:block;font-size:18px} .kpis span{color:#6b7280;font-size:11px}
    table{border-collapse:collapse;width:100%;font-size:10.5px;margin-bottom:6px} th{background:#e5e7eb;text-align:left} th,td{border:1px solid #d1d5db;padding:3px 6px;vertical-align:top} tr{page-break-inside:avoid} thead{display:table-header-group}
    section{page-break-before:always} section:first-of-type{page-break-before:avoid} @page{size:A4 landscape;margin:12mm}</style></head><body>
    <h1>Laporan Maintenance</h1><div class="sub">Periode ${esc(periodText(report.meta))} · dibuat ${esc(report.meta.generated_at)}</div>${report.sections.map(sec).join('')}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 400);
}
