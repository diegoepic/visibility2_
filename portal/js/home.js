// =======================
// home.js (versión final)
// =======================

// ===== CONFIGURACIÓN RÁPIDA =====
const MAX_DIAS_RANGO = 2000; // límite de días permitido para el rango de fechas

// Endpoints backend (usa los que realmente existen)
const URL_IMPLEMENTACION_MC     = "modulos/descargar_data_ipt.php";
const URL_ENCUESTA_MC           = "modulos/descargar_data_ipt_E.php";
const URL_IMPLEMENTACION_OTRAS  = "modulos/descargar_data_ipt.php";
const URL_ENCUESTA_OTRAS        = "modulos/descargar_data_ipt_E.php";
// (Opcional) Solo si lo usas: define vacío para evitar ReferenceError si no existe
// const URL_LISTAR_EJECUTORES     = "modulos/listar_ejecutores.php"; 

// ===== UTILIDADES =====
function diasEntre(aStr, bStr) {
  const a = new Date(aStr);
  const b = new Date(bStr);
  if (isNaN(a) || isNaN(b)) return NaN;
  return Math.round((b - a) / (1000 * 60 * 60 * 24));
}

function buildParamsFromForm(formEl) {
  const fd = new FormData(formEl);
  const params = new URLSearchParams();
  for (const [k, v] of fd.entries()) {
    const val = (v ?? "").toString().trim();
    if (val !== "") params.append(k, val);
  }
  // El backend devuelve CSV → sé explícito
  if (!params.has("formato")) params.set("formato", "csv");
  return params;
}

function validarFiltros(formEl) {
  const fi = formEl.querySelector("#fecha_inicio_ipt")?.value;
  const ff = formEl.querySelector("#fecha_fin_ipt")?.value;

  if (!fi || !ff) {
    alert("Debes seleccionar Fecha Inicio y Fecha Fin.");
    return false;
  }
  if (new Date(fi) > new Date(ff)) {
    alert("La Fecha Inicio no puede ser mayor que la Fecha Fin.");
    return false;
  }
  const dias = diasEntre(fi, ff);
    if (!isNaN(dias) && dias > MAX_DIAS_RANGO) {
      alert(`El rango máximo permitido es ${MAX_DIAS_RANGO} días. Seleccionaste ${dias} días.`);
      return false;
    }
  return true;
}

// MC si el valor seleccionado de #division_ipt es "1" (front decide visibilidad; permisos se validan en el backend)
function detectarModoMC() {
  const selected = document.getElementById("division_ipt")?.value || "";
  const MC_ID = (window.ID_MC ?? "1"); // por si quieres personalizar el id de MC
  return selected === MC_ID;
}

// ===== PROGRESO SIMPLE =====
function showDlModal(show) {
  if (show) {
    if (!document.getElementById("miniProgress")) {
      const d = document.createElement("div");
      d.id = "miniProgress";
      d.style.cssText = "position:fixed;right:16px;bottom:16px;padding:10px 14px;background:#000;color:#fff;border-radius:8px;z-index:9999";
      d.textContent = "Preparando… 0%";
      document.body.appendChild(d);
    }
  } else {
    const d = document.getElementById("miniProgress");
    if (d) d.remove();
  }
}
function setDlProgress(pct, txt) {
  const d = document.getElementById("miniProgress");
  if (d) d.textContent = (txt ? `${txt} ` : "") + `${Math.floor(pct)}%`;
}

// ===== TOGGLE BOTONES POR TIPO =====
function toggleDownloadButtons2() {
  const tipo = document.getElementById("tipo_gestion2")?.value || "implementacion";
  const modoMC = detectarModoMC();

  const hide = id => { const el = document.getElementById(id); if (el) el.style.display = "none"; };
  const show = id => { const el = document.getElementById(id); if (el) el.style.display = ""; };

  ["btnDescargarImplementacionExceliptMC","btnDescargarEncuestaiptMC",
   "btnDescargarImplementacionExcelipt","btnDescargarEncuestaipt"].forEach(hide);

  if (modoMC) {
    if (tipo === "implementacion") show("btnDescargarImplementacionExceliptMC");
    else                           show("btnDescargarEncuestaiptMC");
  } else {
    if (tipo === "implementacion") show("btnDescargarImplementacionExcelipt");
    else                           show("btnDescargarEncuestaipt");
  }
}

