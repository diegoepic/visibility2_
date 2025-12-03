function getDatosUsuario(id)
{
	var formData = {};

	formData.ws = "getDatosUsuario";
	formData.id = id;

	$("#rut").val("");
	$("#nombreEmpresa").val("");
	$("#telefonoContacto").val("");
	$("#correo").val("");
	

	$.ajax({
				 url: urlGlobal,
		         type: "GET",
		         data: formData,
		         //processData: false,
		         //contentType: false,
		         dataType: "json",
				 success: function (data) {

					if(data.datosUsuario != null)
					{
						$("#idUsuario").val(data.datosUsuario[0].id);
						$("#nombreUsuario").val(data.datosUsuario[0].nombre);
						$("#apePatUsuario").val(data.datosUsuario[0].apepat);
						$("#apeMatUsuario").val(data.datosUsuario[0].apemat);
						$("#telefonoUsuario").val(data.datosUsuario[0].telefono);
						$("#perfilUsuario").val(data.datosUsuario[0].id_perfil);
						$("#rutUsuario").val(data.datosUsuario[0].rut);
					}
					else
					{
						swal({
						  title: 'Error',
						  text: 'El usuario que consultaste no existe en los registros de la plataforma.',
						  type: 'error',
						  confirmButtonText: 'Aceptar'
						});
					}
				},
				error: function(e){
					console.log(e);
					swal({
						  title: 'Error',
						  text: 'Existe un problema al realizar la consulta, por favor, contáctate con la administración.',
						  type: 'error',
						  confirmButtonText: 'Aceptar'
						});
				}
		});
}

function guardaDatosUsuario()
{
	var fd = new FormData();
	var objForm = {};
	var arrElementos = [];
	var i = 0;

	//console.log(result);
	//fd.append('ws', $("#ws").val());
	fd.append('ws', "guardaDatosUsuario");
	//fd.append('idVisita', $("#idVisita").val());
	fd.append('idUsuario', $("#idUsuario").val());
	fd.append('nombreUsuario', $("#nombreUsuario").val());
	fd.append('apepatUsuario', $("#apePatUsuario").val());
	fd.append('apematUsuario', $("#apeMatUsuario").val());
	fd.append('telefonoUsuario', $("#telefonoUsuario").val());
	fd.append('perfilUsuario', $("#perfilUsuario").val());
	fd.append('rutUsuario', $("#rutUsuario").val());

	if($("#fotoPerfilUsu").val() != "")
	{
		fd.append("fotoPerfilUsu", $("#fotoPerfilUsu")[0].files[0]);
	}

	$("#modalDatosUsuario").modal("hide");
	$("#modal-cargando").modal("show");

	$.ajax({
		xhr: function()
		{
		    var xhr = new window.XMLHttpRequest();
		    //Upload progress
		    xhr.upload.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = (evt.loaded / evt.total)*100;
		        //Do something with upload progress
		        console.log(percentComplete);

		        $('div#barraAvance').width(percentComplete + '%');
		      }
		    }, false);
		    //Download progress
		    xhr.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = evt.loaded / evt.total;
		        //Do something with download progress
		        //console.log(percentComplete);
		      }
		    }, false);
		    return xhr;
		},
		url: urlGlobal,
		type: "POST",
		data: fd,
		processData: false,
		contentType: false,
		//dataType: "json",
		success: function (data) {

			console.log(data);
			var foto = $("#fotoPerfilUsu")[0].files[0];
			console.log('foto: '+foto+' '+urlGlobal);
			$("#modal-cargando").modal("hide");

			swal({
				title: 'Guardado',
				text: 'Datos actualizados correctamente.',
				type: 'success',
				confirmButtonText: 'Aceptar'
			}).then((result) => {
			location.reload();
		});
		},
		error: function(e){
		    $("#modal-cargando").modal("hide");
		    $("#modalDatosUsuario").modal("show");

		    swal({
		        title: 'Error',
		        text: 'Se produjo un error al intentar enviar la información. Por favor, intentalo nuevamante.',
		        type: 'error',
		        confirmButtonText: 'Aceptar'
		    });
		}
	});
}

