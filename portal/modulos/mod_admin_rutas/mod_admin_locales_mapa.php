<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'mapa_data.php';

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Panel de Locales - Rutas IPT</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.2/css/all.min.css">
  <link rel="stylesheet" type="text/css" href="../../css/mapa.css">  
  <link rel="stylesheet" type="text/css" href="../../assets/css/style.css">
  <link rel="stylesheet" type="text/css" href="style.css">  
  <link rel='stylesheet' href='https://cdn.datatables.net/v/dt/jq-3.3.1/jszip-2.5.0/dt-1.10.20/b-1.6.1/b-colvis-1.6.1/b-html5-1.6.1/r-2.2.3/datatables.min.css'>
</head>
<body>
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
<div class="map-filters-card mt-3">
  <div class="map-filters-body">
    <form id="formFiltrosMapa" method="GET" action="mod_admin_locales_mapa.php">

      <!-- FILA 1 -->
      <div class="form-row">
        <?php if ($puedeCambiarDivision): ?>
          <div class="form-group col-md-3 filter-group">
            <label for="id_division" class="filter-label">DIVISIÓN</label>
        
            <select class="form-control filter-control" 
                    id="id_division" 
                    name="id_division">
              <option value="0">SELECCIONE DIVISIÓN</option>
        
              <?php foreach ($divisiones as $div): ?>
                <option value="<?= (int)$div['id'] ?>" 
                  <?= ((int)$div['id'] === (int)$filter_division) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($div['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
        
            <?php if (empty($divisiones)): ?>
              <small style="color:#ffcf4d;">
                No se encontraron divisiones cargadas.
              </small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <input type="hidden" name="id_division" value="<?= (int)$filter_division ?>">
        <?php endif; ?>

        <div class="form-group col-md-3 filter-group">
          <label for="id_subdivision" class="filter-label">SUB DIVISIÓN</label>
          <select class="form-control filter-control" id="id_subdivision" name="id_subdivision" disabled>
            <option value="0" selected>TODAS</option>
            <option value="-1">SIN SUB DIVISIÓN</option>
          </select>
          <small class="help">FILTRA CAMPAÑAS POR SUB DIVISIÓN (SI APLICA).</small>
        </div>

        <div class="form-group col-md-2 filter-group">
          <label for="tipo_gestion" class="filter-label">TIPO DE GESTIÓN</label>
          <select class="form-control filter-control" id="tipo_gestion" name="tipo_gestion">
            <option value="0" <?= ($tipoCampana==0)?'selected':'' ?>>TODAS</option>
            <option value="1" <?= ($tipoCampana==1)?'selected':'' ?>>CAMPAÑA</option>
            <option value="3" <?= ($tipoCampana==3)?'selected':'' ?>>RUTA</option>
          </select>
          <small class="help">FILTRA POR TIPO DE GESTIÓN.</small>
        </div>

        <div class="form-group col-md-2 filter-group">
          <label for="estado" class="filter-label">ESTADO CAMPAÑA</label>
          <select class="form-control filter-control" id="estado" name="estado"
                  onchange="document.getElementById('id_campana').value = 0; document.getElementById('id_ejecutor').value = 0;">
            <option value="0" <?= $filter_estado==0?'selected':'' ?>>AMBOS</option>
            <option value="1" <?= $filter_estado==1?'selected':'' ?>>EN CURSO</option>
            <option value="3" <?= $filter_estado==3?'selected':'' ?>>FINALIZADAS</option>
          </select>
        </div>
      </div>

      <!-- FILA 2 -->
      <div class="form-row">
        <div class="form-group col-md-4 filter-group" id="campo-campana" style="display:none">
          <label for="id_campana" class="filter-label">CAMPAÑA</label>
          <select class="form-control filter-control" id="id_campana" name="id_campana" disabled>
            <option value="0" selected>SELECCIONE CAMPAÑA</option>
          </select>
          <small class="help">PUEDES ESCOGER CAMPAÑA O IR DIRECTO AL EJECUTOR</small>
        </div>

        <div class="form-group col-md-3 filter-group">
          <label for="id_ejecutor" class="filter-label">EJECUTOR</label>
          <select class="form-control filter-control" id="id_ejecutor" name="id_ejecutor">
            <option value="0" selected>TODOS LOS EJECUTORES</option>
          </select>
<small class="help">SI NO ELIGES CAMPAÑA O RUTA, SE MOSTRARÁN TODOS LOS EJECUTORES DISPONIBLES.</small>
        </div>

        <div class="form-group col-md-2 filter-group" id="campo-desde">
          <label for="desde" class="filter-label">DESDE</label>
          <input type="date" class="form-control filter-control" id="desde" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>">
        </div>

        <div class="form-group col-md-2 filter-group" id="campo-hasta">
          <label for="hasta" class="filter-label">HASTA</label>
          <input type="date" class="form-control filter-control" id="hasta" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
        </div>
      </div>

      <!-- FILA 3: BOTÓN -->
      <div class="form-row">
        <div class="form-group col-12 filter-search-wrap mb-0">
          <button type="submit" class="btn filter-search-btn px-4">
            BUSCAR <i class="fa fa-search ml-2"></i>
          </button>
        </div>
      </div>

      <!-- Persistencia -->
<input type="hidden" name="buscar" value="1">

<input type="hidden" id="val_division" value="<?= (int)$filter_division ?>">
<input type="hidden" id="val_subdivision" value="<?= (int)$filter_subdivision ?>">
<input type="hidden" id="val_campana" value="<?= (int)$filter_campana ?>">
<input type="hidden" id="val_ejecutor" value="<?= (int)$id_ejecutor ?>">
<input type="hidden" id="val_tipo" value="<?= (int)$tipoCampana ?>">
<input type="hidden" name="empresa_id" id="empresa_id" value="<?= (int)$id_empresa ?>">

        </form>
      </div>
    </div> 
  </div>
</div>

<div class="container admin-panel-wrap mt-4 mb-5">
  <div class="admin-panel-card">
    <div class="admin-panel-header">
      <div>
        <h4 class="mb-1">Panel administrativo de locales</h4>
        <p class="mb-0">
          Selecciona locales desde el mapa para reasignar usuario, cambiar fecha propuesta o eliminar planificación.
        </p>
      </div>

      <div class="admin-panel-counter">
        <span id="contadorSeleccionados">0</span>
        <small>seleccionados</small>
      </div>
    </div>

    <div class="admin-actions-grid mt-3">
      <button type="button" class="btn admin-btn secondary" id="btnModoSeleccion">
        <i class="fa fa-vector-square mr-2"></i> Modo selección
      </button>

      <button type="button" class="btn admin-btn secondary" id="btnLimpiarSeleccion">
        <i class="fa fa-times-circle mr-2"></i> Limpiar selección
      </button>

      <div>
        <label class="filter-label">Nuevo usuario</label>
        <select class="form-control filter-control" id="nuevoUsuario">
          <option value="">Seleccione usuario</option>
        </select>
      </div>

      <div>
        <label class="filter-label">Nueva fecha propuesta</label>
        <input type="date" class="form-control filter-control" id="nuevaFechaPropuesta">
      </div>

      <button type="button" class="btn admin-btn success" id="btnReasignarUsuario">
        <i class="fa fa-user-edit mr-2"></i> Reasignar usuario
      </button>

      <button type="button" class="btn admin-btn warning" id="btnCambiarFecha">
        <i class="fa fa-calendar-alt mr-2"></i> Cambiar fecha
      </button>

      <button type="button" class="btn admin-btn danger" id="btnEliminarLocales">
        <i class="fa fa-trash mr-2"></i> Eliminar de planificación
      </button>
    </div>

    <div class="selected-list mt-3">
      <div class="selected-list-title">Locales seleccionados</div>
      <div id="listaSeleccionados" class="selected-list-body">
        No hay locales seleccionados.
      </div>
    </div>
  </div>
</div>

<div id="selectionBox"></div>

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
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
    window.puedeCambiarDivision = <?= $puedeCambiarDivision ? 'true' : 'false' ?>;
    window.divisionUsuario = <?= (int)$filter_division ?>;
</script>

<script>
const puedeCambiarDivision = window.puedeCambiarDivision;
const divisionUsuario = window.divisionUsuario;
</script>

<script>
  const coordenadasLocales = <?= json_encode($coordenadas_locales, JSON_UNESCAPED_UNICODE) ?>;
    console.log('DEBUG filtros mapa admin:', {
      accionBuscar: <?= (int)$accionBuscar ?>,
      empresa: <?= (int)$id_empresa ?>,
      division: <?= (int)$filter_division ?>,
      subdivision: <?= (int)$filter_subdivision ?>,
      tipo: <?= (int)$tipoCampana ?>,
      estado: <?= (int)$filter_estado ?>,
      campana: <?= (int)$filter_campana ?>,
      ejecutor: <?= (int)$id_ejecutor ?>,
      desde: '<?= htmlspecialchars($fecha_desde) ?>',
      hasta: '<?= htmlspecialchars($fecha_hasta) ?>',
      locales: <?= count($locales) ?>,
      coordenadas: coordenadasLocales.length
    });  
  const idEjecutor = <?= (int)$id_ejecutor ?>;
</script>
<script src="admin_mapa_lotes.js"></script>
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
    const valor = parseInt(tipoGestion?.value || 0, 10);
    if (!campoCampana || !campoDesde || !campoHasta) return;

    campoCampana.style.display = (valor === 0 || valor === 1) ? "block" : "none";
    campoDesde.style.display = "block";
    campoHasta.style.display = "block";
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
      if (selectedVal !== null && String(selectedVal) === String(o.id)) {
        opt.selected = true;
      }
      select.add(opt);
    });
  }

  /* ============================================
     🧩 ELEMENTOS DEL FORMULARIO
  ============================================ */
  const $division = document.getElementById('id_division');
  const divisionIsHidden = !$division;
  const $subdivision = document.getElementById('id_subdivision');
  const $campana = document.getElementById('id_campana');
  const $ejecutor = document.getElementById('id_ejecutor');
  const $estado = document.getElementById('estado');
  const $tipo = document.getElementById('tipo_gestion');
  const $distrito = document.getElementById('id_distrito');
