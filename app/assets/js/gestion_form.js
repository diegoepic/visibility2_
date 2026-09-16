/**
 * Gestion: el formulario. Fotos de pregunta y de material, compresion y EXIF, pasos del
 * asistente, materiales, etapas, encuesta y apoyos.
 *
 * Extraido del <script> inline de gestionarPruebas.php (T6-6). Era el bloque mas grande
 * del proyecto: 2.384 lineas, 104 KB en cada apertura de gestion.
 *
 * Todo lo que PHP le tiene que contar a este archivo viaja en window.__GESTION, que se
 * declara en el <head> de gestionarPruebas.php. No hay ningun otro acoplamiento.
 *
 * Va PRECACHEADO en sw.js y por eso se pide SIN ?v=: cacheFirst() hace match sin
 * ignoreSearch, asi que con query string no encontraria la entrada precacheada y sin red
 * la pagina de gestion se quedaria sin funcionar. El cache-busting lo da el nombre del
 * cache (static-VERSION). Este archivo no lleva ningun dato del usuario.
 *
 * UN CAMBIO QUE NO ES SOLO DE SITIO
 * ---------------------------------
 * Dos manejadores (#btnNext2 y #btnBack2) estaban envueltos en un condicional de PHP
 * sobre $encuestaPendiente, o sea que PHP ni siquiera los imprimia cuando la campana no
 * traia encuesta. Aca eso no se puede hacer, asi que es un if de tiempo de ejecucion
 * sobre window.__GESTION.encuestaPendiente. El efecto es el mismo.
 *
 * Se carga DESPUES de gestion_offline.js.
 */

/* === Pregunta tipo foto === */
async function subirFotoPregunta(id_form_question, id_local) {
  const inputFile = document.getElementById('fotoPregunta_' + id_form_question);
  const file      = inputFile.files[0];
  if (!file) { alert("Debes seleccionar una imagen primero."); return; }

  // Aseguramos que exista un client_guid consistente para enlazar fotos offline
  const clientGuidInput = document.getElementById('client_guid');
  const clientGuid      = (clientGuidInput?.value || '').trim() || ensureClientGuid();
  if (clientGuidInput && !clientGuidInput.value) clientGuidInput.value = clientGuid;

  const visitaReal = await ensureRealVisitaId('pregunta_foto');
  if (visitaReal) { $('#visita_id').val(visitaReal); }

  // === Metadatos/coords ===
  let meta = await extractPhotoMeta(file);
  meta.capture_source = inputFile.dataset.captureSource || 'unknown';
  const coords = { lat: cachedPhotoCoords.lat || '', lng: cachedPhotoCoords.lng || '' };

  // === Compresión + conversión HEIC (el MISMO camino que las fotos de material) ===
  // Antes esto llamaba a imageCompression() directamente y nunca tocaba heic2any: una foto
  // HEIC tomada sin conexión llegaba cruda al servidor y el endpoint respondía 500. Las de
  // implementación no fallaban sólo porque sí pasaban por compressFile().
  // Se conservan los tamaños de este camino (800px / 0,2MB), que son más chicos a propósito.
  let compressedFile;
  try { compressedFile = await compressFile(file, { maxSizeMB: 0.2, maxWidthOrHeight: 800 }); }
  catch (_) { compressedFile = file; }

  // === UI de carga ===
  const preview = document.getElementById('previewFoto_' + id_form_question);
  const uploadInstance = document.createElement('div');
  uploadInstance.className = 'upload-instance';
  preview.appendChild(uploadInstance);
  const bar = document.createElement('div');
  bar.className = 'upload-bar';
  uploadInstance.appendChild(bar);

  // === Construcción del FormData ===
  const formData = new FormData();
 formData.append('visita_id', $('#visita_id').val());
  formData.append('id_form_question', id_form_question);
  formData.append('id_local', id_local);
  // Se manda tal cual lo devuelve compressFile: conserva su propio nombre y tipo. Antes se
  // reenvolvía con file.name, así que un HEIC ya convertido a JPEG viajaba llamándose .heic.
  formData.append('fotoPregunta', compressedFile);
  formData.append('csrf_token', window.CSRF_TOKEN);
  if (typeof formData.set === 'function') { formData.set('client_guid', clientGuid); }
  else { formData.append('client_guid', clientGuid); }
  formData.append('lat', coords.lat);
  formData.append('lng', coords.lng);
  formData.append('exif_datetime', meta.exif_datetime);
  formData.append('exif_lat', meta.exif_lat);
  formData.append('exif_lng', meta.exif_lng);
  formData.append('exif_altitude', meta.exif_altitude);
  formData.append('exif_img_direction', meta.exif_img_direction);
  formData.append('exif_make', meta.exif_make);
  formData.append('exif_model', meta.exif_model);
  formData.append('exif_software', meta.exif_software);
  formData.append('exif_lens_model', meta.exif_lens_model);
  formData.append('exif_fnumber', meta.exif_fnumber);
  formData.append('exif_exposure_time', meta.exif_exposure_time);
  formData.append('exif_iso', meta.exif_iso);
  formData.append('exif_focal_length', meta.exif_focal_length);
  formData.append('exif_orientation', meta.exif_orientation);
  formData.append('capture_source', meta.capture_source);
  formData.append('meta_json', meta.meta_json);

  // === Idempotencia (header + body) ===
  const idempo = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
  formData.append('X_Idempotency_Key', idempo);

  // === Intento online primero (si hay red real) ===
  const online = await isReallyOnline().catch(() => false);

  // Helper para pintar tarjeta (éxito u “en cola”)
  function renderThumb({ url, queuedId=null }) {
    // Si ya había una miniatura (el ejecutor repite la foto de la misma pregunta), su blob
    // quedaría vivo para siempre: borrar el HTML no le avisa nada al navegador.
    const previa = uploadInstance.querySelector('img.thumbnail');
    if (previa) V2Blobs.liberar(previa.src);
    uploadInstance.innerHTML = '';
    const wrapper = document.createElement('div');
    wrapper.className = 'img-container';
    wrapper.dataset.qid = id_form_question;

    const img = document.createElement('img');
    img.src = url;                    // en cola: blob URL, online: URL servidor
    img.className = 'thumbnail';
    img.style.cursor = 'pointer';
    img.title = 'Clic para ampliar';
    img.addEventListener('click', () => verImagenGrande(img.src));
    wrapper.appendChild(img);

    if (queuedId) {
      // Badge “en cola”
      const badge = document.createElement('div');
      badge.className = 'label label-info';
      badge.style.display = 'inline-block';
      badge.style.marginTop = '6px';
      badge.textContent = 'En cola';
      wrapper.appendChild(badge);

      // Botón cancelar (elimina tarea de IndexedDB y la miniatura)
      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'btn btn-xs btn-warning';
      cancel.style.marginLeft = '8px';
      cancel.textContent = 'Cancelar';
      cancel.onclick = async () => {
        try {
          const cancelled = await (window.Queue && typeof Queue.cancel === 'function'
            ? Queue.cancel(queuedId)
            : AppDB.remove(queuedId));
          if (!cancelled) throw new Error('cancel_failed');
          $(wrapper).fadeOut(150, () => wrapper.remove());
          mcToast('info', 'Cancelado', 'Se eliminó la foto de la cola.');
          // Limpia flag si no quedan fotos
          const container = document.getElementById('previewFoto_' + id_form_question);
          if (container && container.querySelectorAll('.img-container').length === 0) {
            const flag = document.getElementById('flagFoto_' + id_form_question);
            if (flag) flag.value = '';
          }
        } catch {
          mcToast('danger', 'Error', 'No se pudo cancelar la tarea en cola.');
        }
      };
      wrapper.appendChild(cancel);

      // Marca hidden para que el flujo sepa que existe contenido
      const flag = document.getElementById('flagFoto_' + id_form_question);
      if (flag) flag.value = 'queued:' + queuedId;
    } else {
      // Botón eliminar (online)
      const delBtn = document.createElement('button');
      delBtn.className = 'delete-button';
      delBtn.type = 'button';
      delBtn.title = 'Eliminar foto';
      delBtn.textContent = '×';
      delBtn.onclick = async function () {
        const imgUrl = img.src || '';
        const ok = await mcConfirm({
          title: 'Eliminar foto',
          message: `
            <div class="text-center">
              <p>¿Seguro que desea eliminar <strong>esta foto</strong> de la pregunta?</p>
              <img src="${imgUrl}" alt="Foto a eliminar"
                   class="img-thumbnail"
                   style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;">
            </div>
          `,
          confirmText: 'Sí, eliminar',
          confirmClass: 'btn-danger'
        });
        if (!ok) return;

        delBtn.disabled = true;
        try {
          const form = new FormData();
          form.append('csrf_token', window.CSRF_TOKEN);
          form.append('resp_id', wrapper.dataset.respId);
          form.append('id_form_question', wrapper.dataset.qid);
          form.append('visita_id', document.getElementById('visita_id').value);
          const r  = await fetch('/visibility2/app/eliminar_pregunta_foto_pruebas.php', { method: 'POST', body: form });
          const js = await r.json();
          if (js.status === 'success') {
            $(wrapper).fadeOut(150, () => {
              wrapper.remove();
              const container = document.getElementById('previewFoto_' + id_form_question);
              const quedan = container.querySelectorAll('.img-container').length;
              if (quedan === 0) {
                const flag = document.getElementById('flagFoto_' + id_form_question);
                if (flag) flag.value = '';
              }
            });
            mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
          } else {
            mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
            delBtn.disabled = false;
          }
        } catch (e) {
          console.error(e);
          mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
          delBtn.disabled = false;
        }
      };
      wrapper.appendChild(delBtn);
    }

    uploadInstance.appendChild(wrapper);
  }

  // --- Rama ONLINE: XHR con progreso + headers de seguridad ---
  if (online) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/visibility2/app/procesar_pregunta_foto_pruebas.php');
    xhr.withCredentials = true;   
    xhr.setRequestHeader('X-Idempotency-Key', idempo);
    // (Opcional) si el endpoint acepta CSRF por header:
    try { xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || ''); } catch(_) {}

    xhr.upload.onprogress = e => {
      if (e.lengthComputable) bar.style.width = Math.round(e.loaded / e.total * 100) + '%';
    };
    xhr.onload = () => {
      let data;
      try { data = JSON.parse(xhr.responseText); }
      catch {
        enqueueFallback();
        return;
      }

      // Foto duplicada exacta (409): rechazo terminal → NO encolar. Se descarta la miniatura y se
      // avisa al ejecutor con el local donde ya existe.
      if (xhr.status === 409 && data && data.error_code === 'DUPLICATE_PHOTO') {
        uploadInstance.remove();
        const msgDup = data.message || 'Foto duplicada: esta imagen ya fue cargada en otra sala.';
        if (typeof mcToast === 'function') mcToast('danger', 'Foto duplicada', msgDup);
        else alert(msgDup);
        resetFileInput(inputFile);
        const flag = document.getElementById('flagFoto_' + id_form_question);
        if (flag) flag.value = '';
        return;
      }

      if (xhr.status >= 200 && xhr.status < 300 && data.status === 'success') {
        // Pintar miniatura con URL definitiva y botón de borrar
        renderThumb({ url: normalizeAppUrl(data.fotoUrl), queuedId: null });
        // Guardar resp_id para eliminar
        const wrapper = uploadInstance.querySelector('.img-container');
        if (wrapper) wrapper.dataset.respId = data.resp_id;

        resetFileInput(inputFile);
        mcToast('success', 'Foto subida', '¡Se cargó correctamente!');
        const flag = document.getElementById('flagFoto_' + id_form_question);
        if (flag) flag.value = data.fotoUrl;
      } else {
        // Si el server respondió error, caemos a encolar
        enqueueFallback();
      }
    };
    xhr.onerror = enqueueFallback;
    xhr.send(formData);
    return;
  }
  
  
 
  

  // --- Rama OFFLINE o fallo online → encolar con Queue.smartPost ---
  await enqueueFallback();

  // ===== Helper: encolar y marcar UI “en cola” =====
  async function enqueueFallback() {
  try {
    // Llenamos la barra de “progreso” local para indicar que quedó en cola
    bar.style.width = '100%';

    // Verificamos que exista la cola offline
    const QueueObj =
      (window.Queue && typeof window.Queue.smartPost === 'function') ? window.Queue :
      (window.OfflineQueue && typeof window.OfflineQueue.smartPost === 'function') ? window.OfflineQueue :
      null;

    if (!QueueObj) {
      mcToast('danger', 'Queue no disponible', 'Revisa que /visibility2/app/assets/js/offline-queue.js esté cargado antes de este script.');
      // Render igual la miniatura local, pero sin queuedId (no habrá sincronización automática)
      const blobUrlFallback = V2Blobs.crear(compressedFile);
      renderThumb({ url: blobUrlFallback, queuedId: null });
      resetFileInput(inputFile);
      return;
    }

    // Encolamos el POST completo (incluye el archivo) para que se procese cuando haya red
    const dependsOn = clientGuid ? `create:${clientGuid}` : undefined;

    let res = await QueueObj.smartPost(
      '/visibility2/app/procesar_pregunta_foto_pruebas.php',
      formData,
      { type: 'pregunta_foto', sendCSRF: true, dependsOn, client_guid: clientGuid, idempotencyKey: idempo, timeoutMs: 60000, enqueueOnBlocked: true }
    );

    if (res && !res.queued && !res.ok && QueueObj.enqueueFromForm) {
      const forcedId = await QueueObj.enqueueFromForm(
        '/visibility2/app/procesar_pregunta_foto_pruebas.php',
        formData,
        { type: 'pregunta_foto', sendCSRF: true, dependsOn, client_guid: clientGuid, idempotencyKey: idempo, timeoutMs: 60000 }
      );
      res = { queued: true, ok: true, id: forcedId, blocked: res.blocked };
    }

    // Pintamos miniatura local (blob) y badge “En cola” si corresponde
    const blobUrl = V2Blobs.crear(compressedFile);
    renderThumb({ url: blobUrl, queuedId: (res && res.queued ? res.id : null) });

    // Limpia el input file (ya quedó persistido en IndexedDB por la cola)
    resetFileInput(inputFile);

    if (res && res.queued) {
      // Caso típico OFFLINE: quedó en cola
      mcToast('info', 'Offline', 'La foto se subirá al recuperar conexión.');

      /* Nos suscribimos al evento global que emite la cola cuando esa tarea se procese.
         El listener se quita solo al recibir SU job: antes quedaba puesto para siempre
         (`once:false` y sin removeEventListener), y como el closure retiene el FormData
         —con la foto comprimida adentro— cada foto encolada dejaba su imagen viva en
         memoria por el resto de la sesión, aunque ya se hubiera subido. De paso, con 20
         fotos encoladas eran 20 listeners corriendo en CADA evento de la cola. */
      const onQueueDone = async (ev) => {
        try {
          if (!ev || !ev.detail) return;
          const { id, type, response } = ev.detail;

          // Aseguramos que sea el MISMO job y del MISMO tipo
          if (id !== res.id || type !== 'pregunta_foto') return;

          // Es nuestro job y la cola sólo emite 'queue:done' tras subir con éxito (ahí borra
          // la tarea): no va a haber un segundo evento para este id, así que nos bajamos.
          window.removeEventListener('queue:done', onQueueDone);

          // Validamos respuesta del backend
          if (!response || response.status !== 'success' || !response.fotoUrl) return;

          // Reemplazamos el blob URL por la URL final del servidor Y soltamos el blob:
          // la foto ya está en el servidor, mantenerla en memoria no aporta nada.
          const thumb = uploadInstance.querySelector('img.thumbnail');
          V2Blobs.reemplazarPorUrlServidor(thumb, response.fotoUrl);

          // Actualizamos contenedor de la miniatura: quitamos “en cola”, agregamos botón eliminar
          const w = uploadInstance.querySelector('.img-container');
          if (w) {
            // Limpia metadata de cola
            w.removeAttribute('data-queued-id');
            if (response.resp_id) w.dataset.respId = response.resp_id;

            // Quitar badge “En cola”
            const lab = w.querySelector('.label.label-info');
            if (lab) lab.remove();

            // Quitar botón “Cancelar” (si lo tenías)
            const cancelBtn = w.querySelector('button.btn-warning');
            if (cancelBtn) cancelBtn.remove();

            // Añadir botón eliminar (online)
            const delBtn = document.createElement('button');
            delBtn.className = 'delete-button';
            delBtn.type = 'button';
            delBtn.title = 'Eliminar foto';
            delBtn.textContent = '×';
            delBtn.onclick = async () => {
              const ok = await mcConfirm({
                title: 'Eliminar foto',
                message: `
                  <div class="text-center">
                    <p>¿Seguro que desea eliminar <strong>esta foto</strong> de la pregunta?</p>
                    <img src="${thumb?.src || ''}" alt="Foto a eliminar"
                         class="img-thumbnail"
                         style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;">
                  </div>
                `,
                confirmText: 'Sí, eliminar',
                confirmClass: 'btn-danger'
              });
              if (!ok) return;

              try {
                const form = new FormData();
                form.append('csrf_token', window.CSRF_TOKEN || '');
                form.append('resp_id', w.dataset.respId || '');
                form.append('id_form_question', w.dataset.qid || String(id_form_question));
                form.append('visita_id', document.getElementById('visita_id')?.value || '');
                const r  = await fetch('/visibility2/app/eliminar_pregunta_foto_pruebas.php', { method: 'POST', body: form });
                const js = await r.json().catch(() => ({}));
                if (js.status === 'success') {
                  $(w).fadeOut(150, () => w.remove());
                  mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
                  const flag = document.getElementById('flagFoto_' + id_form_question);
                  if (flag) flag.value = '';
                } else {
                  mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
                }
              } catch (err) {
                console.error(err);
                mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
              }
            };
            w.appendChild(delBtn);
          }

          // Actualizamos flag hidden con la URL definitiva
          const flag = document.getElementById('flagFoto_' + id_form_question);
          if (flag) flag.value = response.fotoUrl;

          mcToast('success', 'Foto sincronizada', '¡Tu foto se subió correctamente!');
        } catch (err) {
          console.error(err);
        }
      };
      window.addEventListener('queue:done', onQueueDone);

    } else if (res && res.ok && res.response && res.response.fotoUrl) {
      // Caso menos común: smartPost logró subirla online directamente
      const flag = document.getElementById('flagFoto_' + id_form_question);
      if (flag) flag.value = res.response.fotoUrl;

    } else {
      // No tenemos confirmación; igual la miniatura quedó y la cola intentará reenviar
      mcToast('warning', 'Aviso', 'No se pudo confirmar la subida; quedó en cola local.');
    }
  } catch (e) {
    console.error(e);
    uploadInstance.innerHTML = `<div class="alert alert-danger" style="margin-top:8px;">No se pudo encolar la foto.</div>`;
  }
}
}

