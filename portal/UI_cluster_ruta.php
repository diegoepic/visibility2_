<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Agrupar salas cercanas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <style>
    body { font-family: system-ui, sans-serif; margin: 24px; }
    .card { max-width: 680px; padding: 16px; border: 1px solid #ddd; border-radius: 12px; }
    .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .muted { color: #666; font-size: 14px; }
    .hidden { display: none; }
    progress { width: 100%; height: 12px; }
    input[type="number"]{ width: 110px; }
  </style>
</head>
<body>
  <div class="card">
    <h2>Agrupar salas cercanas (lotes de 25)</h2>
    <p class="muted">Campos requeridos: <code>codigo local</code>, <code>comuna</code>, <code>merchan</code>, <code>latitud</code>, <code>longitud</code>.</p>

    <form id="formRutas" enctype="multipart/form-data">
      <div class="row">
        <input type="file" name="archivo" accept=".xlsx,.csv,.tsv,.txt" required />
        <label class="muted">Tamaño grupo:
          <input type="number" name="max" min="1" value="25">
        </label>
        <button type="submit">Procesar archivo</button>
      </div>
      <label class="muted">
        <input type="checkbox" name="debug" value="1"> Debug (ver primeros registros / diagnóstico)
      </label>
    </form>

    <div id="estado" class="muted" style="margin-top:12px;"></div>
    <progress id="bar" class="hidden" max="100" value="0"></progress>

    <div id="resultado" style="margin-top:16px;"></div>
  </div>

<script>
const form = document.getElementById('formRutas');
const estado = document.getElementById('estado');
const bar = document.getElementById('bar');
const resultado = document.getElementById('resultado');

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  estado.textContent = 'Subiendo y procesando...';
  resultado.innerHTML = '';
  bar.classList.remove('hidden');
  bar.value = 10;

  const fd = new FormData(form);
  try {
    const res = await fetch('/visibility2/portal/modulos/rutas/process_upload.php', {
      method: 'POST',
      body: fd
    });
    bar.value = 60;
    const text = await res.text();

    // Intenta parsear JSON; si falla, muestra respuesta cruda
    let data;
    try { data = JSON.parse(text); } catch {
      estado.textContent = 'Respuesta no-JSON desde el servidor.';
      resultado.innerHTML = `<pre>${text.replace(/[&<>"']/g, s=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[s]))}</pre>`;
      return;
    }

    bar.value = 100;

    // Errores de backend
    if (!data.ok) {
      estado.textContent = 'Error: ' + (data.msg || 'desconocido');
      // Si viene diagnóstico de permisos/ruta, muéstralo
      if (data.path || data.dir_exists !== undefined) {
        resultado.innerHTML = `<pre>${JSON.stringify(data, null, 2)}</pre>`;
      }
      return;
    }

    // Modo debug: el backend corta antes y NO genera archivos
    if (data.stage) {
      estado.textContent = `Debug (${data.stage}): ${data.rows_count ?? data.rows_total ?? ''} filas`;
      resultado.innerHTML = `<pre>${JSON.stringify(data, null, 2)}</pre>
      <p class="muted">En modo debug no se genera archivo de salida.</p>`;
      return;
    }

    // Éxito normal
    estado.textContent = `Hecho: ${data.total} filas válidas, ${data.grupos} grupos${data.invalid!==undefined ? (', inválidas: ' + data.invalid) : ''}.`;

    let html = '';
    if (data.download) {
      html += `<p><a href="${data.download}" target="_blank">Descargar resultado (CSV)</a></p>`;
    } else {
      html += `<p class="muted">No llegó URL de descarga. Payload:</p><pre>${JSON.stringify(data, null, 2)}</pre>`;
    }
    if (data.resumen) {
      html += `<p><a href="${data.resumen}" target="_blank">Descargar resumen (CSV)</a></p>`;
    }
    if (data.errors) {
      html += `<p><a href="${data.errors}" target="_blank">Descargar errores (CSV)</a></p>`;
    }
    if (data.debug && data.debug.length) {
      html += `<pre>${JSON.stringify(data.debug, null, 2)}</pre>`;
    }
    resultado.innerHTML = html;

  } catch (err) {
    console.error(err);
    estado.textContent = 'Error: ' + err.message;
  } finally {
    setTimeout(() => { bar.classList.add('hidden'); bar.value = 0; }, 600);
  }
});
</script>
</body>
</html>