const $empresa = document.getElementById('empresa_id') || { value: '<?= (int)$id_empresa ?>' };

const $nuevoUsuario = document.getElementById('nuevoUsuario');

function cargarUsuariosReasignar() {
  if (!$nuevoUsuario) return;

  const empresa = $empresa?.value || 0;
  const division = $division ? ($division.value || 0) : divisionUsuario;
  const subdivision = $subdivision?.value || 0;

  $nuevoUsuario.innerHTML = '<option value="">Cargando usuarios...</option>';
  $nuevoUsuario.disabled = true;

  const params = new URLSearchParams({
    id_empresa: empresa,
    id_division: division,
    id_subdivision: subdivision
  });

  fetch(`ajax_admin_cargar_usuarios_reasignar.php?${params.toString()}`)
    .then(r => r.json())
    .then(data => {
      console.log('Usuarios para reasignar:', data);

      $nuevoUsuario.innerHTML = '<option value="">Seleccione usuario</option>';

      if (data.ok && Array.isArray(data.usuarios) && data.usuarios.length > 0) {
        data.usuarios.forEach(u => {
          const nombre = `${u.nombre || ''} ${u.apellido || ''}`.trim();
          const usuario = u.usuario ? ` (${u.usuario})` : '';
          const opt = new Option(`${nombre}${usuario}`, u.id);
          $nuevoUsuario.add(opt);
        });
      } else {
        const opt = new Option('SIN USUARIOS DISPONIBLES', '');
        opt.disabled = true;
        $nuevoUsuario.add(opt);
      }

      $nuevoUsuario.disabled = false;
    })
    .catch(err => {
      console.error('Error cargando usuarios para reasignar:', err);
      $nuevoUsuario.innerHTML = '<option value="">ERROR AL CARGAR</option>';
      $nuevoUsuario.disabled = false;
    });
}

  // Valores guardados desde PHP
  const val_division = document.getElementById('val_division')?.value || 0;
  const val_subdivision = document.getElementById('val_subdivision')?.value || 0;
  const val_campana = document.getElementById('val_campana')?.value || 0;
  const val_ejecutor = document.getElementById('val_ejecutor')?.value || 0;
  const val_tipo = document.getElementById('val_tipo')?.value || 0;
  