function verImagenGrande(url) {
  let modalImg = document.getElementById('imagenAmpliada');
  modalImg.src = url;
  $('#modalImagenGrande').modal('show');
}

/* ====== utilitarios EXIF ====== */
function pad2(n){ return n<10 ? '0'+n : ''+n; }
function toMySQLDateTime(d){
  if (!(d instanceof Date)) return '';
  return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate())+' '+pad2(d.getHours())+':'+pad2(d.getMinutes())+':'+pad2(d.getSeconds());
}
function toFixedOrEmpty(v, decimals){ if (v==null || isNaN(v)) return ''; return Number(v).toFixed(decimals); }
function exposureToStr(x){ if (!x || isNaN(x)) return ''; if (x >= 1) return x.toFixed(3); const den = Math.round(1/x); return `1/${den}`; }

async function extractPhotoMeta(file){
  try{
    const ex = await exifr.parse(file, { gps: true, tiff: true, ifd0: true, exif: true, xmp: true, jfif: true, iptc: true }) || {};
    const exifDate = ex.DateTimeOriginal || ex.CreateDate || ex.ModifyDate || null;
    const dt = exifDate ? toMySQLDateTime(exifDate) : '';
    return {
      exif_datetime      : dt,
      exif_lat           : toFixedOrEmpty(ex.latitude, 7),
      exif_lng           : toFixedOrEmpty(ex.longitude, 7),
      exif_altitude      : toFixedOrEmpty(ex.altitude, 2),
      exif_img_direction : toFixedOrEmpty(ex.GPSImgDirection, 2),
      exif_make          : ex.Make    || '',
      exif_model         : ex.Model   || '',
      exif_software      : ex.Software|| '',
      exif_lens_model    : ex.LensModel || '',
      exif_fnumber       : toFixedOrEmpty(ex.FNumber, 2),
      exif_exposure_time : exposureToStr(ex.ExposureTime),
      exif_iso           : (ex.ISO && !isNaN(ex.ISO)) ? parseInt(ex.ISO,10) : '',
      exif_focal_length  : toFixedOrEmpty(ex.FocalLength, 1),
      exif_orientation   : (ex.Orientation && !isNaN(ex.Orientation)) ? parseInt(ex.Orientation,10) : '',
      capture_source     : 'unknown',
      meta_json          : JSON.stringify({
        DateTimeOriginal : ex.DateTimeOriginal || null,
        CreateDate       : ex.CreateDate || null,
        SubSecTimeOriginal: ex.SubSecTimeOriginal || null,
        GPSImgDirectionRef: ex.GPSImgDirectionRef || null,
      })
    };
  } catch(e){
    console.warn('No se pudieron leer metadatos EXIF:', e);
    return {
      exif_datetime:'', exif_lat:'', exif_lng:'', exif_altitude:'', exif_img_direction:'',
      exif_make:'', exif_model:'', exif_software:'', exif_lens_model:'',
      exif_fnumber:'', exif_exposure_time:'', exif_iso:'', exif_focal_length:'',
      exif_orientation:'', capture_source:'unknown', meta_json: '{}'
    };
  }
}

/**
 * Prepara una foto para subir: convierte HEIC/HEIF a JPEG y comprime.
 *
 * `opciones` permite que cada camino conserve su propio tamaño: las fotos de material van a
 * 1280px/1MB y las de encuesta a 800px/0,2MB. Lo que NO se puede tener es un camino sin
 * conversión HEIC — era exactamente el bug: la foto de encuesta llamaba a imageCompression()
 * directamente, sin pasar por heic2any, y sin conexión (donde imageCompression tampoco
 * existía) el .heic salía crudo al servidor.
 */
async function compressFile(file, opciones) {
  // Convertir HEIC/HEIF a JPEG antes de comprimir — Chrome/Android no soporta HEIC en Canvas
  const isHeic = file.type === 'image/heic' || file.type === 'image/heif' ||
                 /\.(heic|heif)$/i.test(file.name);
  if (isHeic) {
    if (typeof heic2any === 'function') {
      try {
        const jpegBlob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.92 });
        const blob = Array.isArray(jpegBlob) ? jpegBlob[0] : jpegBlob;
        file = new File([blob], file.name.replace(/\.(heic|heif)$/i, '.jpg'),
                        { type: 'image/jpeg', lastModified: Date.now() });
      } catch (e) {
        console.warn('heic2any falló, enviando original al servidor:', e);
        return file;
      }
    } else {
      return file; // sin librería: el servidor lo convierte, thumbnail quedará roto
    }
  }

  if (typeof imageCompression !== 'function') return file;
  const options = Object.assign(
    { maxSizeMB: 1, maxWidthOrHeight: 1280, useWebWorker: true, fileType: 'image/jpeg' },
    opciones || {}
  );
  try {
    const blob = await imageCompression(file, options);
    const safeName = file.name.replace(/\.[^.]+$/, '.jpg');
    return new File([blob], safeName, { type: 'image/jpeg', lastModified: Date.now() });
  } catch (_) { return file; }
}

function etapaActualDe(idFQ) {
  const sel = document.querySelector(`.etapa-material-select[data-id-material="${idFQ}"]`);
  return sel ? sel.value : '';
}

async function uploadFile(file, url, idFQ, onProgress, meta = {}, extra = {}) {
  pendingUploads++;
  updateFinalizeButton();

  try {
    const visitaReal = await ensureRealVisitaId('material_foto');
    if (visitaReal) { $('#visita_id').val(visitaReal); }

    const resp = await new Promise((res, rej) => {
      const fd = new FormData();
      fd.append('visita_id', $('#visita_id').val());
      fd.append('client_guid', document.getElementById('client_guid')?.value || '');
      fd.append('foto', file);
      fd.append('csrf_token', window.CSRF_TOKEN);
      fd.append('idFQ', idFQ);
      fd.append('idCampana', document.querySelector('input[name="idCampana"]').value);
      fd.append('idLocal', document.querySelector('input[name="idLocal"]').value);
      fd.append('division_id', window.campaignDivision);
      fd.append('etapa_material', etapaActualDe(idFQ));
      if (extra.lat) fd.append('lat', extra.lat);
      if (extra.lng) fd.append('lng', extra.lng);

      fd.append('exif_datetime', meta.exif_datetime || '');
      fd.append('exif_lat', meta.exif_lat || '');
      fd.append('exif_lng', meta.exif_lng || '');
      fd.append('exif_altitude', meta.exif_altitude || '');
      fd.append('exif_img_direction', meta.exif_img_direction || '');
      fd.append('exif_make', meta.exif_make || '');
      fd.append('exif_model', meta.exif_model || '');
      fd.append('exif_software', meta.exif_software || '');
      fd.append('exif_lens_model', meta.exif_lens_model || '');
      fd.append('exif_fnumber', meta.exif_fnumber || '');
      fd.append('exif_exposure_time', meta.exif_exposure_time || '');
      fd.append('exif_iso', meta.exif_iso || '');
      fd.append('exif_focal_length', meta.exif_focal_length || '');
      fd.append('exif_orientation', meta.exif_orientation || '');
      fd.append('capture_source', meta.capture_source || extra.capture_source || 'unknown');
      fd.append('meta_json', meta.meta_json || '{}');

      const xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.upload.onprogress = e => { if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100)); };
      xhr.onload  = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          try { res(JSON.parse(xhr.response)); } catch { res({}); }
          return;
        }
        // Error HTTP: rechazar con objeto estructurado (status + cuerpo parseado) para que el
        // llamador distinga un rechazo terminal (p. ej. foto duplicada 409) de un error transitorio.
        let body = null; try { body = JSON.parse(xhr.response); } catch (_) {}
        const e = new Error((body && body.message) || xhr.responseText || ('HTTP ' + xhr.status));
        e.status = xhr.status;
        e.body = body;
        e.duplicate = (xhr.status === 409 && !!body && body.error_code === 'DUPLICATE_PHOTO');
        rej(e);
      };
      xhr.onerror = () => { const e = new Error(xhr.statusText || 'network'); e.status = 0; rej(e); };
      try { xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || ''); } catch(_) {}
        const _idem = (crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random()));
        try { xhr.setRequestHeader('X-Idempotency-Key', _idem); } catch(_) {}
        xhr.send(fd);
    });
    return resp;
  } finally {
    pendingUploads--;
    updateFinalizeButton();
  }
}

