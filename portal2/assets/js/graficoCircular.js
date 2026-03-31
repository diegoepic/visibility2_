function sliceSize(dataNum, dataTotal) {
  return (dataNum / dataTotal) * 360;
}

function addSlice(id, sliceSize, pieElement, offset, sliceID, color) {
  $(pieElement).append("<div class='slice "+ sliceID + "'><span></span></div>");
  var offset = offset - 1;
  var sizeRotation = -179 + sliceSize;

  $(id + " ." + sliceID).css({
    "transform": "rotate(" + offset + "deg) translate3d(0,0,0)"
  });

  $(id + " ." + sliceID + " span").css({
    "transform"       : "rotate(" + sizeRotation + "deg) translate3d(0,0,0)",
    "background-color": color
  });
}

function iterateSlices(id, sliceSize, pieElement, offset, dataCount, sliceCount, color) {
  var
    maxSize = 179,
    sliceID = "s" + dataCount + "-" + sliceCount;

  if( sliceSize <= maxSize ) {
    addSlice(id, sliceSize, pieElement, offset, sliceID, color);
  } else {
    addSlice(id, maxSize, pieElement, offset, sliceID, color);
    iterateSlices(id, sliceSize-maxSize, pieElement, offset+maxSize, dataCount, sliceCount+1, color);
  }
}

function createPie(id) {
  var listData = [],
      listTotal = 0,
      offset = 0,
      i = 0,
      pieElement = id + " .pie-chart__pie",
      dataElement = id + " .pie-chart__legend",
      legendColors = [];

  // Asignar colores según el ID del gráfico
  if (id === '.pieID--operations') {
    // Colores para el gráfico de "Motivos No implementado", que se aleatorizan
    legendColors = [
      "#90EE90",
      "#87CEFA",
      "#FFA07A",
      "#FFFACD",
      "#9370DB"
    ];
    legendColors = shuffle(legendColors);
  } else {
    // Para los demás gráficos, se fijan los colores
    legendColors = [
      "#aaa",     // Primer color fijo        
      "#8CC63F" // Segundo color fijo
    ];
  }

  // Obtener los datos de la leyenda
  $(dataElement + " span").each(function() {
    listData.push(Number($(this).html()));
  });

  // Sumar todos los valores
  for (i = 0; i < listData.length; i++) {
    listTotal += listData[i];
  }

  // Crear el gráfico circular y asignar los colores de la leyenda
  for (i = 0; i < listData.length; i++) {
    var size = sliceSize(listData[i], listTotal);
    iterateSlices(id, size, pieElement, offset, i, 0, legendColors[i]);
    $(dataElement + " li:nth-child(" + (i + 1) + ")").css("border-color", legendColors[i]);
    offset += size;
  }
}
function shuffle(a) {
    var j, x, i;
    for (i = a.length; i; i--) {
        j = Math.floor(Math.random() * i);
        x = a[i - 1];
        a[i - 1] = a[j];
        a[j] = x;
    }

    return a;
}

function createPieCharts() {
  createPie('.pieID--micro-skills' );
  createPie('.pieID--categories' );
  createPie('.pieID--operations' );
  createPie('.pieID--implementaciones' );  
  
}

createPieCharts();