// ===== DESCARGA (unificada) =====
function descargarDataIPT_Unificado() {
  const formEl = document.getElementById("formFiltros2");
  if (!validarFiltros(formEl)) return;

  const tipo = document.getElementById("tipo_gestion2").value; // implementacion | encuesta
  const modoMC = detectarModoMC();

  const params = buildParamsFromForm(formEl);
  const urlBase = modoMC
    ? (tipo === "implementacion" ? URL_IMPLEMENTACION_MC : URL_ENCUESTA_MC)
    : (tipo === "implementacion" ? URL_IMPLEMENTACION_OTRAS : URL_ENCUESTA_OTRAS);

  const url = `${urlBase}?${params.toString()}`;

  const xhr = new XMLHttpRequest();
  xhr.open("GET", url, true);
  xhr.withCredentials = true;        // útil si hay subdominios o cookies SameSite
  xhr.responseType = "blob";

  let indeterminate = true;
  let simPct = 0;
  let simTimer = null;

  const startSim = () => {
    if (simTimer) return;
    simTimer = setInterval(() => {
      simPct = Math.min(simPct + 1.2, 95);
      setDlProgress(simPct, "Preparando / descargando…");
    }, 120);
  };
  const stopSim = () => { if (simTimer) { clearInterval(simTimer); simTimer = null; } };

  showDlModal(true);
  setDlProgress(0, "Preparando…");
  startSim();

  xhr.onprogress = function (e) {
    if (e.lengthComputable && e.total > 0) {
      if (indeterminate) { indeterminate = false; stopSim(); }
      const pct = (e.loaded / e.total) * 100;
      setDlProgress(pct);
    } else {
      if (!indeterminate) { indeterminate = true; startSim(); }
    }
  };

  xhr.onload = function () {
    stopSim();
    setDlProgress(100, "Completado");
    showDlModal(false);

    const ct = xhr.getResponseHeader('Content-Type') || '';
    const isHtml = ct.includes('text/html') || (xhr.response && xhr.response.type === 'text/html');
    // Debug útil si algo falla:
    // console.log('status:', xhr.status, 'ct:', ct);

    if (xhr.status === 401 || isHtml) {
      alert('Tu sesión expiró o no tienes acceso. Inicia sesión de nuevo.');
      return;
    }

    if (xhr.status >= 200 && xhr.status < 300) {
      let filename = "Reporte_IPT.csv";
      const dispo = xhr.getResponseHeader("Content-Disposition") || "";
      const m = dispo.match(/filename\*=UTF-8''([^;]+)|filename="?([^"]+)"?/i);
      if (m) filename = decodeURIComponent(m[1] || m[2]);

      const urlBlob = URL.createObjectURL(xhr.response);
      const a = document.createElement("a");
      a.href = urlBlob;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(urlBlob), 1500);
      return;
    }

    // Errores no-2xx
    try {
      const reader = new FileReader();
      reader.onload = () => {
        const msg = (reader.result || '').toString().slice(0, 300);
        alert(`Error al generar/descargar (HTTP ${xhr.status}). ${msg}`);
      };
      reader.onerror = () => alert(`Error al generar/descargar (HTTP ${xhr.status}).`);
      reader.readAsText(xhr.response);
    } catch (_) {
      alert(`Error al generar/descargar (HTTP ${xhr.status}).`);
    }
  };

  xhr.onerror = function () {
    stopSim();
    showDlModal(false);
    alert("Error de red durante la descarga.");
  };

  xhr.send();
}

// ===== EVENTOS =====
document.addEventListener("DOMContentLoaded", () => {
  // Toggle inicial
  toggleDownloadButtons2();

  // Cambios de selección
  document.getElementById("tipo_gestion2")?.addEventListener("change", toggleDownloadButtons2);
  document.getElementById("division_ipt")?.addEventListener("change", toggleDownloadButtons2);

  // Normaliza fechas si el usuario invierte el rango
  const fi = document.getElementById("fecha_inicio_ipt");
  const ff = document.getElementById("fecha_fin_ipt");
  const autocorrige = () => {
    if (fi?.value && ff?.value && new Date(fi.value) > new Date(ff.value)) {
      const tmp = fi.value; fi.value = ff.value; ff.value = tmp;
    }
  };
  fi?.addEventListener("change", autocorrige);
  ff?.addEventListener("change", autocorrige);

  // Botones → misma función unificada
  ["btnDescargarImplementacionExceliptMC","btnDescargarEncuestaiptMC","btnDescargarImplementacionExcelipt","btnDescargarEncuestaipt"]
    .forEach(id => {
      const b = document.getElementById(id);
      if (b) b.addEventListener("click", descargarDataIPT_Unificado);
    });

  // Si usas modal de Bootstrap y jQuery, asegura toggle al mostrar:
  if (window.jQuery) {
    jQuery('#modalDataIPT').on('shown.bs.modal', toggleDownloadButtons2);
  }
});

// ===== OPCIONAL: Cargar ejecutores vía endpoint =====
async function cargarEjecutoresOpcional() {
  if (typeof URL_LISTAR_EJECUTORES === 'undefined' || !URL_LISTAR_EJECUTORES) return;

  const division     = document.getElementById("division_ipt")?.value || "";
  const subdivision  = document.getElementById("subdivision_ipt")?.value || "";

  try {
    const qs = new URLSearchParams();
    if (division)    qs.append("id_division", division);
    if (subdivision) qs.append("id_subdivision", subdivision);

    const res = await fetch(`${URL_LISTAR_EJECUTORES}?${qs.toString()}`, { credentials: 'include' });
    if (!res.ok) throw new Error("HTTP " + res.status);
    const data = await res.json();
    const sel = document.getElementById("ejecutor_ipt");
    if (sel) {
      sel.innerHTML = '<option value="">Todos los Ejecutores</option>';
      (data || []).forEach(it => {
        const opt = document.createElement("option");
        opt.value = it.id;
        opt.textContent = it.nombre;
        sel.appendChild(opt);
      });
    }
  } catch (err) {
    console.warn("No se pudieron cargar los ejecutores:", err);
  }
}