function syncOfflineMetaInputs(container, idFQ, metas = []) {
  if (!container) return;
  container.innerHTML = '';
  metas.forEach(meta => {
    const metaInput = document.createElement('input');
    metaInput.type = 'hidden';
    metaInput.name = `fotos_meta[${idFQ}][]`;
    metaInput.value = JSON.stringify({
      capture_source: meta.capture_source || 'unknown',
      lat: meta.lat || '',
      lng: meta.lng || ''
    });
    if (meta.localId) metaInput.dataset.localId = meta.localId;
    container.appendChild(metaInput);
  });
}

/* === Encola una foto de material individualmente (upload durante la visita) === */
async function enqueueMaterialFoto({ idFQ, compressed, meta, coords, captureSource,
                                     wrapper, imgContainer, bar, blobUrl,
                                     hiddenContainer, inputElem }) {
  const QueueObj =
    (window.Queue && typeof window.Queue.smartPost === 'function') ? window.Queue :
    (window.OfflineQueue && typeof window.OfflineQueue.smartPost === 'function') ? window.OfflineQueue :
    null;
  if (!QueueObj) {
    bar.classList.add('error');
    mcToast('danger', 'Queue no disponible', 'No se puede encolar la foto.');
    return;
  }

  const localId    = crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36));
  const idempo     = crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random().toString(36));
  const clientGuid = document.getElementById('client_guid')?.value || ensureClientGuid();
  const dependsOn  = `create:${clientGuid}`;

  wrapper.dataset.localId = localId;
  bar.style.width = '100%';

  const qfd = new FormData();
  qfd.append('foto', compressed, compressed.name || 'material.jpg');
  qfd.append('idFQ', idFQ);
  qfd.append('idCampana', document.querySelector('input[name="idCampana"]').value);
  qfd.append('idLocal', document.querySelector('input[name="idLocal"]').value);
  qfd.append('division_id', window.campaignDivision || 0);
  qfd.append('etapa_material', etapaActualDe(idFQ));
  qfd.append('visita_id', document.getElementById('visita_id')?.value || '');
  qfd.append('client_guid', clientGuid);
  qfd.append('lat', coords?.lat || '');
  qfd.append('lng', coords?.lng || '');
  qfd.append('csrf_token', window.CSRF_TOKEN || '');
  qfd.append('capture_source', captureSource || 'gallery');
  qfd.append('exif_datetime',      meta.exif_datetime      || '');
  qfd.append('exif_lat',           meta.exif_lat           || '');
  qfd.append('exif_lng',           meta.exif_lng           || '');
  qfd.append('exif_altitude',      meta.exif_altitude      || '');
  qfd.append('exif_img_direction', meta.exif_img_direction || '');
  qfd.append('exif_make',          meta.exif_make          || '');
  qfd.append('exif_model',         meta.exif_model         || '');
  qfd.append('exif_software',      meta.exif_software      || '');
  qfd.append('exif_lens_model',    meta.exif_lens_model    || '');
  qfd.append('exif_fnumber',       meta.exif_fnumber       || '');
  qfd.append('exif_exposure_time', meta.exif_exposure_time || '');
  qfd.append('exif_iso',           meta.exif_iso           || '');
  qfd.append('exif_focal_length',  meta.exif_focal_length  || '');
  qfd.append('exif_orientation',   meta.exif_orientation   || '');
  qfd.append('meta_json',          meta.meta_json          || '{}');

  let taskRef;
  try {
    taskRef = await QueueObj.smartPost(
      '/visibility2/app/upload_material_foto_pruebas.php',
      qfd,
      { type: 'material_foto', sendCSRF: true, dependsOn, client_guid: clientGuid,
        idempotencyKey: idempo, timeoutMs: 60000,
        enqueueOnFail: true, enqueueOnBlocked: true }
    );
  } catch (qErr) {
    console.error('[material_foto] Error al encolar:', qErr);
    bar.classList.add('error');
    mcToast('danger', 'Error', 'No se pudo encolar la foto.');
    return;
  }

  if (!inputElem._queuedPhotos) inputElem._queuedPhotos = [];
  inputElem._queuedPhotos.push({ localId, taskId: taskRef.id });

  const badge = document.createElement('span');
  badge.className = 'badge badge-warning';
  badge.dataset.queueBadge = taskRef.id;
  badge.style.cssText = 'position:absolute;top:4px;left:4px;font-size:10px;';
  badge.textContent = 'En cola';
  wrapper.style.position = 'relative';
  wrapper.appendChild(badge);

  const delBtnQueue = document.createElement('button');
  delBtnQueue.className   = 'delete-button';
  delBtnQueue.type        = 'button';
  delBtnQueue.title       = 'Cancelar y eliminar foto';
  delBtnQueue.textContent = '×';
  delBtnQueue.onclick = async function () {
    const ok = await mcConfirm({
      title: 'Eliminar foto',
      message: `<div class="text-center"><p>¿Seguro que desea eliminar <strong>esta foto</strong> del material?</p><img src="${wrapper.querySelector('img.thumbnail')?.src || ''}" class="img-thumbnail" style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;"></div>`,
      confirmText: 'Sí, eliminar', confirmClass: 'btn-danger'
    });
    if (!ok) return;
    if (taskRef?.id) {
      try { await QueueObj.cancel(taskRef.id, { reason: 'user_delete' }); } catch(_) {}
    }
    window.removeEventListener('queue:done', onDone);
    if (inputElem._queuedPhotos) {
      inputElem._queuedPhotos = inputElem._queuedPhotos.filter(p => p.taskId !== taskRef?.id);
    }
    V2Blobs.liberar(blobUrl);
    $(wrapper).fadeOut(150, () => wrapper.remove());
  };
  imgContainer.appendChild(delBtnQueue);

  const onDone = (ev) => {
    if (!ev?.detail || ev.detail.id !== taskRef.id || ev.detail.type !== 'material_foto') return;
    window.removeEventListener('queue:done', onDone);
    const resp = ev.detail.response;
    if (!resp?.url) return;

    // La foto quedó subida: la miniatura pasa a la URL del servidor y el blob se libera.
    const thumb = wrapper.querySelector('img.thumbnail');
    V2Blobs.reemplazarPorUrlServidor(thumb, resp.url);

    const h = document.createElement('input');
    h.type  = 'hidden';
    h.name  = `fotos[${idFQ}][]`;
    h.value = resp.url;
    if (resp.id_foto) h.dataset.idFoto = String(resp.id_foto);
    hiddenContainer.appendChild(h);
    wrapper.dataset.idFoto = resp.id_foto || '';

    const b = wrapper.querySelector(`[data-queue-badge="${taskRef.id}"]`);
    if (b) b.remove();
    bar.remove();

    if (inputElem._queuedPhotos) {
      inputElem._queuedPhotos = inputElem._queuedPhotos.filter(p => p.taskId !== taskRef.id);
    }

    // Notificar al draft que esta foto fue subida
    if (window.GestionDraft && resp.id_foto) {
      window.GestionDraft.markFotoMatUpload(idFQ, localId, resp.id_foto, resp.url || resp.foto_url || '');
    }

    delBtnQueue.remove();
    const delBtnOnline = document.createElement('button');
    delBtnOnline.className   = 'delete-button';
    delBtnOnline.type        = 'button';
    delBtnOnline.title       = 'Eliminar foto';
    delBtnOnline.textContent = '×';
    delBtnOnline.onclick = async function () {
      const ok2 = await mcConfirm({
        title: 'Eliminar foto',
        message: `<div class="text-center"><p>¿Seguro que desea eliminar <strong>esta foto</strong> del material?</p><img src="${wrapper.querySelector('img.thumbnail')?.src || ''}" class="img-thumbnail" style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;"></div>`,
        confirmText: 'Sí, eliminar', confirmClass: 'btn-danger'
      });
      if (!ok2) return;
      delBtnOnline.disabled = true;
      try {
        const form = new FormData();
        form.append('csrf_token', window.CSRF_TOKEN || getCSRF());
        form.append('id_foto', wrapper.dataset.idFoto);
        form.append('id_formularioQuestion', idFQ);
        form.append('visita_id', document.getElementById('visita_id').value);
        const _idem2 = crypto.randomUUID ? crypto.randomUUID() : (Date.now() + '-' + Math.random());
        form.append('X_Idempotency_Key', _idem2);
        const r  = await fetch('/visibility2/app/eliminar_material_foto_pruebas.php', { method: 'POST', body: form });
        const js = await r.json();
        if (js.status === 'success') {
          const matching = hiddenContainer.querySelector(`input[data-id-foto="${wrapper.dataset.idFoto}"]`);
          if (matching) matching.remove();
          V2Blobs.liberar(blobUrl);
          $(wrapper).fadeOut(150, () => wrapper.remove());
          mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
        } else {
          mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
          delBtnOnline.disabled = false;
        }
      } catch (e) {
        console.error(e);
        mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
        delBtnOnline.disabled = false;
      }
    };
    imgContainer.appendChild(delBtnOnline);

    mcToast('success', 'Foto sincronizada', 'La foto de material se subió correctamente.');
  };
  window.addEventListener('queue:done', onDone);

  mcToast('info', 'Offline', 'La foto se subirá al recuperar conexión.');
}

