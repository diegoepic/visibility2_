<?php
// template_child_questions.php
// Este archivo se incluye para renderizar recursivamente las preguntas hijas dentro de un set.
// Se asume que la variable $q contiene la pregunta padre cuya propiedad 'children' es un arreglo
// de preguntas hijas y que $selectedSet está disponible en el contexto.

if (!empty($q['children'])):
?>
    <ul class="child-list list-group mt-2">
    <?php foreach ($q['children'] as $child): ?>
        <li class="list-group-item draggable-group" data-id="<?php echo $child['id']; ?>">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <span class="drag-handle mr-2"><i class="fas fa-bars"></i></span>
                    <div>
                        <em><?php echo htmlspecialchars($child['question_text'], ENT_QUOTES, 'UTF-8'); ?></em><br>
                        <small class="text-secondary">
                            Tipo: 
                            <?php 
                            $tipoTextoChild = "Otro";
                            switch ($child['id_question_type']) {
                                case 1: $tipoTextoChild = "Sí/No"; break;
                                case 2: $tipoTextoChild = "Selección única"; break;
                                case 3: $tipoTextoChild = "Selección múltiple"; break;
                                case 4: $tipoTextoChild = "Texto"; break;
                                case 5: $tipoTextoChild = "Numérico"; break;
                                case 6: $tipoTextoChild = "Fecha"; break;
                                case 7: $tipoTextoChild = "Foto"; break;
                            }
                            if ($child['is_required']) { 
                                $tipoTextoChild .= " (Requerida)";
                            }
                            if (isset($child['is_valued']) && $child['is_valued'] == 1) { 
                                $tipoTextoChild .= " - Valorizada";
                            }
                            echo $tipoTextoChild;
                            ?>
                        </small>
                    </div>
                </div>
                <div>
                    <button class="btn btn-sm btn-info" onclick="editarSetPregunta(<?php echo $child['id']; ?>, <?php echo $selectedSet['id']; ?>)">Editar</button>
                    <a href="eliminar_set_pregunta.php?id=<?php echo $child['id']; ?>&idSet=<?php echo $selectedSet['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar esta pregunta?');">Eliminar</a>
                </div>
            </div>
            <?php
            // Renderizar recursivamente los hijos de este nodo
            if (!empty($child['children'])) {
                $q = $child;
                include 'template_child_questions.php';
            }
            ?>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
