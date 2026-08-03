<?php
declare(strict_types=1);

$baseDir = __DIR__ . '/repositorio';
$urlBase = 'https://visibility.cl/visibility2/portal/repositorio';

if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0755, true);
}

function safeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = min((int)floor(log($bytes, 1024)), count($units) - 1);

    return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
}

function collectFiles(string $baseDir, string $urlBase): array
{
    if (!is_dir($baseDir)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $relativePath = str_replace('\\', '/', substr($fullPath, strlen($baseDir) + 1));
        $pathParts = explode('/', $relativePath);
        $folder = count($pathParts) > 1 ? implode('/', array_slice($pathParts, 0, -1)) : 'Raiz';
        $encodedPath = implode('/', array_map('rawurlencode', $pathParts));
        $modifiedAt = $fileInfo->getMTime();
        $createdAt = $fileInfo->getCTime();

        $files[] = [
            'name' => $fileInfo->getFilename(),
            'folder' => $folder,
            'relative_path' => $relativePath,
            'url' => rtrim($urlBase, '/') . '/' . $encodedPath,
            'size' => $fileInfo->getSize(),
            'size_label' => formatBytes($fileInfo->getSize()),
            'created_at' => $createdAt,
            'modified_at' => $modifiedAt,
            'created_label' => date('d-m-Y H:i', $createdAt),
            'modified_label' => date('d-m-Y H:i', $modifiedAt),
            'extension' => strtolower($fileInfo->getExtension()),
        ];
    }

    usort($files, static fn(array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

    return $files;
}

$folders = [];
if (is_dir($baseDir)) {
    foreach (scandir($baseDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        if (is_dir($baseDir . DIRECTORY_SEPARATOR . $item)) {
            $folders[] = $item;
        }
    }
}
natcasesort($folders);
$folders = array_values($folders);

$files = collectFiles($baseDir, $urlBase);
$filterFolders = array_values(array_unique(array_column($files, 'folder')));
natcasesort($filterFolders);
$filterFolders = array_values($filterFolders);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Repositorio de archivos</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
  <style>
    :root {
      --navy: #10223f;
      --navy-soft: #19365f;
      --blue: #1677ff;
      --cyan: #0ea5c6;
      --green: #14845f;
      --page: #f3f6fa;
      --line: #dce4ee;
      --muted: #66758a;
      --danger: #c2414b;
    }

    body {
      min-height: 100vh;
      background: var(--page);
      color: #17243a;
      font-family: Inter, "Segoe UI", Arial, sans-serif;
    }

    .page-header {
      background: var(--navy);
      color: #fff;
      padding: 26px 0 64px;
    }

    .page-title {
      display: flex;
      align-items: center;
      gap: 12px;
      margin: 0;
      font-size: 1.65rem;
      font-weight: 800;
      letter-spacing: 0;
    }

    .page-subtitle {
      margin: 7px 0 0;
      color: #bfd0e8;
    }

    .content-wrap {
      margin-top: -38px;
      padding-bottom: 40px;
    }

    .panel {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 8px;
      box-shadow: 0 12px 34px rgba(16, 34, 63, .08);
      margin-bottom: 22px;
      overflow: hidden;
    }

    .panel-head {
      min-height: 58px;
      padding: 16px 20px;
      border-bottom: 1px solid var(--line);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .panel-title {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 0;
      color: var(--navy);
      font-size: 1.05rem;
      font-weight: 800;
    }

    .panel-body {
      padding: 20px;
    }

    .form-label {
      color: #2c405e;
      font-size: .84rem;
      font-weight: 800;
    }

    .form-control,
    .custom-file-label {
      min-height: 43px;
      border-color: #cfd9e6;
      border-radius: 6px;
      box-shadow: none !important;
    }

    .form-control:focus {
      border-color: var(--blue);
    }

    .replace-box {
      padding: 12px 14px;
      background: #f7faff;
      border: 1px solid #d9e7f7;
      border-radius: 6px;
      color: #405674;
    }

    .btn-upload {
      min-height: 44px;
      border: 0;
      border-radius: 6px;
      background: var(--blue);
      font-weight: 800;
      padding: 0 22px;
    }

    .btn-upload:hover {
      background: #0867df;
    }

    .toolbar {
      display: grid;
      grid-template-columns: minmax(230px, 1fr) minmax(190px, 260px) minmax(190px, 240px);
      gap: 12px;
      padding: 16px 20px;
      background: #f9fbfd;
      border-bottom: 1px solid var(--line);
    }

    .input-icon {
      position: relative;
    }

    .input-icon i {
      position: absolute;
      left: 14px;
      top: 14px;
      color: #7a8aa1;
    }

    .input-icon .form-control {
      padding-left: 39px;
    }

    .file-table {
      margin: 0;
    }

    .file-table thead th {
      border-top: 0;
      border-bottom: 1px solid var(--line);
      background: #fff;
      color: #53647a;
      font-size: .76rem;
      letter-spacing: .04em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .file-table tbody td {
      vertical-align: middle;
      border-color: #edf1f6;
      padding-top: 13px;
      padding-bottom: 13px;
    }

    .file-name {
      color: #1d3150;
      font-weight: 800;
      overflow-wrap: anywhere;
    }

    .file-path {
      color: var(--muted);
      font-size: .78rem;
      margin-top: 3px;
    }

    .folder-pill,
    .type-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 9px;
      border-radius: 999px;
      background: #eaf3ff;
      color: #285a98;
      font-size: .76rem;
      font-weight: 800;
    }

    .type-pill {
      background: #e9f8f3;
      color: var(--green);
      text-transform: uppercase;
    }

    .date-primary {
      color: #253955;
      font-weight: 700;
      white-space: nowrap;
    }

    .date-secondary {
      color: var(--muted);
      font-size: .75rem;
      white-space: nowrap;
    }

    .open-btn {
      width: 36px;
      height: 36px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #cdd9e7;
      border-radius: 6px;
      color: var(--blue);
      background: #fff;
    }

    .empty-state {
      display: none;
      padding: 48px 20px;
      color: var(--muted);
      text-align: center;
    }

    .upload-progress {
      display: none;
      margin-top: 16px;
    }

    .upload-progress.is-visible {
      display: block;
    }

    .result-count {
      color: var(--muted);
      font-size: .88rem;
      font-weight: 700;
    }

    @media (max-width: 860px) {
      .toolbar {
        grid-template-columns: 1fr;
      }

      .page-title {
        font-size: 1.35rem;
      }
    }
  </style>
</head>
<body>
<header class="page-header">
  <div class="container-fluid px-4 px-lg-5">
    <h1 class="page-title"><i class="fas fa-folder-open"></i> Repositorio de archivos</h1>
    <p class="page-subtitle">Carga documentos, reemplaza versiones existentes y encuentra archivos rápidamente.</p>
  </div>
</header>

<main class="container-fluid px-4 px-lg-5 content-wrap">
  <section class="panel">
    <div class="panel-head">
      <h2 class="panel-title"><i class="fas fa-cloud-upload-alt text-primary"></i> Subir archivo</h2>
    </div>
    <div class="panel-body">
      <form id="formSubirArchivo" method="POST" enctype="multipart/form-data">
        <div class="form-row">
          <div class="form-group col-lg-4">
            <label class="form-label" for="carpetaExistente">Carpeta existente</label>
            <select class="form-control" id="carpetaExistente" name="carpeta_existente">
              <option value="">Seleccionar carpeta</option>
              <?php foreach ($folders as $folder): ?>
                <option value="<?= safeHtml($folder) ?>"><?= safeHtml($folder) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group col-lg-4">
            <label class="form-label" for="carpetaNueva">O crear una carpeta nueva</label>
            <input type="text" class="form-control" id="carpetaNueva" name="carpeta_nueva" placeholder="Ejemplo: reportes_2026">
          </div>

          <div class="form-group col-lg-4">
            <label class="form-label" for="archivoInput">Archivo</label>
            <input
              type="file"
              class="form-control"
              id="archivoInput"
              name="mi_archivo"
              accept=".ppt,.pptx,.csv,.xls,.xlsx,.pdf,.zip,.rar,.7z,.tar,.gz,.tgz,.bz2,.xz"
              required
            >
            <small class="form-text text-muted">
              Documentos y archivos comprimidos: ZIP, RAR, 7Z, TAR, GZ, TGZ, BZ2 y XZ.
            </small>
          </div>
        </div>

        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
          <div class="replace-box mb-3 mb-md-0">
            <div class="custom-control custom-checkbox">
              <input type="checkbox" class="custom-control-input" id="reemplazarExistente" name="reemplazar_existente" value="1" checked>
              <label class="custom-control-label" for="reemplazarExistente">
                Reemplazar automáticamente si ya existe un archivo con el mismo nombre
              </label>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-upload" id="btnSubir">
            <i class="fas fa-upload mr-2"></i> Subir archivo
          </button>
        </div>

        <div class="upload-progress" id="uploadProgress">
          <div class="progress" style="height:8px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%"></div>
          </div>
          <div class="small text-muted mt-2" id="uploadStatus">Subiendo y validando el archivo...</div>
        </div>
      </form>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head">
      <h2 class="panel-title"><i class="fas fa-file-alt text-info"></i> Archivos disponibles</h2>
      <span class="result-count"><span id="visibleCount"><?= count($files) ?></span> de <?= count($files) ?> archivos</span>
    </div>

    <div class="toolbar">
      <div class="input-icon">
        <i class="fas fa-search"></i>
        <input type="search" class="form-control" id="searchInput" placeholder="Buscar por nombre o ruta...">
      </div>

      <select class="form-control" id="folderFilter">
        <option value="">Todas las carpetas</option>
        <?php foreach ($filterFolders as $folder): ?>
          <option value="<?= safeHtml(strtolower($folder)) ?>"><?= safeHtml($folder) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="form-control" id="sortSelect">
        <option value="modified-desc">Modificación: más reciente</option>
        <option value="modified-asc">Modificación: más antigua</option>
        <option value="created-desc">Creación: más reciente</option>
        <option value="created-asc">Creación: más antigua</option>
        <option value="name-asc">Nombre: A-Z</option>
        <option value="name-desc">Nombre: Z-A</option>
      </select>
    </div>

    <div class="table-responsive">
      <table class="table file-table" id="fileTable">
        <thead>
          <tr>
            <th>Archivo</th>
            <th>Carpeta</th>
            <th>Tipo</th>
            <th>Tamaño</th>
            <th>Fechas</th>
            <th class="text-center">Abrir</th>
          </tr>
        </thead>
        <tbody id="fileRows">
          <?php foreach ($files as $file): ?>
            <tr
              data-name="<?= safeHtml(strtolower($file['name'])) ?>"
              data-folder="<?= safeHtml(strtolower($file['folder'])) ?>"
              data-path="<?= safeHtml(strtolower($file['relative_path'])) ?>"
              data-created="<?= (int)$file['created_at'] ?>"
              data-modified="<?= (int)$file['modified_at'] ?>"
            >
              <td>
                <div class="file-name"><?= safeHtml($file['name']) ?></div>
                <div class="file-path"><?= safeHtml($file['relative_path']) ?></div>
              </td>
              <td><span class="folder-pill"><i class="fas fa-folder"></i><?= safeHtml($file['folder']) ?></span></td>
              <td><span class="type-pill"><?= safeHtml($file['extension'] ?: 'archivo') ?></span></td>
              <td><?= safeHtml($file['size_label']) ?></td>
              <td>
                <div class="date-primary">Modificado: <?= safeHtml($file['modified_label']) ?></div>
                <div class="date-secondary">Creado: <?= safeHtml($file['created_label']) ?></div>
              </td>
              <td class="text-center">
                <a class="open-btn" href="<?= safeHtml($file['url']) ?>" target="_blank" rel="noopener" title="Abrir archivo">
                  <i class="fas fa-external-link-alt"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="empty-state" id="emptyState">
        <i class="fas fa-search fa-2x mb-3"></i>
        <div>No hay archivos que coincidan con los filtros.</div>
      </div>
    </div>
  </section>
</main>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('formSubirArchivo');
  const uploadButton = document.getElementById('btnSubir');
  const uploadProgress = document.getElementById('uploadProgress');
  const uploadStatus = document.getElementById('uploadStatus');
  const existingFolder = document.getElementById('carpetaExistente');
  const newFolder = document.getElementById('carpetaNueva');
  const fileInput = document.getElementById('archivoInput');
  const searchInput = document.getElementById('searchInput');
  const folderFilter = document.getElementById('folderFilter');
  const sortSelect = document.getElementById('sortSelect');
  const rowsContainer = document.getElementById('fileRows');
  const emptyState = document.getElementById('emptyState');
  const visibleCount = document.getElementById('visibleCount');

  existingFolder.addEventListener('change', function() {
    if (this.value) {
      newFolder.value = '';
    }
  });

  newFolder.addEventListener('input', function() {
    if (this.value.trim()) {
      existingFolder.value = '';
    }
  });

  fileInput.addEventListener('change', function() {
    if (!this.files.length) return;
    const allowedExtensions = ['ppt', 'pptx', 'csv', 'xls', 'xlsx', 'pdf', 'zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'];
    const extension = this.files[0].name.split('.').pop().toLowerCase();
    if (!allowedExtensions.includes(extension)) {
      alert('Formato no permitido. Puedes subir documentos o archivos ZIP, RAR, 7Z, TAR, GZ, TGZ, BZ2 y XZ.');
      this.value = '';
      uploadStatus.textContent = 'Selecciona un archivo válido.';
      return;
    }
    uploadStatus.textContent = this.files[0].name;
  });

  form.addEventListener('submit', async function(event) {
    event.preventDefault();

    if (!existingFolder.value && !newFolder.value.trim()) {
      alert('Selecciona una carpeta existente o escribe el nombre de una carpeta nueva.');
      return;
    }

    uploadButton.disabled = true;
    uploadButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Subiendo...';
    uploadProgress.classList.add('is-visible');
    uploadStatus.textContent = 'Subiendo y validando el archivo...';

    try {
      const response = await fetch('modulos/upload_archivo.php', {
        method: 'POST',
        body: new FormData(form)
      });

      const responseText = await response.text();
      let data;

      try {
        data = JSON.parse(responseText);
      } catch (parseError) {
        throw new Error(responseText || 'El servidor devolvió una respuesta inválida.');
      }

      if (!response.ok || data.error) {
        throw new Error(data.error || 'No fue posible subir el archivo.');
      }

      uploadStatus.textContent = data.reemplazado
        ? 'Archivo reemplazado correctamente.'
        : 'Archivo subido correctamente.';

      alert(
        data.reemplazado
          ? 'El archivo existente fue reemplazado correctamente.'
          : 'El archivo fue subido correctamente.'
      );
      window.location.reload();
    } catch (error) {
      uploadStatus.textContent = error.message;
      alert('Error: ' + error.message);
    } finally {
      uploadButton.disabled = false;
      uploadButton.innerHTML = '<i class="fas fa-upload mr-2"></i> Subir archivo';
    }
  });

  function applyTableState() {
    const query = searchInput.value.trim().toLowerCase();
    const selectedFolder = folderFilter.value;
    const rows = Array.from(rowsContainer.querySelectorAll('tr'));
    const sortValue = sortSelect.value;

    rows.sort(function(a, b) {
      if (sortValue === 'name-asc' || sortValue === 'name-desc') {
        const result = a.dataset.name.localeCompare(b.dataset.name, 'es');
        return sortValue === 'name-asc' ? result : -result;
      }

      const field = sortValue.startsWith('created') ? 'created' : 'modified';
      const result = Number(a.dataset[field]) - Number(b.dataset[field]);
      return sortValue.endsWith('asc') ? result : -result;
    });

    rows.forEach(row => rowsContainer.appendChild(row));

    let count = 0;
    rows.forEach(function(row) {
      const matchesText = !query
        || row.dataset.name.includes(query)
        || row.dataset.path.includes(query);
      const matchesFolder = !selectedFolder || row.dataset.folder === selectedFolder;
      const visible = matchesText && matchesFolder;

      row.style.display = visible ? '' : 'none';
      if (visible) count++;
    });

    visibleCount.textContent = count;
    emptyState.style.display = count === 0 ? 'block' : 'none';
    document.getElementById('fileTable').style.display = count === 0 ? 'none' : '';
  }

  searchInput.addEventListener('input', applyTableState);
  folderFilter.addEventListener('change', applyTableState);
  sortSelect.addEventListener('change', applyTableState);
  applyTableState();
});
</script>
</body>
</html>