/* === FileInput materiales con soporte OFFLINE === */
function setupFileInput(inputElem) {
  const idFQ = inputElem.id.split('_').pop();
  const previewContainer = document.createElement('div');
  previewContainer.classList.add('preview-container');
  inputElem.parentNode.appendChild(previewContainer);

  const hiddenContainer = document.createElement('div');
  hiddenContainer.id = 'hiddenUploadContainer_' + idFQ;
  inputElem.parentNode.appendChild(hiddenContainer);

  const metaContainer = document.createElement('div');
  metaContainer.id = 'hiddenMetaContainer_' + idFQ;
  inputElem.parentNode.appendChild(metaContainer);

  inputElem._retainedFilesDT = inputElem._retainedFilesDT || new DataTransfer();
  inputElem._retainedMeta = inputElem._retainedMeta || [];

  inputElem.addEventListener('change', async () => {
    ensureClientGuid();

    const previousSelection = new DataTransfer();
    Array.from(inputElem.files || []).forEach(f => previousSelection.items.add(f));
    const files = Array.from(previousSelection.files || []);
    if (files.length === 0) return;

    const yaSubidas = document.querySelectorAll(
      `#hiddenUploadContainer_${idFQ} input[type="hidden"][name^="fotos[${idFQ}]"]`
    ).length;

    const offlineCount = (inputElem._queuedPhotos ? inputElem._queuedPhotos.length : 0)
      + ((inputElem._retainedFilesDT && inputElem._retainedFilesDT.files)
          ? inputElem._retainedFilesDT.files.length : 0);

    if (yaSubidas + offlineCount + files.length > MAX_FOTOS_POR_MATERIAL) {
      alert(`Máximo ${MAX_FOTOS_POR_MATERIAL} fotos por material. Ya tienes ${yaSubidas + offlineCount}.`);
      return;
    }

    const sourceSelect = document.querySelector(`select.photo-source[data-target-input="fotos_input_${idFQ}"]`);
    const captureSource = (sourceSelect && sourceSelect.value) || 'gallery';

    const tieneVisita = !!$('#visita_id').val();
    const online = await isReallyOnline();   // ← una sola vez para todo el batch
    const cg = document.getElementById('client_guid')?.value || '';
    const allowOnline = online && (tieneVisita || cg);

    if (!cg) { ensureClientGuid(); }

    const coords = { lat: cachedPhotoCoords.lat || '', lng: cachedPhotoCoords.lng || '' };
    const retainedDT = inputElem._retainedFilesDT || new DataTransfer();
    const retainedMeta = Array.isArray(inputElem._retainedMeta) ? inputElem._retainedMeta : [];

    // ── Fase 1: crear wrappers DOM en orden (sync) para que aparezcan de inmediato
    const fileData = files.map(rawFile => {
      const wrapper = document.createElement('div');
      wrapper.classList.add('upload-instance');

      const imgContainer = document.createElement('div');
      imgContainer.classList.add('img-container');

      const img = document.createElement('img');
      img.classList.add('thumbnail');
      img.style.cursor = 'pointer';
      img.title = 'Clic para ampliar';

      const bar = document.createElement('div');
      bar.classList.add('upload-bar');

      imgContainer.appendChild(img);
      wrapper.appendChild(imgContainer);
      wrapper.appendChild(bar);
      previewContainer.appendChild(wrapper);

      return { rawFile, wrapper, imgContainer, img, bar, blobUrl: null, meta: null, compressed: null };
    });

    // ── Fase 2: comprimir + extraer meta en paralelo; thumbnail aparece en cuanto comprime
    await Promise.all(fileData.map(async fd => {
      const [meta, compressed] = await Promise.all([
        extractPhotoMeta(fd.rawFile),
        compressFile(fd.rawFile)
      ]);
      meta.capture_source = captureSource;
      fd.meta       = meta;
      fd.compressed = compressed;
      fd.blobUrl    = V2Blobs.crear(compressed);
      fd.img.src    = fd.blobUrl;
      // Se captura SOLO el elemento, no `fd`: un closure sobre fd mantendría vivos también
      // fd.rawFile (la foto original de la cámara, varios MB) y fd.compressed mientras la
      // miniatura siga en pantalla.
      const imgEl = fd.img;
      imgEl.addEventListener('click', () => verImagenGrande(imgEl.src));
    }));

    // ── Fase 3: subir (online) u offline en paralelo
    await Promise.all(fileData.map(async fd => {
      const { compressed, meta, wrapper, imgContainer, bar } = fd;
      const blobUrl = fd.blobUrl;

      if (allowOnline) {
        try {
          const resp = await uploadFile(
            compressed,
            '/visibility2/app/upload_material_foto_pruebas.php',
            idFQ,
            pct => { bar.style.width = pct + '%'; },
            meta,
            coords
          );

          bar.remove();
          wrapper.dataset.idFoto = resp.id_foto || '';

          // Subida OK: la miniatura pasa a la URL del servidor y se suelta el blob. Antes la
          // miniatura seguía apuntando al blob toda la sesión, así que cada foto sacada quedaba
          // retenida en memoria aunque ya estuviera guardada en el servidor.
          V2Blobs.reemplazarPorUrlServidor(fd.img, resp.url);

          const hiddenInput = document.createElement('input');
          hiddenInput.type = 'hidden';
          hiddenInput.name = `fotos[${idFQ}][]`;
          hiddenInput.value = resp.url;
          hiddenInput.dataset.idFoto = resp.id_foto || '';
          hiddenContainer.appendChild(hiddenInput);

          // ── Delete button (online: llama endpoint servidor)
          const delBtn = document.createElement('button');
          delBtn.className = 'delete-button';
          delBtn.type = 'button';
          delBtn.title = 'Eliminar foto';
          delBtn.textContent = '×';
          delBtn.onclick = async function () {
            const ok = await mcConfirm({
              title: 'Eliminar foto',
              message: `
                <div class="text-center">
                  <p>¿Seguro que desea eliminar <strong>esta foto</strong> del material?</p>
                  <img src="${wrapper.querySelector('img.thumbnail')?.src || ''}" alt="Foto a eliminar"
                       class="img-thumbnail"
                       style="max-height:220px;max-width:100%;margin-top:8px;object-fit:contain;">
                </div>
              `,
              confirmText: 'Sí, eliminar',
              confirmClass: 'btn-danger'
            });
            if (!ok) return;

            delBtn.disabled = true;
            try {
              const form = new FormData();
              form.append('csrf_token', window.CSRF_TOKEN || getCSRF());  // Bug 3: fallback al hidden input
              form.append('id_foto', wrapper.dataset.idFoto);
              form.append('id_formularioQuestion', idFQ);
              form.append('visita_id', document.getElementById('visita_id').value);
              const _idem = (crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random());
              form.append('X_Idempotency_Key', _idem);  // Bug 2: idempotencia en delete
              const r  = await fetch('/visibility2/app/eliminar_material_foto_pruebas.php', { method: 'POST', body: form });
              const js = await r.json();
              if (js.status === 'success') {
                const matching = hiddenContainer.querySelector(`input[data-id-foto="${wrapper.dataset.idFoto}"]`);
                if (matching) matching.remove();
                V2Blobs.liberar(blobUrl);
                $(wrapper).fadeOut(150, () => wrapper.remove());
                mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
              } else {
                mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
                delBtn.disabled = false;
              }
            } catch (e) {
              console.error(e);
              mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
              delBtn.disabled = false;
            }
          };
          imgContainer.appendChild(delBtn);

          const okMsg = document.createElement('div');
          okMsg.className = 'alert alert-success';
          okMsg.style.marginTop = '8px';
          okMsg.textContent = '¡Foto subida exitosamente!';
          wrapper.appendChild(okMsg);
          setTimeout(() => { $(okMsg).fadeOut(300, () => okMsg.remove()); }, 2000);
        } catch (err) {
          // Foto duplicada exacta (409): rechazo terminal → NO encolar (nunca se aceptaría).
          // Se descarta la miniatura y se avisa al ejecutor con el local donde ya existe.
          if (err && err.duplicate) {
            try { bar.remove(); } catch (_) {}
            try { V2Blobs.liberar(blobUrl); } catch (_) {}
            $(wrapper).fadeOut(150, () => wrapper.remove());
            const msgDup = (err.body && err.body.message) || 'Foto duplicada: esta imagen ya fue cargada en otra sala.';
            if (typeof mcToast === 'function') mcToast('danger', 'Foto duplicada', msgDup);
            else if (typeof mcAlert === 'function') await mcAlert('Foto duplicada', msgDup);
            else alert(msgDup);
          } else {
            console.warn('[material_foto] upload online falló, encolando:', err);
            await enqueueMaterialFoto({ idFQ, compressed, meta, coords, captureSource,
              wrapper, imgContainer, bar, blobUrl, hiddenContainer, inputElem });
          }
        }
      } else {
        // ── Offline: encolar foto individualmente (se sube al recuperar red)
        await enqueueMaterialFoto({ idFQ, compressed, meta, coords, captureSource,
          wrapper, imgContainer, bar, blobUrl, hiddenContainer, inputElem });
      }

      /* La foto ya está subida o persistida en la cola (IndexedDB): las copias en memoria no
         hacen falta más. Sin esto, `fileData` conserva el archivo original de la cámara Y su
         versión comprimida por cada foto de la gestión — con la original pesando varios MB,
         que es lo que más suma. */
      fd.rawFile    = null;
      fd.compressed = null;
    }));

    if (allowOnline) {
      const onlineAgain = await isReallyOnline();
      if (onlineAgain) inputElem.value = '';
    } else {
      inputElem.value = '';
    }
  });
}

function bindCompressionTo(inputElem) {
  inputElem.addEventListener('change', async function(e) {
    if (!this.files || !this.files.length) return;
    const files = Array.from(this.files);
    const compressed = await Promise.all(files.map(f => compressFile(f)));
    const dt = new DataTransfer();
    compressed.forEach(f => dt.items.add(f));
    this.files = dt.files;
  });
}

function setupEvidencePreview(inputId) {
  const input = document.getElementById(inputId);
  const preview = document.getElementById('evPreview_' + inputId);
  if (!input || !preview) return;

  let _evBlobUrl = null;
  input.addEventListener('change', function () {
    if (_evBlobUrl) { V2Blobs.liberar(_evBlobUrl); _evBlobUrl = null; }
    preview.innerHTML = '';
    if (!this.files || !this.files.length) { preview.style.display = 'none'; return; }

    _evBlobUrl = V2Blobs.crear(this.files[0]);
    preview.style.cssText = 'display:block; margin-top:8px;';

    const wrapper = document.createElement('div');
    wrapper.className = 'img-container';

    const img = document.createElement('img');
    img.src = _evBlobUrl;
    img.className = 'thumbnail';
    img.style.cursor = 'pointer';
    img.title = 'Clic para ampliar';
    img.addEventListener('click', () => verImagenGrande(_evBlobUrl));

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'delete-button';
    delBtn.title = 'Eliminar foto';
    delBtn.textContent = '×';
    delBtn.onclick = function () {
      if (_evBlobUrl) { V2Blobs.liberar(_evBlobUrl); _evBlobUrl = null; }
      resetFileInput(input);
      preview.innerHTML = '';
      preview.style.display = 'none';
    };

    wrapper.appendChild(img);
    wrapper.appendChild(delBtn);
    preview.appendChild(wrapper);
  });
}

async function compressEvidenceInputs() {
  const ids = ['fotoLocalCerrado','fotoLocalNoExiste','fotoMuebleNoSala','fotoPendienteGenerica','fotoCanceladoGenerica'];
  for (const id of ids) {
    const el = document.getElementById(id);
    if (!el || !el.files || !el.files.length) continue;
    try {
      const compressed = await Promise.all(Array.from(el.files).map(f => compressFile(f)));
      const dt = new DataTransfer();
      compressed.forEach(f => dt.items.add(f));
      el.files = dt.files;
    } catch(_) {}
  }
}

/* === DOM Ready inicializaciones === */
document.addEventListener('DOMContentLoaded', async ()=>{
  window.CSRF_TOKEN = document.querySelector('input[name="csrf_token"]').value;
  const sess = ensureVisitSession();
  const mapped = resolveVisitaIdFromMappings(sess.client_guid);
  if (mapped) { updateVisitSession({ visita_id: mapped }); }
  document.querySelectorAll('.file-input').forEach(input=>{ setupFileInput(input); });
  // Comprimir fotos de evidencia genérica antes del submit del form
  ['fotoLocalCerrado','fotoLocalNoExiste','fotoMuebleNoSala','fotoPendienteGenerica','fotoCanceladoGenerica']
    .forEach(id => {
      const el = document.getElementById(id);
      if (el) { setupEvidencePreview(id); }
    });

  // ── Draft: inicializar módulo ───────────────────────────────────────────
  const _userId  = window.__GESTION.usuarioId;
  const _formId  = window.__GESTION.idCampana;
  const _localId = window.__GESTION.idLocal;
  const _guid    = sess.client_guid || '';

  if (window.GestionDraft && _guid) {
    window.GestionDraft.init(_userId, _formId, _localId, _guid);

    // Cargar en paralelo: snapshot servidor + draft local
    const [snapshot, localDraft] = await Promise.all([
      window.GestionDraft.fetchServerSnapshot(_formId, _localId, _guid, sess.visita_id || 0)
        .catch(() => null),
      window.GestionDraft.loadDraft(_userId, _formId, _localId, _guid)
        .catch(() => null),
    ]);

    const isVisitaClosed = snapshot?.visita?.is_open === false;
    const hasServerData  = snapshot && (
      Object.keys(snapshot.fotos_materiales || {}).length > 0 ||
      Object.keys(snapshot.fotos_preguntas  || {}).length > 0
    );
    const hasLocalDraft  = localDraft && localDraft.status !== 'submitted';

    if (isVisitaClosed) {
      // Visita ya cerrada: solo mostramos fotos en modo lectura
      if (snapshot) {
        window.GestionDraft.renderSnapshotThumbs(snapshot, { isClosed: true });
      }
    } else {
      // Hay datos que restaurar
      if (hasServerData || hasLocalDraft) {
        // Merge y aplicar
        const merged = window.GestionDraft.mergeStates(snapshot, localDraft);

        if (merged) {
          // Restaurar inputs del formulario
          window.GestionDraft.applyDraftToUI(merged);
          // Guardar draft fusionado en IndexedDB (para offline)
          window.GestionDraft.saveDraft(merged, true);
        }

        // Mostrar thumbnails de fotos ya subidas
        if (snapshot) {
          window.GestionDraft.renderSnapshotThumbs(snapshot, { isClosed: false });
        }
        // Mostrar thumbnails de fotos pendientes locales
        if (merged) {
          window.GestionDraft.renderPendingThumbs(merged);
        }

        // Banner de recuperación
        const fotosCount = snapshot
          ? Object.values(snapshot.fotos_materiales || {}).reduce((a, v) => a + (Array.isArray(v) ? v.length : 1), 0)
          + Object.keys(snapshot.fotos_preguntas || {}).length
          : 0;

        const banner = document.getElementById('gestion-recovery-banner');
        if (banner && (hasServerData || hasLocalDraft)) {
          document.getElementById('recovery-fotos-msg').textContent =
            fotosCount > 0 ? ` ${fotosCount} foto(s) ya subida(s) recuperadas.` : '';
          document.getElementById('recovery-inputs-msg').textContent =
            hasLocalDraft ? ' Respuestas previas restauradas.' : '';
          banner.style.display = 'block';
        }
      }

      // Wiring autosave
      window.GestionDraft.wireAutosave();

      // Botón descartar draft
      const btnDiscard = document.getElementById('btn-discard-draft');
      if (btnDiscard) {
        btnDiscard.addEventListener('click', async () => {
          if (!confirm('¿Seguro que deseas descartar la gestión guardada y empezar de nuevo?')) return;
          await window.GestionDraft.clearDraft(_userId, _formId, _localId, _guid);
          location.reload();
        });
      }
    }
  }
});

