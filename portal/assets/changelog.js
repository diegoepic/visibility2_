$(document).ready(function () {
    cargarTiposGestion();
    cargarUsuariosEditores();

    function showOverlay() {
        $("#changelogOverlay").fadeIn(150);
    }

    function hideOverlay() {
        $("#changelogOverlay").fadeOut(150);
    }

    function cargarUsuariosEditores() {
        $.ajax({
            url: "/visibility2/portal/modulos/mod_changelog/obtener_usuarios_editores.php",
            type: "GET",
            dataType: "json",
            success: function (resp) {
                const $select = $("#usuario_registro");
                $select.empty();

                if (resp.ok && resp.items.length > 0) {
                    $select.append('<option value="">Selecciona un responsable</option>');

                    resp.items.forEach(function (item) {
                        $select.append(
                            `<option value="${item.id}">${item.nombre}</option>`
                        );
                    });
                } else {
                    $select.append('<option value="">No hay usuarios editores</option>');
                }
            },
            error: function () {
                $("#usuario_registro").html('<option value="">Error al cargar usuarios</option>');
            }
        });
    }

    function cargarTiposGestion() {
        $.ajax({
            url: "/visibility2/portal/modulos/mod_changelog/obtener_tipos.php",
            type: "GET",
            dataType: "json",
            success: function (resp) {
                const $select = $("#id_tipo_gestion");
                $select.empty();

                if (resp.ok && resp.items.length > 0) {
                    $select.append('<option value="">Selecciona un tipo</option>');
                    resp.items.forEach(function (item) {
                        $select.append(
                            `<option value="${item.id}">${item.nombre}</option>`
                        );
                    });
                } else {
                    $select.append('<option value="">No hay tipos disponibles</option>');
                }
            },
            error: function () {
                $("#id_tipo_gestion").html('<option value="">Error al cargar tipos</option>');
            }
        });
    }

    $("#formChangelog").on("submit", function (e) {
        e.preventDefault();

        showOverlay();

        $.ajax({
            url: "/visibility2/portal/modulos/mod_changelog/guardar_changelog.php",
            type: "POST",
            data: $(this).serialize(),
            dataType: "json",
            success: function (resp) {
                hideOverlay();

                if (resp.ok) {
                    $("#changelogFormMsg").html(
                        `<div class="alert alert-success">${resp.message}</div>`
                    );

                    $("#formChangelog")[0].reset();

                    recargarTimelineInicial();

                    $("#btnCargarMas").data("offset", 3).show();
                } else {
                    $("#changelogFormMsg").html(
                        `<div class="alert alert-danger">${resp.message}</div>`
                    );
                }
            },
            error: function () {
                hideOverlay();
                $("#changelogFormMsg").html(
                    `<div class="alert alert-danger">Ocurrió un error al procesar la solicitud.</div>`
                );
            }
        });
    });

    function recargarTimelineInicial() {
        $.ajax({
            url: "/visibility2/portal/modulos/mod_changelog/listar_changelog.php",
            type: "GET",
            success: function (html) {
                $("#timelineContainer").html(html);
            }
        });
    }

    $("#btnCargarMas").on("click", function () {
        const $btn = $(this);
        let offset = parseInt($btn.data("offset"), 10) || 3;

        showOverlay();

        $.ajax({
            url: "/visibility2/portal/modulos/mod_changelog/cargar_mas.php",
            type: "POST",
            data: { offset: offset },
            success: function (html) {
                hideOverlay();

                const cleanHtml = $.trim(html);

                if (cleanHtml === "") {
                    $btn.hide();
                    return;
                }

                $("#timelineContainer").append(cleanHtml);
                $btn.data("offset", offset + 6);
            },
            error: function () {
                hideOverlay();
                alert("No se pudieron cargar más registros.");
            }
        });
    });
});