function enviarArchivoUsuarios(){
	if($("#archivoUsuarios").val() == "")
	{
		swal({
	    	title: 'Error',
			text: 'Debes seleccionar un archivo',
			type: 'error',
			confirmButtonText: 'Aceptar'
		});

		return false;
	}

	var fd = new FormData();
	var objForm = {};

	fd.append('ws', "cargaUsuariosMasivo");

	if($("#archivoUsuarios").val() != "")
	{
		fd.append('archivoUsuarios', $("#archivoUsuarios")[0].files[0]);
	}

	$("#modal-cargando").modal("show");

	$.ajax({
		xhr: function()
		{
		    var xhr = new window.XMLHttpRequest();
		    //Upload progress
		    xhr.upload.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = (evt.loaded / evt.total)*100;
		        //Do something with upload progress
		        console.log(percentComplete);

		        $('div#barraAvance').width(percentComplete + '%');
		      }
		    }, false);
		    //Download progress
		    xhr.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = evt.loaded / evt.total;
		        //Do something with download progress
		        //console.log(percentComplete);
		      }
		    }, false);
		    return xhr;
		},
		url: urlGlobal,
		type: "POST",
		data: fd,
		processData: false,
		contentType: false,
		//dataType: "json",
		success: function (data) {
		    console.log(data);

			$("#modal-cargando").modal("hide");

			var mensaje = "";

			if(data.mensaje)
			{
				for(i=0; i<data.mensaje.length; i++)
				{
					mensaje = data;
				}

			}

			swal({
				title: 'Guardado',
				text: 'Usuarios cargados correctamente. '+mensaje,
				type: 'success',
				confirmButtonText: 'Aceptar'
			}).then((result) => {
			location.reload();
		});
		},
		error: function(e){
		    $("#modal-cargando").modal("hide");

		    swal({
		        title: 'Error',
		        text: 'Se produjo un error al intentar enviar la información. Por favor, intentalo nuevamante.',
		        type: 'error',
		        confirmButtonText: 'Aceptar'
		    });
		}
	});
}

function desactivarUsuario(id)
{
			//console.log(fechaFin);
			swal({
				  title: 'Desactivar',
				  text: "¿Realmente deseas desactivar a este usuario? ",
				  type: 'warning',
				  showCancelButton: true,
				  confirmButtonColor: '#3085d6',
				  cancelButtonColor: '#d33',
				  confirmButtonText: 'Aceptar'
				}).then((result) => {
					$.ajax({
						 url: 		"ws.php",
						 method: 	"GET",
						 data:		"ws=desactivarUsuario&idUsuario="+id,
						 //dataType:  "json",
				         success: function (data) {
					         //console.log(data);
					         location.reload();
					     }
					});
			});
}

function activarUsuario(id)
{
	//console.log(fechaFin);
	swal({
			title: 'Activar',
			text: "¿Realmente deseas activar a este usuario? ",
			type: 'success',
			showCancelButton: true,
			confirmButtonColor: '#3085d6',
			cancelButtonColor: '#d33',
			confirmButtonText: 'Aceptar'
		}).then((result) => {
			$.ajax({
					url: 		"ws.php",
					method: 	"GET",
					data:		"ws=activarUsuario&idUsuario="+id,
					//dataType:  "json",
					success: function (data) {
						//console.log(data);
						location.reload();
					}
			});
	});
}

function crearUsuario()
{
	var error = 0;
	var mensaje = "";


	if($("#run").val() == "")
	{
		error = 1;
		mensaje = "El campo Run es obligatorio";
	}
	if($("#nombreEmpresa").val() == "")
	{
		error = 1;
		mensaje = "El campo Empresa es obligatorio";
	}
	if($("#telefonoContacto").val() == "")
	{
		error = 1;
		mensaje = "El campo Teléfono es obligatorio";
	}
	if($("#correo").val() == "")
	{
		error = 1;
		mensaje = "El campo correo es obligatorio";
	}
	
	var fd = new FormData();
	var objForm = {};
	var arrElementos = [];
	var i = 0;

	//console.log(result);
	//fd.append('ws', $("#ws").val());
	fd.append('ws', "crearNuevoUsuario");
	//fd.append('idVisita', $("#idVisita").val());
	fd.append('run', $("#run").val());
	fd.append('nombreEmpresa', $("#nombreEmpresa").val());
	fd.append('telefonoContacto', $("#telefonoContacto").val());
	fd.append('correo', $("#correo").val());


	$.ajax({
		xhr: function()
		{
		    var xhr = new window.XMLHttpRequest();
		    //Upload progress
		    xhr.upload.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = (evt.loaded / evt.total)*100;
		        //Do something with upload progress
		        console.log(percentComplete);

		        $('div#barraAvance').width(percentComplete + '%');
		      }
		    }, false);
		    //Download progress
		    xhr.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = evt.loaded / evt.total;
		        //Do something with download progress
		        //console.log(percentComplete);
		      }
		    }, false);
		    return xhr;
		},