$(document).ready(function(){
  function canAddMaterial() { return ![6, 9, 21].includes(Number(window.campaignDivision)); }
  $('.conditional-question').each(function(){ if ($(this).css('display') === 'none') { $(this).find('input, select, textarea').removeAttr('required'); } });

  // ── Eliminar fotos restauradas desde snapshot (clase generada por buildFotoThumbHtml) ──
  $(document).on('click', '.draft-foto-del-btn', async function () {
    const btn    = this;
    const thumb  = btn.closest('.draft-foto-thumb');
    const fotoId = thumb ? thumb.dataset.fotoId : null;
    if (!fotoId) return;

    const matContainer  = btn.closest('[id^="previewContainer_"]');
    const pregContainer = btn.closest('[id^="previewFoto_"]');

    const ok = await mcConfirm({
      title: 'Eliminar foto',
      message: '<p>¿Seguro que deseas eliminar esta foto?</p>',
      confirmText: 'Sí, eliminar',
      confirmClass: 'btn-danger',
    });
    if (!ok) return;
    btn.disabled = true;

    try {
      const visitaId = document.getElementById('visita_id')?.value || '';
      const csrf     = window.CSRF_TOKEN || '';

      if (matContainer) {
        const fqId = matContainer.id.replace('previewContainer_', '');
        const form = new FormData();
        form.append('csrf_token', csrf);
        form.append('id_foto', fotoId);
        form.append('id_formularioQuestion', fqId);
        form.append('visita_id', visitaId);
        form.append('X_Idempotency_Key', crypto.randomUUID ? crypto.randomUUID() : Date.now() + '-' + Math.random());
        const r  = await fetch('/visibility2/app/eliminar_material_foto_pruebas.php', { method: 'POST', body: form });
        const js = await r.json();
        if (js.status === 'success') {
          $(thumb).fadeOut(150, () => thumb.remove());
          document.getElementById('hiddenUploadContainer_' + fqId)?.querySelector(`input[data-foto-id="${fotoId}"]`)?.remove();
          mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
        } else {
          mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
          btn.disabled = false;
        }
      } else if (pregContainer) {
        const qId = pregContainer.id.replace('previewFoto_', '');
        const form = new FormData();
        form.append('csrf_token', csrf);
        form.append('resp_id', fotoId);
        form.append('id_form_question', qId);
        form.append('visita_id', visitaId);
        const r  = await fetch('/visibility2/app/eliminar_pregunta_foto_pruebas.php', { method: 'POST', body: form });
        const js = await r.json();
        if (js.status === 'success') {
          $(thumb).fadeOut(150, () => thumb.remove());
          const flag = document.getElementById('flagFoto_' + qId);
          if (flag) flag.value = '';
          mcToast('success', 'Foto eliminada', 'La foto fue eliminada correctamente.');
        } else {
          mcToast('danger', 'No se pudo eliminar', js.message || 'Intente nuevamente.');
          btn.disabled = false;
        }
      }
    } catch (e) {
      console.error(e);
      mcToast('danger', 'Error de red', 'No se pudo eliminar la foto.');
      btn.disabled = false;
    }
  });

  let currentStep = 1;
  let totalSteps  = window.__GESTION.totalSteps;
  function showStep(step){
      $('.wizard-step').hide();
      $('#step-' + step).show();
      let percent = (step / totalSteps) * 100;
      $('#wizardProgress').css('width', percent + '%');
      $('#wizardProgress').text('Paso ' + step + ' de ' + totalSteps);
  }
  showStep(currentStep);

  $('#btnNext1').prop('disabled', true).text('Siguiente »');

  // === Paso 1: iniciar visita con soporte OFFLINE ===
 $('#btnNext1').off('click').on('click', async function () {
  const $btn = $(this);
  $btn.prop('disabled', true).text('Obteniendo ubicación…');

  const estado = $('#estadoGestion').val();
  const motivo = $('#motivo').val();

  if (!estado) {
    await mcAlert('Campo requerido', 'Selecciona el estado de la gestión.');
    $btn.prop('disabled', false).text('Siguiente »');
    return;
  }

  if ((estado === 'pendiente' || estado === 'cancelado') && !motivo) {
    await mcAlert('Campo requerido', 'Debes seleccionar un motivo antes de continuar.');
    $btn.prop('disabled', false).text('Siguiente »');
    return;
  }

  if (motivo === 'otro_tipo_exhibicion' && !$('#otroTipoExhibicionDetalle').val().trim()) {
    await mcAlert('Campo requerido', 'Debes especificar el tipo de exhibición.');
    $btn.prop('disabled', false).text('Siguiente »');
    return;
  }

  try {
    await ensureFreshGeo();
  } catch (err) {
    console.error('Error GPS:', err);
    showGeoError(err);
    $btn.prop('disabled', false).text('Siguiente »');
    return;
  }

  const session = ensureVisitSession();
  const client_guid = session.client_guid;
  const idempKey = makeIdempKey();
  $('#idemp_create').val(idempKey);

  const payload = {
    id_formulario: window.__GESTION.idCampana,
    id_local: window.__GESTION.idLocal,
    lat: String($('#latGestion').val() || ''),
    lng: String($('#lngGestion').val() || ''),
    client_guid: String(client_guid || '')
  };

  updateVisitSession({
    startPayload: { lat: payload.lat, lng: payload.lng },
    formState: {
      estado: $('#estadoGestion').val() || '',
      motivo: $('#motivo').val() || '',
      otroTipoExhibicionDetalle: $('#otroTipoExhibicionDetalle').val() || ''
    }
  });

  $btn.text('Iniciando visita…');

  try {
    const params = new URLSearchParams();
    params.append('id_formulario', String(payload.id_formulario));
    params.append('id_local', String(payload.id_local));
    params.append('lat', String(payload.lat));
    params.append('lng', String(payload.lng));
    params.append('client_guid', String(payload.client_guid));
    params.append('csrf_token', getCSRF());

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), START_VISITA_TIMEOUT_MS);

    let r;
    try {
      r = await fetch('/visibility2/app/create_visita_pruebas.php', {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-Token': getCSRF(),
          'X-Idempotency-Key': idempKey
        },
        body: params.toString(),
        signal: controller.signal
      });
    } finally {
      clearTimeout(timeoutId);
    }

    const rawText = await r.text();
    console.log('create_visita_pruebas.php status:', r.status);
    console.log('create_visita_pruebas.php response:', rawText);

    let js = {};
    try {
      js = rawText ? JSON.parse(rawText) : {};
    } catch (parseErr) {
      throw new Error('La respuesta no es JSON válido: ' + rawText.substring(0, 300));
    }

    if (r.ok && js && (js.status === 'success' || js.status === 'ok') && js.visita_id) {
      $('#visita_id').val(js.visita_id);

      try {
        const _cdKey = 'v2_completed_deps';
        const _cdRaw = localStorage.getItem(_cdKey);
        const _cdArr = Array.isArray(JSON.parse(_cdRaw || '[]')) ? JSON.parse(_cdRaw || '[]') : [];
        const _cdTag = `create:${client_guid}`;
        if (!_cdArr.includes(_cdTag)) { _cdArr.push(_cdTag); localStorage.setItem(_cdKey, JSON.stringify(_cdArr)); }
      } catch (_) {}

      if (window.Queue && window.Queue.CompletedDeps) {
        await window.Queue.CompletedDeps.add(`create:${client_guid}`);
      }
      if (window.Queue && window.Queue.LocalByGuid) {
        await window.Queue.LocalByGuid.set(client_guid, js.visita_id);
      }

      updateVisitSession({ visita_id: js.visita_id, client_guid: js.client_guid || client_guid });
      currentStep = 2;
      showStep(currentStep);
      return;
    }

    throw new Error(js.message || ('Respuesta inválida del servidor: ' + rawText.substring(0, 300)));

  } catch (e) {
      console.error('Error iniciando visita:', e);

  const msg = String(e && e.message ? e.message : '');

  // Si el backend respondió conflicto, no encolar otra vez
  if (msg.includes('409') || msg.includes('GUID_REUSED') || msg.includes('Conflict')) {
    await mcAlert('Conflicto de visita', 'Ya existe una visita abierta o hubo conflicto de creación. Recarga la página e inténtalo nuevamente.', { type: 'danger' });
    return;
  }

  try {
    // Timeout de seguridad: si el encolado IDB tarda más de 5s, avanzar igual.
    // Evita que un IDB bloqueado congele el botón indefinidamente.
    await Promise.race([
      queueCreateVisita(payload, idempKey),
      new Promise((_, rej) => setTimeout(() => rej(new Error('E_ENQUEUE_TIMEOUT')), 5000))
    ]);
    const offlineMsg = (e.name === 'AbortError')
      ? 'El servidor tardó demasiado. Continuamos en modo offline.'
      : 'No se pudo iniciar la visita online. Continuamos en modo offline.';
    mcToast('warning', 'Modo offline', offlineMsg);
    currentStep = 2;
    showStep(currentStep);
  } catch (offlineErr) {
    if (offlineErr && offlineErr.message === 'E_ENQUEUE_TIMEOUT') {
      // IDB no respondió pero avanzamos igual; la gestión se reintentará
      console.warn('[queueCreateVisita] timeout de encolado — avanzando a paso 2');
      mcToast('warning', 'Modo offline', 'Continuamos en modo offline.');
      currentStep = 2;
      showStep(currentStep);
    } else {
      console.error('Error encolando visita:', offlineErr);
      await mcAlert('Error de conexión', 'No se pudo iniciar la visita ni online ni offline.', { type: 'danger' });
    }
  }
} finally {
    $btn.prop('disabled', false).text('Siguiente »');
  }
});


  $('#btnBack1').on('click', function(){
    currentStep = 1; showStep(currentStep);
    $('#btnNext1').prop('disabled', false).text('Siguiente »');
  });

  // Estos dos manejadores solo existian cuando la campana traia encuesta pendiente: el
  // condicional de PHP hacia que ni siquiera se imprimieran. En un .js no hay PHP, asi que
  // el condicional pasa a ser de tiempo de ejecucion. Hace lo mismo: sin encuesta no se
  // registran, y #btnNext2 tampoco existe en el DOM.
  if (window.__GESTION.encuestaPendiente) {
  $('#btnNext2').on('click', async function(e) {
      e.preventDefault();
      if (!await validarMateriales()) return;
      let estado = $('#estadoGestion').val();
      if (estado === 'implementado_auditado' || estado === 'solo_auditoria'  ) {
          currentStep = 3;
          showStep(currentStep);
      } else {
          $('#btnFinalizar').click();
      }
  });
  $('#btnBack2').on('click', function(){ currentStep = 2; showStep(currentStep); });
  }

  let estadoSelect    = $('#estadoGestion');
  let motivoContainer = $('#motivoContainer');
  let motivoSelect    = $('#motivo');
  let materialesDiv   = $('#materialesContainer');
  let comentarioDiv   = $('#comentarioContainer');
  let btnNext1        = $('#btnNext1');
  let tituloPaso2     = $('#tituloPaso2');

  let motivosPendiente = [
      {value:'local_cerrado', text:'Local cerrado'},
      {value:'no_permitieron', text:'No permitieron'},
      {value:'no_permitieron_por_robo', text:'No permitieron por robo'},
      {value:'sin_material', text:'Sin material'},
      {value:'sin_productos', text:'Sin productos'},
      {value:'local_no_existe', text:'Local no existe'},
      {value:'otro_tipo_exhibicion', text:'No, hay otro tipo de exhibicion'}
  ];
  let motivosCancelado = [
      {value:'no_permitieron', text:'No permitieron'},
      {value:'no_permitieron_por_robo', text:'No permitieron por robo'},
      {value:'local_no_existe', text:'Local no existe'},
      {value:'mueble_no_esta_en_sala', text:'Mueble no está en la sala'},
      {value:'sin_productos', text:'Sin productos'},
      {value:'local_cerrado', text:'Local cerrado'},
      {value:'otro_tipo_exhibicion', text:'No, hay otro tipo de exhibicion'}
  ];

  function resetFotoContainers() {
    ['fotoLocalCerrado','fotoLocalNoExiste','fotoMuebleNoSala','fotoPendienteGenerica','fotoCanceladoGenerica'].forEach(id => {
      $('#' + id + 'Container').hide();
      const el = document.getElementById(id);
      if (el) resetFileInput(el);
      const prev = document.getElementById('evPreview_' + id);
      if (prev) { prev.innerHTML = ''; prev.style.display = 'none'; }
    });
    $('#otroTipoExhibicionContainer').hide(); $('#otroTipoExhibicionDetalle').val('').removeAttr('required');
  }

   estadoSelect.on('change', function(){
      let val = $(this).val();
      motivoSelect.empty().append('<option value="" disabled selected>Seleccione una opción</option>');
      motivoSelect.val('');
      motivoSelect.removeAttr('required');
      motivoContainer.hide(); materialesDiv.hide(); comentarioDiv.hide();
      btnNext1.prop('disabled', false);
      tituloPaso2.text('Paso 2');
      resetFotoContainers();

      if (val === 'implementado_auditado') {
        materialesDiv.show(); if (canAddMaterial()) { $('#btnAgregarMaterial').show(); }
        tituloPaso2.text('Paso 2: Detalles de Materiales');
      } else if (val === 'solo_implementado') {
        materialesDiv.show(); if (canAddMaterial()) $('#btnAgregarMaterial').show();
        tituloPaso2.text('Paso 2: Solo Implementación de Materiales');
      } else if (val === 'solo_retirado') {
        materialesDiv.show(); if (canAddMaterial()) $('#btnAgregarMaterial').show();
        tituloPaso2.text('Paso 2: Solo Retiro de Materiales');
      } else if (val === 'solo_auditoria') {
        $('#btnAgregarMaterial').hide();
        tituloPaso2.text('Paso 2: -- Sin materiales --');
      } else if (val === 'gestion_material') {
        materialesDiv.show(); if (canAddMaterial()) $('#btnAgregarMaterial').show();
        tituloPaso2.text('Paso 2: Gestionar Materiales por Etapa');
      } else if (val === 'pendiente') {
        motivosPendiente.forEach(function(m){ motivoSelect.append('<option value="'+m.value+'">'+m.text+'</option>'); });
        motivoContainer.show(); comentarioDiv.show();
        motivoSelect.attr('required', 'required');
        tituloPaso2.text('Paso 2: Motivo, Comentarios y Foto');
        $('#fotoPendienteGenericaContainer').slideDown();
      } else if (val === 'cancelado') {
        motivosCancelado.forEach(function(m){ motivoSelect.append('<option value="'+m.value+'">'+m.text+'</option>'); });
        motivoContainer.show(); comentarioDiv.show();
        motivoSelect.attr('required', 'required');
        tituloPaso2.text('Paso 2: Motivo, Comentarios y Foto');
        $('#fotoCanceladoGenericaContainer').slideDown();
      } else {
        btnNext1.prop('disabled', true);
      }
  });

  $(document).off('change', '.implementa-material').on('change', '.implementa-material', function () {
    const idFQ = $(this).data('id-material');
    const $yes = $('#implementa_section_' + idFQ);
    const $no  = $('#no_implementa_section_' + idFQ);

    if (this.checked) {
      $yes.show();
      $yes.find('input, textarea, select').prop('disabled', false).attr('required', 'required');
      $no.hide();
      $no.find('textarea, select').removeAttr('required');
    } else {
      $yes.hide();
      $yes.find('input, textarea, select').val('').prop('disabled', true).removeAttr('required');
      $no.show();
      $no.find('textarea, select').attr('required', 'required');
    }
  });

  // Muestra/oculta el motivo de implementación parcial según el acumulado tras la cantidad ingresada.
  // (acumulado = lo ya implementado + lo de esta visita). Si queda bajo el propuesto → motivo obligatorio.
  function actualizarMotivoParcial(idFQ) {
    const $sel = $('.etapa-material-select[data-id-material="' + idFQ + '"]');
    if (!$sel.length) return;
    const etapa       = $sel.val();
    const propuesto   = parseInt($sel.data('valor-propuesto'), 10) || 0;
    const original    = $sel.data('etapa-original') || '';
    const prevImpl    = (original === 'implementado') ? (parseInt($sel.data('valor-actual'), 10) || 0) : 0;
    const delta       = parseInt($('#etapa_valor_group_' + idFQ + ' .valor-implementado').val(), 10);
    const $motivoGrp  = $('#motivo_parcial_group_' + idFQ);
    const $motivoSel  = $motivoGrp.find('.motivo-parcial');

    if (etapa !== 'implementado' || isNaN(delta) || delta < 1) {
      $motivoGrp.hide();
      $motivoSel.prop('disabled', true).removeAttr('required');
      return;
    }
    const acumulado = prevImpl + delta;
    if (propuesto > 0 && acumulado < propuesto) {
      $motivoGrp.show();
      $motivoSel.prop('disabled', false).attr('required', 'required');
    } else {
      $motivoGrp.hide();
      $motivoSel.prop('disabled', true).removeAttr('required').val('');
    }
  }
  window.actualizarMotivoParcial = actualizarMotivoParcial;

  $(document).off('change', '.etapa-material-select').on('change', '.etapa-material-select', function () {
    const idFQ = $(this).data('id-material');
    const etapa = $(this).val();
    const etapaOriginal = $(this).data('etapa-original') || '';
    const fotosPorEtapa = $(this).data('fotos-por-etapa') || {};
    const parcial = String($(this).data('parcial')) === '1';
    const $section = $('#etapa_material_section_' + idFQ);
    const $valorGroup = $('#etapa_valor_group_' + idFQ);
    const $valorInput = $valorGroup.find('.valor-implementado');
    const $uploadWrap = $('#foto_upload_wrap_' + idFQ);
    const $btnAdjuntarOtra = $('#btn_adjuntar_otra_' + idFQ);
    const $fileInput = $section.find('.file-input');
    const $photoSource = $section.find('.photo-source');
    const $motivoGroup = $('#motivo_parcial_group_' + idFQ);
    const $motivoSelect = $motivoGroup.find('.motivo-parcial');
    const $editable = $section.find('.valor-implementado, .file-input, .photo-source, textarea[name^="observacion"]');

    // Un implementado parcial puede seguir sumando aunque la etapa siga siendo 'implementado':
    // por eso, además de "etapa distinta a la original", se considera cambio el caso parcial.
    const esImplementadoParcial = parcial && etapa === 'implementado';
    const esCambio = etapa !== '' && (etapa !== etapaOriginal || esImplementadoParcial);

    if (!esCambio) {
      $section.hide();
      $editable.val('').prop('disabled', true).removeAttr('required');
      $motivoGroup.hide();
      $motivoSelect.prop('disabled', true).removeAttr('required');
      return;
    }

    $section.show();
    $editable.prop('disabled', false);
    $photoSource.attr('required', 'required');

    // Foto: en el top-up de un implementado parcial SIEMPRE se exige una foto nueva (evidencia del
    // avance), aunque ya existan fotos de 'implementado' de visitas anteriores.
    const tieneFotoParaEstaEtapa = (fotosPorEtapa[etapa] || 0) > 0;
    if (tieneFotoParaEstaEtapa && !esImplementadoParcial) {
      $uploadWrap.hide();
      $fileInput.val('').prop('disabled', true).removeAttr('required');
      $btnAdjuntarOtra.show();
    } else {
      $uploadWrap.show();
      $fileInput.prop('disabled', false).attr('required', 'required');
      $btnAdjuntarOtra.hide();
    }

    if (etapa === 'implementado' || etapa === 'retirado') {
      $valorGroup.show();
      $valorInput.attr('required', 'required');
    } else {
      $valorGroup.hide();
      $valorInput.val('').prop('disabled', true).removeAttr('required');
    }

    actualizarMotivoParcial(idFQ);
  });

  // Al cambiar la cantidad implementada, re-evaluar si corresponde pedir motivo de parcial.
  $(document).off('input.parcial change.parcial', '.valor-implementado')
    .on('input.parcial change.parcial', '.valor-implementado', function () {
      const m = ($(this).attr('name') || '').match(/valor\[(\d+)\]/);
      if (m) actualizarMotivoParcial(m[1]);
    });

  $(document).off('click', '.etapa-adjuntar-otra-btn').on('click', '.etapa-adjuntar-otra-btn', function () {
    const idFQ = $(this).data('id-material');
    $('#foto_upload_wrap_' + idFQ).show();
    $('#fotos_input_' + idFQ).prop('disabled', false);
    $(this).hide();
  });

  $(document).on('change', '.photo-source', function() {
    let sourceOption = $(this).val();
    let inputId = $(this).data('target-input'); 
    let fileInput = document.getElementById(inputId);
    if (!fileInput) return;
    if (sourceOption === 'camera') {
        fileInput.setAttribute('capture', 'camera');
        fileInput.setAttribute('multiple', 'multiple');
        fileInput.setAttribute('accept', 'image/*;capture=camera');
    } else {
        fileInput.removeAttribute('capture');
        fileInput.setAttribute('multiple', 'multiple');
        fileInput.setAttribute('accept', 'image/*');
    }
    fileInput.value = '';
  });

  $('.photo-source').on('change', function() {
    let sourceOption = $(this).val();
    let inputId      = $(this).data('target-input'); 
    let fileInput    = document.getElementById(inputId);
    if (!fileInput) return;
    if (sourceOption === 'camera') {
        fileInput.setAttribute('capture', 'camera');
        fileInput.multiple = true;
        fileInput.setAttribute('accept', 'image/*;capture=camera');
    } else {
        fileInput.removeAttribute('capture');
        fileInput.setAttribute('multiple', 'multiple');
        fileInput.setAttribute('accept', 'image/*');
    }
    fileInput.value = '';
  });

  // === Validación materiales (acepta modo offline) ===
  
  async function validarMateriales() {
    const estado = $('#estadoGestion').val();
    if (estado === 'pendiente' || estado === 'cancelado' || estado === 'solo_auditoria') { return true; }
    if (estado === 'gestion_material') { return await validarMaterialesPorEtapa(); }

    if (estado === 'implementado_auditado' || estado === 'solo_implementado' || estado === 'solo_retirado') {
      if ($('.implementa-material:checked').length === 0) {
        await mcAlert('Validación', 'Para marcar la gestión como implementada, debes seleccionar al menos un material e ingresar su cantidad.');
        return false;
      }
    }

    const mats = $('.implementa-material').toArray();
    for (const el of mats) {
      const $el = $(el);
      if (!$el.is(':checked')) continue;
      const idFQ = $el.data('id-material');
      const $inputValor = $('#implementa_section_' + idFQ).find('.valor-implementado');
      const maxPropuesto = parseInt($inputValor.data('valor-propuesto'), 10);
      const valor = parseInt($inputValor.val(), 10);

      if (isNaN(valor)) {
        await mcAlert('Valor requerido', 'Debes ingresar un valor numérico para el material seleccionado.');
        return false;
      }
      if (valor <= 0) {
        await mcAlert('Valor inválido', 'El valor implementado debe ser al menos 1.');
        return false;
      }
      if (!isNaN(maxPropuesto) && valor > maxPropuesto) {
        await mcAlert('Valor excedido', `El valor implementado (${valor}) excede el propuesto (${maxPropuesto}).`);
        return false;
      }

      const hiddenUrls = document.querySelectorAll(`#hiddenUploadContainer_${idFQ} input[type="hidden"][name^="fotos[${idFQ}]"]`);
      const fileInput  = document.getElementById(`fotos_input_${idFQ}`);
      const haveLocalFiles = fileInput && fileInput.files && fileInput.files.length > 0;
      const haveQueued = fileInput && fileInput._queuedPhotos && fileInput._queuedPhotos.length > 0;

      if (hiddenUrls.length === 0 && !haveLocalFiles && !haveQueued) {
        await mcAlert('Foto requerida', 'Debes adjuntar al menos una foto para el material implementado. Si estás offline, basta con dejar los archivos cargados (se subirán cuando haya red).');
        return false;
      }
    }
    return true;
  }

  async function validarMaterialesPorEtapa() {
    // Materiales con un cambio REAL: etapa distinta a la guardada, o bien un implementado parcial
    // al que se le ingresó una cantidad nueva (suma sobre lo previo). El resto no se procesa/valida.
    const cambiados = $('.etapa-material-select').toArray().filter(el => {
      const $el = $(el);
      const etapa = $el.val();
      if (etapa === '') return false;
      const original = $el.data('etapa-original') || '';
      if (etapa !== original) return true;
      const parcial = String($el.data('parcial')) === '1';
      if (parcial && etapa === 'implementado') {
        const d = parseInt($('#etapa_valor_group_' + $el.data('id-material') + ' .valor-implementado').val(), 10);
        return !isNaN(d) && d >= 1;
      }
      return false;
    });
    if (cambiados.length === 0) {
      await mcAlert('Validación', 'Debes gestionar al menos un material (cambiar su etapa o registrar más unidades implementadas) para poder continuar.');
      return false;
    }

    for (const el of cambiados) {
      const $el = $(el);
      const idFQ = $el.data('id-material');
      const etapa = $el.val();
      const original = $el.data('etapa-original') || '';
      const parcial = String($el.data('parcial')) === '1';
      const fotosPorEtapa = $el.data('fotos-por-etapa') || {};

      if (etapa === 'implementado' || etapa === 'retirado') {
        const $inputValor = $('#etapa_valor_group_' + idFQ).find('.valor-implementado');
        const maxPropuesto = parseInt($inputValor.data('valor-propuesto'), 10);
        const valor = parseInt($inputValor.val(), 10);

        if (isNaN(valor)) {
          await mcAlert('Cantidad requerida', 'Debes ingresar la cantidad para el material marcado como Implementado/Retirado.');
          return false;
        }
        if (valor <= 0) {
          await mcAlert('Cantidad inválida', 'La cantidad debe ser al menos 1.');
          return false;
        }

        if (etapa === 'implementado') {
          const prevImpl  = (original === 'implementado') ? (parseInt($el.data('valor-actual'), 10) || 0) : 0;
          const acumulado = prevImpl + valor;
          if (!isNaN(maxPropuesto) && acumulado > maxPropuesto) {
            await mcAlert('Cantidad excedida', `El acumulado (${acumulado}) excede el propuesto (${maxPropuesto}).`);
            return false;
          }
          if (!isNaN(maxPropuesto) && acumulado < maxPropuesto) {
            const motivo = $('#motivo_parcial_group_' + idFQ + ' .motivo-parcial').val();
            if (!motivo) {
              await mcAlert('Motivo requerido', 'Indica por qué se implementó menos de lo propuesto (quedará pendiente).');
              return false;
            }
          }
        } else if (!isNaN(maxPropuesto) && valor > maxPropuesto) {
          await mcAlert('Cantidad excedida', `La cantidad (${valor}) excede el propuesto (${maxPropuesto}).`);
          return false;
        }
      }

      // Foto obligatoria si: es top-up de implementado parcial (siempre), o no existe foto previa
      // para esta etapa. Cuenta como adjuntada: foto subida en esta sesión, archivo local o en cola.
      const esTopUpParcial = parcial && etapa === 'implementado';
      const tieneFotoParaEstaEtapa = (fotosPorEtapa[etapa] || 0) > 0;
      if (esTopUpParcial || !tieneFotoParaEstaEtapa) {
        const hiddenUrls = document.querySelectorAll(`#hiddenUploadContainer_${idFQ} input[type="hidden"][name^="fotos[${idFQ}]"]`);
        const fileInput  = document.getElementById(`fotos_input_${idFQ}`);
        const haveLocalFiles = fileInput && fileInput.files && fileInput.files.length > 0;
        const haveQueued = fileInput && fileInput._queuedPhotos && fileInput._queuedPhotos.length > 0;

        if (hiddenUrls.length === 0 && !haveLocalFiles && !haveQueued) {
          await mcAlert('Foto requerida', 'Debes adjuntar al menos una foto para cada material gestionado. Si estás offline, basta con dejar los archivos cargados (se subirán cuando haya red).');
          return false;
        }
      }
    }
    return true;
  }

  // Motivos visible => select foto requerida
  motivoSelect.on('change', function(){
      let val = $(this).val();
      let estado = $('#estadoGestion').val();
      resetFotoContainers();

      if (val === 'otro_tipo_exhibicion') {
        $('#otroTipoExhibicionContainer').slideDown();
        $('#otroTipoExhibicionDetalle').attr('required', 'required');
      }

      if (estado === 'pendiente') {
        if (val === 'local_cerrado') { $('#fotoLocalCerradoContainer').slideDown(); }
        else if (val === 'local_no_existe') { $('#fotoLocalNoExisteContainer').slideDown(); }
        else { $('#fotoPendienteGenericaContainer').slideDown(); }
      } else if (estado === 'cancelado') {
        if (val === 'mueble_no_esta_en_sala') { $('#fotoMuebleNoSalaContainer').slideDown(); }
        else { $('#fotoCanceladoGenericaContainer').slideDown(); }
      }
  });

  // Dependencias de preguntas
  function clearAndHideBlock($block) {
    const qid = $block.data('question-id');
    $block.find('input[type=radio], input[type=checkbox]').prop('checked', false);
    $block.find('input:not([type=radio]):not([type=checkbox]), select, textarea').val('');
    $block.find('input, select, textarea').removeAttr('required disabled');
    $('.conditional-question').filter('[data-parent-question="' + qid + '"]').each(function() {
      clearAndHideBlock($(this));
      $(this).hide();
    });
  }
  function onDependencyChange($input, isCheckbox) {
    const $parent = $input.closest('.question-container');
    const qid     = $parent.data('question-id');
    const val     = $input.val();
    $('.conditional-question').filter('[data-parent-question="' + qid + '"]').each(function() {
      const $cond = $(this);
      const dep   = String($cond.data('dependency-option'));
      const show  = isCheckbox ? $parent.find(`input[type=checkbox][value="${dep}"]`).is(':checked') : val === dep;

      if (show) {
        $cond.slideDown(200, () => {
          $cond.find('input, select, textarea').removeAttr('disabled')
               .filter('[data-required="1"]').attr('required','required');
        });
      } else {
        clearAndHideBlock($cond);
        $cond.slideUp(200);
      }
    });
  }

  $(document).on('change', 'input[id^="fotoPregunta_"]', function () {
    const qId = this.id.split('_').pop();
    subirFotoPregunta(qId, window.__GESTION.idLocal);
  });
  
  $(document).on('change', '.question-container input[type=radio]', function(){ onDependencyChange($(this), false); });
  $(document).on('change', '.question-container input[type=checkbox]', function(){ onDependencyChange($(this), true); });

  // === Restaurar estado del formulario si hay sesión previa (ej. tras recarga) ===
  (function restoreFormState() {
    const _s = readVisitSession();
    if (!_s || !_s.formState || !_s.formState.estado) return;
    const fs = _s.formState;
    $('#estadoGestion').val(fs.estado).trigger('change');
    if (fs.motivo) {
      $('#motivo').val(fs.motivo).trigger('change');
    }
    if (fs.otroTipoExhibicionDetalle) {
      $('#otroTipoExhibicionDetalle').val(fs.otroTipoExhibicionDetalle);
    }
  })();

  initApoyosSelect2();
  updateFinalizeButton();
});


