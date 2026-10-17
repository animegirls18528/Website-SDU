/* Scan quality check and compression for staged PDF files (runs in the browser). */
App.Quality = {
  MAX_PAGES: 60,

  /** Analyse every page: dark, blank, blurry, skewed; remember the page count. */
  check: async function(item, list) {
    if (item.format !== 'pdf') return;
    item.quality = { analyzing: true, issues: [] };
    list.render();
    try {
      const res = await fetch(App.fileUrl(item.fileId));
      const pdf = await pdfjsLib.getDocument({ data: new Uint8Array(await res.arrayBuffer()) }).promise;
      item.pages = pdf.numPages;
      const n = Math.min(pdf.numPages, this.MAX_PAGES);
      for (let i = 1; i <= n; i++) {
        const page = await pdf.getPage(i);
        const vp1 = page.getViewport({ scale: 1 });
        const scale = 900 / Math.max(vp1.width, vp1.height);
        const vp = page.getViewport({ scale });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(vp.width); canvas.height = Math.round(vp.height);
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport: vp }).promise;
        this.analysePage(ctx, canvas.width, canvas.height, i, item.quality.issues);
      }
      item.quality.checkedPages = n;
    } catch (e) {
      item.quality.error = 'ตรวจคุณภาพไม่ได้: ' + e.message;
    }
    item.quality.analyzing = false;
    list.render();
  },

  analysePage: function(ctx, w, h, pageNo, issues) {
    const data = ctx.getImageData(0, 0, w, h).data;
    const gray = new Float32Array(w * h);
    let sum = 0, sumSq = 0, dark = 0;
    for (let p = 0, i = 0; i < data.length; i += 4, p++) {
      const g = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
      gray[p] = g; sum += g; sumSq += g * g;
      if (g < 128) dark++;
    }
    const n = w * h;
    const mean = sum / n;
    const std = Math.sqrt(Math.max(0, sumSq / n - mean * mean));
    const darkRatio = dark / n;

    if (darkRatio < 0.0015 && mean > 230) { issues.push({ page: pageNo, kind: 'blank', text: `หน้า ${pageNo} ว่างเปล่า` }); return; }
    if (mean < 120) issues.push({ page: pageNo, kind: 'dark', text: `หน้า ${pageNo} ภาพมืด` });
    else if (std < 18) issues.push({ page: pageNo, kind: 'faint', text: `หน้า ${pageNo} ภาพจาง/คอนทราสต์ต่ำ` });

    // Sharpness: variance of the Laplacian (low = blurry)
    let lsum = 0, lsq = 0, ln = 0;
    for (let y = 1; y < h - 1; y += 2) {
      for (let x = 1; x < w - 1; x += 2) {
        const c = y * w + x;
        const lap = gray[c - 1] + gray[c + 1] + gray[c - w] + gray[c + w] - 4 * gray[c];
        lsum += lap; lsq += lap * lap; ln++;
      }
    }
    const lvar = lsq / ln - (lsum / ln) ** 2;
    if (darkRatio > 0.01 && lvar < 60) issues.push({ page: pageNo, kind: 'blur', text: `หน้า ${pageNo} อาจเบลอ` });

    // Skew: the angle whose row projection of dark pixels is the most "peaky" (text lines line up)
    const pts = [];
    const step = Math.max(1, Math.floor(Math.sqrt(dark / 6000)));
    for (let y = 0; y < h; y += step) for (let x = 0; x < w; x += step) if (gray[y * w + x] < 110) pts.push(x, y);
    if (pts.length > 800) {
      const score = (deg) => {
        const t = Math.tan(deg * Math.PI / 180);
        const bins = new Float32Array(Math.ceil(h / 3) + 400);
        for (let k = 0; k < pts.length; k += 2) {
          const b = Math.floor((pts[k + 1] - pts[k] * t) / 3) + 200;
          if (b >= 0 && b < bins.length) bins[b]++;
        }
        let s = 0;
        for (let b = 0; b < bins.length; b++) s += bins[b] * bins[b];
        return s;
      };
      let best = 0, bestScore = score(0);
      const zero = bestScore;
      for (let deg = -6; deg <= 6; deg += 0.5) {
        if (deg === 0) continue;
        const s = score(deg);
        if (s > bestScore) { bestScore = s; best = deg; }
      }
      if (Math.abs(best) >= 1.5 && bestScore > zero * 1.15) {
        issues.push({ page: pageNo, kind: 'skew', text: `หน้า ${pageNo} เอียงประมาณ ${Math.abs(best)}°` });
      }
    }
  },

  renderResult: function(f, list) {
    if (f.format !== 'pdf' || !f.quality) return '';
    const q = f.quality;
    if (q.analyzing) return `<div class="file-warn info"><span class="material-icons-round">hourglass_top</span>กำลังตรวจคุณภาพภาพสแกน...</div>`;
    const lines = [];
    const expected = list && list.expectedPages ? list.expectedPages() : null;
    if (expected && f.pages && f.pages !== expected) {
      lines.push(`<div class="file-warn danger"><span class="material-icons-round">find_in_page</span>ได้ ${f.pages} หน้า แต่ควรได้ ${expected} หน้า${f.pages < expected ? ' — อาจมีกระดาษติดหรือป้อนซ้อน' : ''}</div>`);
    }
    if (q.error) lines.push(`<div class="file-warn info"><span class="material-icons-round">info</span>${App.escapeHtml(q.error)}</div>`);
    if (q.issues.length) {
      const shown = q.issues.slice(0, 5).map(i => App.escapeHtml(i.text)).join(' · ');
      lines.push(`<div class="file-warn warn"><span class="material-icons-round">warning</span>${shown}${q.issues.length > 5 ? ` และอีก ${q.issues.length - 5} รายการ` : ''} — ตรวจดูและใช้ "จัดหน้า" เพื่อหมุน/ลบ/แทนที่หน้า</div>`);
    } else if (!q.error && !lines.length) {
      lines.push(`<div class="file-warn ok"><span class="material-icons-round">check_circle</span>ตรวจคุณภาพแล้ว ${q.checkedPages || f.pages} หน้า ไม่พบปัญหา</div>`);
    }
    return lines.join('');
  },

  /** Re-render pages as JPEG at a lower resolution to save disk space (the text layer from OCR is lost). */
  compress: async function(list, idx) {
    const item = list.items[idx];
    const r = await App.Dialog.form({
      title: 'บีบอัดไฟล์สแกน', icon: 'compress',
      message: `${item.name} · ขนาดปัจจุบัน ${App.fmtBytes(item.size)}`,
      fields: [],
      note: 'แปลงทุกหน้าเป็นภาพขาวดำเทา 150 DPI คุณภาพ JPEG 60% เหมาะกับเอกสารตัวหนังสือ (ข้อความ OCR ในไฟล์จะหายไป ระบบจะใช้ไฟล์ใหม่เฉพาะเมื่อเล็กลงจริง)',
      confirmText: 'บีบอัด',
    });
    if (!r) return;
    const { PDFDocument } = PDFLib;
    try {
      App.showLoader('กำลังบีบอัด...');
      const bytes = new Uint8Array(await (await fetch(App.fileUrl(item.fileId))).arrayBuffer());
      const pdf = await pdfjsLib.getDocument({ data: bytes.slice(0) }).promise;
      const out = await PDFDocument.create();
      for (let i = 1; i <= pdf.numPages; i++) {
        App.showLoader(`กำลังบีบอัดหน้า ${i}/${pdf.numPages}...`);
        const page = await pdf.getPage(i);
        const vp1 = page.getViewport({ scale: 1 });
        const vp = page.getViewport({ scale: 150 / 72 });
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(vp.width); canvas.height = Math.round(vp.height);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport: vp }).promise;
        const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
        for (let k = 0; k < img.data.length; k += 4) {
          const g = 0.299 * img.data[k] + 0.587 * img.data[k + 1] + 0.114 * img.data[k + 2];
          img.data[k] = img.data[k + 1] = img.data[k + 2] = g;
        }
        ctx.putImageData(img, 0, 0);
        const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.6));
        const jpg = await out.embedJpg(new Uint8Array(await blob.arrayBuffer()));
        out.addPage([vp1.width, vp1.height]).drawImage(jpg, { x: 0, y: 0, width: vp1.width, height: vp1.height });
      }
      const result = await out.save({ useObjectStreams: false });
      if (result.length >= item.size * 0.95) {
        App.hideLoader();
        App.toast(`ไฟล์ไม่เล็กลง (${App.fmtBytes(result.length)}) จึงใช้ไฟล์เดิม`, 'info');
        return;
      }
      const res = await App.uploadFile(new File([result], item.name.replace(/\.[^.]+$/, '') + '.pdf', { type: 'application/pdf' }),
        p => App.showLoader(`กำลังอัปโหลด... ${p}%`), item.fileId, list.context);
      const newItem = Object.assign({}, item, { fileId: res.fileId, size: res.size, compressedFrom: item.compressedFrom || item.size, quality: null });
      list.items[idx] = newItem;
      App.hideLoader();
      list.render();
      App.toast(`บีบอัดแล้ว ${App.fmtBytes(newItem.compressedFrom)} → ${App.fmtBytes(res.size)}`, 'success');
      this.check(newItem, list);
    } catch (e) {
      App.hideLoader();
      App.toast('บีบอัดไม่สำเร็จ: ' + e.message);
    }
  },
};
