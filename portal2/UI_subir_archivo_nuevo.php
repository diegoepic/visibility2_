<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    echo "<h3>No autorizado</h3>";
    exit;
}

include $_SERVER['DOCUMENT_ROOT'] . "/visibility2/portal/modulos/db.php";

// --- Obtener archivos desde BD ---
$sqlArchivos = "
    SELECT 
        ra.id,
        ra.nombre_archivo,
        ra.carpeta,
        ra.ruta_url,
        ra.estado,
        ra.fecha_creacion,
        concat(u.nombre,' ',u.apellido) as nombre
    FROM repo_archivo ra
    INNER JOIN usuario u ON u.id = ra.id_usuario
    ORDER BY ra.fecha_creacion DESC
";
$resArchivos = $conn->query($sqlArchivos);

// --- Obtener carpetas desde BD ---
$sqlCarpetas = "
    SELECT id, nombre, fecha_creacion
    FROM repo_carpeta
    ORDER BY nombre ASC
";
$resCarpetas = $conn->query($sqlCarpetas);

$division_id = isset($_SESSION['division_id']) ? (int)$_SESSION['division_id'] : 0;
$division    = isset($_GET['division']) ? (int)$_GET['division'] : $division_id;

$division_nombre = '';
if ($division_id > 0) {
    $stmt = $conn->prepare("SELECT nombre FROM division_empresa WHERE id = ?");
    $stmt->bind_param("i", $division_id);
    $stmt->execute();
    $stmt->bind_result($division_nombre);
    $stmt->fetch();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>📦 Repositorio IPT</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        #progressBar{
    transition: width .35s ease;
}
    </style>
</head>

<body class="bg-light">

<div class="container mt-4">

    <!-- NAV TABS -->
    <ul class="nav nav-tabs" id="tabRepo" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-toggle="tab" href="#tabSubir" role="tab">
                📤 Subir archivo
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link" data-toggle="tab" href="#tabArchivos" role="tab">
                📦 Archivos cargados
            </a>
        </li>

        <li class="nav-item">
            <a class="nav-link" data-toggle="tab" href="#tabCarpetas" role="tab">
                📁 Carpetas
            </a>
        </li>
    </ul>

    <div class="tab-content p-3 border border-top-0">

        <!-- TAB SUBIR ARCHIVO -->
        <div class="tab-pane fade show active" id="tabSubir" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Cargar IPT (Ruta Planificada)</h4>
                </div>

                <div class="card-body">
                    <form id="formSubirArchivo" enctype="multipart/form-data">
                        
                        <!-- SELECT CARPETA -->
                        <div class="form-group">
                            <label><strong>Carpeta destino:</strong></label>
                            <select class="form-control" name="carpeta" id="selectCarpeta" required>
                                <option value="">-- Seleccione carpeta --</option>
                                <?php while($c = $resCarpetas->fetch_assoc()): ?>
                                    <option value="<?= $c['nombre'] ?>">
                                        <?= $c['nombre'] ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><strong>Archivo (.csv o .xlsx):</strong></label>
                            <input type="file" name="mi_archivo" accept=".csv,.xlsx" class="form-control" required>
                            <small class="text-muted">
                                El archivo debe contener columnas obligatorias: 
                                FECHA ASIGNADA, TIPO DE ZONA, ZONA, CODIGO, 
                                RAZON SOCIAL, DIRECCION, COMUNA.
                            </small>
                        </div>

                        <button class="btn btn-success" id="btnSubir">
                            📤 Subir Archivo
                        </button>
                    </form>
                    <div class="progress mt-3" style="height: 22px; display:none;">
                      <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                           role="progressbar" style="width:0%">0%</div>
                    </div>                    
                </div>
            </div>
        </div>

        <!-- TAB ARCHIVOS -->
        <div class="tab-pane fade" id="tabArchivos" role="tabpanel">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <h4 class="mb-0">Historial de cargas</h4>
                </div>
                <div class="card-body p-0">

                    <table class="table table-striped table-bordered mb-0 text-center">
                        <thead class="thead-dark">
                            <tr>
                                <th>Nombre</th>
                                <th>Carpeta</th>
                                <th>URL</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Gestion</th>
                            </tr>
                        </thead>
                        <tbody>

                        <?php while($row = $resArchivos->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['nombre_archivo']) ?></td>
                                <td><?= htmlspecialchars($row['carpeta']) ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($row['ruta_url']) ?>"
                                       target="_blank"
                                       class="btn btn-outline-primary btn-sm">
                                        Descargar
                                    </a>
                                </td>
                                <td>
                                    <?php
                                        switch($row['estado']){
                                            case 0: echo "<span class='badge badge-warning'>Pendiente</span>"; break;
                                            case 1: echo "<span class='badge badge-success'>Cargado</span>"; break;
                                            case 2: echo "<span class='badge badge-danger'>Rechazado</span>"; break;
                                        }
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($row['fecha_creacion']) ?></td>
                                <td><?= htmlspecialchars($row['nombre']) ?></td>
                                <td>
                                <?php if (strtoupper(trim($division_nombre)) == 'MC'): ?>
                                    <div class="input-group">
                                        <select class="form-control estado-select" id="estado-<?= $row['id'] ?>">
                                            <option value="0" <?= $row['estado']==0?'selected':''?>>Pendiente</option>
                                            <option value="1" <?= $row['estado']==1?'selected':''?>>Cargado</option>
                                            <option value="2" <?= $row['estado']==2?'selected':''?>>Rechazado</option>
                                        </select>
                                        <div class="input-group-append">
                                            <button class="btn btn-info btn-sm btn-actualizar"
                                                    data-id="<?= $row['id'] ?>">
                                                Actualizar
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?= $row['estado'] == 0 ? "<span class='badge badge-warning'>Pendiente</span>" :
                                       ($row['estado'] == 1 ? "<span class='badge badge-success'>Cargado</span>" :
                                       ($row['estado'] == 2 ? "<span class='badge badge-danger'>Rechazado</span>" :
                                       "<span class='badge badge-secondary'>Eliminado</span>")) ?>
                                <?php endif ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>

                        </tbody>
                    </table>

                </div>
            </div>
        </div>

        <!-- TAB CARPETAS -->
        <div class="tab-pane fade" id="tabCarpetas" role="tabpanel">

            <div class="row">

                <div class="col-md-4">
                    <form id="formCrearCarpeta">
                        <label><strong>Crear nueva carpeta:</strong></label>
                        <input class="form-control mb-2" 
                               name="nombre_carpeta" 
                               placeholder="Nombre carpeta..." required>
                        <button class="btn btn-primary btn-block">Crear carpeta</button>
                    </form>
                </div>

                <div class="col-md-8">
                    <table class="table table-bordered text-center">
                        <thead class="thead-light">
                            <tr>
                                <th>Carpeta</th>
                                <th>Fecha creación</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            $resCarpList = $conn->query("
                                SELECT nombre, fecha_creacion
                                FROM repo_carpeta
                                ORDER BY fecha_creacion DESC
                            ");
                            while($c = $resCarpList->fetch_assoc()):
                        ?>
                            <tr>
                                <td><?= $c['nombre']?></td>
                                <td><?= $c['fecha_creacion']?></td>
                            </tr>
                        <?php endwhile;?>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>

    </div>
</div>


<!-- JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>

<script>
/* ===========================================
    SUBIR ARCHIVO - MODO SEGURO JSON
=========================================== */

document.getElementById('formSubirArchivo').addEventListener('submit', function(e){
    e.preventDefault();

    const fd = new FormData(this);
    const btn = document.getElementById('btnSubir');
    const barra = document.getElementById('progressBar');
    const contenedor = barra.parentElement;

    btn.disabled = true;
    btn.innerText = "Subiendo...";

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'modulos/mod_repositorio/upload_archivo.php', true);

    // 1️⃣ Mostrar barra apenas inicia
    xhr.upload.onloadstart = function(){
        contenedor.style.display = "block";
        barra.style.width = "10%";
        barra.innerText = "10%";
    };

    // 2️⃣ Progreso real
    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const porcentaje = Math.round((e.loaded / e.total) * 100);
            barra.style.width = porcentaje + "%";
            barra.innerText = porcentaje + "%";
        }
    };

    // 3️⃣ Fin de subida
    xhr.onload = function(){
        // Empuja la barra a 100% visualmente
        barra.style.width = "100%";
        barra.innerText = "100%";

        // Pequeño delay visual
        setTimeout(()=>{
            resetBarra();
            btn.disabled = false;
            btn.innerText = "Subir Archivo";
        }, 300);

        try{
            const data = JSON.parse(xhr.responseText);
            if(data.error){
                alert("⚠ " + data.error);
                return;
            }

            alert("✔ Archivo subido correctamente");
            location.reload();
        }catch(err){
            alert("⚠ Respuesta inválida");
            console.log(xhr.responseText);
        }
    };

    xhr.onerror = function(){
        resetBarra();
        btn.disabled = false;
        btn.innerText = "Subir Archivo";
        alert("Error de conexión");
    };

    xhr.send(fd);
});