function onAddMaterialSuccess(ev){
  const d    = ev.detail || {};
  const job  = d.job || {};
  const resp = d.response || {};

  if ((job.type || d.type) !== 'add_material') return;

  const idJob = job.id || d.id;

  // Quitar placeholder “en cola”
  const temp = document.getElementById('pend-mat-' + idJob);
  if (temp) temp.remove();

  if (!resp || resp.status !== 'success' || !resp.idNuevo) {
    mcToast('danger','Error al sincronizar','El material no se pudo crear en el servidor.');
    return;
  }

  // Si ya existe un checkbox con ese id de material, NO volvemos a pintarlo
  const yaExiste = document.querySelector(
    `#materialesContainer .implementa-material[data-id-material="${resp.idNuevo}"]`
  );
  if (yaExiste) {
    // ya se agregó desde el submit online, evitamos duplicar bloque y toast
    return;
  }

  const $cont = $('#materialesContainer');

  const newMaterial = {
    id:              resp.idNuevo,
    material:        resp.nombre     || 'Material',
    categoria:       resp.categoria  || '',
    material_label:  resp.categoria  ? (resp.nombre || '') + ' - ' + resp.categoria : (resp.nombre || 'Material'),
    valor_propuesto: String(resp.valor_prop ?? ''),
    valor:           '',
    observacion:     '',
    ref_image:       resp.ref_image  || ''
  };

  if ($cont.find('#no-materiales-msg').length) { $cont.empty(); }

  const bloque = construirBloqueMaterial(newMaterial);
  $cont.append(bloque);
  initApoyosSelect2('#materialesContainer');

  const $chk = $(`#materialesContainer .implementa-material[data-id-material="${newMaterial.id}"]`);
  $chk.prop('checked', true).trigger('change');

  const input = document.getElementById('fotos_input_' + newMaterial.id);
  if (input) setupFileInput(input);

  mcToast('success','Material sincronizado','Se agregó correctamente.');
}

