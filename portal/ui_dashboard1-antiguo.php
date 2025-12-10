<?php
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/visibility2/portal/modulos/db.php';

// Obtenemos la división del usuario (variable de sesión)
$userDivision = $_SESSION['division_id'];

// Si se pasa un division_id por GET y el usuario es de división 1, se usa ese valor; 
// de lo contrario, se utiliza la división del usuario.
$division_id = ($userDivision == 1 && isset($_GET['division_id']))
    ? $conn->real_escape_string($_GET['division_id'])
    : $userDivision;

// Consulta para obtener los dashboards activos asociados a la división seleccionada
$query  = "SELECT * FROM dashboard_items WHERE is_active = 1 AND id_division = '$division_id' ORDER BY orden ASC";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en" class="theme_switchable">
  <head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <!-- Hojas de estilo -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.0/normalize.min.css">
    <link rel="stylesheet" href="https://static.fontawesome.com/css/fontawesome-app.css">
    <link rel="stylesheet" href="https://pro.fontawesome.com/releases/v5.2.0/css/all.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:400,700">
    <link rel="stylesheet" href="./style.css">
    <link rel="stylesheet" href="assets/css/styledashboard.css">
    <style>
    #divisionSelectorContainer {
      position: fixed; /* O absolute, según lo que necesites */
      top: 10px;
      left: 10px;
      z-index: 1000; /* Asegura que esté por encima de otros elementos */
      background-color: #fff; /* Opcional, para distinguirlo */
      padding: 5px; /* Opcional, para dar algo de espacio interno */
      border: 1px solid #ccc; /* Opcional, para delimitarlo */
      border-radius: 4px; /* Opcional */
    }
     
    .options {
      display: flex;
      overflow-x: auto;    
      overflow-y: hidden; 
      white-space: nowrap; 
      gap: 10px;
      padding-bottom: 10px; 
      scroll-behavior: smooth; /* scroll suave */
    }
    
    .option {
      flex: 0 0 200px;         
      transition: flex 0.3s ease;
      position: relative;
      cursor: pointer;
    }
    .option .info {
      opacity: 0;
      transition: opacity 0.3s;
      pointer-events: none;        
    }
    .option.active .info,
    .option:hover .info {
      opacity: 1;
      pointer-events: auto;
    }
    .option:hover {
      flex: 0 0 400px;         
      z-index: 10;            
    }
    body .options .option .label .info {
      opacity: 0;
      transform: translateX(20px);
      transition: opacity 0.3s ease, transform 0.3s ease;
      pointer-events: none;
    }
    
    body .options .option.active .label .info,
    body .options .option:hover .label .info {
      opacity: 1;
      transform: translateX(0);
      pointer-events: auto;
    }


    .carousel-wrapper {
      max-width: 1200px;
      margin: 0 auto;
      display: flex;
      justify-content: center;
    }
    .scroll-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      z-index: 20;
      background: rgba(0,0,0,0.3);
      color: white;
      border: none;
      font-size: 1.5rem;
      width: 2rem;
      height: 2rem;
      cursor: pointer;
    }
    #scrollLeft  { left: 0.5rem; }
    #scrollRight { right: 0.5rem; }
    </style>
  </head>
  <body>
    <?php if($userDivision == 1): ?>
      <!-- Selector de División, visible solo para usuarios de división 1 -->
      <div id="divisionSelectorContainer">
        <label for="selectDivision">Seleccionar Division:</label>
        <select id="selectDivision" onchange="location.href='ui_dashboard1.php?division_id=' + this.value;">
          <?php
          // Consulta para obtener todas las divisiones activas (puedes ajustar el query según tu lógica)
          $queryDiv = "SELECT * FROM division_empresa WHERE estado = 1 ORDER BY nombre ASC";
          $resultDiv = $conn->query($queryDiv);
          while($div = $resultDiv->fetch_assoc()){
              $selected = ($div['id'] == $division_id) ? 'selected' : '';
              echo "<option value='".$div['id']."' $selected>".$div['nombre']."</option>";
          }
          ?>
        </select>
      </div>
    <?php endif; ?>

    <div class="carousel-wrapper">
      <button id="scrollLeft"  class="scroll-btn">‹</button>
      <div class="options">
        <?php if ($result->num_rows > 0): ?>
        <?php
        // Antes de entrar al loop, definimos un flag
        $esPrimero = true;
        
        while ($row = $result->fetch_assoc()):
            // Si es el primer registro, asignamos "active" y luego desactivamos el flag
            $activeClass = $esPrimero ? 'active' : '';
            $esPrimero = false;
        ?>
          <div class="option <?php echo $activeClass; ?>"
               style="--optionBackground:url('<?php echo $row['image_url']; ?>');"
               onclick="if(this.classList.contains('active')) { window.open('dashboard.php?id=<?php echo $row['id']; ?>', '_blank'); }">
            <div class="shadow"></div>
            <div class="label">
              <div class="icon">
                <i class="<?php echo $row['icon_class']; ?>"></i>
              </div>
              <div class="info">
                <div class="main"><?php echo $row['main_label']; ?></div>
                <div class="sub"><?php echo $row['sub_label']; ?></div>
              </div>
            </div>
          </div>
        <?php endwhile; ?>
        <?php else: ?>
          <p>No existen dashboards disponibles para esta división.</p>
        <?php endif; ?>
      </div>
      <button id="scrollRight" class="scroll-btn">›</button>
    </div>

    <!-- Scripts necesarios -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script>
      // Al hacer clic en una opción, se remueve "active" de todas y se asigna a la opción clickeada.
      $(".option").click(function(){
        $(".option").removeClass("active");
        $(this).addClass("active");
      });

      const cont = document.querySelector('.options');
      let idInterval;

      cont.addEventListener('mousemove', e => {
        const { left, width } = cont.getBoundingClientRect();
        const x = e.clientX - left;
        const edge = 0.2 * width; // 20% por cada lado

        clearInterval(idInterval);
        if (x < edge) {
          // desplaza a la izquierda
          idInterval = setInterval(()=> cont.scrollBy({ left: -5 }), 16);
        } else if (x > width - edge) {
          // desplaza a la derecha
          idInterval = setInterval(()=> cont.scrollBy({ left: 5 }), 16);
        }
      });
      cont.addEventListener('mouseleave', () => clearInterval(idInterval));

      // ——————— Añadido: comportamiento de flechas ———————
      document.getElementById('scrollLeft').addEventListener('click', () => {
        cont.scrollBy({ left: -300, behavior: 'smooth' });
      });
      document.getElementById('scrollRight').addEventListener('click', () => {
        cont.scrollBy({ left: 300, behavior: 'smooth' });
      });
    </script>
  </body>
</html>