function resetBarra(){
    const barra = document.getElementById('progressBar');
    const contenedor = barra.parentElement;

    barra.style.width = "0%";
    barra.innerText = "0%";
    contenedor.style.display = "none";
}


/* ===========================================
    CREAR CARPETA - MODO SEGURO JSON
=========================================== */
document.getElementById('formCrearCarpeta').addEventListener('submit', function(e){
    e.preventDefault();

    const fd = new FormData(this);

    fetch('modulos/mod_repositorio/crear_carpeta.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.text())
    .then(txt => {

        console.log("📂 Respuesta RAW:", txt);

        let data;
        try{
            data = JSON.parse(txt);
        } catch(e){
            alert("⚠ El servidor no devolvió JSON válido.\nRevisar consola.");
            console.error(txt);
            throw new Error("Respuesta no JSON");
        }

        if(data.error){
            alert("⚠ " + data.error);
            console.error("DEBUG:", data.debug ?? "");
            return;
        }

        alert("✔ Carpeta creada: " + data.carpeta);
        location.reload();
    })
    .catch(err=>{
        alert("Error de red o servidor:\n" + err);
        console.error(err);
    });
});


/* ===================================================
    BOTÓN ACTUALIZAR ESTADO
=================================================== */
$('.btn-actualizar').click(function(){

    const id = $(this).data('id');
    const estado = $(this).closest('tr').find('.estado-select').val();

    if(!confirm("¿Seguro que deseas actualizar el estado del archivo?")) return;

    fetch('modulos/mod_repositorio/update_estado.php', {
        method: 'POST',
        body: new URLSearchParams({ id, estado })
    })
    .then(r=>r.text())
    .then(txt=>{
        console.log("RAW:", txt);
        let data;
        try{ data = JSON.parse(txt); }
        catch(e){
            alert("⚠ Respuesta inválida del servidor");
            console.log(txt);
            return;
        }

        if(data.error){
            alert("⚠ " + data.error);
            return;
        }

        alert("✔ Estado actualizado correctamente");
        location.reload();
    })
    .catch(err=>{
        alert("Error de red/servidor");
        console.error(err);
    });
});


</script>


</body>
</html>