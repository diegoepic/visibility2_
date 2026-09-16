/**
 * Modal "Reportar direccion incorrecta" de la ruta del ejecutor (index_pruebas.php).
 *
 * Extraido tal cual desde el <script> inline de index_pruebas.php (T6-6). El cuerpo no
 * se toco: mismo IIFE, mismas variables, mismo orden. Lo unico que cambia es donde vive.
 *
 * Va PRECACHEADO en sw.js y por eso se pide SIN ?v= (cacheFirst hace match sin
 * ignoreSearch: con query string no encontraria la entrada precacheada y sin red los
 * botones del modal quedarian muertos). El cache-busting lo da el nombre del cache.
 *
 * Publica en window: abrirModalReportarDir, rdGeocodeDir, rdEnviarSolicitud
 *   -> los invocan atributos onclick del HTML, o sea al hacer clic, nunca al parsear.
 * Lee de window (siempre con guarda): markersProg, markersReag, planRouteFromSelection,
 *   isMapVisible, google. Todas se usan dentro de callbacks, no en tiempo de carga.
 */
(function () {
    var rdIdLocal     = 0;
    var rdLatActual   = 0;
    var rdLngActual   = 0;
    var rdMap         = null;
    var rdMarker      = null;
    var rdGeocoder    = null;

    function rdHaversine(lat1, lng1, lat2, lng2) {
        var R = 6371;
        var dLat = (lat2 - lat1) * Math.PI / 180;
        var dLng = (lng2 - lng1) * Math.PI / 180;
        var a = Math.sin(dLat/2)*Math.sin(dLat/2) +
                Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
                Math.sin(dLng/2)*Math.sin(dLng/2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
    }

    function rdActualizarDistancia() {
        var lat = parseFloat(document.getElementById('rdLat').value);
        var lng = parseFloat(document.getElementById('rdLng').value);
        if (!lat || !lng) return;
        var km = rdHaversine(rdLatActual, rdLngActual, lat, lng);
        document.getElementById('rdDistancia').textContent =
            'Distancia desde dirección actual: ' + km.toFixed(2) + ' km' +
            (km > 5 ? '  ⚠️ (será marcada como sospechosa)' : '');
    }

    function rdInitMap() {
        loadGoogleMapsSdk().then(function (maps) {
            var center = { lat: rdLatActual, lng: rdLngActual };
            rdMap = new maps.Map(document.getElementById('rdMiniMap'), {
                center: center,
                zoom: 16,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: false,
            });
            rdMarker = new maps.Marker({ position: center, map: rdMap, draggable: true, title: 'Arrastrar para ajustar' });
            rdGeocoder = new maps.Geocoder();

            rdMarker.addListener('dragend', function (ev) {
                document.getElementById('rdLat').value = ev.latLng.lat().toFixed(7);
                document.getElementById('rdLng').value = ev.latLng.lng().toFixed(7);
                rdActualizarDistancia();
            });
        }).catch(function () {
            document.getElementById('rdMiniMap').innerHTML = '<p class="text-danger text-center" style="padding:20px">No se pudo cargar el mapa.</p>';
        });
    }

    window.rdGeocodeDir = function () {
        var dir = document.getElementById('rdDirNueva').value.trim();
        if (!dir || !rdGeocoder) return;
        rdGeocoder.geocode({ address: dir + ', Chile' }, function (results, status) {
            if (status === 'OK' && results[0]) {
                var loc = results[0].geometry.location;
                var lat = loc.lat(), lng = loc.lng();
                document.getElementById('rdLat').value = lat.toFixed(7);
                document.getElementById('rdLng').value = lng.toFixed(7);
                rdMap.setCenter({ lat: lat, lng: lng });
                rdMarker.setPosition({ lat: lat, lng: lng });
                rdActualizarDistancia();
            } else {
                document.getElementById('rdError').textContent = 'No se encontró la dirección. Intenta ser más específico.';
                document.getElementById('rdError').style.display = 'block';
                setTimeout(function(){ document.getElementById('rdError').style.display='none'; }, 3000);
            }
        });
    };

    window.abrirModalReportarDir = function (idLocal, nombre, dirActual, lat, lng) {
        rdIdLocal   = idLocal;
        rdLatActual = lat;
        rdLngActual = lng;

        document.getElementById('rdNombreLocal').textContent = nombre;
        document.getElementById('rdDirActual').textContent   = dirActual;
        document.getElementById('rdDirNueva').value          = '';
        document.getElementById('rdLat').value               = lat;
        document.getElementById('rdLng').value               = lng;
        document.getElementById('rdDistancia').textContent   = '';
        document.getElementById('rdError').style.display     = 'none';

        $('#modalReportarDir').modal('show');

        // Inicializar el mapa cuando el modal esté visible
        $('#modalReportarDir').one('shown.bs.modal', function () {
            if (!rdMap) {
                rdInitMap();
            } else {
                rdMap.setCenter({ lat: lat, lng: lng });
                rdMarker.setPosition({ lat: lat, lng: lng });
                google.maps.event.trigger(rdMap, 'resize');
            }
        });
    };

    window.rdEnviarSolicitud = function () {
        var dirNueva = document.getElementById('rdDirNueva').value.trim();
        var lat      = parseFloat(document.getElementById('rdLat').value);
        var lng      = parseFloat(document.getElementById('rdLng').value);

        if (!dirNueva) {
            document.getElementById('rdError').textContent = 'Ingresa la nueva dirección.';
            document.getElementById('rdError').style.display = 'block';
            return;
        }
        if (!lat || !lng) {
            document.getElementById('rdError').textContent = 'Ajusta la ubicación en el mapa.';
            document.getElementById('rdError').style.display = 'block';
            return;
        }

        document.getElementById('rdBtnEnviar').disabled = true;
        document.getElementById('rdBtnEnviar').innerHTML = '<i class="fa fa-spinner fa-spin"></i> Enviando...';
        document.getElementById('rdError').style.display = 'none';

        var fd = new FormData();
        fd.append('action',   'crear');
        fd.append('id_local', rdIdLocal);
        fd.append('dir_nueva', dirNueva);
        fd.append('lat_nueva', lat);
        fd.append('lng_nueva', lng);

        fetch('/visibility2/app/api/solicitud_cambio_local.php', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(resp) {
                var newLat = parseFloat(document.getElementById('rdLat').value);
                var newLng = parseFloat(document.getElementById('rdLng').value);
                var newDir = document.getElementById('rdDirNueva').value.trim();

                $('#modalReportarDir').modal('hide');

                // Mover el pin en el mapa inmediatamente
                var markerEntry = (window.markersProg && window.markersProg[rdIdLocal])
                               || (window.markersReag && window.markersReag[rdIdLocal]);
                if (markerEntry && window.google && window.google.maps) {
                    markerEntry.marker.setPosition({ lat: newLat, lng: newLng });
                    // Actualizar la posición de referencia para numeración y recálculo
                    if (markerEntry.originalIcon) {
                        markerEntry.marker.setIcon(markerEntry.originalIcon);
                    }
                }

                // Actualizar data-lat/data-lng en las filas de tabla y mostrar badge
                $('tr[data-idlocal="' + rdIdLocal + '"]').each(function() {
                    $(this).attr('data-lat', newLat).attr('data-lng', newLng);
                    // Añadir badge "En revisión" en celda de dirección si no existe
                    var $dirCell = $(this).find('td').filter(function() {
                        return $(this).text().trim().length > 0 && !$(this).find('input,button,a,.circulo').length;
                    }).first();
                    if ($dirCell.length && !$dirCell.find('.badge-geo-pendiente').length) {
                        $dirCell.append('<span class="badge-geo-pendiente" style="display:inline-block;background:#f59e0b;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap" title="Solicitud de cambio pendiente de revisión">📍 En revisión</span>');
                    }
                });

                // Recalcular ruta con la nueva posición
                var pos = window.ejecutorMarker && window.ejecutorMarker.getPosition();
                if (pos && typeof window.planRouteFromSelection === 'function' && window.isMapVisible !== false) {
                    window.planRouteFromSelection(pos.toJSON(), { trigger: 'geo_report', force: true });
                }

                var msg = 'Solicitud enviada. Tu ruta usará esta dirección hasta que sea revisada.';
                if (resp.sospechoso) {
                    msg += ' (marcada para revisión prioritaria)';
                }
                if (typeof mostrarToast === 'function') {
                    mostrarToast(msg, 'success');
                } else {
                    alert(msg);
                }
            })
            .catch(function() {
                document.getElementById('rdError').textContent = 'Error al enviar. Intenta nuevamente.';
                document.getElementById('rdError').style.display = 'block';
            })
            .finally(function() {
                document.getElementById('rdBtnEnviar').disabled = false;
                document.getElementById('rdBtnEnviar').innerHTML = '<i class="fa fa-paper-plane"></i> Enviar solicitud';
            });
    };

    // Tecla Enter en el campo de dirección dispara la búsqueda
    document.getElementById('rdDirNueva').addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); rdGeocodeDir(); }
    });
})();
