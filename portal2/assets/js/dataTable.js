$(document).ready(function() {
  var table = $('#example').DataTable({
    dom: 'Bfrtip',
    responsive: true,
    buttons: [
      {
        extend: 'colvis',
        text: 'Mostrar columnas',
        columns: function(idx, data, node) {
          return true;  // Permite todas las columnas
        }
      },
      {
        extend: 'collection',
        text: 'Exportar',
        buttons: [
          {
            extend: 'copyHtml5',
            exportOptions: {
              columns: function(idx, data, node) {
                return true;
              }
            }
          },
          {
            extend: 'excelHtml5',
            exportOptions: {
              columns: function(idx, data, node) {
                return true;
              }
            }
          },
          {
            extend: 'pdfHtml5',
            exportOptions: {
              columns: function(idx, data, node) {
                return true;
              }
            }
          }
        ]
      }
    ]
  });
});
