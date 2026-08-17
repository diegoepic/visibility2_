(function(window){
  'use strict';

  /**
   * Recolecta los puntos de ruta desde las tablas visibles.
   * Retorna { pts, dropped, isMultiDate, fechas } donde:
   *   pts        — array de RoutePoint (TODOS los válidos; sin límite duro de 24)
   *   dropped    — siempre [] (compatibilidad; ya no se descartan locales aquí — el chunking
   *                de rutas largas lo maneja RoutePlanner, ver route_planner.js)
   *   isMultiDate — true si los puntos provienen de más de una fecha
   *   fechas      — Set con las fechas involucradas
   */
  function collect({ modo, fechaSel, searchTerm, excluded, cont }){
    const term = String(searchTerm || '').trim();

    const tableFilter = term
      ? `${cont} table[data-fechaTabla]:visible tbody tr:visible`
      : `${cont} table[data-fechaTabla="${fechaSel}"]:visible tbody tr:visible`;

    const pts    = [];
    const fechas = new Set();

    $(tableFilter).each(function(){
      const $tr     = $(this);
      const idLocal = parseInt($tr.data('idlocal'), 10);
      const lat     = parseFloat($tr.data('lat'));
      const lng     = parseFloat($tr.data('lng'));
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
      if (lat === 0 && lng === 0) return;

      const fecha = $tr.closest('table').data('fechatabla') || fechaSel;
      fechas.add(fecha);

      /*
       * Un local entra a la ruta sólo si el checkbox está marcado Y no está en el set de
       * exclusiones persistido. Antes se miraba UNA de las dos fuentes:
       *   - con checkbox presente, sólo el checkbox;
       *   - sin checkbox, sólo el set.
       * En rutas de varias fechas eso se rompía: applyFilters() sincroniza los checkboxes
       * desde `excluded`, pero sólo recorre TODAS las tablas cuando hay texto de búsqueda,
       * así que una fila de otra fecha podía llegar marcada (viene `checked` del servidor)
       * pese a estar excluida, y la ruta terminaba tomando todos los locales del día.
       * Exigir ambas condiciones hace que la exclusión mande siempre.
       */
      const $chk       = $tr.find('.in-route');
      const marcado    = $chk.length ? $chk.prop('checked') : true;
      const estaExcl   = !!(excluded && excluded.has(`${modo}|${fecha}|${idLocal}`));
      if (!marcado || estaExcl) return;

      const isBotilleria = $tr.data('isbotilleria') === 'true' || $tr.data('isbotilleria') === true;
      pts.push({ idLocal, lat, lng, isBotilleria, fecha, modo });
    });

    // Botillerías al final si es antes de las 13:00
    const horaMin = new Date().getHours() * 60 + new Date().getMinutes();
    if (horaMin < 13 * 60) {
      const norm = pts.filter(p => !p.isBotilleria);
      const bots = pts.filter(p =>  p.isBotilleria);
      pts.length = 0;
      pts.push(...norm, ...bots);
    }

    // Sin límite duro: se devuelven TODOS los puntos válidos. Las rutas con más de 24 paradas
    // se dividen en bloques (chunks) en RoutePlanner.planFull para respetar el límite por
    // llamada de Routes API; ningún local se descarta silenciosamente.
    const dropped = [];

    // Filtrar fechas a solo las que tienen puntos incluidos
    const fechasConPuntos = new Set(pts.map(p => p.fecha));

    return {
      pts,
      dropped,
      isMultiDate: fechasConPuntos.size > 1,
      fechas: fechasConPuntos
    };
  }

  // Promesa de la confirmación cross-date en curso.
  // planRouteFromSelection se dispara desde varios lados a la vez (al abrir el modal del mapa
  // lo lanzan tanto applyFilters() como el handler shown.bs.modal), así que sin este candado
  // se abrían DOS modales: el segundo hacía .remove() del primero mientras seguía visible,
  // dejando su backdrop huérfano — la pantalla negra que no deja hacer clic.
  let _pendiente = null;

  /**
   * Muestra un modal de confirmación cuando la ruta mezcla varias fechas.
   * Si ya hay una confirmación abierta devuelve LA MISMA promesa, de modo que todas las
   * llamadas concurrentes se resuelven con la única respuesta que dio el usuario.
   * Retorna Promise<boolean>.
   */
  function confirmCrossDate(fechas){
    if (_pendiente) return _pendiente;

    _pendiente = new Promise(function(resolve){
      const listaFechas = Array.from(fechas)
        .sort()
        .map(f => {
          try { return new Date(f + 'T00:00:00').toLocaleDateString('es-CL', { weekday:'long', day:'numeric', month:'long' }); }
          catch(_){ return f; }
        })
        .map(f => `<li>${f}</li>`)
        .join('');

      // Modal Bootstrap 3 compatible
      const modalHtml = `
        <div class="modal fade" id="modalCrossDate" tabindex="-1" role="dialog">
          <div class="modal-dialog" role="document">
            <div class="modal-content">
              <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title"><i class="fa fa-calendar-o"></i> Ruta de múltiples fechas</h4>
              </div>
              <div class="modal-body">
                <p>La búsqueda activa incluye locales de <strong>${fechas.size} fechas</strong> diferentes:</p>
                <ul>${listaFechas}</ul>
                <p>¿Deseas armar la ruta combinando locales de todas estas fechas?</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-default" id="crossDateCancel">Cancelar</button>
                <button type="button" class="btn btn-primary" id="crossDateConfirm">Sí, armar ruta</button>
              </div>
            </div>
          </div>
        </div>`;

      // Acá no puede haber una confirmación viva: el candado _pendiente impide entrar mientras
      // haya una abierta. Si quedó un elemento suelto es basura de una ejecución anterior.
      // Ojo: este modal se abre ENCIMA del modal del mapa, así que los backdrops sobrantes se
      // barren sólo si no queda ningún modal visible (si no, se le quitaría el suyo al mapa).
      $('#modalCrossDate').remove();
      if ($('.modal.in').length === 0) {
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css('padding-right', '');
      }

      $('body').append(modalHtml);
      const $modal = $('#modalCrossDate');

      // El elemento se destruye recién cuando Bootstrap terminó de ocultarlo: en ese momento el
      // backdrop ya fue retirado y body.modal-open limpiado.
      $modal.on('hidden.bs.modal', function(){
        $modal.remove();
        limpiarBackdropHuerfano();
      });

      // Handlers acotados a ESTE modal (antes eran selectores globales por id).
      $modal.find('#crossDateCancel').on('click', function(){
        $modal.modal('hide');
        resolve(false);
      });

      $modal.find('#crossDateConfirm').on('click', function(){
        $modal.modal('hide');
        resolve(true);
      });

      // Un solo modal(): pasarle opciones ya lo muestra (show:true viene en los DEFAULTS de
      // Bootstrap 3), así que el .modal('show') que había después era una segunda apertura.
      $modal.modal({ backdrop: 'static', keyboard: false });
    });

    // Se libera el candado pase lo que pase, para que la próxima ruta cross-date sí pregunte.
    _pendiente.then(function(){ _pendiente = null; }, function(){ _pendiente = null; });
    return _pendiente;
  }

  /**
   * Red de seguridad contra el "fade negro" que bloquea la pantalla.
   * Bootstrap 3 mantiene un único body.modal-open y un backdrop por modal; si alguno queda
   * suelto (modal removido en caliente, dos modales encimados), la capa oscura sigue ahí y se
   * come todos los clics. Si no queda ningún modal visible, se barre lo que haya sobrado.
   */
  function limpiarBackdropHuerfano(){
    setTimeout(function(){
      if ($('.modal.in').length === 0) {
        $('.modal-backdrop').remove();
        $('body').removeClass('modal-open').css('padding-right', '');
      } else {
        // Bootstrap 3 no contempla modales anidados: al cerrar el de encima le quita
        // body.modal-open aunque siga abierto el de abajo, y la página de atrás recupera el
        // scroll. Se repone mientras quede alguno visible.
        $('body').addClass('modal-open');
      }
    }, 300); // > la transición de 150 ms de Bootstrap
  }

  window.RouteSelection = { collect, confirmCrossDate, limpiarBackdropHuerfano };

})(window);
