<div class="table-responsive">
  <table class="table table-sm table-bordered permission-table mb-0">
    <thead>
      <tr>
        <th style="width: 34%;">Modulo / Submodulo</th>
        <th>Clave</th>
        <th style="width: 34%;">Permiso</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($modulos as $modulo): ?>
        <?php
          $claveModulo = (string)$modulo['clave'];
          $esPadre = strpos($claveModulo, '.') === false;
          $rowClass = $esPadre ? 'module-parent' : 'module-child';
          $idModulo = (int)$modulo['id'];
        ?>
        <tr class="<?php echo $rowClass; ?>" data-modulo-id="<?php echo $idModulo; ?>">
          <td>
            <?php if (!$esPadre): ?>
              <i class="fas fa-level-up-alt fa-rotate-90 text-muted mr-1"></i>
            <?php endif; ?>
            <?php echo h($modulo['nombre']); ?>
          </td>
          <td><code><?php echo h($claveModulo); ?></code></td>
          <td>
            <div class="permission-radios">
              <label>
                <input type="radio" name="permiso[<?php echo $idModulo; ?>]" value="inherit" checked>
                Heredar
              </label>
              <label>
                <input type="radio" name="permiso[<?php echo $idModulo; ?>]" value="1">
                Permitir
              </label>
              <label>
                <input type="radio" name="permiso[<?php echo $idModulo; ?>]" value="0">
                Bloquear
              </label>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