const $formFiltrosMapa = document.getElementById('formFiltrosMapa');

if ($formFiltrosMapa) {
  $formFiltrosMapa.addEventListener('submit', function () {
    /*
      Importante:
      Los campos disabled no se envían por GET.
      Antes de buscar, habilitamos los selects para que PHP reciba todos los filtros.
    */
    [$division, $subdivision, $campana, $ejecutor, $estado, $tipo].forEach(el => {
      if (el) el.disabled = false;
    });
  });
}  
  
  let xhrEjecutores = null;
  let firmaEjecutores = '';

  /* ============================================
     🧩 SUBDIVISIONES DINÁMICAS
  ============================================ */
  function cargarSubdivisionesAuto(idDivision, restaurar = false) {
    if (!$subdivision) return;

    $subdivision.disabled = false;
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

        if (
          restaurar &&
          val_subdivision !== "0" &&
          $subdivision.querySelector(`option[value="${val_subdivision}"]`)
        ) {
          $subdivision.value = val_subdivision;
        }

        $subdivision.disabled = false;
      })
      .catch(err => {
        console.error('Error cargando subdivisiones:', err);
        resetSelect($subdivision, 'ERROR AL CARGAR');
        $subdivision.disabled = false;
      });
  }

  /* ============================================
     🧩 CAMPAÑAS DINÁMICAS
  ============================================ */
  function cargarCampanasMapa(restaurar = false) {
    if (!$campana || !$subdivision) return;

    const division = $division ? ($division.value || 0) : divisionUsuario;
    const subdiv = $subdivision.value || 0;
    const estado = $estado?.value || 0;
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

        if (
          restaurar &&
          val_campana !== "0" &&
          $campana.querySelector(`option[value="${val_campana}"]`)
        ) {
          $campana.value = val_campana;
        }

        $campana.disabled = false;
      })
      .catch(err => {
        console.error('Error cargando campañas:', err);
        resetSelect($campana, 'ERROR AL CARGAR');
        $campana.disabled = false;
      });
  }

  /* ============================================
     🧩 EJECUTORES DINÁMICOS
  ============================================ */
  function recargarEjecutoresMapa(restaurar = false) {
    if (!$ejecutor) return;

    const empresa = $empresa?.value || 0;
    const division = $division ? ($division.value || 0) : divisionUsuario;
    const subdivision = $subdivision?.value || 0;
    const distrito = $distrito?.value || 0;
    const tipoG = $tipo?.value || 0;
    const idCampana = $campana?.value || 0;
    const estado = $estado?.value || 0;

    const firmaActual = [empresa, division, subdivision, distrito, tipoG, idCampana, estado].join('|');
    if (!restaurar && firmaActual === firmaEjecutores) return;
    firmaEjecutores = firmaActual;

    if (xhrEjecutores && xhrEjecutores.abort) {
      xhrEjecutores.abort();
    }

    resetSelect($ejecutor, 'Cargando ejecutores...');
    $ejecutor.disabled = true;

    const params = new URLSearchParams({
      id_empresa: empresa,
      id_division: division,
      id_subdivision: subdivision,
      id_distrito: distrito,
      tipo_gestion: tipoG,
      id_campana: idCampana,
      estado: estado
    });

    xhrEjecutores = new AbortController();

    fetch(`ajax_admin_cargar_ejecutores.php?${params.toString()}`, {
      signal: xhrEjecutores.signal
    })
      .then(r => r.json())
        .then(data => {
          console.log('Ejecutores cargados:', data);
        
          resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
        
          if (data.ok && Array.isArray(data.ejecutores) && data.ejecutores.length > 0) {
            data.ejecutores.forEach(e => {
              const nombre = `${e.nombre || ''} ${e.apellido || ''}`.trim();
              const usuario = e.usuario ? ` (${e.usuario})` : '';
              const opt = new Option(`${nombre}${usuario}`, e.id);
              $ejecutor.add(opt);
            });
          } else {
            const opt = new Option('SIN EJECUTORES DISPONIBLES', '');
            opt.disabled = true;
            $ejecutor.add(opt);
          }
        
          if (
            restaurar &&
            val_ejecutor &&
            $ejecutor.querySelector(`option[value="${val_ejecutor}"]`)
          ) {
            $ejecutor.value = val_ejecutor;
          } else {
            $ejecutor.value = "0";
          }
        
          $ejecutor.disabled = false;
        })
      .catch(err => {
        if (err.name === 'AbortError') return;
        console.error('Error cargando ejecutores:', err);
        resetSelect($ejecutor, 'ERROR AL CARGAR');
        $ejecutor.disabled = false;
      });
  }

  /* ============================================
     🔥 LÓGICA INICIAL SEGÚN TIPO DE USUARIO
  ============================================ */
