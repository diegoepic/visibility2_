(function () {
  'use strict';
  function matrizFotos(rows, fotos) {
    const grupos = new Map();
    const celdas = rows.map(row => {
      const valores = new Map();
      (fotos.get(row.local) || []).forEach(foto => {
        if (String(foto.id_formulario) !== row.campana || String(foto.id_usuario) !== row.usuario) return;
        const url = new URL(foto.url, window.location.href);
        if (!['http:', 'https:'].includes(url.protocol)) return;
        const label = String(foto.etiqueta || 'Foto').trim();
        const key = JSON.stringify([foto.tipo, label]);
        if (!valores.has(key)) valores.set(key, []);
        if (!valores.get(key).includes(url.href)) valores.get(key).push(url.href);
        const anterior = grupos.get(key);
        grupos.set(key, { key, label, tipo: foto.tipo, cantidad: Math.max(anterior ? anterior.cantidad : 0, valores.get(key).length) });
      });
      return valores;
    });
    const columnas = [];
    const orden = Array.from(grupos.values()).sort((a, b) => String(a.tipo).localeCompare(String(b.tipo)) || a.label.localeCompare(b.label, 'es', { numeric: true }));
    const usados = new Set(orden.map(g => g.label));
    const emitidos = new Set();
    orden.forEach(g => {
      let base = g.label;
      if (emitidos.has(base)) base += ' (' + g.tipo + ')';
      for (let i = 0; i < g.cantidad; i++) {
        let nombre = i ? base + '_' + (i + 1) : base;
        let sufijo = i + 1;
        while (emitidos.has(nombre) || (i > 0 && usados.has(nombre))) nombre = base + '_' + (++sufijo);
        emitidos.add(nombre);
        columnas.push({ nombre, key: g.key, index: i });
      }
    });
    return { header: columnas.map(c => c.nombre), body: celdas.map(v => columnas.map(c => (v.get(c.key) || [])[c.index] || '')) };
  }

  function enlacesExcel(xlsx, primeraColumna) {
    const ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const relns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const sheet = xlsx.xl.worksheets['sheet1.xml'];
    const rels = document.implementation.createDocument(pkg, 'Relationships', null);
    const links = sheet.createElementNS(ns, 'hyperlinks');
    let count = 0;
    Array.from(sheet.getElementsByTagName('c')).forEach(cell => {
      const ref = cell.getAttribute('r');
      let col = 0;
      for (const c of ref.replace(/[0-9]/g, '')) col = col * 26 + c.charCodeAt(0) - 64;
      if (col <= primeraColumna || !/^https?:\/\//i.test(cell.textContent)) return;
      const id = 'rIdFoto' + (++count);
      const link = sheet.createElementNS(ns, 'hyperlink');
      link.setAttribute('ref', ref);
      link.setAttributeNS(relns, 'r:id', id);
      links.appendChild(link);
      const rel = rels.createElementNS(pkg, 'Relationship');
      rel.setAttribute('Id', id);
      rel.setAttribute('Type', relns + '/hyperlink');
      rel.setAttribute('Target', cell.textContent);
      rel.setAttribute('TargetMode', 'External');
      rels.documentElement.appendChild(rel);
      cell.setAttribute('s', '4');
    });
    if (count) {
      const before = Array.from(sheet.documentElement.children).find(n => ['printOptions', 'pageMargins', 'pageSetup', 'headerFooter', 'drawing', 'extLst'].includes(n.localName));
      sheet.documentElement.insertBefore(links, before || null);
      xlsx.xl.worksheets._rels = { 'sheet1.xml.rels': rels };
    }
  }

  async function cargarFotos(rows, aviso) {
    const ids = Array.from(new Set(rows.map(r => r.local).filter(Boolean)));
    const fotos = new Map();
    let cursor = 0, completados = 0, fallo = null;
    await Promise.all(Array.from({ length: Math.min(3, ids.length) }, async () => {
      while (!fallo && cursor < ids.length) {
        const id = ids[cursor++];
        const url = new URL('ajax_mapa_evidencias.php', window.location.href);
        url.search = window.location.search;
        url.searchParams.set('id_local', id);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 60000);
        try {
        const response = await fetch(url, { credentials: 'same-origin', signal: controller.signal });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron consultar las imágenes.');
        fotos.set(id, result.imagenes || []);
      aviso.textContent = 'Cargando fotografías… ' + (++completados) + ' de ' + ids.length + ' locales';
        } catch (error) { fallo = error; }
        finally { clearTimeout(timeout); }
      }
    }));
    if (fallo) throw fallo;

    return fotos;
  }

  window.prepararColumnasFotos = async function(table) {
    const rows = Array.from(table.tBodies[0].rows).filter(n => n.dataset.local);
    if (!rows.length) {
      table.tBodies[0].replaceChildren();
      return;
    }
    const aviso = document.createElement('div');
    aviso.id = 'mapa-carga-columnas';
    aviso.setAttribute('role', 'status');
    aviso.style.cssText = 'padding:14px;color:#193c70;white-space:normal';
    aviso.textContent = 'Cargando columnas de fotografías…';
    table.parentNode.insertBefore(aviso, table);
    try {
      const fotos = await cargarFotos(rows.map(n => ({local:n.dataset.local,campana:n.dataset.campana,usuario:n.dataset.usuario})), aviso);
      const extra = matrizFotos(rows.map(n => ({local:n.dataset.local,campana:n.dataset.campana,usuario:n.dataset.usuario})), fotos);
      table.dataset.fotoInicio = table.tHead.rows[0].cells.length;
      table.dataset.fotoColumnas = extra.header.length;
      extra.header.forEach(label => { const th = document.createElement('th'); th.textContent = label; table.tHead.rows[0].appendChild(th); });
      rows.forEach((row,i) => extra.body[i].forEach(url => {
        const td = row.insertCell();
        if (url) { const a = document.createElement('a'); a.href=url; a.target='_blank'; a.rel='noopener noreferrer'; a.textContent='Ver imagen'; a.title=url; a.dataset.fotoUrl=url; td.appendChild(a); }
        else td.textContent='—';
      }));
      table.dataset.fotosCargadas='1';
      aviso.textContent = extra.header.length ? 'Fotografías disponibles: ' + extra.header.length + ' columnas después de Observación.' : 'No hay fotografías asociadas a estos resultados.';
    } catch (error) {
      aviso.textContent = 'No se pudieron cargar las columnas de fotos. Puedes reintentar al exportar a Excel. ' + error.message;
    }
  };

  let ocupado = false;
  window.exportarLocalesConFotos = async function (e, dt, button, config) {
    if (dt.table().node().dataset.fotosCargadas === '1') {
      const primeraFoto = dt.buttons.exportData(config.exportOptions).header.length - Number(dt.table().node().dataset.fotoColumnas);
      const exportConfig = Object.assign({}, config, {customize: xlsx => enlacesExcel(xlsx, primeraFoto)});
      return $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, exportConfig, function () {});
    }
    if (ocupado) return;
    ocupado = true;
    const aviso = document.createElement('div');
    aviso.setAttribute('role', 'status');
    aviso.id = 'mapa-exportacion-estado';
    aviso.style.cssText = 'position:fixed;inset:0;z-index:100000;background:#ffffffed;display:flex;align-items:center;justify-content:center;color:#193c70;font:600 18px sans-serif';
    aviso.textContent = 'Preparando Excel con imágenes…';
    document.body.appendChild(aviso);
    try {
      const options = Object.assign({}, config.exportOptions, { modifier: { search: 'applied', order: 'applied', selected: null } });
      const data = dt.buttons.exportData(options);
      const rows = dt.rows(options.modifier).nodes().toArray().map(n => ({ local: n.dataset.local, campana: n.dataset.campana, usuario: n.dataset.usuario }));
      const fotos = await cargarFotos(rows, aviso);
      const extra = matrizFotos(rows, fotos);
      const baseCount = data.header.length;
      data.header.push(...extra.header);
      data.body.forEach((row, i) => row.push(...extra.body[i]));
      if (data.footer) data.footer.push(...extra.header.map(() => ''));
      const exportConfig = Object.assign({}, config, {
        exportOptions: Object.assign({}, options, { customizeData: output => { output.header = data.header; output.body = data.body; output.footer = data.footer; } }),
        customize: xlsx => enlacesExcel(xlsx, baseCount)
      });
      $.fn.dataTable.ext.buttons.excelHtml5.action.call(this, e, dt, button, exportConfig, function () {});
    } catch (error) {
      window.alert('No se pudo preparar el Excel con imágenes. ' + (error.name === 'AbortError' ? 'La consulta tardó demasiado; vuelve a intentarlo.' : error.message));
    } finally {
      ocupado = false;
      aviso.remove();
    }
  };
  window.mapaExportacionFotos = { matrizFotos, enlacesExcel };
})();
