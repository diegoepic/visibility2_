// File: /visibility2/portal/modulos/mod_formulario/gestiones.js
(function($){

  // Leer CSRF token desde meta-tag
  const CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content');

  // Variables globales para estado
  let currentFormId, currentLocalId, currentUserId;
  let pageImpl = 1, perPageImpl = 10;
  let pageEnc  = 1, perPageEnc = 10;

  // 1) RENDER DEL <select> DE USUARIOS
  function renderUserSelect(users, selected) {
    const $sel = $('#select_ejecutor');
    $sel.empty();
    if (users.length === 0) {
      $sel.append($('<option>').val(0).text('— sin ejecutores —'));
      return;
    }
    users.forEach(u => {
      const $opt = $('<option>')
        .val(u.uid)
        .text(u.usuario);
      if (u.uid === selected) {
        $opt.prop('selected', true);
      }
      $sel.append($opt);
    });
  }

  // 2) RENDER DE IMPLEMENTACIONES
  function renderImplementaciones(dataImpl, pagination) {
    const $tbody = $('#tbl-implementaciones tbody').empty();
    if (!Array.isArray(dataImpl) || dataImpl.length === 0) {
      $tbody.append(
        $('<tr>').append(
          $('<td>').attr('colspan', 9).addClass('text-center').text('No hay implementaciones registradas.')
        )
      );
    } else {
      dataImpl.forEach(row => {
        const $tr = $('<tr>').attr('data-id', row.id);
        $tr.append($('<td>').text(row.id));
        $tr.append($('<td>').text(row.nombre_usuario));
        // Celda “material” con inline-edit
        const $cellMat = $('<td>').addClass('material-cell');
        $cellMat.append(
          $('<span>')
            .addClass('material-text')
            .text(row.material)
        );
        $cellMat.append(
          $('<input>')
            .addClass('material-input form-control form-control-sm')
            .val(row.material)
            .hide()
            .css('width', 'auto')
        );
        $cellMat.append(
          $('<button>')
            .addClass('btn btn-sm btn-primary save-material')
            .text('Guardar')
            .hide()
        );
        $tr.append($cellMat);

        $tr.append($('<td>').text(row.valor_propuesto));
        $tr.append($('<td>').text(row.valor !== null ? row.valor : 'N/A'));
        $tr.append($('<td>').text(row.fechaVisita || ''));
        // Celda de fotos
        const $tdFotos = $('<td>');
        if (row.fotos_urls) {
          row.fotos_urls.split(',').forEach(rel => {
            const url = '/visibility2/app/' + rel;
            const $img = $('<img>')
              .addClass('photo-thumb')
              .css({
                width: '40px',
                margin: '2px',
                cursor: 'pointer'
              })
              .attr('src', url);
            $tdFotos.append($img);
          });
        }
        $tr.append($tdFotos);

        $tr.append($('<td>').text(row.observacion || ''));

        // Botones de acción
        const $tdAcc = $('<td>');
        $tdAcc.append(
          $('<button>')
            .addClass('btn btn-sm btn-danger delete-impl')
            .text('Eliminar')
        );
        $tdAcc.append(' ');
        $tdAcc.append(
          $('<button>')
            .addClass('btn btn-sm btn-warning clear-material')
            .text('Limpiar')
        );
        $tdAcc.append(' ');
        $tdAcc.append(
          $('<button>')
            .addClass('btn btn-sm btn-secondary edit-material')
            .text('Editar')
        );
        $tr.append($tdAcc);

        $tbody.append($tr);
      });
    }

    // Paginación para Implementaciones
    renderPagination(
      '#pagination-impl',
      pagination.page,
      pagination.last_page,
      pagination.total,
      'impl'
    );
  }

  // 3) RENDER DE RESPUESTAS DE ENCUESTA
  function renderRespuestas(dataRes, pagination) {
    const $tbody = $('#tbl-respuestas tbody').empty();
    if (!Array.isArray(dataRes) || dataRes.length === 0) {
      $tbody.append(
        $('<tr>').append(
          $('<td>').attr('colspan', 6).addClass('text-center').text('No hay respuestas registradas.')
        )
      );
    } else {
      dataRes.forEach(row => {
        const $tr = $('<tr>').attr('data-id', row.id);
        $tr.append($('<td>').text(row.id));
        $tr.append($('<td>').text(row.nombre_usuario));
        $tr.append($('<td>').text(row.question_text));

        // Celda de “respuesta”: si es URL de imagen, mostrar miniatura
        const $tdAns = $('<td>');
        if (row.answer_text && /\.(jpe?g|png|gif)$/i.test(row.answer_text)) {
          const rel = row.answer_text.replace(/^\/+/, '');
          const url = '/visibility2/' + rel;
          $tdAns.append(
            $('<img>')
              .addClass('photo-thumb')
              .css({
                width: '40px',
                margin: '2px',
                cursor: 'pointer'
              })
              .attr('src', url)
          );
        } else {
          $tdAns.text(row.answer_text || '');
        }
        $tr.append($tdAns);

        $tr.append($('<td>').text(row.created_at || ''));

        const $tdAcc = $('<td>');
        $tdAcc.append(
          $('<button>')
            .addClass('btn btn-sm btn-danger delete-resp')
            .text('Eliminar')
        );
        $tr.append($tdAcc);

        $tbody.append($tr);
      });
    }

    // Paginación para Encuesta
    renderPagination(
      '#pagination-enc',
      pagination.page,
      pagination.last_page,
      pagination.total,
      'enc'
    );
  }

  // 4) RENDERIZAR PAGINACIÓN GENÉRICA
  function renderPagination(containerSelector, currentPage, lastPage, totalItems, tipo) {
    const $ul = $(containerSelector).empty();

    // Botón “Anterior”
    const isPrevDisabled = currentPage <= 1;
    const $liPrev = $('<li>')
      .addClass('page-item' + (isPrevDisabled ? ' disabled' : ''));
    const $aPrev = $('<a>')
      .addClass('page-link')
      .attr('href', '#')
      .text('← Anterior')
      .data('tipo', tipo)
      .data('page', currentPage - 1);
    $liPrev.append($aPrev);
    $ul.append($liPrev);

    // Info “Página X de Y”
    const $liInfo = $('<li>')
      .addClass('page-item disabled');
    const $spanInfo = $('<span>')
      .addClass('page-link')
      .text(`Página ${currentPage} de ${lastPage} (Total: ${totalItems})`);
    $liInfo.append($spanInfo);
    $ul.append($liInfo);

    // Botón “Siguiente”
    const isNextDisabled = currentPage >= lastPage;
    const $liNext = $('<li>')
      .addClass('page-item' + (isNextDisabled ? ' disabled' : ''));
    const $aNext = $('<a>')
      .addClass('page-link')
      .attr('href', '#')
      .text('Siguiente →')
      .data('tipo', tipo)
      .data('page', currentPage + 1);
    $liNext.append($aNext);
    $ul.append($liNext);
  }

  // 5) FUNCIÓN PRINCIPAL: OBTENER DATOS POR AJAX Y RENDERIZAR
  function loadGestiones(tabToShow = 'impl') {
    const params = {
      action:        'fetch',
      formulario_id: currentFormId,
      local_id:      currentLocalId,
      user_id:       currentUserId,
      page_impl:     pageImpl,
      per_page_impl: perPageImpl,
      page_resp:     pageEnc,
      per_page_resp: perPageEnc
    };

    // Antes de la llamada, vaciamos ambas tablas y mostramos “Cargando…”
    $('#tbl-implementaciones tbody').html(
      '<tr><td colspan="9" class="text-center">Cargando…</td></tr>'
    );
    $('#tbl-respuestas tbody').html(
      '<tr><td colspan="6" class="text-center">Cargando…</td></tr>'
    );

    $.getJSON('/visibility2/portal/modulos/mod_formulario/ajax_obtener_gestiones.php', params)
      .done(function(resp) {
        if (!resp.success) {
          alert(resp.message || 'Error desconocido al obtener datos.');
          return;
        }

        // 5.1) Renderizar el <select> de usuarios
        renderUserSelect(resp.data.users, resp.data.selected_user);
        currentUserId = resp.data.selected_user;

        // 5.2) Armar las categorías de paginación para implementaciones
        const pagImpl = {
          total:     resp.data.pagination.implementaciones.total,
          page:      resp.data.pagination.implementaciones.page,
          per_page:  resp.data.pagination.implementaciones.per_page,
          last_page: resp.data.pagination.implementaciones.last_page
        };
        renderImplementaciones(resp.data.implementaciones, pagImpl);

        // 5.3) Armar las categorías de paginación para respuestas
        const pagEnc = {
          total:     resp.data.pagination.respuestas.total,
          page:      resp.data.pagination.respuestas.page,
          per_page:  resp.data.pagination.respuestas.per_page,
          last_page: resp.data.pagination.respuestas.last_page
        };
        renderRespuestas(resp.data.respuestas, pagEnc);

        // 5.4) Mostrar la pestaña solicitada
        $(`#tabsGestiones a[href="#panel-${tabToShow}"]`).tab('show');
      })
      .fail(function(xhr, status, error) {
        alert('Error en la llamada AJAX: ' + status);
      });
  }

  // 6) LISTENERS DE PAGINACIÓN
  $(document).on('click', '#pagination-impl .page-link', function(e) {
    e.preventDefault();
    const tipo = $(this).data('tipo');   // “impl”
    const pg   = parseInt($(this).data('page'));
    if (tipo === 'impl' && pg >= 1) {
      pageImpl = pg;
      loadGestiones('impl');
    }
  });
  $(document).on('click', '#pagination-enc .page-link', function(e) {
    e.preventDefault();
    const tipo = $(this).data('tipo');  // “enc”
    const pg   = parseInt($(this).data('page'));
    if (tipo === 'enc' && pg >= 1) {
      pageEnc = pg;
      loadGestiones('enc');
    }
  });

  // 7) CAMBIO DE “EJECUTOR” SELECCIONADO
  $(document).on('change', '#select_ejecutor', function() {
    currentUserId = parseInt($(this).val());
    pageImpl = 1;
    pageEnc  = 1;
    loadGestiones('impl');
  });

  // 8) FUNCIÓN AUXILIAR PARA ENVIAR MUTACIONES (POST)
  function postAction(actionName, payload, callback) {
    payload.action        = actionName;
    payload.csrf_token    = CSRF_TOKEN;
    payload.formulario_id = currentFormId;
    payload.local_id      = currentLocalId;
    payload.user_id       = currentUserId;

    $.post(
      '/visibility2/portal/modulos/mod_formulario/ajax_obtener_gestiones.php',
      payload,
      function(resp) {
        if (!resp.success) {
          alert(resp.message || 'Error en la operación.');
          return;
        }
        callback && callback(resp);
      },
      'json'
    ).fail(function() {
      alert('Error en la llamada AJAX.');
    });
  }

  // 8.1) Borrar todas las respuestas de encuesta
  $(document).on('click', '#btn-clear-resps', function() {
    if (!currentUserId) {
      alert('Selecciona primero un ejecutor.');
      return;
    }
    if (!confirm('¿Borrar respuestas del ejecutor?')) return;
    postAction('clear_responses', {}, function() {
      pageEnc = 1;
      loadGestiones('enc');
    });
  });

  // 8.2) Recargar gestión (reseteo completo)
  $(document).on('click', '#btn-reset-local', function() {
    if (!currentUserId) {
      alert('Selecciona primero un ejecutor.');
      return;
    }
    if (!confirm('¿Recargar gestión del ejecutor?')) return;
    postAction('reset_local', {}, function() {
      pageImpl = 1;
      pageEnc  = 1;
      loadGestiones('impl');
    });
  });

  // 8.3) Eliminar implementación individual
  $(document).on('click', '.delete-impl', function() {
    const implId = parseInt($(this).closest('tr').data('id'));
    if (!implId) return;
    if (!confirm('¿Eliminar esta implementación?')) return;
    postAction('delete_impl', { impl_id: implId }, function() {
      loadGestiones('impl');
    });
  });

  // 8.4) Limpiar (resetear) material individual
  $(document).on('click', '.clear-material', function() {
    const implId = parseInt($(this).closest('tr').data('id'));
    if (!implId) return;
    if (!confirm('¿Limpiar este material?')) return;
    postAction('clear_material', { impl_id: implId }, function() {
      loadGestiones('impl');
    });
  });

  // 8.5) Editar inline el nombre del material
  $(document).on('click', '.edit-material', function() {
    const $cell = $(this).closest('tr').find('.material-cell');
    $cell.find('.material-text').hide();
    $cell.find('.material-input, .save-material').show();
  });

  // 8.6) Guardar inline el nuevo nombre de material
  $(document).on('click', '.save-material', function() {
    const $cell = $(this).closest('tr').find('.material-cell');
    const implId = parseInt($(this).closest('tr').data('id'));
    const nuevoMat = $cell.find('.material-input').val().trim();
    if (!nuevoMat) {
      alert('El nombre de material no puede quedar vacío.');
      return;
    }
    postAction('update_material', { impl_id: implId, material: nuevoMat }, function() {
      loadGestiones('impl');
    });
  });

  // 8.7) Eliminar respuesta individual de encuesta
  $(document).on('click', '.delete-resp', function() {
    const respId = parseInt($(this).closest('tr').data('id'));
    if (!respId) return;
    if (!confirm('¿Eliminar esta respuesta?')) return;
    postAction('delete_resp', { resp_id: respId }, function() {
      loadGestiones('enc');
    });
  });

  // 8.8) Lightbox para fotos (clic en miniatura)
  $(document).on('click', '.photo-thumb', function() {
    $('#photoModalImg').attr('src', $(this).attr('src'));
    $('#photoModal').modal('show');
  });

  // 9) INICIALIZACIÓN AL ABRIR EL MODAL
  $('#gestionesModal').on('show.bs.modal', function(e) {
    // El botón que disparó el modal tendrá data-local-id.
    // En tu “.ver-gestiones” original, antes de llamar a modal('show'), se debe setear:
    //   $('#gestionesModal').data('formulario-id', formularioId);
    //   $('#gestionesModal').data('local-id', localId);
    // De modo que lo podamos leer aquí:
    currentFormId  = parseInt($('#gestionesModal').data('formulario-id'));
    currentLocalId = parseInt($('#gestionesModal').data('local-id'));

    // Reiniciar paginaciones y usuario
    pageImpl = 1;
    pageEnc  = 1;
    currentUserId = 0;

    // Cargar los datos (se mostrará la pestaña de “Implementaciones” por defecto)
    loadGestiones('impl');
  });

})(jQuery);