if (!puedeCambiarDivision || divisionIsHidden) {
    if ($subdivision) {
      $subdivision.disabled = false;
      cargarSubdivisionesAuto(divisionUsuario, true);
    }
  } else if ($division) {
    $division.addEventListener('change', function () {
      const idDivision = parseInt($division.value, 10) || 0;

      resetSelect($campana, 'SELECCIONE CAMPAÑA');
      resetSelect($ejecutor, 'TODOS LOS EJECUTORES');

      if (idDivision <= 0) {
        resetSelect($subdivision, 'SIN SUBDIVISIÓN');
        $subdivision.disabled = true;
        return;
      }

        cargarSubdivisionesAuto(idDivision, false);
        
        setTimeout(() => {
          if ($subdivision) {
            $subdivision.value = "0";
            $subdivision.dispatchEvent(new Event('change'));
          }
        
          recargarEjecutoresMapa(false);
          cargarUsuariosReasignar();
        }, 300);
    });

    if (parseInt(val_division, 10) > 0) {
      $division.value = val_division;
      cargarSubdivisionesAuto(val_division, true);
    }
  }

  /* ============================================
     🔄 SINCRONIZACIÓN ENTRE FILTROS
  ============================================ */
  if ($subdivision) {
    $subdivision.addEventListener('change', function () {
      const tipoG = parseInt($tipo?.value || 0, 10);

      if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
      }

      if ($ejecutor) {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
      }

      if (tipoG === 0 || tipoG === 1) {
        cargarCampanasMapa(false);
      } else if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
        $campana.disabled = true;
      }

      recargarEjecutoresMapa(false);
      cargarUsuariosReasignar();
    });
  }

  if ($campana) {
    $campana.addEventListener('change', function () {
      recargarEjecutoresMapa(false);
    });
  }

  if ($tipo) {
    $tipo.addEventListener('change', function () {
      const tipoG = parseInt($tipo.value || 0, 10);

      if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
      }

      if ($ejecutor) {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
        $ejecutor.disabled = false;
      }

      if (tipoG === 3) {
        if ($campana) {
          $campana.disabled = true;
        }
        recargarEjecutoresMapa(false);
        return;
      }

      if (tipoG === 0 || tipoG === 1) {
        if ($campana) {
          $campana.disabled = false;
        }

        if ($subdivision) {
          $subdivision.dispatchEvent(new Event('change'));
        } else {
          recargarEjecutoresMapa(false);
        }
      }
    });
  }

  if ($estado) {
    $estado.addEventListener('change', function () {
      const tipoG = parseInt($tipo?.value || 0, 10);

      if ($campana) {
        resetSelect($campana, 'SELECCIONE CAMPAÑA');
      }

      if ($ejecutor) {
        resetSelect($ejecutor, 'TODOS LOS EJECUTORES');
      }

      if (tipoG === 0 || tipoG === 1) {
        cargarCampanasMapa(false);
      }

      recargarEjecutoresMapa(false);
    });
  }

  if ($distrito) {
    $distrito.addEventListener('change', function () {
      recargarEjecutoresMapa(false);
    });
  }

  /* ============================================
     🔁 RESTAURACIÓN AUTOMÁTICA
  ============================================ */
  function restaurarEstadoInicial() {
    const tipoG = parseInt(val_tipo || 0, 10);

    if ($tipo) {
      $tipo.value = val_tipo || 0;
    }

    actualizarVisibilidad();

if (!puedeCambiarDivision || divisionIsHidden) {
      setTimeout(() => {
        if ($subdivision && val_subdivision) {
          $subdivision.value = val_subdivision;
        }

        if (tipoG === 0 || tipoG === 1) {
          cargarCampanasMapa(true);
        } else if ($campana) {
          resetSelect($campana, 'SELECCIONE CAMPAÑA');
          $campana.disabled = true;
        }

        recargarEjecutoresMapa(true);
      }, 250);
    } else {
      setTimeout(() => {
        if ($subdivision && val_subdivision) {
          $subdivision.value = val_subdivision;
        }

        if (tipoG === 0 || tipoG === 1) {
          cargarCampanasMapa(true);
        } else if ($campana) {
          resetSelect($campana, 'SELECCIONE CAMPAÑA');
          $campana.disabled = true;
        }

        recargarEjecutoresMapa(true);
      }, 250);
    }
  }
  
  
  restaurarEstadoInicial();
  cargarUsuariosReasignar();
});

const panel = document.getElementById('panel-filtros');
const boton = document.querySelector('[data-target="#panel-filtros"]');

if (panel && boton) {
  const icono = boton.querySelector('i');

  panel.addEventListener('hide.bs.collapse', () => {
    icono?.classList.replace('fa-chevron-up', 'fa-chevron-down');
  });

  panel.addEventListener('show.bs.collapse', () => {
    icono?.classList.replace('fa-chevron-down', 'fa-chevron-up');
  });
}
</script>


</body>
</html>