window.addEventListener('queue:dispatch:success', onAddMaterialSuccess);

// Compatibilidad con evento legacy “queue:done” (opcional pero recomendado):
window.addEventListener('queue:done', (ev)=>{
  const d = ev.detail || {};
  if (d.type !== 'add_material') return;
  onAddMaterialSuccess({ detail: { job: { id: d.id, type: d.type }, response: d.response } });
});

window.addEventListener('queue:dispatch:error', (ev) => {
  const d   = ev.detail || {};
  const job = d.job || {};
  if ((job.type || d.type) !== 'add_material') return;
  const id = job.id || d.id;
  const temp = document.getElementById('pend-mat-' + id);
  if (temp) temp.remove();
  mcToast('danger','No se pudo crear el material','La tarea falló al sincronizar.');
});


/* modal de agregar material (funciona solo online de momento) */
$('#btnAgregarMaterial').on('click', function(){ $('#modalAgregarMaterial').modal('show'); });

$('#formAgregarMaterial').on('submit', async function (e) {
  e.preventDefault();

  const nombre = $('#nombreMaterial').val().trim();
  const valor  = $('#valorImplementado').val().trim();
  const refImg = $('#nombreMaterial option:selected').data('ref') || '';

  if (!nombre) {
    mcToast('warning','Faltan datos','Selecciona el material');
    return;
  }
  if (!/^\d$/.test(valor)) {
    mcToast('warning','Valor inválido','Debe ser un número entre 0 y 9');
    return;
  }

  const fd = new FormData(this);
  fd.append('csrf_token', window.CSRF_TOKEN || '');

  const { queued, ok, response, id: taskId } =
    await Queue.smartPost('/visibility2/app/agregarMaterial_pruebas.php', fd, { type: 'add_material' });

  const $cont = $('#materialesContainer');

  if (queued) {
    // Placeholder “pendiente” mientras sincroniza
    const tempId = 'pend-mat-' + taskId;
    $cont.append(
      `<div id="${tempId}" class="alert alert-info" style="margin-top:8px;">
         “${nombre}” en cola… <small>(se añadirá automáticamente)</small>
       </div>`
    );
    $('#modalAgregarMaterial').modal('hide');
    this.reset();
    mcToast('info','Sin conexión','El material quedó en cola.');
    return;
  }

  // Online inmediato: dejamos que el listener onAddMaterialSuccess pinte el bloque
  if (ok && response && response.status === 'success') {
    $('#modalAgregarMaterial').modal('hide');
    this.reset();
    mcToast('success','Material agregado', response.message || 'OK');
  } else {
    mcToast('danger','Error', (response && response.message) || 'No se pudo agregar el material.');
  }
});


const ACTION_LABEL  = window.__GESTION.actionLabel;
const SECTION_LABEL = window.__GESTION.sectionLabel;
const MODALIDAD     = window.__GESTION.modalidad;
const EJECUTORES    = window.__GESTION.ejecutores;

function apoyosSelectHtml(idFQ) {
  if (!Array.isArray(EJECUTORES) || EJECUTORES.length === 0) {
    return `<div class="form-group"><label>Apoyos (otros ejecutores que ayudaron):</label>
      <p class="form-control-static text-muted">No hay otros ejecutores disponibles.</p></div>`;
  }
  const opts = EJECUTORES.map(e => {
    const nombre = String(e.nombre || ('Usuario ' + e.id))
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return `<option value="${parseInt(e.id, 10)}">${nombre}</option>`;
  }).join('');
  return `<div class="form-group"><label>Apoyos (otros ejecutores que ayudaron):</label>
    <select multiple class="form-control apoyos-select" name="apoyos[${idFQ}][]" size="4">${opts}</select></div>`;
}

// Convierte los <select multiple class="apoyos-select"> en un select2 con búsqueda y multi-selección.
function initApoyosSelect2(scope) {
  if (!window.jQuery || typeof jQuery.fn.select2 !== 'function') return;
  const $els = scope ? jQuery(scope).find('.apoyos-select') : jQuery('.apoyos-select');
  $els.each(function () {
    const $el = jQuery(this);
    if ($el.hasClass('select2-offscreen') || $el.data('select2')) return; // ya inicializado
    $el.select2({
      width: '100%',
      placeholder: 'Buscar y seleccionar ejecutores…',
      closeOnSelect: false,
      dropdownAutoWidth: true
    });
  });
}
window.initApoyosSelect2 = initApoyosSelect2;

