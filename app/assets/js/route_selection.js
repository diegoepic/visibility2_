(function(window){
  'use strict';

  /**
   * Recolecta los puntos de ruta desde las tablas visibles.
   * Retorna { pts, isMultiDate, fechas } donde:
   *   pts        — array de RoutePoint (máx 24 tras aplicar límite)
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

      const hasCheck = $tr.find('.in-route').length > 0;
      const include  = hasCheck
        ? $tr.find('.in-route').prop('checked')
        : !(excluded && excluded.has(`${modo}|${fecha}|${idLocal}`));
      if (!include) return;

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

    // Límite de 24 puntos (Routes API / DirectionsService)
    let dropped = [];
    if (pts.length > 24) {
      dropped = pts.splice(24);
      const $w = $('#routeWarning');
      if ($w.length) {
        $w.html(
          `⚠ Ruta limitada a 24 locales. <strong>${dropped.length}</strong> local(es) no incluido(s).` +
          ` <button type="button" style="float:right;background:none;border:none;font-size:14px;line-height:1;cursor:pointer;opacity:.7" onclick="$(\'#routeWarning\').hide()">✕</button>`
        ).stop(true).show();
      }
    }

    // Filtrar fechas a solo las que tienen puntos incluidos
    const fechasConPuntos = new Set(pts.map(p => p.fecha));

    return {
      pts,
      dropped,
      isMultiDate: fechasConPuntos.size > 1,
      fechas: fechasConPuntos
    };
  }

  /**
   * Muestra un modal de confirmación cuando la ruta mezcla varias fechas.
   * Retorna Promise<boolean>.
   */
  function confirmCrossDate(fechas){
    return new Promise(function(resolve){
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

      // Remover modal previo si existe
      $('#modalCrossDate').remove();
      $('body').append(modalHtml);
      const $modal = $('#modalCrossDate');

      $modal.on('hidden.bs.modal', function(){
        $modal.remove();
        // Si ya fue resuelto no hacer nada
      });

      $('#crossDateCancel').off('click').on('click', function(){
        $modal.modal('hide');
        resolve(false);
      });

      $('#crossDateConfirm').off('click').on('click', function(){
        $modal.modal('hide');
        resolve(true);
      });

      $modal.modal({ backdrop: 'static', keyboard: false });
      $modal.modal('show');
    });
  }

  window.RouteSelection = { collect, confirmCrossDate };

})(window);
