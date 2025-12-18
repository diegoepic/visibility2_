(function(){
  'use strict';

  const BASE                     = '/visibility2/app';
  const JOURNAL_SERVER_ENDPOINT  = BASE + '/api/journal_server.php';
  const JOURNAL_DETAIL_ENDPOINT  = BASE + '/api/journal_server_detalle.php';

  const qs  = s => document.querySelector(s);
  const qsa = s => Array.from(document.querySelectorAll(s));

  const $today = qs('#jr-list-today');
  const $week  = qs('#jr-list-week');
  const $pg    = qs('#jr-global-progress');

  let GROUPS_TODAY   = {};
  let GROUPS_WEEK    = {};
  let OFFLINE_GUIDS  = new Set(); 
  
  function esc(str){
    return String(str == null ? '' : str).replace(/[&<>"']/g, function(ch){
      switch (ch){
        case '&': return '&amp;';
        case '<': return '&lt;';
        case '>': return '&gt;';
        case '"': return '&quot;';
        default:  return ch;
      }
    });
  }


  function fmtTime(ts){
    const d = ts ? new Date(ts) : new Date();
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleTimeString('es-CL', { hour:'2-digit', minute:'2-digit' });
  }


  function fmtDate(ts){
    const d = ts ? new Date(ts) : new Date();
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('es-CL', {
      weekday:'short', day:'2-digit', month:'2-digit', year:'numeric'
    });
  }

  function fmtDuration(seg){
    let s = Number(seg || 0);
    if (!Number.isFinite(s) || s <= 0) return '';
    const min = Math.floor(s / 60);
    const rem = s % 60;
    if (min && rem) return `${min} min ${rem} s`;
    if (min)        return `${min} min`;
    return `${rem} s`;
  }

  function badge(el, n, txt){
    if (!el) return;
    el.textContent = `${txt}: ${n}`;
  }

  function pct(n){
    return Math.max(0, Math.min(100, Math.round(n || 0)));
  }

  function setGlobalProgress(done, total){
    const p = total > 0 ? Math.round((done / total) * 100) : 0;
    if (!$pg) return;
    $pg.style.width = p + '%';
    $pg.textContent = p + '%';
    $pg.className   = 'progress-bar ' + (p >= 100 ? 'progress-bar-success' : '');
  }

  async function fetchServerJournal(fromYmd, toYmd){
    try {
      if (typeof fetch !== 'function') return [];
      if (typeof navigator !== 'undefined' && navigator && navigator.onLine === false){
        // Navegador reporta offline → no intentamos
        return [];
      }

      const params = new URLSearchParams();
      if (fromYmd) params.set('from', fromYmd);
      if (toYmd)   params.set('to',   toYmd);

      const res = await fetch(JOURNAL_SERVER_ENDPOINT + '?' + params.toString(), {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });

      if (!res.ok) return [];
      const data = await res.json();
      if (!data || data.status !== 'ok' || !Array.isArray(data.items)) return [];
      return data.items;
    } catch(_){
      return [];
    }
  }

  // ---------- OFFLINE GUIDS (para marcar subidas offline) ----------

  function collectOfflineGuids(rows){
    const set = new Set();
    if (!Array.isArray(rows)) {
      OFFLINE_GUIDS = set;
      return;
    }
    rows.forEach(r => {
      const g =
        (r && (r.guid || r.client_guid)) ||
        (r && r.vars && (r.vars.client_guid || r.vars.guid)) ||
        null;
      if (g) set.add(String(g));
    });
    OFFLINE_GUIDS = set;
  }

  // ---------- Agrupación: DÍA + LOCAL (para cola local) ----------

  function groupKey(r){
    const localKey = r.local_id || r.visita_local_id || 0;
    return `${r.ymd || ''}|${localKey}`;
  }

  function aggStatus(items){
    const has = s => items.some(x => x.status === s);
    if (items.some(x => ['error','fatal','auth_paused'].includes(x.status))) return 'error';
    if (has('running')) return 'running';
    if (items.some(x => ['pending','queued','retry'].includes(x.status))) return 'pending';
    return 'success';
  }

  function aggProgress(items){
    if (!items.length) return 0;
    const sum = items.reduce((a, x) => a + (x.status === 'success' ? 100 : (x.progress || 0)), 0);
    return Math.round(sum / items.length);
  }

  function countByKind(items){
    const c = { materialPhotos:0, surveyPhotos:0, answers:0, create:0, other:0 };
    for (const it of items){
      const kind = (it.kind || '').toLowerCase();
      const phts = (it.counts && it.counts.photos) ? Number(it.counts.photos) : 0;

      if (kind.includes('upload_material'))        c.materialPhotos += (phts || 1);
      else if (kind.includes('pregunta_foto'))     c.surveyPhotos   += (phts || 1);
      else if (kind.includes('procesar_gestion'))  c.answers        += 1;
      else if (kind.includes('create_visita'))     c.create         += 1;
      else                                         c.other          += 1;
    }
    return c;
  }

  const plural = (n, s, p) => `${n} ${n === 1 ? s : p}`;

  function explFromCounts(c){
    const parts = [];
    if (c.materialPhotos) parts.push(plural(c.materialPhotos, 'foto de material', 'fotos de materiales'));
    if (c.surveyPhotos)   parts.push(plural(c.surveyPhotos,   'foto de encuesta', 'fotos de encuesta'));
    if (c.answers)        parts.push(plural(c.answers,        'gestión/respuesta', 'gestiones/respuestas'));
    if (c.create)         parts.push(plural(c.create,         'creación de visita', 'creaciones de visita'));
    if (c.other)          parts.push(plural(c.other,          'tarea', 'tareas'));
    return parts.join(', ') || '—';
  }

  function popoverHTML(gr){
    const c = gr.kindCounts;
    const bullets = [
      c.materialPhotos ? `<li><b>Fotos de materiales</b>: ${c.materialPhotos}</li>` : '',
      c.surveyPhotos   ? `<li><b>Fotos de encuesta</b>: ${c.surveyPhotos}</li>`     : '',
      c.answers        ? `<li><b>Gestión/Respuestas</b>: ${c.answers}</li>`         : '',
      c.create         ? `<li><b>Creación de visita</b>: ${c.create}</li>`          : '',
      c.other          ? `<li><b>Otras tareas</b>: ${c.other}</li>`                 : ''
    ].join('');

    return `<div style="min-width:220px">
      <div><b>¿Qué se está cargando?</b></div>
      <ul style="padding-left:18px;margin:6px 0">${bullets || '<li>Sin tareas</li>'}</ul>
      <small>La barra resume el avance total de este local hoy.</small>
    </div>`;
  }

  function uniq(arr){
    return Array.from(new Set(arr.filter(Boolean)));
  }

  function groupFromRows(rows){
    const g = {};
    for (const r of rows || []){
      const k = groupKey(r);
      (g[k] = g[k] || {
        key: k,
        items: [],
        ymd: r.ymd,
        local_id: r.local_id || r.visita_local_id || null,
        names: {},
        created: r.created,
        campaigns: new Set()
      });
      g[k].items.push(r);
      if (!g[k].created || r.created < g[k].created) g[k].created = r.created;
      if (r.names && r.names.campaign) g[k].campaigns.add(r.names.campaign);
      // merge names si vienen resueltos
      g[k].names = Object.assign({}, g[k].names, r.names || {});
    }

    Object.values(g).forEach(gr => {
      gr.status     = aggStatus(gr.items);
      gr.progress   = aggProgress(gr.items);
      gr.kindCounts = countByKind(gr.items);
      gr.expl       = explFromCounts(gr.kindCounts);
      gr.counts     = {
        total:   gr.items.length,
        pending: gr.items.filter(x => x.status === 'pending').length
      };
      gr.campaigns = uniq(Array.from(gr.campaigns));

      // merge de vars (estado_final, fecha_reagendada, etc.)
      gr.vars = gr.items.reduce((acc, it) => {
        if (it.vars){
          Object.keys(it.vars).forEach(function(k){
            if (it.vars[k] != null) acc[k] = it.vars[k];
          });
        }
        return acc;
      }, {});
    });

    return g;
  }

  // ---------- HTML para grupos (cola local) ----------

  function chipForStatus(st){
    if (st === 'success') return '<span class="jr-chip jr-chip--ok">Subida</span>';
    if (st === 'running') return '<span class="jr-chip jr-chip--run">Enviando</span>';
    if (st === 'error')   return '<span class="jr-chip jr-chip--err">Error</span>';
    return '<span class="jr-chip jr-chip--pend">Pendiente</span>';
  }

  function groupHTML(gr){
    const lName  = gr.names && gr.names.local ? esc(gr.names.local) : (gr.local_id ? `Local #${gr.local_id}` : '—');
    const codigo = gr.names && gr.names.codigo ? ` (${esc(gr.names.codigo)})` : '';
    const comuna = gr.names && gr.names.comuna ? ` · ${esc(gr.names.comuna)}` : '';
    const dir    = gr.names && gr.names.direccion ? esc(gr.names.direccion) : '';
    const campTxt= gr.campaigns && gr.campaigns.length
      ? esc(gr.campaigns.join(' · '))
      : (gr.names && gr.names.campaign ? esc(gr.names.campaign) : '');

    const content = popoverHTML(gr).replace(/"/g, '&quot;');
    const canRetry = (gr.status !== 'success'); // si todo ok, se desactiva

    // chip de estado_final (resultado de la gestión, si viene en vars)
    const estadoFinal = gr.vars && gr.vars.estado_final
      ? String(gr.vars.estado_final)
      : null;

    let estadoChip = '';
    if (estadoFinal){
      const lower = estadoFinal.toLowerCase();
      let cls = 'jr-chip--ok';
      if (/pendiente/.test(lower)) cls = 'jr-chip--pend';
      else if (/cancelado|no implementado|rechazado/.test(lower)) cls = 'jr-chip--err';
      estadoChip = `<span class="jr-chip ${cls} jr-chip--estado">${esc(estadoFinal)}</span>`;
    }

    return `
      <div class="jr-item jr-group" data-key="${esc(gr.key)}">
        <div class="jr-left">
          <div class="jr-time">${fmtTime(gr.created)}</div>
          <div class="jr-what">
            <div class="jr-title">${lName}${codigo}<small>${comuna}</small>
              <i class="fa fa-info-circle jr-help" tabindex="0"
                 data-toggle="popover" data-trigger="focus" data-html="true"
                 data-container="body" data-placement="left" title="Detalle de subidas"
                 data-content="${content}"></i>
            </div>
            ${dir ? `<div class="jr-subline">${dir}</div>` : ''}
            <div class="jr-sub">${campTxt ? `${campTxt} · ` : ''}${esc(gr.expl)}</div>
          </div>
        </div>
        <div class="jr-right">
          ${chipForStatus(gr.status)}
          ${estadoChip}
          <div class="progress jr-progress">
            <div class="progress-bar ${gr.progress >= 100 ? 'progress-bar-success' : ''}"
                 style="width:${pct(gr.progress)}%">
              ${pct(gr.progress)}%
            </div>
          </div>
          <div class="jr-actions">
            <button class="btn btn-xs btn-default jr-view">
              <i class="fa fa-eye"></i> Ver tareas
            </button>
            <button class="btn btn-xs btn-default jr-retry" ${canRetry ? '' : 'disabled title="Todo subido"'}>
              <i class="fa fa-refresh"></i> Reintentar${gr.counts.pending ? ` (${gr.counts.pending})` : ''}
            </button>
          </div>
        </div>
      </div>
      <div class="jr-details" data-for="${esc(gr.key)}" hidden>
        ${gr.items.map(it => {
          const p = (it.status === 'success' ? 100 : (it.progress || 0));
          const kind =
            (it.kind || '').includes('upload_material') ? 'Fotos de materiales' :
            (it.kind || '').includes('pregunta_foto')   ? 'Fotos de encuesta'   :
            (it.kind || '').includes('procesar_gestion')? 'Gestión / respuestas' :
            (it.kind || '').includes('create_visita')   ? 'Creación de visita'  :
            (it.kind || 'Tarea');
          const extra = it.counts && it.counts.photos
            ? ` (${it.counts.photos} foto${it.counts.photos > 1 ? 's' : ''})`
            : '';
          const debugParts = [];
          if (it.visita_local_id) debugParts.push(`local:${esc(it.visita_local_id)}`);
          if (it.request_id)      debugParts.push(`req:${esc(it.request_id)}`);
          if (it.attempts)        debugParts.push(`reintentos:${esc(String(it.attempts))}`);
          if (it.nextTryAt) {
            const nt = new Date(it.nextTryAt);
            if (!Number.isNaN(nt.getTime())) debugParts.push(`próximo:${fmtTime(nt)}`);
          }
          if (it.last_error_code) debugParts.push(`código:${esc(it.last_error_code)}`);

          const debugLine = (debugParts.length || it.last_error)
            ? `<div class="jr-debug">${debugParts.join(' · ')}${it.last_error ? ` · err:${esc(it.last_error)}` : ''}</div>`
            : '';
          return `
            <div class="jr-detail-row">
              <div class="jr-d-left">
                ${chipForStatus(it.status)}
                <span class="jr-d-kind">${esc(kind)}${extra}</span>
              </div>
              <div class="jr-d-right">
                <div class="progress jr-progress">
                  <div class="progress-bar ${p >= 100 ? 'progress-bar-success' : ''}"
                       style="width:${pct(p)}%">
                    ${pct(p)}%
                  </div>
                </div>
                ${debugLine}
              </div>
            </div>`;
        }).join('')}
      </div>
    `;
  }

  // ---------- Modal de detalle servidor ----------

  function ensureDetailModal(){
    if (document.getElementById('jr-detail-modal')) return;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `
      <div class="modal fade" id="jr-detail-modal" tabindex="-1" role="dialog" aria-labelledby="jr-detail-title">
        <div class="modal-dialog modal-lg" role="document">
          <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">
                <span aria-hidden="true">&times;</span>
              </button>
              <h4 class="modal-title" id="jr-detail-title">Detalle de visita</h4>
            </div>
            <div class="modal-body" id="jr-detail-body">
              Cargando detalle...
            </div>
          </div>
        </div>
      </div>
    `;
    document.body.appendChild(wrapper.firstElementChild);
  }

  function setDetailModalTitle(txt){
    ensureDetailModal();
    const el = qs('#jr-detail-title');
    if (el) el.textContent = txt || 'Detalle de visita';
  }

  function setDetailModalBody(html){
    ensureDetailModal();
    const el = qs('#jr-detail-body');
    el.innerHTML = html;
  }

  function tipoGestionDesdeModalidad(modalidad){
    const m = (modalidad || '').toLowerCase();
    if (m === 'solo_auditoria') return 'Auditoría';
    if (m === 'solo_implementacion') return 'Implementación';
    if (m === 'implementacion_auditoria' || m === 'complementaria') return 'Implementación + auditoría';
    if (m === 'retiro') return 'Retiro';
    if (m === 'entrega') return 'Entrega';
    return '';
  }

  function renderServerDetail(payload, fromOffline){
    const basic  = payload.basic || {};
    const loc    = basic.local || {};
    const form   = basic.formulario || {};
    const usr    = basic.usuario || {};
    const t      = basic.tiempos || {};
    const resumen= payload.summary || {};
    const mats   = Array.isArray(payload.materials) ? payload.materials : [];
    const survey = payload.survey || {};
    const qsArr  = Array.isArray(survey.questions) ? survey.questions : [];
    const photosMisc = Array.isArray(payload.photos_misc) ? payload.photos_misc : [];

    const modalidad   = form.modalidad || '';
    const tipoGestion = tipoGestionDesdeModalidad(modalidad);

    // Duración aproximada si tenemos inicio/fin
    let durTxt = '';
    if (t.fecha_inicio && t.fecha_fin){
      const start = new Date(String(t.fecha_inicio).replace(' ', 'T'));
      const end   = new Date(String(t.fecha_fin).replace(' ', 'T'));
      const diff  = (end.getTime() - start.getTime()) / 1000;
      if (diff > 0) durTxt = fmtDuration(diff);
    }

    let html = '';

    // RESUMEN
    html += `
      <div class="jr-detail-section">
        <h4>Resumen de visita</h4>
        ${fromOffline ? '<p><span class="label label-warning">Esta visita fue enviada desde modo offline</span></p>' : ''}
        <table class="table table-condensed">
          <tbody>
            <tr><th>Local</th><td>${esc(loc.nombre || '')}${loc.codigo ? ' (' + esc(loc.codigo) + ')' : ''}</td></tr>
            <tr><th>Dirección</th><td>${esc(loc.direccion || '')}</td></tr>
            <tr><th>Distrito</th><td>${esc(loc.distrito && loc.distrito.nombre ? loc.distrito.nombre : '')}</td></tr>
            <tr><th>Campaña</th><td>${esc(form.nombre || '')}</td></tr>
            <tr><th>Modalidad</th><td>${esc(tipoGestion || modalidad || '—')}</td></tr>
            <tr><th>Fecha inicio</th><td>${t.fecha_inicio ? esc(fmtDate(t.fecha_inicio) + ' ' + fmtTime(t.fecha_inicio)) : '—'}</td></tr>
            <tr><th>Fecha fin</th><td>${t.fecha_fin ? esc(fmtDate(t.fecha_fin) + ' ' + fmtTime(t.fecha_fin)) : '—'}</td></tr>
            ${durTxt ? `<tr><th>Duración aprox.</th><td>${esc(durTxt)}</td></tr>` : ''}
            <tr><th>Materiales distintos</th><td>${resumen.materials_count != null ? resumen.materials_count : '—'}</td></tr>
            <tr><th>Preguntas respondidas</th><td>${resumen.questions_answered != null ? resumen.questions_answered : '—'}</td></tr>
            <tr><th>Fotos totales</th><td>${resumen.photos_total != null ? resumen.photos_total : '—'}</td></tr>
          </tbody>
        </table>
      </div>
    `;

    // IMPLEMENTACIÓN
    if (mats.length){
      html += `<div class="jr-detail-section"><h4>Implementación de materiales</h4>`;
      mats.forEach(mat => {
        const gsts = Array.isArray(mat.gestiones) ? mat.gestiones : [];
        const phs  = Array.isArray(mat.photos) ? mat.photos : [];
        html += `
          <div class="panel panel-default jr-detail-material">
            <div class="panel-heading">
              <strong>${esc(mat.material_nombre || 'Sin material')}</strong>
              ${mat.material_id ? ` <small>(ID ${mat.material_id})</small>` : ''}
            </div>
            <div class="panel-body">
        `;
        if (gsts.length){
          html += `
            <table class="table table-condensed table-striped">
              <thead>
                <tr>
                  <th>Fecha gestión</th>
                  <th>Estado</th>
                  <th>Valor real</th>
                  <th>Motivo / Observación</th>
                </tr>
              </thead>
              <tbody>
          `;
          gsts.forEach(g => {
            const mot = g.motivo_no_implementacion || '';
            const obs = g.observacion || '';
            const mix = [mot, obs].filter(Boolean).join(' · ');
            html += `
              <tr>
                <td>${g.fecha_visita ? esc(fmtDate(g.fecha_visita) + ' ' + fmtTime(g.fecha_visita)) : ''}</td>
                <td>${esc(g.estado_gestion || '')}</td>
                <td>${g.valor_real != null ? esc(String(g.valor_real)) : ''}</td>
                <td>${esc(mix)}</td>
              </tr>
            `;
          });
          html += `</tbody></table>`;
        } else {
          html += `<p class="text-muted">Sin registros de gestión.</p>`;
        }

        if (phs.length){
          html += `<div class="jr-detail-photos"><strong>Fotos de este material:</strong><br>`;
          phs.forEach(p => {
            html += `
              <a href="${esc(p.url)}" target="_blank" style="display:inline-block;margin:2px;">
                <img src="${esc(p.url)}" alt="Foto ${p.id}" style="max-width:80px;max-height:80px;border-radius:3px;border:1px solid #ddd;">
              </a>
            `;
          });
          html += `</div>`;
        }

        html += `</div></div>`;
      });
      html += `</div>`;
    }

    // ENCUESTA
    if (qsArr.length){
      html += `<div class="jr-detail-section"><h4>Encuesta / Auditoría</h4>`;
      qsArr.forEach(q => {
        const ans = Array.isArray(q.answers) ? q.answers : [];
        const qph = Array.isArray(q.photos) ? q.photos : [];
        const isPhotoQ = q.question_type && (q.question_type.name || '').toLowerCase() === 'photo';

        const typeLabel = (q.question_type && q.question_type.name)
          ? `<span class="label label-default">${esc(q.question_type.name)}</span>`
          : '';
        const reqLabel  = q.is_required ? '<span class="label label-info">Requerida</span>' : '';
        const valLabel  = q.is_valued   ? '<span class="label label-success">Valorizada</span>' : '';

        html += `
          <div class="panel panel-default jr-detail-question">
            <div class="panel-heading jr-q-heading">
              <div class="jr-q-title">${esc(q.question_text || '')}</div>

              </div>
            </div>
            <div class="panel-body">
        `;

        if (ans.length){
          html += `<ul class="list-unstyled">`;
          ans.forEach(a => {
            const opt     = (a.option_text || '').trim();
            const ansText = (a.answer_text || '').trim();

            // Texto principal (deduplicando opción/respuesta cuando son iguales)
            let info = '';
            if (isPhotoQ){
              if (opt) {
                info = `<b>Opción:</b> ${esc(opt)}`;
              } else {
                info = '(foto)';
              }
            } else {
              if (opt && (!ansText || opt === ansText)) {
                info = `<b>Respuesta:</b> ${esc(opt)}`;
              } else if (ansText) {
                if (opt) {
                  info = `<b>Opción:</b> ${esc(opt)} · <b>Respuesta:</b> ${esc(ansText)}`;
                } else {
                  info = `<b>Respuesta:</b> ${esc(ansText)}`;
                }
              } else if (opt) {
                info = `<b>Opción:</b> ${esc(opt)}`;
              } else {
                info = '(sin texto)';
              }
            }

            const pieces = [info];

            if (q.is_valued && a.valor != null){
              pieces.push(`<span class="label label-success">valor: ${esc(String(a.valor))}</span>`);
            }
            const infoLine = pieces.join(' · ');

            // Foto principal (inline)
            let mainPhoto = null;
            if (Array.isArray(a.photos) && a.photos.length){
              mainPhoto = a.photos[0];
            } else if (isPhotoQ && a.answer_text && /(^\/?uploads\/)/.test(a.answer_text)){
              mainPhoto = { url: a.answer_text, id: a.foto_visita_id || 0 };
            }

            let inlinePhoto = '';
            if (mainPhoto && mainPhoto.url){
              inlinePhoto = `
                <div class="jr-detail-photo-inline">
                  <a href="${esc(mainPhoto.url)}" target="_blank">
                    <img src="${esc(mainPhoto.url)}" alt="Foto" style="max-width:80px;max-height:80px;border-radius:3px;border:1px solid #ddd;margin-top:4px;">
                  </a>
                </div>`;
            }

            // Más fotos ligadas a esa respuesta (además de la principal)
            let extraLinks = '';
            if (Array.isArray(a.photos) && a.photos.length > 1){
              const links = a.photos.slice(1).map(p =>
                `<a href="${esc(p.url)}" target="_blank">Foto #${p.id}</a>`
              ).join(', ');
              if (links) {
                extraLinks = ` · <b>Más fotos:</b> ${links}`;
              }
            }

            html += `
              <li style="margin-bottom:6px;">
                ${infoLine}
                ${a.created_at ? ` <small class="text-muted">(${esc(a.created_at)})</small>` : ''}
                ${extraLinks}
                ${inlinePhoto}
              </li>
            `;
          });
          html += `</ul>`;
        } else {
          html += `<p class="text-muted">Sin respuestas registradas.</p>`;
        }

        // Fotos asociadas a la pregunta (por id_form_question)
        if (qph.length){
          html += `<div class="jr-detail-photos"><strong>Fotos asociadas a la pregunta:</strong><br>`;
          qph.forEach(p => {
            html += `
              <a href="${esc(p.url)}" target="_blank" style="display:inline-block;margin:2px;">
                <img src="${esc(p.url)}" alt="Foto ${p.id}" style="max-width:80px;max-height:80px;border-radius:3px;border:1px solid #ddd;">
              </a>
            `;
          });
          html += `</div>`;
        }

        html += `</div></div>`;
      });
      html += `</div>`;
    }

    // Fotos sueltas
    if (photosMisc.length){
      html += `<div class="jr-detail-section"><h4>Otras fotos de la visita</h4>`;
      photosMisc.forEach(p => {
        html += `
          <a href="${esc(p.url)}" target="_blank" style="display:inline-block;margin:2px;">
            <img src="${esc(p.url)}" alt="Foto ${p.id}" style="max-width:80px;max-height:80px;border-radius:3px;border:1px solid #ddd;">
          </a>
        `;
      });
      html += `</div>`;
    }

    return html;
  }

  async function showServerDetail(visitaId, fromOffline, domTitle){
    visitaId = parseInt(visitaId, 10);
    if (!visitaId) return;

    ensureDetailModal();
    setDetailModalTitle(domTitle || `Detalle de visita #${visitaId}`);
    setDetailModalBody('<p>Cargando detalle de la visita...</p>');

    if (window.jQuery && jQuery.fn && jQuery.fn.modal){
      jQuery('#jr-detail-modal').modal('show');
    }

    try {
      const params = new URLSearchParams();
      params.set('visita_id', String(visitaId));

      const res = await fetch(JOURNAL_DETAIL_ENDPOINT + '?' + params.toString(), {
        credentials: 'include',
        headers: { 'Accept': 'application/json' }
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!data || data.status !== 'ok') throw new Error(data && data.message ? data.message : 'Error desconocido');

      const basic = data.basic || {};
      const loc   = basic.local || {};
      const form  = basic.formulario || {};
      const titlePieces = [];
      if (loc.nombre) titlePieces.push(loc.nombre);
      if (form.nombre) titlePieces.push(form.nombre);
      const modalTitle = titlePieces.length ? titlePieces.join(' · ') : `Visita #${visitaId}`;

      setDetailModalTitle(modalTitle);
      setDetailModalBody(renderServerDetail(data, fromOffline));

    } catch (err){
      setDetailModalBody(
        `<div class="alert alert-danger">
           No se pudo cargar el detalle de la visita.<br>
           <small>${esc(err && err.message ? err.message : String(err))}</small>
         </div>`
      );
    }
  }
    function hasEndDate(item){
    return Boolean(item && item.tiempos && item.tiempos.fecha_fin);
  }
  
  
  // ---------- HTML para items venidos del SERVIDOR ----------

  function serverItemHTML(item){
    const local    = item.local       || {};
    const form     = item.formulario  || {};
    const tiempos  = item.tiempos     || {};
    const conteos  = item.conteos     || {};
    const distrito = (local.distrito && local.distrito.nombre) ? local.distrito.nombre : '';

    const lName  = local.nombre ? esc(local.nombre) : (local.id ? `Local #${local.id}` : '—');
    const codigo = local.codigo ? ` (${esc(local.codigo)})` : '';
    const comuna = distrito     ? ` · ${esc(distrito)}`      : '';
    const dir    = local.direccion ? esc(local.direccion)   : '';

    const timeRef = tiempos.fecha_inicio || tiempos.ultima_gestion_at || '';
    const timeStr = timeRef ? fmtTime(timeRef) : '';

    const campName   = form.nombre ? esc(form.nombre) : '';
    const modalidad  = (form.modalidad || '').toLowerCase();

    const fotos = Number(conteos.fotos_totales        || 0);
    const mats  = Number(conteos.materiales_distintos || 0);
    const preg  = Number(conteos.preguntas_respondidas || 0);

    // Tipo de gestión según modalidad
    const tipoGestion = tipoGestionDesdeModalidad(modalidad);

    const detailParts = [];

    // Solo mostramos materiales si la modalidad NO es solo auditoría
    if (tipoGestion !== 'Auditoría' && mats > 0){
      detailParts.push(plural(mats, 'material implementado', 'materiales implementados'));
    }

    // Siempre podemos mostrar preguntas respondidas si hay
    if (preg > 0){
      detailParts.push(plural(preg, 'pregunta respondida', 'preguntas respondidas'));
    }

    let summary = '';
    if (tipoGestion && detailParts.length){
      summary = `${tipoGestion} · ${detailParts.join(' · ')}`;
    } else if (tipoGestion){
      summary = tipoGestion;
    } else if (detailParts.length){
      summary = detailParts.join(' · ');
    } else {
      summary = 'Sin detalle registrado';
    }

    const durTxt  = fmtDuration(tiempos.duracion_seg);
    const durLine = durTxt
      ? `<div class="jr-subline">Duración: ${esc(durTxt)}</div>`
      : '';

    const visitaId   = item.visita_id != null ? Number(item.visita_id) : null;
    const clientGuid = item.client_guid ? String(item.client_guid) : '';
    const fromOffline = clientGuid && OFFLINE_GUIDS.has(clientGuid);
    const offlineChip = fromOffline
      ? '<span class="jr-chip jr-chip--offline">Offline</span>'
      : '';

    return `
      <div class="jr-item jr-item--server"
           data-visita-id="${visitaId != null ? esc(String(visitaId)) : ''}"
           data-client-guid="${esc(clientGuid)}"
           data-offline="${fromOffline ? '1' : '0'}">
        <div class="jr-left">
          <div class="jr-time">${esc(timeStr)}</div>
          <div class="jr-what">
            <div class="jr-title">${lName}${codigo}<small>${comuna}</small></div>
            ${dir ? `<div class="jr-subline">${dir}</div>` : ''}
            <div class="jr-sub">
              ${campName ? campName + ' · ' : ''}${esc(summary)}
            </div>
            ${durLine}
          </div>
        </div>
        <div class="jr-right">
          <span class="jr-chip jr-chip--ok">Subida</span>
          ${offlineChip}
          <div class="progress jr-progress">
            <div class="progress-bar progress-bar-success" style="width:100%">
              100%
            </div>
          </div>
          ${visitaId ? `
          <div class="jr-actions">
            <button class="btn btn-xs btn-default jr-view" data-visita-id="${esc(String(visitaId))}">
              <i class="fa fa-eye"></i> Ver detalle
            </button>
          </div>` : ''}
        </div>
      </div>
    `;
  }

  // ---------- Render Hoy (local + servidor) ----------

  async function renderToday(){
    if (!$today) return;

    const ymd  = JournalDB.ymdLocal(new Date());
    const rows = await JournalDB.listByYMD(ymd);

    // GUIDs offline para marcar subidas
    collectOfflineGuids(rows);

    // resolver nombres (incluye fallback a agenda + dirección/código)
    await Promise.all(rows.map(r => JournalDB.resolveNamesIfPossible(r)));

    const allGroups = groupFromRows(rows);
    GROUPS_TODAY    = allGroups;

    // Sólo dejamos en "Pendiente por subir" los grupos que NO estén 100% subidos
    const pendingGroups = Object.values(allGroups).filter(gr => gr.status !== 'success');

    const offlineHtml = pendingGroups.length
      ? pendingGroups
          .sort((a, b) => (a.created || 0) - (b.created || 0))
          .map(groupHTML).join('')
      : '';

    // Stats + progreso global (todas las tareas, incluidas subidas)
    const s = await JournalDB.statsFor(rows);
    badge(qs('#jr-badge-pending'), s.pending, 'Pendientes');
    badge(qs('#jr-badge-running'), s.running, 'Enviando');
    badge(qs('#jr-badge-success'), s.success, 'Subidas');
    badge(qs('#jr-badge-error'),   s.error,   'Errores');
    setGlobalProgress(s.success, s.pending + s.running + s.error + s.success);

    // Complementar con datos del servidor (visitas ya grabadas)
    let serverSection = '';
    let localSection  = '';

    try {
    const serverItems = await fetchServerJournal(ymd, ymd);
      const completedServerItems = (serverItems || []).filter(hasEndDate);
      if (completedServerItems.length){
        const serverHtml = completedServerItems.map(serverItemHTML).join('');
        if (serverHtml){
          serverSection = `
            <div class="jr-section jr-section--server">
              <h4 class="jr-section-title">Subido al servidor</h4>
              ${serverHtml}
            </div>
          `;
        }
      }
    } catch(_) {
      // silencioso; si falla, sólo veremos la cola local
    }

    if (offlineHtml){
      localSection = `
        <div class="jr-section jr-section--local">
          <h4 class="jr-section-title">Pendiente por subir</h4>
          ${offlineHtml}
        </div>
      `;
    }

    let finalHtml = serverSection + localSection;
    if (!finalHtml){
      finalHtml = `<div class="jr-empty"><i class="fa fa-cloud-download"></i> Sin gestiones hoy</div>`;
    }

    $today.innerHTML = finalHtml;
    initPopovers();
  }

  // ---------- Render Semana (local + servidor) ----------

  async function renderWeek(){
    if (!$week) return;

    const to   = new Date();
    const from = new Date();
    from.setDate(to.getDate() - 6);

    const fromYmd = JournalDB.ymdLocal(from);
    const toYmd   = JournalDB.ymdLocal(to);

    const rows = await JournalDB.listRange(fromYmd, toYmd);
    await Promise.all(rows.map(r => JournalDB.resolveNamesIfPossible(r)));

    // GUIDs offline para toda la semana (para marcar OFFLINE en server)
    collectOfflineGuids(rows);

    const byDate = {};
    rows.forEach(r => { (byDate[r.ymd] = byDate[r.ymd] || []).push(r); });

    GROUPS_WEEK = {};

    // Traer también visitas desde el servidor en el mismo rango
    let serverItems = [];
    try {
      serverItems = await fetchServerJournal(fromYmd, toYmd);
    } catch(_) {
      serverItems = [];
    }

    const srvByDate = {};
    if (Array.isArray(serverItems)){
      serverItems
        .filter(hasEndDate)
        .forEach(it => {
        let ymd = null;
        if (it.tiempos && it.tiempos.fecha_inicio){
          ymd = String(it.tiempos.fecha_inicio).slice(0, 10);
        } else if (it.tiempos && it.tiempos.ultima_gestion_at){
          ymd = String(it.tiempos.ultima_gestion_at).slice(0, 10);
        } else if (it.ymd){
          ymd = String(it.ymd);
        }
        if (!ymd) return;
        (srvByDate[ymd] = srvByDate[ymd] || []).push(it);
      });
    }

    // Unión de días donde haya algo (local o servidor)
    const allDaysSet = new Set([
      ...Object.keys(byDate),
      ...Object.keys(srvByDate)
    ]);
    const allDays = Array.from(allDaysSet).sort();

    const daysHtml = allDays.map(ymd => {
      const rowsForDay = byDate[ymd] || [];
      let offlineHtml = '';

      if (rowsForDay.length){
        const g = groupFromRows(rowsForDay);
        GROUPS_WEEK[ymd] = g;

        const pendingGroups = Object.values(g).filter(gr => gr.status !== 'success');
        if (pendingGroups.length){
          offlineHtml = pendingGroups
            .sort((a, b) => (a.created || 0) - (b.created || 0))
            .map(groupHTML)
            .join('');
        }
      }

      const srvList    = srvByDate[ymd] || [];
      const serverHtml = srvList.map(serverItemHTML).join('');

      let dayContent = '';
      if (serverHtml){
        dayContent += `
          <div class="jr-section jr-section--server">
            <h5 class="jr-section-title">Gestiones subidas</h5>
            ${serverHtml}
          </div>
        `;
      }
      if (offlineHtml){
        dayContent += `
          <div class="jr-section jr-section--local">
            <h5 class="jr-section-title">Pendiente por subir</h5>
            ${offlineHtml}
          </div>
        `;
      }

      if (!dayContent){
        dayContent = `<div class="jr-empty"><i class="fa fa-calendar-o"></i> Sin registros</div>`;
      }

      const f = new Date(ymd + 'T00:00:00');
      const title = f.toLocaleDateString('es-CL', {
        weekday: 'short', day: '2-digit', month: '2-digit'
      });

      return `<h4 class="jr-date">${title}</h4><div class="jr-day">${dayContent}</div>`;
    }).join('');

    $week.innerHTML = daysHtml ||
      `<div class="jr-empty"><i class="fa fa-calendar-o"></i> Semana sin registros</div>`;

    initPopovers();
  }

  // ---------- Interacciones ----------

  // Reintentar grupo (cola local)
  document.addEventListener('click', async (e) => {
    const grp = e.target.closest('.jr-group');
    if (!grp) return;

    if (e.target.closest('.jr-retry')){
      const btn = grp.querySelector('.jr-retry');
      if (btn && btn.hasAttribute('disabled')){
        e.preventDefault();
        return;
      }
      try{
        const key = grp.getAttribute('data-key');
        const g   = GROUPS_TODAY[key]; // reservado por si más adelante usamos algo del grupo
        void g; // evita warning de variable sin uso

        const old = btn.innerHTML;
        btn.innerHTML = `<i class="fa fa-refresh"></i> Reintentando...`;
        await window.Queue?.flushNow?.();
        btn.innerHTML = old;
      } catch(_){}
      e.preventDefault();
      return;
    }
  });

  // Ver detalle de tareas (cola local) y detalle servidor
  document.addEventListener('click', (e) => {
    // COLA LOCAL: ver detalle de tareas internas
    const grp = e.target.closest('.jr-group');
    if (grp && e.target.closest('.jr-view')){
      const key = grp.getAttribute('data-key');
      const det = document.querySelector(`.jr-details[data-for="${key}"]`);
      if (det){ det.hidden = !det.hidden; }
      e.preventDefault();
      return;
    }

    // SERVIDOR: ver detalle profundo de la visita
    const srv = e.target.closest('.jr-item--server');
    if (srv && e.target.closest('.jr-view')){
      const visitaId   = srv.getAttribute('data-visita-id');
      const offlineFlg = srv.getAttribute('data-offline') === '1';
      const titleDom   = srv.querySelector('.jr-title');
      const domTitle   = titleDom ? titleDom.textContent.trim() : null;
      showServerDetail(visitaId, offlineFlg, domTitle);
      e.preventDefault();
      return;
    }
  });

  // Botones globales
  const $btnFlush = qs('#jr-btn-flush');
  const $btnClear = qs('#jr-btn-clear-today');

  $btnFlush && $btnFlush.addEventListener('click', async () => {
    try { await window.Queue?.flushNow?.(); } catch(_){}
  });

  $btnClear && $btnClear.addEventListener('click', async () => {
    const ymd = JournalDB.ymdLocal(new Date());
    await JournalDB.clearUploadedFor(ymd);
    await renderToday();
    await renderWeek();
  });

  // Eventos de la cola (actualizan vista en caliente)
  function safeJob(e){
    return (e && e.detail && e.detail.job)
      ? e.detail.job
      : (e && e.detail)
        ? e.detail
        : null;
  }

  window.addEventListener('queue:enqueue',           async (e) => {
    const job = safeJob(e);
    if (!job) return;
    await JournalDB.onEnqueue(job);
    await renderToday();
  });

  window.addEventListener('queue:enqueued',          async () => {
    await renderToday();
  });

  window.addEventListener('queue:dispatch:start',    async (e) => {
    const job = safeJob(e);
    if (!job) return;
    await JournalDB.onStart(job);
    await renderToday();
  });

  window.addEventListener('queue:dispatch:progress', async (e) => {
    const job = safeJob(e);
    const p   = (e.detail && e.detail.progress) || 60;
    if (!job) return;
    await JournalDB.onProgress(job, p);
    await renderToday();
  });

  window.addEventListener('queue:dispatch:success',  async (e) => {
    const job  = safeJob(e);
    const resp = (e.detail && e.detail.response) || null;
    const httpStatus = (e.detail && e.detail.responseStatus) || null;
    if (!job) return;
    await JournalDB.onSuccess(job, resp, httpStatus);
    await renderToday();
    await renderWeek();

    // Mensaje cuando una gestión (procesar_gestion) se sube correctamente
    try {
      const kind  = ((job.kind || job.type || '') + '').toLowerCase();
      const okJson = !resp || !resp.status || resp.status === 'ok';
      if (okJson && kind.indexOf('procesar_gestion') !== -1){
        const msg = 'La gestión del local se subió correctamente al servidor.';
        if (window.swal) {
          window.swal('Gestión enviada', msg, 'success');
        } else if (window.alert) {
          window.alert(msg);
        }
      }
    } catch(_){}
  });

  window.addEventListener('queue:dispatch:error',    async (e) => {
    const job = safeJob(e);
    const msg = (e.detail && e.detail.error) || 'Error';
    if (!job) return;
    await JournalDB.onError(job, msg);
    await renderToday();
  });

  window.addEventListener('queue:update',            async () => {
    await renderToday();
  });

  // Popovers (Bootstrap)
  function initPopovers(){
    if (window.jQuery && jQuery.fn && jQuery.fn.popover){
      jQuery('[data-toggle="popover"]').popover();
    }
  }

  // tabs → render diferido
  qsa('a[href="#jr-semana"]').forEach(a =>
    a.addEventListener('shown.bs.tab', renderWeek)
  );

  // Init
  (async function init(){
    await JournalDB.openDB();
    await renderToday();
    await renderWeek();  
  })();

})();