/**
 * Modal "Cambiar vendedor" de la ruta del ejecutor (index_pruebas.php).
 *
 * Extraido tal cual desde el <script> inline de index_pruebas.php (T6-6); el cuerpo es
 * el mismo IIFE, sin cambios. Ver la nota de modal_reportar_direccion.js sobre por que
 * se pide SIN ?v= (esta precacheado y cacheFirst no ignora el query string).
 *
 * Publica en window: abrirModalCambiarVendedor, cvSeleccionarNuevo, cvEnviarSolicitud
 *   -> todas se llaman desde atributos onclick del HTML.
 * No depende de ningun global de la pagina: solo fetch y el DOM del propio modal.
 */
(function () {
    var cvIdLocal = 0;
    var cvTipo    = 'Prog';
    var cvOpcionesCargadas = false;

    function cvCargarOpciones() {
        var $select = $('#cvSelectVendedor');
        $select.empty();
        $select.append($('<option>').val('0').text('➕ Crear nuevo vendedor'));
        (window.vendedoresDisponibles || []).forEach(function (v) {
            var label = v.nombre + (v.telefono ? ' (' + v.telefono + ')' : '');
            $select.append($('<option>').val(v.id).attr('data-tel', v.telefono || '').attr('data-nombre', v.nombre).text(label));
        });
        cvOpcionesCargadas = true;
    }

    function cvAplicarSeleccion() {
        var val = $('#cvSelectVendedor').val();
        if (val === '0') {
            $('#cvNuevoVendedorBox').show();
            $('#cvNuevoNombre').val('');
        } else {
            $('#cvNuevoVendedorBox').hide();
            var tel = $('#cvSelectVendedor option:selected').attr('data-tel') || '';
            $('#cvTelefono').val(tel);
        }
    }

    window.cvSeleccionarNuevo = function () {
        $('#cvSelectVendedor').val('0');
        cvAplicarSeleccion();
        $('#cvNuevoNombre').focus();
    };

    $(document).on('change', '#cvSelectVendedor', cvAplicarSeleccion);

    window.abrirModalCambiarVendedor = function (payload) {
        if (!cvOpcionesCargadas) cvCargarOpciones();

        cvIdLocal = payload.idLocal;
        cvTipo    = payload.tipo;

        document.getElementById('cvNombreLocal').textContent   = payload.nombreLocal;
        document.getElementById('cvVendedorActual').textContent = payload.vendedorNombre || 'no aplica';
        document.getElementById('cvError').style.display = 'none';

        var $select = $('#cvSelectVendedor');
        if (payload.idVendedor && $select.find('option[value="' + payload.idVendedor + '"]').length) {
            $select.val(String(payload.idVendedor));
        } else {
            $select.val('0');
        }
        cvAplicarSeleccion();
        if ($select.val() !== '0') {
            $('#cvTelefono').val(payload.vendedorTelefono || '');
        }

        $('#modalCambiarVendedor').modal('show');
    };

    window.cvEnviarSolicitud = function () {
        var idVendedorNuevo = $('#cvSelectVendedor').val();
        var esNuevo = idVendedorNuevo === '0';
        var nombreNuevo = esNuevo
            ? document.getElementById('cvNuevoNombre').value.trim()
            : ($('#cvSelectVendedor option:selected').attr('data-nombre') || '');
        var telefonoNuevo = document.getElementById('cvTelefono').value.trim();

        if (esNuevo && !nombreNuevo) {
            document.getElementById('cvError').textContent = 'Ingresa el nombre del nuevo vendedor.';
            document.getElementById('cvError').style.display = 'block';
            return;
        }

        if (esNuevo) {
            var nombreNormalizado = nombreNuevo.trim().toLowerCase();
            var existente = (window.vendedoresDisponibles || []).find(function (v) {
                return String(v.nombre || '').trim().toLowerCase() === nombreNormalizado;
            });
            if (existente) {
                document.getElementById('cvError').textContent = 'Ya existe un vendedor con ese nombre: "' + existente.nombre + '". Selecciónalo de la lista en vez de crear uno nuevo.';
                document.getElementById('cvError').style.display = 'block';
                return;
            }
        }

        document.getElementById('cvBtnEnviar').disabled = true;
        document.getElementById('cvBtnEnviar').innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enviando...';
        document.getElementById('cvError').style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'crear');
        fd.append('id_local', cvIdLocal);
        fd.append('id_vendedor_nuevo', esNuevo ? 0 : idVendedorNuevo);
        fd.append('nombre_vendedor_nuevo', nombreNuevo);
        fd.append('telefono_nuevo', telefonoNuevo);

        fetch('/visibility2/app/api/solicitud_cambio_vendedor.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (!resp.ok) {
                    document.getElementById('cvError').textContent = resp.msg || 'Error al enviar.';
                    document.getElementById('cvError').style.display = 'block';
                    return;
                }

                $('#modalCambiarVendedor').modal('hide');

                var $h4 = $('#myModalLabel' + cvTipo + cvIdLocal);
                $h4.find('.cv-vendedor-texto').text(nombreNuevo);
                if (!$h4.find('.badge-vendedor-pendiente').length) {
                    $h4.find('.cv-vendedor-texto').after(
                        '<span class="badge-vendedor-pendiente" style="display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap">🧑 En revisión</span>'
                    );
                }

                var msg = 'Solicitud enviada. El vendedor será actualizado cuando se revise.';
                if (resp.sospechoso) {
                    msg += ' (marcada para revisión prioritaria)';
                }
                if (typeof mostrarToast === 'function') {
                    mostrarToast(msg, 'success');
                } else {
                    alert(msg);
                }
            })
            .catch(function () {
                document.getElementById('cvError').textContent = 'Error al enviar. Intenta nuevamente.';
                document.getElementById('cvError').style.display = 'block';
            })
            .finally(function () {
                document.getElementById('cvBtnEnviar').disabled = false;
                document.getElementById('cvBtnEnviar').innerHTML = '<i class="fa fa-paper-plane"></i> Enviar solicitud';
            });
    };
})();