//		url: urlGlobal,
		type: "POST",
		data: fd,
		processData: false,
		contentType: false,
		dataType: "json",
		success: function (data) {
			console.log(data);
		
			if(data.mensaje == "error")
			{

				return false;
			}
			else{
				swal({
						title: 'Guardado',
						text: 'Usuario creado correctamente.',
						type: 'success',
						confirmButtonText: 'Aceptar'
					}).then((result) => {

					console.log(data);
					//location.reload();
				});
			}
		},
		error: function(e){
		    $("#modal-cargando").modal("hide");


		    swal({
		        title: 'Error',
		        text: 'Se produjo un error al intentar enviar la información. Por favor, intentalo nuevamante.',
		        type: 'error',
		        confirmButtonText: 'Aceptar'
		    }).then((result) => {
				
			});
		}
	});
}

function cambiarClaveUsuario()
{
	var error = 0;
	var mensaje = "";

	if($("#claveUsuarioCambio").val().length < 3)
	{
		error = 1;
		mensaje = "La contraseña debe contener al menos 3 caracteres";
	}

	if($("#claveUsuarioCambio").val() != $("#repiteClaveUsuarioCambio").val())
	{
		error = 1;
		mensaje = "Las contraseñas ingresadas no coinciden";
	}


	if(error == 1)
	{
		swal({
			title: 'Error',
			text: mensaje,
			type: 'error',
			confirmButtonText: 'Aceptar'
		});

		return false;
	}

	var fd = new FormData();
	var objForm = {};
	var arrElementos = [];
	var i = 0;

	//console.log(result);
	//fd.append('ws', $("#ws").val());
	fd.append('ws', "cambioClave");
	//fd.append('idVisita', $("#idVisita").val());
	fd.append('idUsuario', $("#idUsuarioCambio").val());
	fd.append('claveUsuario', $("#claveUsuarioCambio").val());

	$("#modalClaveUsuario").modal("hide");
	$("#modal-cargando").modal("show");

	$.ajax({
		xhr: function()
		{
		    var xhr = new window.XMLHttpRequest();
		    //Upload progress
		    xhr.upload.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = (evt.loaded / evt.total)*100;
		        //Do something with upload progress
		        console.log(percentComplete);

		        $('div#barraAvance').width(percentComplete + '%');
		      }
		    }, false);
		    //Download progress
		    xhr.addEventListener("progress", function(evt){
		      if (evt.lengthComputable) {
		        var percentComplete = evt.loaded / evt.total;
		        //Do something with download progress
		        //console.log(percentComplete);
		      }
		    }, false);
		    return xhr;
		},
		url: urlGlobal,
		type: "POST",
		data: fd,
		processData: false,
		contentType: false,
		dataType: "json",
		success: function (data) {
			console.log(data);
			$("#modal-cargando").modal("hide");
			swal({
						title: 'Cambio',
						text: 'Contraseña Modificada correctamente.',
						type: 'success',
						confirmButtonText: 'Aceptar'
				})
		},
		error: function(e){
		    $("#modal-cargando").modal("hide");
		    swal({
		        title: 'Error',
		        text: 'Se produjo un error al intentar enviar la información. Por favor, intentalo nuevamante.',
		        type: 'error',
		        confirmButtonText: 'Aceptar'
		    }).then((result) => {
				$("#modalClaveUsuario").modal("show");
			});
		}
	});
}
