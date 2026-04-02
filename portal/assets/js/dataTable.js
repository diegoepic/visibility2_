$(function () {
  if ($.fn.DataTable.isDataTable('#example')) {
    $('#example').DataTable().destroy();
  }

  $('#example').DataTable({
    responsive: true,
    autoWidth: false,
    scrollX: true,
    dom: 'Bfrtip',
    columnDefs: [
      { targets: [2, 4, 5], visible: false },   // ocultas en pantalla
      { targets: [3], visible: true }           // REGION / COMUNA visible solo front
    ],
    buttons: [
      {
        extend: 'colvis',
        text: 'Mostrar columnas'
      },
      {
        extend: 'excelHtml5',
        text: 'Exportar',
        exportOptions: {
          columns: [0, 1, 2, 4, 5, 6, 7, 8, 9, 10, 11],
          format: {
            body: function (data, row, column, node) {
              return $('<div>').html(data).text().trim();
            }
          }
        }
      }
    ],
    language: {
      search: "Buscar:",
      lengthMenu: "Mostrar _MENU_ registros",
      info: "Mostrando _START_ a _END_ de _TOTAL_ locales",
      infoEmpty: "Mostrando 0 a 0 de 0 locales",
      zeroRecords: "No se encontraron resultados",
      paginate: {
        first: "Primero",
        last: "Último",
        next: "Siguiente",
        previous: "Anterior"
      }
    }
  });
});