function construirBloqueMaterial(material) {
  const idFQ      = material.id;
  const rawLabel  = material.material_label || (material.material + (material.categoria ? ' - ' + material.categoria : ''));
  const matName   = rawLabel.charAt(0).toUpperCase() + rawLabel.slice(1).toLowerCase();
  const valorProp = material.valor_propuesto;
  const refImage  = material.ref_image || "";
  const imgTag    = refImage ? `<img src="${refImage}" style="max-width:50px; max-height:50px; cursor:pointer;" onclick="verImagenGrande('${refImage}')" title="Ver referencia">` : "";
  let html = "";

  if (MODALIDAD === 'implementacion_por_etapas') {
    html += `
      <div class="form-group">
        <label>${matName} ${imgTag} (Propuesto: ${valorProp}) — Etapa actual: <span class="label label-default">Sin acción</span></label>
        <select class="form-control etapa-material-select" data-id-material="${idFQ}" data-etapa-original="" data-parcial="0" data-valor-actual="0" data-valor-propuesto="${valorProp}" data-fotos-por-etapa="{}" name="etapaMaterial[${idFQ}]">
          <option value="">Sin acción (no tocar)</option>
          <option value="armado">Armado</option>
          <option value="entregado">Entregado</option>
          <option value="implementado">Implementado</option>
          <option value="retirado">Retirado</option>
        </select>
      </div>`;

    html += `
      <div class="etapa-material-section" id="etapa_material_section_${idFQ}" style="display:none; padding-left:20px;">
        <div class="form-group etapa-valor-group" id="etapa_valor_group_${idFQ}" style="display:none;">
          <label>Cantidad (en esta visita):</label>
          <input type="number" class="form-control valor-implementado" name="valor[${idFQ}]" min="1" placeholder="Cantidad implementada en esta visita" data-valor-propuesto="${valorProp}" disabled>
        </div>
        <div class="form-group motivo-parcial-group" id="motivo_parcial_group_${idFQ}" style="display:none;">
          <label>Motivo de implementación parcial:</label>
          <select class="form-control motivo-parcial" name="motivoParcial[${idFQ}]" disabled>
            <option value="">Seleccione un motivo</option>
            <option value="No permitieron implementar todo">No permitieron implementar todo</option>
            <option value="Material pendiente queda en bodega">Material pendiente queda en bodega</option>
          </select>
        </div>
        <div class="form-group">
          <label>Origen de la foto:</label>
          <select class="photo-source form-control" data-target-input="fotos_input_${idFQ}" disabled>
            <option value="gallery" selected>Elegir de la Galería</option>
            <option value="camera">Tomar Foto</option>
          </select>
        </div>
        <button type="button" class="btn btn-xs btn-default etapa-adjuntar-otra-btn" id="btn_adjuntar_otra_${idFQ}" data-id-material="${idFQ}" style="display:none; margin-bottom:8px;">
          <i class="fa fa-camera"></i> Adjuntar otra foto (opcional)
        </button>
        <div class="form-group" id="foto_upload_wrap_${idFQ}">
          <label>Fotos (hasta 10):</label>
          <input type="file" accept="image/*" name="fotos[${idFQ}][]" multiple class="form-control file-input" disabled id="fotos_input_${idFQ}">
          <div id="previewContainer_${idFQ}"></div>
        </div>
        ${apoyosSelectHtml(idFQ)}
        <div class="form-group">
          <label>Observación:</label>
          <textarea class="form-control" name="observacion[${idFQ}]" placeholder="Observación..."></textarea>
        </div>
      </div>`;
    return html;
  }

  html += `
    <div class="form-group">
      <div class="checkbox">
        <label>
          <input type="checkbox" class="implementa-material" data-id-material="${idFQ}">
          ${ACTION_LABEL} ${matName} ${imgTag} (Valor Propuesto: ${valorProp})
        </label>
      </div>
    </div>`;

  html += `
    <div class="implementa-section" id="implementa_section_${idFQ}" style="display:none; padding-left:20px;">
      <div class="form-group">
        <label>Valor a ${ACTION_LABEL}:</label>
        <input type="number" class="form-control valor-implementado" name="valor[${idFQ}]" placeholder="Ingrese el valor" data-valor-propuesto="${valorProp}" disabled>
      </div>
      <div class="form-group">
        <label>Origen de la foto:</label>
        <select class="photo-source form-control" data-target-input="fotos_input_${idFQ}" disabled>
          <option value="gallery" selected>Elegir de la Galería</option>
          <option value="camera">Tomar Foto</option>
        </select>
      </div>
      <div class="form-group">
        <label>Fotos (hasta 10):</label>
        <input type="file" accept="image/*" name="fotos[${idFQ}][]" multiple class="form-control file-input" disabled id="fotos_input_${idFQ}">
        <div id="previewContainer_${idFQ}"></div>
      </div>
      <div class="form-group">
        <label>Observación ${SECTION_LABEL}:</label>
        <textarea class="form-control" name="observacion[${idFQ}]" placeholder="Observación..."></textarea>
      </div>
    </div>`;

  html += `
    <div class="no-implementa-section" id="no_implementa_section_${idFQ}" style="display:block; padding-left:20px;">
      <div class="form-group">
        <label>Motivo de NO ${SECTION_LABEL}:</label>
        <select class="form-control" name="motivoSelect[${idFQ}]">
          <option value="No, no permitieron">No, no permitieron</option>
          <option value="No, hay otro tipo de exhibicion">No, hay otro tipo de exhibición</option>
          <option value="No, sin productos">No, sin productos</option>
          <option value="No, no ha llegado el material">No, no ha llegado el material</option>
          <option value="Sala en remodelación">Sala en remodelación</option>
          <option value="No permite por robo">No permite por robo</option>
          <option value="Sin bajada de la central">Sin bajada de la central</option>
          <option value="Ya hay mueble adicional">Ya hay mueble adicional</option>
          <option value="No llegó el material completo">No llegó el material completo</option>
          <option value="El mueble no se encuentra en la sala">Mueble no está</option>
        </select>
      </div>
      <div class="form-group">
        <label>Detalle adicional:</label>
        <textarea class="form-control" name="motivoNoImplementado[${idFQ}]" placeholder="Explique brevemente..."></textarea>
      </div>
    </div>`;
  return html;
}

function eliminarMaterial(idFQ){
  if(!confirm('¿Seguro que deseas eliminar este material?')) return;
  $.ajax({
    url: 'eliminarMaterial_pruebas.php',
    type: 'POST',
    data: { idFormularioQuestion: idFQ, csrf_token: window.CSRF_TOKEN },
    dataType: 'json',
    success: function(resp){
      if(resp.status === 'success'){
        $('#material_block_' + idFQ).remove();
      } else {
        mcToast('danger', 'Error', resp.message || 'No se pudo eliminar el material.', 5000);
      }
    },
    error: function(err){
      console.error(err);
      mcToast('danger', 'Error de red', 'No se pudo conectar con el servidor.', 5000);
    }
  });
}

function chooseFromGallery(qId) {
  const input = document.getElementById(`fotoPregunta_${qId}`);
  input.removeAttribute('capture');
  input.dataset.captureSource = 'gallery';
  input.click();
}
function takeWithCamera(qId) {
  const input = document.getElementById(`fotoPregunta_${qId}`);
  input.setAttribute('capture', 'environment');
  input.dataset.captureSource = 'camera';
  input.click();
}

/* === Finalizar Gestión (online u offline) === */
document.getElementById('btnFinalizar')?.addEventListener('click', async function(e){
  e.preventDefault();

  if (pendingUploads > 0) {
    await mcAlert('Subida en progreso', `Aún se están subiendo ${pendingUploads} foto(s). Espera un momento antes de finalizar.`);
    return;
  }

  const visitaReal = await ensureRealVisitaId('finalizar');
  if (visitaReal) { $('#visita_id').val(visitaReal); }

  // Validación encuesta/obligatorias
  var containers = $('.question-container:visible').toArray();
  for (var i = 0; i < containers.length; i++) {
    var $c = $(containers[i]);
    if ($c.attr('data-required') !== "1") continue;
    var answered = false;
    $c.find("input[type=radio], input[type=checkbox]").each(function() {
      if (this.checked) { answered = true; return false; }
    });
    if (!answered) {
      $c.find("input:not([type=radio]):not([type=checkbox]), select, textarea").each(function() {
        if (!this.disabled && String($(this).val()).trim() !== "") { answered = true; return false; }
      });
    }
    if (!answered) {
      await mcAlert('Pregunta requerida', 'Debes responder la pregunta: <strong>' + $c.data('question-text') + '</strong>');
      containers[i].scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
  }

  // Validar que las alternativas seleccionadas con valor obligatorio tengan su valor ingresado
  for (var j = 0; j < containers.length; j++) {
    var $container = $(containers[j]);
    var inputs = $container.find("input[type=radio][data-valued='1'], input[type=checkbox][data-valued='1']").toArray();
    for (var k = 0; k < inputs.length; k++) {
      if (!inputs[k].checked) continue;
      var $valorInput = $(inputs[k]).closest('div').find('input.valor-alternativa');
      if ($valorInput.length && $.trim($valorInput.val()) === '') {
        var qText = $container.data('question-text');
        var optText = $.trim($(inputs[k]).closest('label').text());
        await mcAlert('Valor requerido', 'Debes ingresar el valor para <strong>"' + optText + '"</strong> en la pregunta: ' + qText);
        $valorInput.focus();
        return;
      }
    }
  }

  if ($('#comentarioContainer').is(':visible')) {
    var comentario = $('#comentario').val()||"";
    if ($.trim(comentario) === "") { await mcAlert('Campo requerido', 'Debes ingresar comentarios generales.'); return; }
  }

  const estado = $('#estadoGestion').val();
  if (estado === 'pendiente' || estado === 'cancelado') {
    const $inputsVisibles = $(
      '#fotoLocalCerrado:visible, ' +
      '#fotoLocalNoExiste:visible, ' +
      '#fotoMuebleNoSala:visible, ' +
      '#fotoPendienteGenerica:visible, ' +
      '#fotoCanceladoGenerica:visible'
    );
    let hasFile = false;
    $inputsVisibles.each(function(){ if (this.files && this.files.length > 0) { hasFile = true; return false; } });
    if (!hasFile) { await mcAlert('Foto requerida', 'Debes subir al menos una foto de evidencia.'); return; }
  }

  if (typeof validarMateriales === 'function' && !await validarMateriales()) { return; }

  this.disabled = true;
  try{
    await ensureFreshGeo();
  }catch(err){
    showGeoError(err);
    await mcAlert('GPS requerido', 'Para finalizar debes activar el GPS y conceder el permiso de ubicación.', { type: 'danger' });
    this.disabled = false;
    return;
  }

  document.getElementById('loadingOverlay').style.display = 'block';

  await compressEvidenceInputs();

const online = await isReallyOnline();
  if (online) {
    const clientGuid = ensureClientGuid();
    try {
      const _currentVid = ($('#visita_id').val() || '').trim();
      const visitaIdIsReal = _currentVid !== '' &&
                             !_currentVid.startsWith('local-') &&
                             /^\d+$/.test(_currentVid);

      const createPending = await hasPendingCreateVisita(clientGuid);
      const visitaCreada  = isCreateVisitaResolved(clientGuid);

      if ((createPending || !visitaCreada) && !visitaIdIsReal) {
        await Queue.flushNow();

        if (!isCreateVisitaResolved(clientGuid)) {
          const mapped = resolveVisitaIdFromMappings(clientGuid);
          if (mapped) { $('#visita_id').val(mapped); }
        }

        const stillPending  = await hasPendingCreateVisita(clientGuid);
        const stillUnmarked = !isCreateVisitaResolved(clientGuid);

        if (stillPending || stillUnmarked) {
          await queueProcesarGestion(document.getElementById('gestionarForm'), { reason: 'create_visita_pending' });
          if (window.GestionDraft) { try { await window.GestionDraft.markSubmitted(); } catch (_) {} }
          clearClientGuid();
          mcToast('warning','Gestión en cola','La visita aún está sincronizándose; la gestión se enviará cuando quede lista.');
          setTimeout(()=>{ window.location.href = 'index_pruebas.php'; }, 700);
          return;
        }
      }

      // Limpiar sólo localStorage para que la próxima sesión use un GUID fresco,
      // sin tocar los inputs del form actual (visita_id y client_guid se necesitan en el POST).
      try {
        localStorage.setItem(VISIT_SESSION_KEY, JSON.stringify({}));
        localStorage.removeItem(clientGuidKey());
      } catch(_) {}
      // Limpiar draft local: el form ya va al servidor
      if (window.GestionDraft) { try { await window.GestionDraft.clearDraft(); } catch (_) {} }
      document.getElementById('gestionarForm').submit();
      return;
    } catch (err) {
      console.error('No se pudo sincronizar la visita antes de enviar la gestión', err);
      mcToast('warning', 'Modo offline', 'No se pudo confirmar la creación de la visita. Continuamos en modo offline.', 5000);
      try {
        await queueProcesarGestion(document.getElementById('gestionarForm'), { reason: 'create_visita_sync_error' });
        if (window.GestionDraft) { try { await window.GestionDraft.markSubmitted(); } catch (_) {} }
        clearClientGuid();
        mcToast('warning','Gestión en cola','Se enviará automáticamente al recuperar conexión.');
        setTimeout(()=>{ window.location.href = 'index_pruebas.php'; }, 700);
        return;
      } catch (e2) {
        console.error('Tampoco se pudo encolar la gestión', e2);
        await mcAlert('Error', 'No se pudo enviar ni encolar la gestión. Intenta nuevamente.', { type: 'danger' });
        this.disabled = false;
        document.getElementById('loadingOverlay').style.display = 'none';
        return;
      }
    }
  }
  // OFFLINE: encolar form completo
  try {
    ensureClientGuid();
    await queueProcesarGestion(document.getElementById('gestionarForm'), { reason: 'offline' });
    if (window.GestionDraft) { try { await window.GestionDraft.markSubmitted(); } catch (_) {} }
    clearClientGuid();     
    mcToast('success','Gestión encolada','Se enviará automáticamente al recuperar conexión.');
    setTimeout(()=>{ window.location.href = 'index_pruebas.php'; }, 600);
  } catch (err) {
    console.error(err);
    alert('No se pudo encolar la gestión. Intenta nuevamente.');
    this.disabled = false;
    document.getElementById('loadingOverlay').style.display = 'none';
  }
});
window.campaignDivision = window.__GESTION.division;

$('#nombreMaterial').on('change', function(){
  let selectedOption = $(this).find('option:selected');
  let refImage = selectedOption.data('ref');
  if (refImage && refImage.trim() !== '') {
      $('#refPreview').attr('src', refImage);
      $('#refImageContainer').show();
  } else {
      $('#refImageContainer').hide();
      $('#refPreview').attr('src', '');
  }
});
    