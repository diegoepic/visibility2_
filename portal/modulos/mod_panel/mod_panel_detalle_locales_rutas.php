<!DOCTYPE html>
<?php include 'mapa_data.php'; ?>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel de Locales - Rutas IPT</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" type="text/css" href="../../css/mapa.css">  
  <link rel="stylesheet" type="text/css" href="../../assets/css/style.css">
  <link rel="stylesheet" type="text/css" href="../../assets/css/dataTable.css">
  <link rel='stylesheet' href='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.css'>
</head>
<body>
<div class="container card-panel">
  <!-- Filtros -->
  <div class="card mt-3">
    <div class="card-body">
    </div>
  </div>
</div>
  <!-- Mapa -->
  <div id="map"></div>
<div class="container card-panel position-relative">

  <!-- Botón de Collapse -->
  <button class="btn btn-outline-secondary btn-sm position-absolute"
          style="top: 8px; right: 12px; z-index: 10;"
          data-toggle="collapse"
          data-target="#panel-filtros"
          aria-expanded="true"
          aria-controls="panel-filtros">
    <i class="fa fa-chevron-up"></i>
  </button>

  <!-- PANEL -->
  <div id="panel-filtros" class="collapse show">

    <!-- Filtros -->
    <div class="card mt-3">
      <div class="card-body">
        <form method="GET" action="">
          <div class="form-row">

            <?php if ($isMC): ?>
              <div class="form-group col-md-3">
                <label for="id_division">DIVISIÓN</label>
                <select class="form-control" id="id_division" name="id_division"
                        onchange="document.getElementById('id_subdivision').value = 0; document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0;">
                  <option value="0">SELECCIONE DIVISIÓN</option>
                  <?php foreach($divisiones as $div): ?>
                    <option value="<?= (int)$div['id'] ?>" <?= $div['id']==$filter_division?'selected':'' ?>>
                      <?= htmlspecialchars($div['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php else: ?>
              <input type="hidden" name="id_division" value="<?= (int)$filter_division ?>">
            <?php endif; ?>

            <div class="form-group col-md-3">
              <label for="id_subdivision">SUB DIVISION</label>
              <select class="form-control" id="id_subdivision" name="id_subdivision" disabled>
                <option value="0" selected>TODAS</option>
                <option value="-1">SIN SUB DIVISION</option>
              </select>
              <small class="help">FILTRA CAMPAÑAS POR SUB DIVISION (SI APLICA).</small>
            </div>

            <div class="form-group col-md-2">
              <label for="tipo_gestion">TIPO DE GESTION</label>
              <select class="form-control" id="tipo_gestion" name="tipo_gestion">
                <option value="0" <?= ($tipoCampana==0)?'selected':'' ?>>TODAS</option>
                <option value="1" <?= ($tipoCampana==1)?'selected':'' ?>>CAMPAÑA</option>
                <option value="3" <?= ($tipoCampana==3)?'selected':'' ?>>RUTA</option>
              </select>
              <small class="help">FILTRA POR TIPO DE GESTIÓN.</small>
            </div>

            <div class="form-group col-md-2">
              <label for="estado">ESTADO</label>
              <select class="form-control" id="estado" name="estado"
                      onchange="document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0; this.form.submit();">
                <option value="1" <?= $filter_estado==1?'selected':'' ?>>EN CURSO</option>
                <option value="3" <?= $filter_estado==3?'selected':'' ?>>FINALIZADAS</option>
              </select>
            </div>

            <div class="form-group col-md-4" id="campo-campana" style="display:none">
              <label for="id_campana">CAMPAÑA</label>
              <select class="form-control" id="id_campana" name="id_campana" disabled>
                <option value="0" selected>SELECCIONE CAMPAÑA</option>
              </select>
              <small class="help">PUEDES ESCOGER CAMPAÑA O IR DIRECTO AL EJECUTOR</small>
            </div>

            <div class="form-group col-md-3">
              <label for="id_ejecutor">EJECUTOR</label>
              <select class="form-control" id="id_ejecutor" name="id_ejecutor" disabled>
                <option value="0" selected>SELECCIONE EJECUTOR</option>
              </select>
              <small class="help">SI NO ELIGES CAMPAÑA, VERÁS TODAS SUS RUTAS.</small>
            </div>

            <div class="form-group col-md-2" id="campo-desde" style="display:none">
              <label for="desde">DESDE</label>
              <input type="date" class="form-control" id="desde" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>">
            </div>

            <div class="form-group col-md-2" id="campo-hasta" style="display:none">
              <label for="hasta">HASTA</label>
              <input type="date" class="form-control" id="hasta" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
            </div>

            <div class="form-group col-md-3 align-self-end">
              <input type="hidden" name="buscar" value="1">
              <button type="submit" class="btn btn-primary btn-block">BUSCAR</button>
            </div>

          </div>

          <!-- 🔹 Persistencia -->
          <input type="hidden" id="val_division" value="<?= (int)$filter_division ?>">
          <input type="hidden" id="val_subdivision" value="<?= (int)$filter_subdivision ?>">
          <input type="hidden" id="val_campana" value="<?= (int)$filter_campana ?>">
          <input type="hidden" id="val_ejecutor" value="<?= (int)$id_ejecutor ?>">
          <input type="hidden" id="val_tipo" value="<?= (int)$tipoCampana ?>">
        </form>
      </div>
    </div>

  </div> <!-- collapse -->
</div><!-- container -->
<div class="seccion-tabla">
  <!-- Tabla -->
  <hr>
  <h5 class="mb-3">
    Listado de Locales <?= ($id_ejecutor>0 && $nombreEjec) ? 'para '.htmlspecialchars($nombreEjec) : '' ?>
    <?php if (!empty($locales)): ?>
      <span class="badge badge-secondary ml-2"><?= count($locales) ?> resultado(s)</span>
    <?php endif; ?>
  </h5>
  <div class="table-responsive">
    <table id="example" class="display nowrap" width="100%">
      <thead>
        <tr>
          <th>CODIGO</th>
          <th>LOCAL</th>
          <th>DIRECCION</th>
          <th>COMUNA</th>
          <th>REGION</th>
          <th>MERCHAN</th>          
          <th>ESTADO</th>
          <th>FECHA PLANIFICADA</th>
          <th>FECHA VISITA</th>
          <th>DETALLE</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($locales): foreach($locales as $loc):
          $nombreCamp  = htmlspecialchars($loc['nombre_campana']); // NUEVO
          $codigoLocal = htmlspecialchars($loc['codigo']);
          $nombreLoc   = htmlspecialchars($loc['nombre_local']);
          $dirLoc      = htmlspecialchars($loc['direccion_local']);
          $comunaLoc   = htmlspecialchars($loc['comuna_local']);
          $regionLoc   = htmlspecialchars($loc['region_local']);
          $usuarioLoc  = htmlspecialchars($loc['usuario_local']);          
          $fechaP      = $loc['fechaPropuesta'] ? date('d-m-Y', strtotime($loc['fechaPropuesta'])) : '-';
          $fechaV      = $loc['fechaVisita'] ? date('d-m-Y', strtotime($loc['fechaVisita'])) : '-';
          $prgRaw      = ($loc['pregunta'] !== '' && $loc['pregunta'] !== null) ? $loc['pregunta'] : '-';

          $badgeEstado = (in_array($prgRaw, ['AUDITORIA','IMPLEMENTACION','IMPL/AUD']))
              ? '<span class="badge badge-custom badge-completados">Compl.</span>'
              : (($prgRaw === '-')
                    ? '<span class="badge badge-custom badge-pendientes">Pend.</span>'
                    : '<span class="badge badge-custom badge-cancelados">Cancel.</span>');

          if (!empty($prgRaw)) {
              if (in_array($prgRaw, ['AUDITORIA','IMPLEMENTACION','IMPL/AUD'])) {
                  $prg = 'GESTIONADO';
              } else {
                  $prg = strtoupper(str_replace('_',' ', $prgRaw));
              }
          } else {
              $prg = '-';
          }
        ?>
        <tr>
          <td><?= $codigoLocal ?></td>
          <td><?= $nombreLoc ?></td>
          <td><?= $dirLoc ?></td>
          <td><?= $comunaLoc ?></td>
          <td><?= $regionLoc ?></td>
          <td><?= $usuarioLoc ?></td>          
          <td class="text-center"><?= $badgeEstado ?></td>
          <td class="text-center"><?= $fechaP ?></td>
          <td class="text-center"><?= $fechaV ?></td>
          <td><?= htmlspecialchars($prg) ?></td>
        </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="8" class="text-center">SIN RESULTADOS.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 🪟 Modal de detalle 
<div id="modalDetalle" class="modal-overlay" style="display:none;">
  <div class="modal-content" onclick="event.stopPropagation()">
    <span class="modal-close" onclick="cerrarModalDetalle()">&times;</span>
    <h3 id="modalTituloLocal" style="margin-top:0; color:#2c3e50;">Detalle del Local</h3>
    <hr>
    <div id="modalBodyDetalle" style="font-size:14px; color:#333;">
    </div>
  </div>
</div> -->

<!-- Modal Detalle Local -->
<div class="modal fade" id="modalDetalle" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">

      <!-- HEADER -->
        <div class="modal-header flex-column align-items-start">
          <h5 class="modal-title w-100" id="modalTituloLocal"></h5>
          <div id="modalHeaderExtra" class="w-100 mt-2" style="font-size:14px; line-height:1.3;"></div>
          <button type="button" class="close position-absolute" style="right:15px; top:15px;"
                  data-dismiss="modal" aria-label="Cerrar">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>

      <!-- BODY -->
      <div class="modal-body" id="modalBodyDetalle">
      </div>

      <!-- FOOTER -->
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>

    </div>
  </div>
</div>


<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="../../assets/js/datatables.min.js"></script>
<script src="../../assets/js/dataTable.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
    window.isMC = <?= $isMC ? 'true' : 'false' ?>;
    window.divisionUsuario = <?= (int)$filter_division ?>;
    console.log("GLOBAL isMC =", window.isMC);
    console.log("GLOBAL divisionUsuario =", window.divisionUsuario);
</script>

<script>
const isMC = window.isMC;
const divisionUsuario = window.divisionUsuario;
</script>

<script>
  const coordenadasLocales = <?= json_encode($coordenadas_locales, JSON_UNESCAPED_UNICODE) ?>;
  const idEjecutor = <?= (int)$id_ejecutor ?>;
</script>
<script src="../../js/mapa.js"></script>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDO0zLDNeEdLcQgkl7dF0C0Lgr3Wl1m3cw&callback=initMap"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
  /* ============================================
     🔁 AUTO-RELOAD SI LA PÁGINA FALLA AL CARGAR
  ============================================ */
  const contenido = document.body.innerText.toLowerCase();
  const erroresDetectados = [
    "es posible que la página web",
    "temporalmente inactiva",
    "trasladado definitivamente",
    "error 500",
    "error 404",
    "no se pudo establecer conexión"
  ];
  const hayError = erroresDetectados.some(msg => contenido.includes(msg));
  if (hayError || document.body.innerText.trim().length < 50) {
    console.warn("⚠️ Página con error o vacía. Recargando en 5 segundos...");
    setTimeout(() => location.reload(), 5000);
  }

  /* ============================================
     🧩 MOSTRAR / OCULTAR CAMPOS SEGÚN TIPO GESTIÓN
  ============================================ */
  const tipoGestion = document.getElementById("tipo_gestion");
  const campoCampana = document.getElementById("campo-campana");
  const campoDesde = document.getElementById("campo-desde");
  const campoHasta = document.getElementById("campo-hasta");

function actualizarVisibilidad() {
  const valor = parseInt(tipoGestion?.value || 0);
  if (!campoCampana || !campoDesde || !campoHasta) return;

  // Mostrar campaña solo cuando tipo = 1
  campoCampana.style.display = (valor === 1) ? "block" : "none";

  // Mostrar fechas cuando tipo = 1 o 3
  const mostrarFechas = (valor === 1 || valor === 3);
  campoDesde.style.display = mostrarFechas ? "block" : "none";
  campoHasta.style.display = mostrarFechas ? "block" : "none";
}

  if (tipoGestion) {
    actualizarVisibilidad();
    tipoGestion.addEventListener("change", actualizarVisibilidad);
  }

  /* ============================================
     🔹 FUNCIONES COMUNES
  ============================================ */
  function resetSelect(sel, placeholder) {
    if (!sel) return;
    sel.innerHTML = `<option value="0">${placeholder}</option>`;
  }

  function setOptions(select, options, selectedVal = null) {
    if (!select) return;
    while (select.firstChild) select.removeChild(select.firstChild);
    options.forEach(o => {
      const opt = new Option(o.nombre, o.id);
      if (selectedVal !== null && String(selectedVal) === String(o.id)) opt.selected = true;
      select.add(opt);
    });
  }

  /* ============================================
     🧩 ELEMENTOS DEL FORMULARIO
  ============================================ */
  const $division = document.getElementById('id_division');
  const divisionIsHidden = ! $division;  
  const $subdivision = document.getElementById('id_subdivision');
  const $campana = document.getElementById('id_campana');
  const $ejecutor = document.getElementById('id_ejecutor');
  const $estado = document.getElementById('estado');
  const $tipo = document.getElementById('tipo_gestion');
  const $empresa = document.querySelector('input[name="empresa_id"]') || { value: '<?= $id_empresa ?>' };
  const $distrito = document.getElementById('id_distrito');

  // 🔹 Valores guardados desde PHP
  const val_division = document.getElementById('val_division')?.value || 0;
  const val_subdivision = document.getElementById('val_subdivision')?.value || 0;
  const val_campana = document.getElementById('val_campana')?.value || 0;
  const val_ejecutor = document.getElementById('val_ejecutor')?.value || 0;
  const val_tipo = document.getElementById('val_tipo')?.value || 0;

/* ============================================
   🧩 SUBDIVISIONES DINÁMICAS + RESTAURACIÓN
============================================ */

function cargarSubdivisionesAuto(idDivision) {

    $subdivision.disabled = false; // SIEMPRE habilitado para no MC
    resetSelect($subdivision, 'Cargando...');

    fetch(`../mod_cargar/cargar_subdivisiones.php?id_division=${idDivision}`)
        .then(r => r.json())
        .then(data => {

            const items = (data.ok && Array.isArray(data.subdivisiones))
                ? data.subdivisiones
                : [];

            setOptions($subdivision, [
                { id: 0, nombre: 'TODAS' },
                { id: -1, nombre: 'SIN SUB DIVISIÓN' },
                ...items
            ]);

            // Restaurar selección si aplica
            if (
                val_subdivision !== "0" &&
                $subdivision.querySelector(`option[value="${val_subdivision}"]`)
            ) {
                $subdivision.value = val_subdivision;
                $subdivision.dispatchEvent(new Event('change'));
            }
        })
        .catch(err => {
            console.error('Error cargando subdivisiones:', err);
            resetSelect($subdivision, 'ERROR AL CARGAR');
            $subdivision.disabled = false;
        });
}


/* ============================================
   🔥 LÓGICA PARA USUARIO NO MC
============================================ */
if (!isMC || divisionIsHidden) {

    // Siempre habilitado para NO MC
    $subdivision.disabled = false;

    // Cargar subdivisiones automáticamente usando su división nativa
    cargarSubdivisionesAuto(divisionUsuario);

} else {

    /* ============================================
       ✔ LÓGICA PARA MC (con select visible)
    ============================================ */
    $division.addEventListener('change', function () {
        const idDivision = parseInt($division.value, 10) || 0;

        resetSelect($campana, 'SELECCIONE CAMPAÑA');
        resetSelect($ejecutor, 'SELECCIONE EJECUTOR');

        if (idDivision <= 0) {
            resetSelect($subdivision, 'SIN SUBDIVISIÓN');
            $subdivision.disabled = true;
            return;
        }

        $subdivision.disabled = false;
        cargarSubdivisionesAuto(idDivision);
    });

    if (parseInt(val_division) > 0) {
        $division.value = val_division;
        cargarSubdivisionesAuto(val_division);
    }
}
  /* ============================================
     🧩 CAMPANAS DINÁMICAS
  ============================================ */
  if ($subdivision) {
    $subdivision.addEventListener('change', function () {
      const division = $division ? $division.value : divisionUsuario;
      const subdiv = $subdivision?.value || 0;
      const estado = $estado?.value || 1;
      const tipoG = $tipo?.value || 0;

      resetSelect($campana, 'Cargando campañas...');
      $campana.disabled = true;

      fetch(`../mod_cargar/cargar_campanas2.php?id_empresa=${$empresa.value}&id_division=${division}&id_subdivision=${subdiv}&estado=${estado}&tipo_gestion=${tipoG}`)
        .then(r => r.json())
        .then(data => {
          resetSelect($campana, 'SELECCIONE CAMPAÑA');
          if (data.ok && Array.isArray(data.campanas)) {
            data.campanas.forEach(c => {
              const opt = new Option(c.nombre, c.id);
              $campana.add(opt);
            });
          }

          $campana.disabled = false;

          // ✅ Restaurar selección si aplica
          if (val_campana !== "0" && $campana.querySelector(`option[value="${val_campana}"]`)) {
            $campana.value = val_campana;
            $campana.dispatchEvent(new Event('change'));
          }
        })
        .catch(err => {
          console.error('Error cargando campañas:', err);
          resetSelect($campana, 'ERROR AL CARGAR');
          $campana.disabled = false;
        });
    });
  }

  /* ============================================
     🧩 EJECUTORES DINÁMICOS + RESTAURACIÓN
  ============================================ */
function cargarEjecutores({ idCampana = 0 } = {}) {
  const empresa = $empresa.value || 0;
  const division = $division?.value || 0;
  const tipoG = $tipo?.value || 0;
  const distrito = $distrito?.value || 0;

  resetSelect($ejecutor, 'Cargando ejecutores...');
  $ejecutor.disabled = true;

  fetch(`../mod_cargar/cargar_ejecutores.php?id_empresa=${empresa}&id_campana=${idCampana}&id_division=${division}&id_distrito=${distrito}&tipo_gestion=${tipoG}`)
    .then(r => r.json())
    .then(data => {
      resetSelect($ejecutor, 'SELECCIONE EJECUTOR');
      if (data.ok && Array.isArray(data.ejecutores)) {
        data.ejecutores.forEach(e => {
          const opt = new Option(`${e.nombre} ${e.apellido}`, e.id);
          $ejecutor.add(opt);
        });
      }

      // ✅ Restaurar ejecutor solo si coincide con el tipo actual
      if (
        val_ejecutor &&
        $ejecutor.querySelector(`option[value="${val_ejecutor}"]`) &&
        parseInt($tipo.value) === parseInt(val_tipo)
      ) {
        $ejecutor.value = val_ejecutor;
      }

      $ejecutor.disabled = false;
    })
    .catch(err => {
      console.error('Error cargando ejecutores:', err);
      resetSelect($ejecutor, 'ERROR AL CARGAR');
      $ejecutor.disabled = false;
    });
}

/* =========================================================
   🔄 SINCRONIZACIÓN ENTRE CAMPANA / RUTA / EJECUTOR
========================================================= */
// Cuando cambia campaña → cargar ejecutores
if ($campana) {
  $campana.addEventListener('change', function () {
    const idCampana = parseInt($campana.value, 10) || 0;
    resetSelect($ejecutor, 'Cargando ejecutores...');
    cargarEjecutores({ idCampana });
  });
}

// Cuando cambia tipo de gestión
if ($tipo) {
  $tipo.addEventListener('change', function () {
    const tipoG = parseInt($tipo.value, 10);

    // Limpia selects dependientes
    resetSelect($campana, 'SELECCIONE CAMPAÑA');
    resetSelect($ejecutor, 'SELECCIONE EJECUTOR');
    $campana.disabled = true;
    $ejecutor.disabled = true;

    if (tipoG === 3) { 
      cargarEjecutores({ idCampana: 0 });
      $ejecutor.disabled = false;
    } 
    
    if (tipoG === 1) { 
      // 🔥 ESTA ES LA LÍNEA QUE FALTABA
      $subdivision.dispatchEvent(new Event('change'));
      $campana.disabled = false;
    }
  });
}

/* =========================================================
   🔁 RESTAURACIÓN AUTOMÁTICA TRAS "BUSCAR"
========================================================= */
if (parseInt(val_tipo) === 1 && parseInt(val_campana) > 0) {
  // Modo campaña
  cargarEjecutores({ idCampana: val_campana });
} else if (parseInt(val_tipo) === 3) {
  // Modo ruta
  cargarEjecutores({ idCampana: 0 });
}

  // ✅ Carga según tipo de gestión
  if ($campana) {
    $campana.addEventListener('change', function () {
      const idCampana = parseInt($campana.value, 10) || 0;
      cargarEjecutores({ idCampana });
    });
  }

  if ($tipo) {
    $tipo.addEventListener('change', function () {
      const tipoG = parseInt($tipo.value, 10);
      if (tipoG === 3) { // Ruta
        cargarEjecutores({ idCampana: 0 });
      }
    });
  }

  // ✅ Restauración automática al cargar tras "Buscar"
  if (parseInt(val_tipo) === 1 && parseInt(val_campana) > 0) {
    cargarEjecutores({ idCampana: val_campana });
  } else if (parseInt(val_tipo) === 3) {
    cargarEjecutores({ idCampana: 0 });
  }
});

const panel = document.getElementById('panel-filtros');
const boton = document.querySelector('[data-target="#panel-filtros"]');
const icono = boton.querySelector('i');

panel.addEventListener('hide.bs.collapse', () => {
  icono.classList.replace('fa-chevron-up', 'fa-chevron-down');
});

panel.addEventListener('show.bs.collapse', () => {
  icono.classList.replace('fa-chevron-down', 'fa-chevron-up');
});
</script>


</body>
</html>
