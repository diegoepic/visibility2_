<?
	include("con_.php");
	header('Access-Control-Allow-Origin: *');

	$ws = $_REQUEST["ws"];
	$mes = date('m');
	$anho = date('Y');
	$diahoy = date('Y-m-d');

	if(isset($_REQUEST["mes"]) and $_REQUEST["mes"] != "")
	{
		$mes = $_REQUEST["mes"];
	}

	$merchan = "";
	$merchanGestion = "";
	$cuenta = "";
	$cadena = "";

	if(isset($_REQUEST["idMerchan"]) and ($_REQUEST["idMerchan"] != "" or $_REQUEST["idMerchan"] != 0))
	{
		$merchan = " AND visita.usuario_id =  ".$_REQUEST["idMerchan"]." ";

		$merchanGestion = " AND gestionUsuario.idItem =  ".$_REQUEST["idMerchan"]." ";
	}

	if(isset($_REQUEST["idCuenta"]) and ($_REQUEST["idCuenta"] != "" or $_REQUEST["idCuenta"] != 0))
	{
		$cuenta = " AND local.id_cuenta =  ".$_REQUEST["idCuenta"]." ";	}

	if(isset($_REQUEST["idCadena"]) and ($_REQUEST["idCadena"] != "" or $_REQUEST["idCadena"] != 0))
	{
		$cadena = " AND local.id_cadena =  ".$_REQUEST["idCadena"]." ";
	}

	if($ws == "getAnhos")
	{
		$s = " SELECT DISTINCT(YEAR(fechaCreacion)) as anho FROM visitaHead";
		$q = consulta($s);

		$salida["anhos"] = $q;

	}

	if($ws == "getMeses")
	{
		$anho = $_REQUEST["anho"];

		$s = " 	SELECT
					CASE WHEN MONTH(fechaCreacion) < 10 THEN concat('0', MONTH(fechaCreacion)) ELSE MONTH(fechaCreacion) END as idMes,

					CASE
						WHEN
								MONTH(fechaCreacion) = '1' THEN 'Enero'
						WHEN
								MONTH(fechaCreacion) = '2' THEN 'Febrero'
						WHEN
								MONTH(fechaCreacion) = '3' THEN 'Marzo'
						WHEN
								MONTH(fechaCreacion) = '4' THEN 'Abril'
						WHEN
								MONTH(fechaCreacion) = '5' THEN 'Mayo'
						WHEN
								MONTH(fechaCreacion) = '6' THEN 'Junio'
						WHEN
								MONTH(fechaCreacion) = '7' THEN 'Julio'
						WHEN
								MONTH(fechaCreacion) = '8' THEN 'Agosto'
						WHEN
								MONTH(fechaCreacion) = '9' THEN 'Septiembre'
						WHEN
								MONTH(fechaCreacion) = '10' THEN 'Octubre'
						WHEN
								MONTH(fechaCreacion) = '11' THEN 'Noviembre'
						WHEN
								MONTH(fechaCreacion) = '12' THEN 'Diciembre'
					END as mes

				FROM visitaHead

				WHERE YEAR(fechaCreacion) = '$anho' GROUP BY MONTH(fechaCreacion)";
		$q = consulta($s);

		$salida["meses"] = $q;

	}

	if($ws == "getPaises")
	{						
		$queryForm = "SELECT 	id,			
		descripcion,
		activo
		FROM loc_pais as pais
			WHERE pais.activo = 1						
			ORDER BY pais.id";
	
		$q = consulta($queryForm);
		$salida["paises"] = $q;		
	}

	if($ws=="getDataCuentas"){
		
		$idcuenta = "";

		$id = "";

		if(isset($_REQUEST["idcuenta"]) && $_REQUEST["idcuenta"] != "" && $_REQUEST["idcuenta"] != null){
			$id = $_REQUEST["idcuenta"];
			$idcuenta = " AND id =  $id";
		}else{
			$idcuenta = "";
		}

		$queryCuentas = "SELECT id,descripcion FROM cuenta
			where activo = 1 $idcuenta
			ORDER BY id";

		$q = consulta($queryCuentas);
		$salida["cuentas"] = $q;		
						
	}

	if($ws=="getDataCadena"){
		
		$idcadena = "";

		$id = "";

		if(isset($_REQUEST["idcadena"]) && $_REQUEST["idcadena"] != "" && $_REQUEST["idcadena"] != null){
			$id = $_REQUEST["idcadena"];
			$idcadena = " AND id =  $id";
		}else{
			$idcadena = "";
		}

		$query = "SELECT id,descripcion FROM cadena
			where activo = 1 $idcadena
			ORDER BY id";

		$q = consulta($query);
		$salida["cadenas"] = $q;		
						
	}

	if($ws=="getDataZonal"){
		
		$idzonal = "";

		$id = "";

		if(isset($_REQUEST["idzonal"]) && $_REQUEST["idzonal"] != "" && $_REQUEST["idzonal"] != null){
			$id = $_REQUEST["idzonal"];
			$idzonal = " AND id =  $id";
		}else{
			$idzonal = "";
		}

		$query = "SELECT id,nombre FROM zonal
			where activo = 1 $idzonal
			ORDER BY id";

		$q = consulta($query);
		$salida["zonal"] = $q;		
						
	}

	if($ws=="getComunasRegion"){

		$idregion = "";

		$id = "";

		if(isset($_REQUEST["idregion"]) && $_REQUEST["idregion"] != "" && $_REQUEST["idregion"] != null){
			$id = $_REQUEST["idregion"];
			$idregion = " AND id_region =  $id";
		}else{
			$idregion = "";
		}

		$queryComunas = "SELECT id,id_region,descripcion FROM loc_comuna
		where activo = 1 $idregion;";
		
		$q = consulta($queryComunas);
		$salida["comunas"] = $q;	

	}

	if($ws=="getDataRegiones"){
		
		$idregion = "";

		$id = "";

		if(isset($_REQUEST["idregion"]) && $_REQUEST["idregion"] != "" && $_REQUEST["idregion"] != null){
			$id = $_REQUEST["idregion"];
			$idregion = " AND id =  $id";
		}else{
			$idregion = "";
		}

		$queryRegiones = "SELECT id,descripcion FROM loc_region
			where activo = 1 $idregion
			ORDER BY descripcion";

		$q = consulta($queryRegiones);
		$salida["regiones"] = $q;		
						
	}
	
	if($ws=="getRegiones"){

		$queryRegiones = "SELECT id,descripcion,activo FROM loc_region			
			ORDER BY descripcion desc";

		$q = consulta($queryRegiones);
		$salida["regiones"] = $q;								
	}

	if($ws=="verificaNombreRegion"){
		$errors = "";
		$mensaje = "OK";
		$region = $_REQUEST["region"];

		if(strlen($region)>0){
			$s = "SELECT * FROM loc_region
			where descripcion like('%$region%');";
			$q = consulta($s);
			if(count($q)>0){
				$errors = ($errors=="")? "" : $errors." | ";
				$errors = $errors."Ya existe una Región que contiene ése nombre, desea continuar?...";
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
			}else{				
				$salida["codigo"] = $mensaje;
			}			
		}		
	}


	if($ws=="guardarRegion"){
		$errors = "";
		$mensaje = "OK";
		$idpais = $_REQUEST["idpais"];
		$nombreRegion = $_REQUEST["nombreRegion"];

		$nombreRegion   = strtoupper(normaliza($nombreRegion)); 

		if($idpais == 0 || $idpais == null || $idpais =="" || $idpais == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de país es incorrecto, verifique.";
		}	

		if(strlen($nombreRegion) == 0 || $nombreRegion == null || $nombreRegion =="" || $nombreRegion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Nombre de la Región no está llegando correctamente, verifique.";
		}	
		

		if(!isset($_REQUEST["idpais"]) || !isset($_REQUEST["nombreRegion"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				$codigo = substr($nombreRegion,0,15);
				try{
					$s = ("INSERT INTO loc_region (descripcion, codigo, id_pais, activo) 
					VALUES ('$nombreRegion', '$codigo',$idpais, 1)");
					
					$q	=	mysql_query($s);
					$idregion = mysql_insert_id();					
					
					if($idregion > 0){
						$salida["idregion"] = $idregion;
						$salida["mensaje"] = "Felicidades! La Región ".$nombreRegion." ha sido guardada con éxito.";
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al insertar la región : ".$nombreRegion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws=="editarNombreRegion"){
		$errors = "";
		$mensaje = "OK";
		$idregion = $_REQUEST["idregion"];
		$idpais = $_REQUEST["idpais"];
		$nombreRegion = $_REQUEST["nombreRegion"];

		$nombreRegion   = strtoupper(normaliza($nombreRegion)); 

		if($idpais == 0 || $idpais == null || $idpais =="" || $idpais == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de país es incorrecto, verifique.";
		}	

		if($idregion == 0 || $idregion == null || $idregion =="" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if(strlen($nombreRegion) == 0 || $nombreRegion == null || $nombreRegion =="" || $nombreRegion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Nombre de la Región no está llegando correctamente, verifique.";
		}	
		

		if(!isset($_REQUEST["idpais"]) || !isset($_REQUEST["idregion"]) || !isset($_REQUEST["nombreRegion"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE loc_region SET descripcion = '$nombreRegion', id_pais = $idpais WHERE id = $idregion");
					
					$q	=	mysql_query($s);
					if(count($q) > 0){
						$salida["idregion"] = $idregion;
						$salida["mensaje"] = "Felicidades! La Región ".$nombreRegion." ha sido actualizada con éxito.";
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar nombre de la región : ".$nombreRegion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws == "cambiaEstadoRegion")
	{
		$errors = "";
		$mensaje = "OK";
		$idregion = $_REQUEST["idregion"];
		$estado = $_REQUEST["estado"];
		$nombreRegion = $_REQUEST["nombreRegion"];
		
		if($idregion == 0 || $idregion == null || $idregion =="" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if($estado == null || $estado =="" || $estado == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El campo estado de la región es incorrecto, verifique.";
		}	

		if(!isset($_REQUEST["idregion"]) || !isset($_REQUEST["estado"]))
		{			
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";	
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";		
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE loc_region SET activo = $estado WHERE id = $idregion");
					
					$q	=	mysql_query($s);
					
					if(count($q) > 0){
						$salida["idregion"] = $idregion;
						if($estado == 0){
							$salida["mensaje"] = "Felicidades! La Región ".$nombreRegion." ha sido eliminada con éxito.";
						}else{
							$salida["mensaje"] = "Felicidades! La Región ".$nombreRegion." ha sido activada con éxito.";
						}
						
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar el estado de la región : ".$nombreRegion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		
	}

	//request de comunas

	if($ws=="guardarComunas"){
		$errors = "";
		$mensaje = "OK";
		$idregion = $_REQUEST["idregion"];
		$comuna = $_REQUEST["comuna"];

		$comuna   = strtoupper(normaliza($comuna)); 

		if($idregion == 0 || $idregion == null || $idregion =="" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if(strlen($comuna) == 0 || $comuna == null || $comuna =="" || $comuna == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Nombre de la Región no está llegando correctamente, verifique.";
		}	
		

		if(!isset($_REQUEST["idregion"]) || !isset($_REQUEST["comuna"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){				
				try{
					$s = ("INSERT INTO loc_comuna (descripcion, id_region, activo) 
					VALUES ('$comuna', $idregion, 1)");
					
					$q	=	mysql_query($s);
					$idcomuna = mysql_insert_id();					
					
					if($idcomuna > 0){
						$salida["idcomuna"] = $idcomuna;
						$salida["mensaje"] = "Felicidades! La Comuna ".$comuna." ha sido guardada con éxito.";
						$salida["codigo"] = $mensaje;
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al insertar la comuna : ".$comuna;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws=="editarComuna"){
		$errors = "";
		$mensaje = "OK";
		$idregion = $_REQUEST["idregion"];		
		$comuna = $_REQUEST["comuna"];
		$idcomuna = $_REQUEST["idcomuna"];

		$comuna   = strtoupper(normaliza($comuna)); 

		if($idcomuna == 0 || $idcomuna == null || $idcomuna =="" || $idcomuna == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la comuna es incorrecto, verifique.";
		}	
			
		if($idregion == 0 || $idregion == null || $idregion =="" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if(strlen($comuna) == 0 || $comuna == null || $comuna =="" || $comuna == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Nombre de la Región no está llegando correctamente, verifique.";
		}	
		

		if(!isset($_REQUEST["idcomuna"]) || !isset($_REQUEST["idregion"]) || !isset($_REQUEST["comuna"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE loc_comuna SET descripcion = '$comuna', id_region = $idregion WHERE id = $idcomuna");
					
					$q	=	mysql_query($s);
					if(count($q) > 0){
						$salida["idcomuna"] = $idcomuna;
						$salida["mensaje"] = "Felicidades! La Comuna ".$comuna." ha sido actualizada con éxito.";
						$salida["codigo"] = $mensaje;
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar nombre de la comuna : ".$comuna;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws == "cambiaEstadoComuna")
	{
		$errors = "";
		$mensaje = "OK";
		$idcomuna = $_REQUEST["idcomuna"];
		$estado = $_REQUEST["estado"];
		$comuna = $_REQUEST["comuna"];
		
		if($idcomuna == 0 || $idcomuna == null || $idcomuna =="" || $idcomuna == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if($estado == null || $estado =="" || $estado == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El campo estado de la región es incorrecto, verifique.";
		}	

		if(!isset($_REQUEST["idcomuna"]) || !isset($_REQUEST["estado"]))
		{			
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";	
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";		
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE loc_comuna SET activo = $estado WHERE id = $idcomuna");
					
					$q	=	mysql_query($s);
					
					if(count($q) > 0){
						$salida["idcomuna"] = $idcomuna;
						if($estado == 0){
							$salida["mensaje"] = "Felicidades! La Comuna ".$comuna." ha sido eliminada con éxito.";
						}else{
							$salida["mensaje"] = "Felicidades! La Comuna ".$comuna." ha sido activada con éxito.";
						}
						
						$salida["codigo"] = $mensaje;
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar el estado de la Comuna : ".$comuna;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		
	}
	
	if($ws=="getComunasDeRegion"){

		$errors = "";
		$mensaje = "OK";
		$idregion = "";
		$nombreRegion ="";
		$activo = 1;
		$estado = "";

		

		if(isset($_REQUEST["idregion"])){
			$idregion = $_REQUEST["idregion"];
		}

		if(isset($_REQUEST["activo"])){
			$activo = $_REQUEST["activo"];
			$estado = "AND activo = $activo";
		}else{
			$estado = "";
		}

		if(isset($_REQUEST["nombreRegion"])){
			$nombreRegion = $_REQUEST["nombreRegion"];
		}								

		if($idregion == 0 || $idregion == null || $idregion == "" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";			
		}	

		if(!isset($_REQUEST["idregion"]) && $_REQUEST["idregion"] == "" && $_REQUEST["idregion"] == null){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";			
		}else{
			if($errors == ""){
				try{

					$id_region = "id_region =  $idregion";

					$queryComunas = "SELECT id,id_region,descripcion,activo FROM loc_comuna
										where $id_region $estado;";	
					
					$q = consulta($queryComunas);

					if(count($q) > 0){
						$salida["comunas"] = $q;
						$salida["codigo"] = $mensaje;
					}else{
						$errors = ($errors=="")? "" : $errors." | ";
						$errors = $errors."No hay comunas para la región : ".$nombreRegion;
						$salida["codigo"] = "ERROR";
						$salida["mensaje"] = $errors;
						$errors = "";
					}
					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al consultar las comunas de la región : ".$nombreRegion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}										
		}		
	}

	if($ws=="verificarNombreComuna"){
		$errors = "";
		$mensaje = "OK";
		$idregion = "";
		$comuna = "";

		if(isset($_REQUEST["idregion"])){
			$idregion = $_REQUEST["idregion"];
		}

		if(isset($_REQUEST["comuna"])){
			$comuna = $_REQUEST["comuna"];
		}

		if(strlen($comuna)>0){
			$s = "SELECT * FROM loc_comuna
			where id_region = $idregion AND descripcion like('%$comuna%');";
			$q = consulta($s);
			if(count($q)>0){
				$errors = ($errors=="")? "" : $errors." | ";
				$errors = $errors."Ya existe una comuna con éste nombre, en ésta Región, desea continuar?...";
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
			}else{				
				$salida["codigo"] = $mensaje;
			}			
		}		
	}
	
	//get data local
	if($ws=="getDataLocal"){
		
		$errors = "";
		$mensaje = "OK";
		$soldto = "";
		$nomlocal = "";

		$numloc = "";


		if(isset($_REQUEST["soldto"]) && $_REQUEST["soldto"] != "" && $_REQUEST["soldto"] != null){
			$id = $_REQUEST["soldto"];
			$soldto = " AND numero_local =  '$id'";
		}else{
			$soldto = "";
		}

		if(isset($_REQUEST["nombre"]) && $_REQUEST["nombre"] != "" && $_REQUEST["nombre"] != null){
			$id = $_REQUEST["nombre"];
			$nomlocal = " AND loc.descripcion LIKE  '%$id%'";
		}else{
			$nomlocal = "";
		}
		
		if($nomlocal !== "" || $soldto !== ""){
			$queryLocal = "SELECT loc.id,loc.descripcion,direccion,numero_local,id_cadena,id_cuenta,id_canal,id_comuna,latitud,
			longitud,loc.activo,id_clasificacion,telefono,id_zonal,modelo_cafetera,rut_local,fechaCreacion,
						fechaModificacion,region.id as idregion
			FROM local as loc
				left join loc_comuna as comuna on comuna.id = id_comuna
				left join loc_region as region on region.id = comuna.id_region
				where loc.activo = 1 $soldto $nomlocal";
			
			$q = consulta($queryLocal);
			
			if(count($q)>0){
				$salida["codigo"] = $mensaje;
				$salida["locales"] = $q;				
			}else{	
				$errors = ($errors=="")? "" : $errors." | ";
				$errors = $errors."No existe un local con éste nombre o éste número, desea continuar?...";
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;				
			}	
				
		}else{
			if($nomlocal !== ""){
				$errors = ($errors=="")? "" : $errors." | ";
				$errors = $errors."El nombre del local no es válido... verifique por favor";
			}
			if($soldto !== ""){
				$errors = ($errors=="")? "" : $errors." | ";
				$errors = $errors."El número del local no es válido... verifique por favor";
			}
			
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
		}								
	}

	if($ws == "getEntidadesBasicas")
	{
		$s = " SELECT id, nombreParaMostrar FROM entidadesBasicas WHERE activo = 1 ORDER BY nombreParaMostrar;";
		$q = consulta($s);

		$salida["entidades"] = $q;
	}

	if($ws == "guardaElementoEntidad")
	{
		$idEntidad = $_REQUEST["idEntidad"];
		$idElemento = $_REQUEST["idElemento"];
		$elemento = $_REQUEST["nombreElemento"];

		$s = "SELECT entidad FROM entidadesBasicas WHERE id = $idEntidad ORDER BY nombreParaMostrar;";
		$q = consulta($s);

		$entidad = $q[0]["entidad"];
		//echo("\r\n".'id_entidad: '.$idEntidad."\r\n".'entidad: '.$entidad."\r\n".'id_elemento: '.$idElemento."\r\n".'elemento: '.$elemento);

		$nombreCampo = "nombre";


		if($entidad == "cuenta" || $entidad == "cadena" || $entidad == "loc_comuna" || $entidad == "loc_region")
		{
			$nombreCampo = "descripcion";
		}
		if($entidad == "loc_comuna" || $entidad == "loc_region")
		{
			$sUpd = "UPDATE $entidad SET  $nombreCampo = '$elemento' WHERE id = $idElemento;";
		}else{
			$sUpd = "UPDATE $entidad SET  $nombreCampo = '$elemento', fechaModificacion = now() WHERE id = $idElemento;";
		}


		mysql_query($sUpd);

	}

	if($ws == "cambiarEstadoElementoEntidad")
	{
		$idEntidad = $_REQUEST["idEntidad"];
		$idElemento = $_REQUEST["idElemento"];
		$idEstado = $_REQUEST["estado"];

		$s = "SELECT entidad FROM entidadesBasicas WHERE id = $idEntidad ORDER BY nombreParaMostrar;";
		$q = consulta($s);

		$entidad = $q[0]["entidad"];

		$nombreCampo = "nombre";

		if($entidad == "cuenta" || $entidad == "cadena" || $entidad == "loc_comuna" || $entidad == "loc_region")
		{
			$nombreCampo = "descripcion";
		}

		mysql_query("UPDATE $entidad SET activo = '$idEstado', fechaModificacion = now() WHERE id = $idElemento;");
	}


	if($ws=="creaElementoEntidad")
	{
		$idEntidad = $_REQUEST["idEntidad"];
		$elemento = $_REQUEST["nombreElemento"];

		$s = "SELECT entidad FROM entidadesBasicas WHERE id = $idEntidad ORDER BY nombreParaMostrar;";
		$q = consulta($s);

		$entidad = $q[0]["entidad"];

		$nombreCampo = "nombre";

		if($entidad == "cuenta" || $entidad == "cadena")
		{
			$nombreCampo = "descripcion";
		}

		$s = "SELECT id FROM $entidad WHERE $nombreCampo = '$elemento';";
		$q = consulta($s);

		if(count($q)>0)
		{
			$salida["resultado"] = "-1";
		}
		else
		{
			mysql_query("INSERT INTO $entidad SELECT null, '$elemento', 1, now(), now();");

			$salida["resultado"] = "0";
		}
	}

	if($ws == "graficoUno")
	{
		$s =			"SELECT 		concat(usuario.nombre, ' ', usuario.apepat) as Merchandising
		FROM			visitaHead 		visita
		INNER JOIN		usuario 				ON	visita.usuario_id = usuario.id AND usuario_id NOT IN (1, 60) $merchan
		INNER JOIN		local					ON	visita.local_id = local.id $cuenta $cadena
		WHERE			(MONTH(visita.fechaCreacion) = '".$mes."' AND YEAR(visita.fechaCreacion) = '".$anho."')

		GROUP BY		Merchandising
		ORDER BY		Merchandising
		";

		$q = consulta($s);

		$salida["merchandising"] = $q;

		$s =			"SELECT 		#concat(usuario.nombre, ' ', usuario.apepat) as Merchandising,
										CASE WHEN COUNT(DISTINCT(local_id)) IS NULL THEN 0 ELSE COUNT(DISTINCT(local_id)) END  as nVisitas
		FROM			visitaHead 	visita
		INNER JOIN		usuario 				ON	visita.usuario_id = usuario.id AND usuario_id NOT IN (1, 60) $merchan
		INNER JOIN		local					ON	visita.local_id = local.id $cuenta $cadena
		WHERE			MONTH(visita.fechaCreacion) = '".$mes."' AND YEAR(visita.fechaCreacion) = '".$anho."'

		GROUP BY		usuario.nombre, usuario.apepat
		";
		//echo $s;
		$q = consulta($s);

		$salida["reporte"] = $q;
	}

	if($ws=="graficoDos")
	{
		$conMes = $_REQUEST["conMes"];
		$conFechas = $_REQUEST["conFechas"];
		$conVisible = $_REQUEST["conVisible"];
		$estado = $_REQUEST["conEstado"];

		$sCabeceras = "SELECT 		formulario.id, count(DISTINCT(local.numero_local)) as total
						FROM		formularioApp			as formulario
						INNER JOIN	formularioAppGestiona	as gestionUsuario	ON	gestionUsuario.codigo = 'MERCHAN'
																				AND	gestionUsuario.formularioApp_id = formulario.id
																				AND	gestionUsuario.activo = 1
																				$merchanGestion
						INNER JOIN	formularioAppGestiona	as gestionLocal		ON	gestionLocal.codigo = 'LOCAL'
																				AND	gestionLocal.formularioApp_id = formulario.id
																				AND gestionLocal.activo = 1
						INNER JOIN  local                                       ON	gestionLocal.idItem = local.id  $cuenta $cadena

						WHERE formulario.activo = 1
                        AND	formulario.idTipoFormulario IN (2, 4)
						$conMes
						$conFechas
						$conVisible
						GROUP BY 	formulario.id
						ORDER BY	formulario.id";

		/*$qTotal = consulta($sCabeceras);
		
		for($i=0; $i<count($qTotal); $i++)
		{
			$salida["idCamp"][$i] = $qTotal[$i]["id"];
			$salida["total"][$i]["total"] = $qTotal[$i]["total"];
			$salida["total"][$i]["id"] = $qTotal[$i]["id"];
		}

		for($i=0; $i<count($qTotal); $i++)
		{
			$salida["noIniciados"][$i]["noIniciado"] = 0;
			$salida["noIniciados"][$i]["id"] = $qTotal[$i]["id"];
		}*/

		//pieChart
		$sEnProceso	= "SELECT 		formulario.id, count(formulario.id) as nEnProceso
		FROM		formularioApp			as formulario
		INNER JOIN	visitaHead				as	visita			ON	visita.formularioApp_id = formulario.id
																$merchan
																AND visita.idEstado = 1
																AND visita.activo = 1
		INNER JOIN		local									ON	visita.local_id = local.id $cuenta $cadena
		INNER JOIN	formularioAppGestiona	as gestionLocal		ON	gestionLocal.formularioApp_id = formulario.id
																	AND	gestionLocal.codigo = 'LOCAL'
																	AND gestionLocal.idItem	= local.id
																	AND gestionLocal.activo = 1
		WHERE formulario.activo = 1
		AND	formulario.idTipoFormulario IN (2, 4)
		$conMes
		$conFechas
		GROUP BY 	formulario.id
		ORDER BY	formulario.id
		";
		//echo     $sDetalles;

		/*$qTotal = consulta($sEnProceso);

		for($i=0; $i<count($qTotal); $i++)
		{
		$salida["enProceso"][$i]["enProceso"] = $qTotal[$i]["nEnProceso"];
		$salida["enProceso"][$i]["id"] = $qTotal[$i]["id"];
		}*/

		$sFinalizadas	= "SELECT 	formulario.id, count(formulario.id) as nFinalizadas
					FROM		formularioApp			as formulario
					INNER JOIN	visitaHead				as	visita			ON	visita.formularioApp_id = formulario.id
																			$merchan
																			AND visita.idEstado = 2
																			AND visita.activo = 1
					INNER JOIN	local					ON	visita.local_id = local.id $cuenta $cadena
					INNER JOIN	formularioAppGestiona	as gestionLocal		ON	gestionLocal.formularioApp_id = formulario.id
																				AND	gestionLocal.codigo = 'LOCAL'
																				AND gestionLocal.idItem	= local.id
																				AND gestionLocal.activo = 1
					WHERE formulario.activo = 1
					AND	formulario.idTipoFormulario IN (2, 4)
					$conMes
					$conFechas
					GROUP BY 	formulario.id
					ORDER BY	formulario.id";

		/*$qTotal = consulta($sFinalizadas);

		for($i=0; $i<count($qTotal); $i++)
		{
			$salida["finalizadas"][$i]["finalizado"] = $qTotal[$i]["nFinalizadas"];
			$salida["finalizadas"][$i]["id"] = $qTotal[$i]["id"];
		}*/

		$sOptimo 	= "SELECT 		formulario.id, count(formulario.id) as nFinalizadas
					FROM		formularioApp			as formulario
					INNER JOIN	visitaHead				as	visita			ON	visita.formularioApp_id = formulario.id
																			AND DATE(visita.fechaModificacion) BETWEEN formulario.fechaInicioImpl AND formulario.fechaFinImpl
																			$merchan
																			AND visita.idEstado = 2
																			AND visita.activo = 1
					INNER JOIN	local										ON	visita.local_id = local.id $cuenta $cadena
					INNER JOIN	formularioAppGestiona	as gestionLocal		ON	gestionLocal.formularioApp_id = formulario.id
																				AND	gestionLocal.codigo = 'LOCAL'
																				AND gestionLocal.idItem	= local.id
																				AND gestionLocal.activo = 1
					WHERE formulario.activo = 1
					AND	formulario.idTipoFormulario IN (2, 4)
					$conMes
					$conFechas
					GROUP BY 	formulario.id
					ORDER BY	formulario.id";

		/*$qTotal = consulta($sOptimo);

		for($i=0; $i<count($qTotal); $i++)
		{
			$salida["finalizadasOptimo"][$i]["finalizado"] = $qTotal[$i]["nFinalizadas"];
			$salida["finalizadasOptimo"][$i]["id"] = $qTotal[$i]["id"];
		}*/

		$sCampaing = "SELECT formulario.id, formulario.nombre, 
		formulario.vigenteDesde, formulario.vigenteHasta, 
		formulario.sinMaterial, formulario.sinStock, 
		TIMESTAMPDIFF(DAY, vigenteDesde, formulario.vigenteHasta) as diasDuracion,
		CASE
			WHEN formulario.vigenteHasta >  '$diahoy' THEN
				TIMESTAMPDIFF(DAY, '$diahoy', formulario.vigenteHasta) 
		    ELSE
				0
		END
		as diasTermino			
		FROM formularioApp as formulario 
		INNER JOIN formularioAppGestiona as gestionLocal ON gestionLocal.formularioApp_id = formulario.id 
		AND gestionLocal.codigo = 'LOCAL' INNER JOIN local ON gestionLocal.idItem = local.id 
			WHERE formulario.activo = 1 AND formulario.idTipoFormulario = 2 AND formulario.estado_id = $estado 
			$conMes
			$conFechas	
			$conVisible		 
				GROUP BY formulario.id, formulario.nombre, formulario.vigenteHasta 
				ORDER BY formulario.id";

		//$sCampaing .= ' LIMIT 1';
		$cCamp = consulta($sCampaing);	
		
		$i=0;
		foreach($cCamp as $ca){		
			$matValProp = 0;
			$matValProp = getMaterialsValorPropuesto($ca["id"]);
			$getSalasProg = getSalasProgramadas($ca["id"]);
			$getIndicadoresByCampaing = getIndicadoresByCampaing($ca["id"]);

			$nVisitadas = 0;
			$nImplementadas = 0;
			$nTotalProgramadas = 0;
			$matValImpl = 0;
			

			foreach($getIndicadoresByCampaing as $vi){
				
				if(strtolower($vi["valor"]) == strtolower('no, hay otro tipo de exhibicion')
				|| strtolower($vi["valor"]) == strtolower('si') || strtolower($vi["valor"]) == strtolower('no, no permitieron') 
				|| strtolower($vi["valor"]) == strtolower('no, sin productos')){
				 $nVisitadas++; 				 
			 	}
				if(strtolower($vi["valor"]) == strtolower('si')){
				  $nImplementadas++; 				  
				}								

				if(strtolower($vi["pregunta"]) == "materiales" && is_numeric($vi["valor"])){
					$matValImpl= $matValImpl+$vi["valor"];
				}	
			}
			$i++;
									
			$item = [
				'id' => $ca["id"],
				'totalvisitadas' => $nVisitadas,
				'totalimplementadas' => $nImplementadas,
				'totalprogramadas' => $getSalasProg,
				'diastermino' => $ca["diasTermino"],
				'matValorPropuesto' => $matValProp,
				'matValorImpl' => $matValImpl
			];

			$salida['indicadores'][] = $item;
			
			$nVisitadas = 0;
			$nImplementadas = 0;
			$nTotalProgramadas = 0;		
			$getSalasProg = 0;			
		}
		
	}

	function getIndicadoresByCampaing($form) {
		$sIndicadores = "SELECT DISTINCT formulario.nombre as nombreFormulario,
		formulario.id as idFormulario,		        
		local.id as idLocal,
		local.numero_local as numeroLocal,		
		DATE_FORMAT(visita.fechaCreacion, '%d/%m/%Y') as fechaVisita,
		DATE_FORMAT(visita.fechaCreacion, '%H:%i:%s') as horaVisita,				
		usuario.id as usuarioId,				
		visita.id as idVisita,		
		pregunta.titulo as pregunta,		
		elemento.codigo as codigo,
		CASE WHEN
		elemento.codigo IN ('LIST') THEN opcionTxt.nombre ELSE lista.id
		END
		as opcion,
		CASE
		WHEN elemento.codigo IN ('LISTM', 'LISTM2', 'OPC', 'OPCU', 'COMBO') THEN lista.nombre	
		WHEN elemento.codigo = 'DICO' AND detalle.valor = 1 THEN 'Si'
		WHEN elemento.codigo = 'DICO' AND detalle.valor = 0 THEN 'No'
		ELSE detalle.valor
		END
		as valor,
		CASE WHEN visita.idEstado IS NULL THEN 0 ELSE visita.idEstado END as idEstadoVisita,
		CASE WHEN visita.idEstado IS NULL THEN 'No Iniciado' WHEN visita.idEstado = 1 THEN 'En Proceso' WHEN visita.idEstado = 2 THEN 'Finalizado' ELSE 'No Iniciado' END as estado,		
		CASE
			WHEN visita.idEstado IS NULL THEN 0
			WHEN visita.idEstado = 1 THEN 1
			WHEN visita.idEstado = 2 THEN 2
		END as ordenEstado
			FROM formularioAppGestiona as localEle
				INNER JOIN formularioApp as formulario ON formulario.id = localEle.formularioApp_id 
				AND idTipoFormulario != 3
				INNER JOIN formularioAppGestiona as usuarioAsignado ON usuarioAsignado.formularioApp_id = formulario.id
				AND usuarioAsignado.codigo = 'MERCHAN'
				AND usuarioAsignado.idPadre = localEle.id
				INNER JOIN local ON  localEle.idItem = local.id 
				INNER JOIN usuario ON usuarioAsignado.idItem = usuario.id AND usuario.activo = 1
				LEFT JOIN visitaHead as visita ON visita.formularioApp_id = localEle.formularioApp_id
				AND visita.activo = 1 AND visita.usuario_id NOT IN (1)
				AND visita.usuario_id = usuario.id
				AND visita.local_id = local.id		
				LEFT JOIN visitaDetalle as detalle ON detalle.visitaHead_id = visita.id AND detalle.activo = 1
				LEFT JOIN elementosFormularioLista as lista ON detalle.valor = lista.id
				LEFT JOIN formularioAppElementos as pregunta ON detalle.pregunta_id = pregunta.id                 
				LEFT JOIN formularioAppElementosOpciones as opcion ON opcion.formularioAppElementos_id = pregunta.id
				AND opcion.formularioApp_id = formulario.id
				LEFT JOIN elementosFormularioLista as opcionTxt ON opcionTxt.id = detalle.opcion_id
				LEFT JOIN elementosFormulario as elemento ON opcion.elementosFormulario_id = elemento.id
				AND elemento.activo = 1			
					WHERE localEle.codigo = 'LOCAL' AND localEle.formularioApp_id = formulario.id AND localEle.activo = 1
					AND formulario.id = $form				
					GROUP BY formulario.nombre,
					local.numero_local,
					visita.id,
					pregunta.titulo,
					opcion,
					detalle.id
					ORDER BY formulario.nombre,
					ordenEstado,
					visita.fechaCreacion, horaVisita,		
					visita.id,
					pregunta.id";
			
			$vTotal = consulta($sIndicadores);
			return $vTotal;			
	}

	function getSalasProgramadas($idformulario){
		$formulario = '';
		$vp = 0;

		if (isset($idformulario))
			$formulario = " AND loc.formularioApp_id =  $idformulario";

		$query = "SELECT count(*) 
		FROM formularioAppGestiona as loc
		INNER JOIN formularioApp as formulario ON formulario.id = loc.formularioApp_id 
		AND idTipoFormulario != 3
		INNER JOIN formularioAppGestiona as usua ON usua.formularioApp_id = formulario.id
		AND usua.codigo = 'MERCHAN'
		AND usua.idPadre = loc.id
		where loc.codigo = 'LOCAL' AND loc.activo = 1 $formulario
		group by 
		loc.idItem;";
		
		return count(consulta($query));
	}

	function getMaterialsValorPropuesto ($idformulario) {

		$formulario = '';
		$vp = 0;

		if (isset($idformulario))
			$formulario = " AND loc.formularioApp_id =  $idformulario";
			
		$query = "SELECT SUM(matvalor.valor) as valorPropuesto       
			FROM formularioAppGestiona as loc
			INNER JOIN formularioAppGestiona as material ON loc.id = material.idPadre 
			INNER join formularioAppGestiona as matvalor ON matvalor.idPadre = material.id
				WHERE material.activo = 1 AND material.codigo = 'MATERIAL' $formulario";
		$vpro = consulta($query);
		foreach($vpro as $v){
			$vp=$v['valorPropuesto'];
		}
		return $vp;
		
	}

	if($ws == "creaFormulario")
	{
		$nombreFormulario 	= $_REQUEST["nombreFormulario"];
		$tipoFormulario 	= $_REQUEST["tipoFormulario"];
		$fechaDesde 		= $_REQUEST["fechaDesde"];
		$fechaHasta 		= $_REQUEST["fechaHasta"];
		$fechaDesdeImp 		= $_REQUEST["fechaDesdeImp"];
		$fechaHastaImp 		= $_REQUEST["fechaHastaImp"];
		$colorBoton 		= $_REQUEST["colorBoton"];
		$iconoBoton 		= $_REQUEST["iconoBoton"];
		$merchandisings	 	= $_REQUEST["merchandisings"];
		$idUsuario		 	= $_REQUEST["idUsuario"];
		$idCategoria	 	= $_REQUEST["idCategoria"];
		$idActividad		= $_REQUEST["idActividad"];
		$formVisible		= $_REQUEST["formVisible"];
		$novisibleweb		= $_REQUEST["novisibleweb"];
		$activo				= 1;

		if($nombreFormulario!="")
		{
			//$s = "INSERT INTO formularioApp SELECT null, '$nombreFormulario', '', 1, '$fechaDesde', '$fechaHasta', '$fechaDesdeImp', '$fechaHastaImp', 'FORM-$idUsuario-".date('YmdHis')."', '$colorBoton', '$iconoBoton', 1, now(), now(), $tipoFormulario, $idUsuario, $idCategoria, 1, $idActividad, 0, uuid(), $formVisible, 0;";			
			$s = ("INSERT INTO formularioApp (nombre, icono, orden, vigenteDesde, vigenteHasta, fechaInicioImpl, fechaFinImpl, codigo, claseColor, claseIcono, activo, fechaCreacion, fechaModificacion, idTipoFormulario, idUsuario, categoria_id, estado_id, actividad_id, sinMaterial, token, visible, sinStock, novisibleweb) VALUES ('$nombreFormulario', '', 1, '$fechaDesde', '$fechaHasta', '$fechaDesdeImp', '$fechaHastaImp', 'FORM-$idUsuario-".date('YmdHis')."', '$colorBoton', '$iconoBoton', 1, now(), now(), $tipoFormulario, $idUsuario, $idCategoria, 1, $idActividad, 0, uuid(), $formVisible, 0 ,$novisibleweb)");
				   
			mysql_query($s);
			$idForm = mysql_insert_id();			
		}

		if(isset($_FILES['archivoExcelForm_1']) && is_uploaded_file($_FILES['archivoExcelForm_1']['tmp_name']))
		{
			//upload directory
			$upload_dir = "csv_dir/";

			//create file name
			$file_path = $upload_dir . date("d-m-Y-h_i_s")."-".$_FILES['archivoExcelForm_1']['name'];
			
			//move uploaded file to upload dir
			if (!move_uploaded_file($_FILES['archivoExcelForm_1']['tmp_name'], $file_path))
			{
				//error moving upload file
				//echo "<script>alert('Error al subir el archivo.')<script>";
			}
			else
			{

				//open the csv file for reading
				$handle = fopen($file_path, 'r');
				$i = 0;
				$exito = 0;

				$cargaPreguntas = 0;
				$cargaOpciones = 0;
				$cargaLocales = 0;
				$cargaRegiones = 0;

				while ($data = fgetcsv($handle, 1000, ';'))
				{

					//echo utf8_encode(ltrim(rtrim($data[0])));

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOPREGUNTAS")
					{
						$cargaPreguntas = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINPREGUNTAS")
					{
						$cargaPreguntas = 0;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOOPCIONES")
					{
						$cargaOpciones = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINOPCIONES")
					{
						$cargaOpciones = 0;
					}

					//EN ESTE CASO LA CARGA DE PREGUNTAS SE UTILIZA PARA CREAR LAS OPCIONES COMO LISTA
					if($cargaPreguntas == 1)
					{
						$codigoPregunta = utf8_encode($data[3]);
						$codigoTipo = $data[4];

						if($codigoPregunta != "Código Pregunta")
						{
							$sLista = "INSERT INTO elementosFormularioLista SELECT null, '$codigoPregunta', 0, null, null, null, 1, now(), now(), null, null;";

							$qLista = consulta($sLista);

						}
					}

					$orden = 0;

					if($cargaOpciones == 1)
					{
						$orden = $data[1];
						$codigoPregunta = $data[3];
						$nombreOpcion = utf8_encode($data[2]);
						$valorDefecto = $data[4];
						$codigoOpcion = "";
						$idDependencia = "0";

						if($nombreOpcion != "Opcion")
						{

							if($data[5]!="")
							{
								$codigoOpcion = $data[5];
								//echo("data[5] codigoOpción: ".$codigoOpcion);
							}

							if($data[6]!="")
							{
								$idDependencia = "(SELECT id FROM elementosFormularioLista WHERE codigo = '".$data[6]."' ORDER BY fechaCreacion DESC limit 1)";
								//echo("idDependencia - data[6]: ".$idDependencia);
							}

							$sLista = "INSERT INTO elementosFormularioLista SELECT null, '$nombreOpcion', '$orden', (SELECT id FROM elementosFormularioLista WHERE nombre = '$codigoPregunta' ORDER BY fechaCreacion DESC limit 1), 0, '$valorDefecto', 1, now(), now(), '$codigoOpcion', $idDependencia;";

							//echo("insertando en elementosFormularioLista: ".$sLista." \r\n");

							$qLista = consulta($sLista);
						}
					}
				}
				fclose($handle);

				$handle = fopen($file_path, 'r');
				$i = 0;

				while ($data = fgetcsv($handle, 1000, ';'))
				{
					//var_dump($data);
					//echo utf8_encode(ltrim(rtrim($data[0])));

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOPREGUNTAS")
					{
						$cargaPreguntas = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINPREGUNTAS")
					{
						$cargaPreguntas = 0;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOOPCIONES")
					{
						$cargaOpciones = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINOPCIONES")
					{
						$cargaOpciones = 0;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOLOCALES")
					{
						$cargaLocales = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINLOCALES")
					{
						$cargaLocales = 0;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOREGIONES")
					{
						$cargaRegiones = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINREGIONES")
					{
						$cargaRegiones = 0;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "INICIOMATERIALES")
					{
						$cargaMateriales = 1;
					}

					if(utf8_encode(ltrim(rtrim($data[0]))) == "FINMATERIALES")
					{
						$cargaMateriales = 0;
					}

					$orden = 0;
					if($cargaPreguntas == 1)
					{
						$orden = $data[1];
						$pregunta = utf8_encode(ltrim(rtrim($data[2])));
						$codigoPregunta = $data[3];
						$tipoPregunta = $data[4];

						$codigoOpcion = null;
						$dependeDe = null;


						$min = 0;
						if($data[5]!="")
						{
							$min = $data[5];
						}

						$max = 0;

						if($data[6] != "")
						{
							$max = $data[6];
						}

						$idPreguntaPadre = "''";

						if($data[7] != "")
						{
							$idPreguntaPadre = "(SELECT id FROM formularioAppElementos WHERE codigo = '".$data[7]."' ORDER by fechaCreacion DESC limit 1)";
						}

						//echo $pregunta;

						if($pregunta != "Pregunta" and $codigoPregunta != "" and $tipoPregunta != "")
						{

							$sPregunta = "INSERT INTO formularioAppElementos SELECT null, '$pregunta', $idForm, '', '$min', '$max', '$orden', '$codigoPregunta', 1, now(), now(), $idPreguntaPadre;";
							//echo $sPregunta." \r\n";
							//echo("insertado en formularioAppElementos: ".$sPregunta." \r\n");
							mysql_query($sPregunta);

							$idElemento = mysql_insert_id();
							$lista = "null";

							if($tipoPregunta != "DICO" or $tipoPregunta != "NOTA")
							{
								$lista = "(SELECT id FROM elementosFormularioLista WHERE nombre = '$codigoPregunta' ORDER BY fechaCreacion DESC limit 1)";
							}

							$sLista = "INSERT INTO formularioAppElementosOpciones SELECT null, '', $idForm, $idElemento, '', 1, $min, $max, 1, null, (SELECT id FROM elementosFormulario WHERE codigo = '$tipoPregunta'), $lista, 1, now(), now();";

							$qLista = consulta($sLista);
						}
					}

					if($cargaLocales == 1)
					{
						$soldto 	= ltrim(rtrim($data[1]));
						$merchan = strtolower(ltrim(rtrim($data[2])));

						$fecha = strtolower(ltrim(rtrim($data[3])));
						$horaDesde = strtolower(ltrim(rtrim($data[4])));
						$horaHasta = strtolower(ltrim(rtrim($data[5])));

						if($soldto != "")
						{
							$sGestiona = "INSERT INTO formularioAppGestiona SELECT null, 'LOCAL', 1, now(), now(), $idForm, local.id, null, null FROM local WHERE numero_local = '$soldto';";

							//echo $sGestiona." \r\n";

							mysql_query($sGestiona);

							$idLocalPadre = mysql_insert_id();

							$sGestionaUsu = "INSERT INTO formularioAppGestiona SELECT null, 'MERCHAN', 1, now(), now(), $idForm, usuario.id, $idLocalPadre, null FROM usuario WHERE rut = '$merchan';";
							//echo $sGestionaUsu;
							//echo $sGestionaUsu." \r\n";
							consulta($sGestionaUsu);

							if($fecha != "")
							{
								$sGestionaFechaDesde = "INSERT INTO formularioAppGestiona SELECT null, 'FECDESDE', 1, now(), now(), $idForm, null, $idLocalPadre, '".$fecha."T".$horaDesde."';";
								consulta($sGestionaFechaDesde);

								$sGestionaFechaHasta = "INSERT INTO formularioAppGestiona SELECT null, 'FECHASTA', 1, now(), now(), $idForm, null, $idLocalPadre, '".$fecha."T".$horaHasta."';";
								consulta($sGestionaFechaHasta);

							}
						}
					}

					if($cargaMateriales == 1)
					{
						$soldto = ltrim(rtrim($data[1]));
						$material 	= utf8_encode(ltrim(rtrim($data[2])));
						$cantidad	= utf8_encode(ltrim(rtrim($data[3])));

						if($soldto != "")
						{
							$sGestiona = "SELECT id FROM formularioAppGestiona WHERE formularioApp_id = $idForm AND idItem = (SELECT id FROM local WHERE numero_local = '$soldto' limit 1) AND codigo = 'LOCAL';";
							//echo $sGestiona." \r\n";

							$local = consulta($sGestiona);
							$idLocalPadre = $local[0]["id"];

							$sGestionaMat = "INSERT INTO formularioAppGestiona SELECT null, 'MATERIAL', 1, now(), now(), $idForm, null, $idLocalPadre, '$material';";
							//echo $sGestionaMat."\r\n";

							mysql_query($sGestionaMat);

							$idMaterialPadre = mysql_insert_id();

							$sGestionaMatVal = "INSERT INTO formularioAppGestiona SELECT null, 'MATVALOR', 1, now(), now(), $idForm, (SELECT id FROM elementosFormularioLista WHERE TRIM(nombre) = TRIM('$material') ORDER BY fechaCreacion DESC limit 1), $idMaterialPadre, '$cantidad';";
							//echo $sGestionaMatVal."\r\n";

							consulta($sGestionaMatVal);
						}
					}

					if($cargaRegiones == 1)
					{
						$region = ltrim(rtrim($data[1]));
						$estadoregion = ltrim(rtrim($data[2]));

						if($region != "")
						{
							if($estadoregion == "SI")
							{
								$sGestiona = "INSERT INTO formularioAppGestiona SELECT null, 'REGION', 1, now(), now(), $idForm, loc_region.id, null, null FROM loc_region WHERE codigo = '$region';";
								//echo $sGestionaUsu." \r\n";
								consulta($sGestiona);
							}
						}
					}
					/*
					if($i != 0)
					{
						$soldto			= strtoupper(utf8_encode(ltrim(rtrim($data[0]))));
						$descripcion	= ucwords(strtolower(utf8_encode(ltrim(rtrim($data[1])))));
						$direccion		= ucwords(strtolower(utf8_encode(ltrim(rtrim($data[2])))));
					}
					*/
				}
				fclose($handle);
			}
		}

		/*
		$arrMechans = explode(",", $merchandisings);

		for($i=0; $i<count($arrMechans); $i++)
		{
			$idMerc = $arrMechans[$i];
			$sMerc = "INSERT INTO formularioAppGestiona SELECT null, 'MERCHAN', 1, now(), now(), $idForm, $idMerc";

			consulta($sMerc);
		}
		*/

		$salida = "OK";
		//echo($salida);

	}

	if($ws=="getLocal2")
	{
		$solto	=	$_REQUEST["solto"];

		$s ="select l.id, l.descripcion as nombre, l.direccion, l.numero_local, c.descripcion as comuna, r.descripcion as region, l.id_canal, n.descripcion as canal, COALESCE(l.telefono, '-') as telefono,
			CASE WHEN s.color IS NULL OR s.color = '' THEN
				'#fff'
			ELSE
				s.color
			END as color,
			'' as vendedor
										from local l
										inner join loc_comuna 			c on c.id = l.id_comuna
										inner join loc_region 			r on c.id_region = r.id
										inner join canal				n on l.id_canal  = n.id
										inner join local_clasificacion	s on l.id_clasificacion = s.id
										WHERE l.numero_local	=	'$solto';";
		
		$salida["local"]	=	consulta($s);

	}

if($ws == "saveCampana") {
    if(isset($_REQUEST["idCampana"])) {
        $id = $_REQUEST["idCampana"];
        $fechaInicio = $_REQUEST["fechaInicio"];
        $fechaFin = $_REQUEST["fechaFin"];
        $fechaInicioImpl = $_REQUEST["fechaInicioImpl"];
        $fechaFinImpl = $_REQUEST["fechaFinImpl"];
        $idCategoria = $_REQUEST["idCategoria"];
        $idActividad = $_REQUEST["idActividad"];
        $nombre = $_REQUEST["nombre"];
        $idEstado = $_REQUEST["idEstado"];
        $urlBi = $_REQUEST["urlBi"];

        // Subir la imagen si se selecciona
        if(isset($_FILES["fotoLogo"]) && $_FILES["fotoLogo"]["error"] == 0) {
            $targetDir = "campaignPics/";
            $fileName = basename($_FILES["fotoLogo"]["name"]);
            $targetFilePath = $targetDir . $fileName;
            $fileType = pathinfo($targetFilePath, PATHINFO_EXTENSION);

            // Permitir solo ciertos formatos de archivo
            $allowTypes = array('jpg', 'png', 'jpeg', 'gif');
            if(in_array($fileType, $allowTypes)) {
                // Subir archivo
                if(move_uploaded_file($_FILES["fotoLogo"]["tmp_name"], $targetFilePath)) {
                    // URL de la imagen
                    $urlF = $targetFilePath;
                } else {
                    // Error al subir el archivo
                    $salida = "Error al subir el archivo";
                    exit();
                }
            } else {
                // Tipo de archivo no permitido
                $salida = "Solo se permiten archivos JPG, JPEG, PNG, GIF";
                exit();
            }
        }

        // Construir la consulta SQL
        $sUpd = "UPDATE formularioApp SET url_bi = '$urlBi', vigenteDesde = '$fechaInicio', vigenteHasta = '$fechaFin', fechaInicioImpl = '$fechaInicioImpl', fechaFinImpl = '$fechaFinImpl', categoria_id = $idCategoria, nombre = '$nombre', actividad_id = $idActividad, estado_id = $idEstado";
        if(isset($urlF)) {
            // Si se subió una imagen, incluir la columna fotoLogo en la actualización
            $sUpd .= ", fotoLogo = '$urlF'";
        }
        $sUpd .= " WHERE id = $id";

        // Actualizar la base de datos
        consulta($sUpd);

        // Respuesta exitosa
        $salida = "OK";
    } else {
        // Error, no se proporcionó el ID de la campaña
        $salida = "Error, no se proporcionó el ID de la campaña";
    }
}

	if($ws == "desactivarCampana")
	{
		if(isset($_REQUEST["idCampana"]))
		{
			$id = $_REQUEST["idCampana"];
			$idEstado = $_REQUEST["idEstado"];


			$sUpd = "UPDATE formularioApp SET activo = $idEstado, fechaModificacion = now() WHERE id = $id;";
			//echo $sUpd;
			//return false;
			mysql_query($sUpd);

		}

		$salida = "OK";
	}

	if($ws == "cambiaEstadoCampana")
	{
		if(isset($_REQUEST["idCampana"]))
		{
			$id = $_REQUEST["idCampana"];
			$idEstado = $_REQUEST["idEstado"];

			$sUpd = "UPDATE formularioApp SET estado_id = $idEstado, fechaModificacion = now() WHERE id = $id;";
			//echo $sUpd;
			//return false;
			mysql_query($sUpd);

		}

		$salida = "OK";
	}

	if($ws=="saveLocal")
	{
		$numeroLocal = trim($_REQUEST["numeroLocal"]);
		$nombreLocal = trim($_REQUEST["nombreLocal"]);
		$direccionLocal = trim($_REQUEST["direccionLocal"]);
		$nombreLocal = trim($_REQUEST["nombreLocal"]);
		$comunaLocal = trim($_REQUEST["comunaLocal"]);
		$idCadena = $_REQUEST["cadenaLocal"];
		$idCuenta = $_REQUEST["cuentaLocal"];
		$idZonal = $_REQUEST["zonalLocal"];

		$salida[0]["mensaje"]["codigo"] = "OK";
		$salida[0]["mensaje"]["titulo"] = "Guardado";
		$salida[0]["mensaje"]["mensaje"] = "Local creado Correctamente";
		$salida[0]["mensaje"]["tipo"] = "success";



		$sLocal = "SELECT * FROM local WHERE numero_local = '$numeroLocal' AND activo = 1;";

		$qLocal = consulta($sLocal);

		if(count($qLocal) != 0)
		{
			$salida[0]["mensaje"]["codigo"] = "ERROR";
			$salida[0]["mensaje"]["titulo"] = "Error";
			$salida[0]["mensaje"]["mensaje"] = "Este número de local ya existe";
			$salida[0]["mensaje"]["tipo"] = "error";
		}
		else
		{
			$sCreaLocal = "INSERT INTO local SELECT null, '$nombreLocal', '$direccionLocal', '$numeroLocal', $idCadena, $idCuenta, 1, '$comunaLocal', '', '', 1, 1, '', $idZonal, '', '$numeroLocal', now(), now(), null;";
			//echo $sCreaLocal;

			$qLocal = mysql_query($sCreaLocal);
		}
	}

	if($ws=="newSaveLocal")
	{
		
		$errors = "";
		$mensaje = "OK";

		$idLocal = $_REQUEST["idLocal"];
		$numeroLocal = trim($_REQUEST["numeroLocal"]);
		$telefonoLocal = trim($_REQUEST["telefonoLocal"]);
		$direccionLocal = trim($_REQUEST["direccionLocal"]);
		$nombreLocal = trim($_REQUEST["nombreLocal"]);
		$idregion = $_REQUEST["regionLocal"];		
		$idComuna = $_REQUEST["comunaLocal"];
		$idCadena = $_REQUEST["cadenaLocal"];
		$idCuenta = $_REQUEST["cuentaLocal"];
		$idZonal = $_REQUEST["zonalLocal"];		
		$latLocal = trim($_REQUEST["latLocal"]);
		$longLocal = trim($_REQUEST["longLocal"]);
				

		if($numeroLocal == null || $numeroLocal =="" || $numeroLocal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El número de Local no está definido o no está llegando correctamente.";
		}else if(strlen($numeroLocal) > 20){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El número de Local posee más de 20 carácteres.";
		}

		if($nombreLocal == null || $nombreLocal =="" || $nombreLocal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El nombre de Local no está definido o no está llegando correctamente.";
		}
				
		if(strlen($direccionLocal) > 300){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."La Dirección del Local posee más de 300 carácteres.";
		}
		
		if(strlen($telefonoLocal) > 50){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El teléfono del Local posee más de 50 carácteres.";
		}
		
		if($idregion == 0 || $idregion == null || $idregion =="" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Región no está llegando correctamente, verifique.";
		}

		if($idComuna == 0 || $idComuna == null || $idComuna =="" || $idComuna == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Comuna no está llegando correctamente, verifique.";
		}

		if($idCadena == 0 || $idCadena == null || $idCadena =="" || $idCadena == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Cadena no está llegando correctamente, verifique.";
		}

		if($idCuenta == 0 || $idCuenta == null || $idCuenta =="" || $idCuenta == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Cuenta no está llegando correctamente, verifique.";
		}

		if($idZonal == 0 || $idZonal == null || $idZonal =="" || $idZonal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Zona no está llegando correctamente, verifique.";
		}
				
				
		if(!isset($_REQUEST["comunaLocal"]) || !isset($_REQUEST["nombreLocal"]) || !isset($_REQUEST["numeroLocal"]) || 
			!isset($_REQUEST["regionLocal"]) || !isset($_REQUEST["cadenaLocal"]) || !isset($_REQUEST["cuentaLocal"])
			|| !isset($_REQUEST["direccionLocal"]) || !isset($_REQUEST["cuentaLocal"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){				
				try{

					$sLocal = "SELECT id,activo FROM local WHERE numero_local = '$numeroLocal';";

					$qLocal = consulta($sLocal);
					
					if(count($qLocal) != 0)
					{
						$salida["codigo"] = "ERROR";
						
						if($qLocal[0]["activo"] == 0){
							$salida["mensaje"] = "Este número de local ya existe, pero está inactivo, vuelva a activarlo y modifique sus datos";
						}else{
							$salida["mensaje"] = "Este número de local ya existe";
						}						
					}
					else
					{
						
						$s = ("INSERT INTO local (descripcion, direccion, numero_local, id_cadena, id_cuenta, id_canal, id_comuna,
						  latitud, longitud, activo, id_clasificacion, telefono, id_zonal, modelo_cafetera, rut_local, fechaCreacion,
						  fechaModificacion,latLng) 
						VALUES ('$nombreLocal', '$direccionLocal', '$numeroLocal', $idCadena, $idCuenta, 1, $idComuna,'$latLocal','$longLocal',1,1,'$telefonoLocal',$idZonal,1,'$numeroLocal',now(),now(),null)");								

						$q	=	mysql_query($s);
						
						$idlocal = mysql_insert_id();					
						
						if($idlocal > 0){
							$salida["idlocal"] = $idlocal;
							$salida["mensaje"] = "Felicidades! el Local ".$nombreLocal." ha sido guardado con éxito.";
							$salida["codigo"] = $mensaje;
						}						
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al insertar el local : ".$nombreLocal;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		
	}

	if($ws=="newUpdateLocal")
	{
		
		$errors = "";
		$mensaje = "OK";

		$idLocal = $_REQUEST["idLocal"];
		$numeroLocal = trim($_REQUEST["numeroLocal"]);
		$telefonoLocal = trim($_REQUEST["telefonoLocal"]);
		$direccionLocal = trim($_REQUEST["direccionLocal"]);
		$nombreLocal = trim($_REQUEST["nombreLocal"]);
		$idregion = $_REQUEST["regionLocal"];		
		$idComuna = $_REQUEST["comunaLocal"];
		$idCadena = $_REQUEST["cadenaLocal"];
		$idCuenta = $_REQUEST["cuentaLocal"];
		$idZonal = $_REQUEST["zonalLocal"];		
		$latLocal = trim($_REQUEST["latLocal"]);
		$longLocal = trim($_REQUEST["longLocal"]);
		

		if($numeroLocal == null || $numeroLocal =="" || $numeroLocal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El número de Local no está definido o no está llegando correctamente.";
		}else if(strlen($numeroLocal) > 20){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El número de Local posee más de 20 carácteres.";
		}

		if($nombreLocal == null || $nombreLocal =="" || $nombreLocal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El nombre de Local no está definido o no está llegando correctamente.";
		}
				
		if(strlen($direccionLocal) > 300){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."La Dirección del Local posee más de 300 carácteres.";
		}
		
		if(strlen($telefonoLocal) > 50){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El teléfono del Local posee más de 50 carácteres.";
		}
		
		if($idLocal == 0 || $idLocal == null || $idLocal =="" || $idLocal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id del local no está llegando correctamente, verifique.";
		}

		if($idregion == 0 || $idregion == null || $idregion =="" || $idregion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Región no está llegando correctamente, verifique.";
		}

		if($idComuna == 0 || $idComuna == null || $idComuna =="" || $idComuna == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Comuna no está llegando correctamente, verifique.";
		}

		if($idCadena == 0 || $idCadena == null || $idCadena =="" || $idCadena == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Cadena no está llegando correctamente, verifique.";
		}

		if($idCuenta == 0 || $idCuenta == null || $idCuenta =="" || $idCuenta == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Cuenta no está llegando correctamente, verifique.";
		}

		if($idZonal == 0 || $idZonal == null || $idZonal =="" || $idZonal == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la Zona no está llegando correctamente, verifique.";
		}
								
		if(!isset($_REQUEST["comunaLocal"]) || !isset($_REQUEST["nombreLocal"]) || !isset($_REQUEST["numeroLocal"]) || 
			!isset($_REQUEST["regionLocal"]) || !isset($_REQUEST["cadenaLocal"]) || !isset($_REQUEST["cuentaLocal"])
			|| !isset($_REQUEST["direccionLocal"]) || !isset($_REQUEST["cuentaLocal"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){				
				try{

					$sLocal = "SELECT id,activo FROM local WHERE id = $idLocal;";

					$qLocal = consulta($sLocal);
					
					if(count($qLocal) == 0)
					{
						$salida["codigo"] = "ERROR";												
						$salida["mensaje"] = "Este local no existe... verifique sus datos";
						
					}
					else
					{
						$s = "UPDATE local SET descripcion = '$nombreLocal', direccion = '$direccionLocal', numero_local = '$numeroLocal',
												 id_cadena = $idCadena, id_cuenta = $idCuenta, id_comuna = $idComuna,
						  						 latitud = '$latLocal', longitud = '$longLocal', telefono = '$telefonoLocal',
												 id_zonal = $idZonal, rut_local = '$numeroLocal', fechaModificacion = now()
												 WHERE id = $idLocal;";
						
						$q	=	mysql_query($s);
												
						if(count($q) > 0){							
							$salida["mensaje"] = "Felicidades! el Local ".$nombreLocal." ha sido modificado con éxito.";
							$salida["codigo"] = $mensaje;
						}						
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al Modificar el local : ".$nombreLocal;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		
	}


	if($ws == "guardarLocal")
	{
		if(isset($_REQUEST["idLocal"]))
		{
			$nombre = $_REQUEST["nombreLocal"];
			$direccion = $_REQUEST["direccionLocal"];
			$direccion = str_replace("'", "''", $direccion);

			$idZonal = $_REQUEST["zonalLocal"];
			$id = $_REQUEST["idLocal"];

			$sUpd = "UPDATE local SET descripcion = '$nombre', direccion = '$direccion' WHERE id = $id;";
			//echo $sUpd;
			//return false;
			mysql_query($sUpd);

			if($idZonal!="")
			{
				$sUpd = "UPDATE local SET id_zonal = $idZonal WHERE id = $id;";
			}
			//echo $sUpd;
			//return false;
			mysql_query($sUpd);

		}

		$salida = "OK";
	}

	if($ws == "desactivarLocal")
	{
		if(isset($_REQUEST["idLocal"]))
		{
			$id = $_REQUEST["idLocal"];

			$sUpd = "UPDATE local SET activo = 0 WHERE id = $id;";
			//echo $sUpd;
			//return false;
			mysql_query($sUpd);

		}

		$salida = "OK";
	}

	if($ws == "getCuentas")
	{
		$id = $_REQUEST["idCadena"];

		$s = "	SELECT
				        cuenta.id,
				        cuenta.descripcion
				FROM 		cuenta
				INNER JOIN	local				ON	local.id_cuenta = cuenta.id	AND local.id_cadena = $id
				WHERE 		cuenta.activo = 1

				GROUP BY	cuenta.id,
				cuenta.descripcion";

		//echo $s;
		//die;
		$salida = consulta($s);

	}

	if($ws == "cambiaEstadoLocalCampana")
	{
		$id = $_REQUEST["id"];
		$estado = $_REQUEST["tipo"];

		$s = "UPDATE formularioAppGestiona SET activo = $estado WHERE id = $id;";

		mysql_query($s);

		//desactiva o cambia estado también donde codigo es MERCHAN, para conteo de salas activas
		//por merchan, para que las cuentas en la página usuario no iniciados, en proceso y finalizados cuadren
		$m = "UPDATE formularioAppGestiona SET activo = $estado
			  WHERE idPadre = $id and codigo = 'MERCHAN';";

		mysql_query($m);

		$salida = "OK";
	}

	if($ws == "modificaActividad")
	{
		$id = $_REQUEST["id"];
		$idVisita = $_REQUEST["idVisita"];
		$idMerchan = $_REQUEST["idMerchan"];
		$idEstado = $_REQUEST["idEstado"];
		$idLocalGestiona = $_REQUEST["idLocalGestiona"];

		$s = "
			UPDATE formularioAppGestiona SET idItem = $idMerchan WHERE id = $id;
		";

		mysql_query($s);

		if($idVisita != 0)
		{
			$s = "UPDATE visitaHead SET usuario_id = $idMerchan, idEstado = $idEstado WHERE id = $idVisita;";

			mysql_query($s);
		}

		if($_REQUEST["fechaInicio"] != "")
		{
			$valorFecha = $_REQUEST["fechaInicio"];
			$valorHora = formateaHora($_REQUEST["horaInicio"]);

			$s = "UPDATE formularioAppGestiona SET valor = '".$valorFecha."T".$valorHora."' WHERE idPadre = $idLocalGestiona AND codigo = 'FECDESDE'";
			//echo $s;
			//die;
			mysql_query($s);
		}

		if($_REQUEST["fechaFin"] != "")
		{
			$valorFecha = $_REQUEST["fechaFin"];
			$valorHora = formateaHora($_REQUEST["horaFin"]);

			$s = "UPDATE formularioAppGestiona SET valor = '".$valorFecha."T".$valorHora."' WHERE idPadre = $idLocalGestiona AND codigo = 'FECHASTA'";

			mysql_query($s);
		}


		$salida = "OK";
	}

	if($ws == "sinMaterial")
	{
		$id = $_REQUEST["idCampana"];
		$estado = $_REQUEST["idEstado"];

		$s = "UPDATE formularioApp SET sinMaterial = $estado WHERE id = $id;";

		mysql_query($s);

		$salida = "OK";
	}

	if($ws == "sinStock")
	{
		$id = $_REQUEST["idCampana"];
		$estado = $_REQUEST["idEstado"];

		$s = "UPDATE formularioApp SET sinStock = $estado WHERE id = $id;";

		mysql_query($s);

		$salida = "OK";
	}

	//se agrega condición para que verifique que valor del material sea numerico y distinto de -1 para poder guardar y actualizar los valores
	if($ws=="agregarLocalCampana")
	{
		//print_r($_REQUEST);
		//die;
		
		$idForm = $_REQUEST["idCampana"];
		$numeroLocal = $_REQUEST["localCampana"];
		$idMerchan = $_REQUEST["merchanCampana"];

		$sLocal = "SELECT id FROM local WHERE numero_local = '$numeroLocal';";
		$qLocal = consulta($sLocal);
		
		if($qLocal>0)
		{
			$sLocalCamp = "SELECT id FROM formularioAppGestiona WHERE idItem = '".$qLocal[0]["id"]."' AND formularioApp_id = $idForm AND codigo = 'LOCAL';";
			$qLocalCamp = consulta($sLocalCamp);
						
			if(count($qLocalCamp)==0)
			{
				$sInsLocal = "INSERT INTO formularioAppGestiona SELECT null, 'LOCAL', 1, now(), now(), $idForm, ".$qLocal[0]["id"].", null, null;";
				
				$qInsLocal = mysql_query($sInsLocal);

				$idGesLocal = mysql_insert_id();

				$qInsMerchan = "INSERT INTO formularioAppGestiona SELECT null, 'MERCHAN', 1, now(), now(), $idForm, $idMerchan, $idGesLocal, null;";
				mysql_query($qInsMerchan);

				for($i=0; $i<count($_REQUEST["idMaterialCampana"]); $i++)
				{
					$valor = $_REQUEST["material"][$i];
					if(is_numeric($valor) && $valor != "-1"){						
						$idMaterialPadre = 0;
						$sInsMaterial = "INSERT INTO formularioAppGestiona SELECT null, 'MATERIAL', 1, now(), now(), $idForm, NULL, $idGesLocal, '".$_REQUEST["nomMaterialCampana"][$i]."';";
						mysql_query($sInsMaterial);
	
						$idMaterialPadre = mysql_insert_id();
						
						$sInsMaterialVal = "INSERT INTO formularioAppGestiona SELECT null, 'MATVALOR', 1, now(), now(), $idForm, ".$_REQUEST["idMaterialCampana"][$i].", $idMaterialPadre, '".$_REQUEST["material"][$i]."';";
						mysql_query($sInsMaterialVal);	
					}
									
				}

				if($_REQUEST["fechaInicioNueva"])
				{
					
					$fechaNueva = $_REQUEST["fechaInicioNueva"];
					$horaNueva = formateaHora($_REQUEST["horaInicioNueva"]);

					$sInsLocal = "INSERT INTO formularioAppGestiona SELECT null, 'FECDESDE', 1, now(), now(), $idForm, null, $idGesLocal, '".$fechaNueva."T".$horaNueva."';";
					//echo $sInsLocal;
					$qInsLocal = mysql_query($sInsLocal);

					$fechaNueva = $_REQUEST["fechaFinNueva"];
					$horaNueva = formateaHora($_REQUEST["horaFinNueva"]);

					$sInsLocal = "INSERT INTO formularioAppGestiona SELECT null, 'FECHASTA', 1, now(), now(), $idForm, null, $idGesLocal, '".$fechaNueva."T".$horaNueva."';";
					//echo $sInsLocal;
					$qInsLocal = mysql_query($sInsLocal);										
				}
			}else{

				$idGesLocal = $qLocalCamp[0]["id"];
											
				//verificamos si el merchan es diferente y lo modificamos en ése caso
				$sMerchan = "SELECT idItem FROM formularioAppGestiona
				WHERE  formularioApp_id = $idForm AND codigo = 'MERCHAN' and idPadre = $idGesLocal";
				$qidMerchan = consulta($sMerchan);
	
				if(count($qidMerchan) > 0){
					$merchanVerificado = $qidMerchan[0]["idItem"];
					if($merchanVerificado != "" && $idMerchan != $merchanVerificado){
						$s = "UPDATE formularioAppGestiona SET idItem = $idMerchan
								WHERE  formularioApp_id = $idForm AND codigo = 'MERCHAN' and idPadre = $idGesLocal";								
						mysql_query($s);
					}
				}else{ //si el merchan no existe, lo agregamos
											
					$qInsMerchan = ("INSERT INTO formularioAppGestiona (codigo, activo, fechaCreacion, fechaModificacion, formularioApp_id, idItem, idPadre, valor) VALUES ('MERCHAN', 1, now(), now(), $idForm, $idMerchan, $idGesLocal, null)");																
					mysql_query($qInsMerchan);																		
				}
				
				if($_REQUEST["idMaterialCampana"]){
															
					for($i=0; $i<count($_REQUEST["idMaterialCampana"]); $i++)
					{
						$sMaterial = "SELECT id, valor as mat FROM formularioAppGestiona
										  WHERE  formularioApp_id = $idForm AND codigo = 'MATERIAL'
										  and idPadre = $idGesLocal";
						//SELECT * FROM formularioAppGestiona and trim(valor) = trim('".$_REQUEST["nomMaterialCampana"][$i]."')
						//WHERE formularioApp_id = 655 and codigo = 'MATERIAL' AND idPadre = 185778
						
						$qidMaterial = consulta($sMaterial);
						
						$idMaterial = $qidMaterial[$i]["id"];
						
						$valor = $_REQUEST["material"][$i];
						//VERIFICO SI EL MATERIAL ESTÁ AGREGADO
						if($idMaterial != ""){
							if(is_numeric($valor) && $valor != "-1"){	
								$sVerificaMaterial = "SELECT id FROM formularioAppGestiona
								WHERE  formularioApp_id = $idForm AND codigo = 'MATVALOR'
								and idItem = '".$_REQUEST["idMaterialCampana"][$i]."' and idPadre = $idMaterial";							
								$qVerificaMat = consulta($sVerificaMaterial);
								$idmatVerificado = $qVerificaMat[0]["id"];
								//echo('<br> modificado: '.'form: '.$idForm.' idMaterialCampana '.$_REQUEST["idMaterialCampana"][$i].'valor: '.$_REQUEST["material"][$i]);
								if(count($qVerificaMat)==0){
	
									$sInsMaterialVal = "INSERT INTO formularioAppGestiona SELECT null, 'MATVALOR', 1, now(), now(), $idForm, ".$_REQUEST["idMaterialCampana"][$i].",$idMaterial,'".$_REQUEST["material"][$i]."';";
									mysql_query($sInsMaterialVal);
								}else{
									$s = "UPDATE formularioAppGestiona SET valor = '".$_REQUEST["material"][$i]."'
									WHERE formularioApp_id = $idForm AND codigo = 'MATVALOR' and id = $idmatVerificado";									
									mysql_query($s);
								}
							}							
						}else{
							if(is_numeric($valor) && $valor != "-1"){	
								//echo('<br> insertado: '.'form: '.$idForm.' idMaterialCampana '.$_REQUEST["idMaterialCampana"][$i].'valor: '.$_REQUEST["material"][$i]);
								$idMaterialPadre = 0;
								$sInsMaterial = "INSERT INTO formularioAppGestiona SELECT null, 'MATERIAL', 1, now(), now(), $idForm, NULL, $idGesLocal, '".$_REQUEST["nomMaterialCampana"][$i]."';";
								mysql_query($sInsMaterial);
	
								$idMaterialPadre = mysql_insert_id();
								
								$sInsMaterialVal = "INSERT INTO formularioAppGestiona SELECT null, 'MATVALOR', 1, now(), now(), $idForm, ".$_REQUEST["idMaterialCampana"][$i].", $idMaterialPadre, '".$_REQUEST["material"][$i]."';";
								mysql_query($sInsMaterialVal);	
							}							
						}					
					}									
				}
			}
		}
		
		header("Location: UI_detalle_campana.php?idFormulario=$idForm");
				
	}


	if($ws=="guardaFormularioSimple")
	{
		//var_dump($_REQUEST);
		$idForm = $_REQUEST["idForm"];
		$numeroLocal = $_REQUEST["localCampana"];

		//echo(count($_REQUEST["idOpcion"]));
		//die;

		for($i=0; $i<count($_REQUEST["idOpcion"]); $i++)
		{
			//echo "UPDATE elementosFormularioLista SET idDependencia = ".$_REQUEST["idDependencia"][$i]." WHERE id = ".$_REQUEST["idOpcion"][$i]."; <br>";

			mysql_query("UPDATE elementosFormularioLista SET idDependencia = ".$_REQUEST["idDependencia"][$i]." WHERE id = ".$_REQUEST["idOpcion"][$i].";");
		}
		//echo $idForm;
		//die;
		header("Location: UI_detalle_form.php?idFormulario=$idForm");
		//die;
	}

	if($ws=="agregaLineaFormulario"){

		$maxcodi = "";
		$maxcodi2 = "";
		$maxorden = "";
		$rescodi;
		$etiq = "";
		$idPadre = 0;
		$idForm = $_REQUEST["idFormu"];
		$pregForm = $_REQUEST["preguntaForm"];
		$nombre = $_REQUEST["opcionForm"];
		$sformlista = "	SELECT
				        max(formlista.orden)	as maxorden,
								max(formlista.codigo) as maxcodigo

				FROM 		formularioApp as formulario
				INNER JOIN	formularioAppElementos ON	formularioAppElementos.formularioApp_id =	formulario.id
				INNER JOIN	formularioAppElementosOpciones ON	formularioAppElementosOpciones.formularioAppElementos_id	=	formularioAppElementos.id
				INNER JOIN	elementosFormularioLista as formlista ON	formularioAppElementosOpciones.idLista	= formlista.idPadre
				WHERE 		formulario.id = $idForm
				ORDER BY	formlista.nombre
				";

		$qTotal = consulta($sformlista);

		for($i=0; $i<count($qTotal); $i++)
		{
			$salida["formlista"][$i]["maxorden"] = $qTotal[$i]["maxorden"];
			$salida["formlista"][$i]["maxcodigo"] = $qTotal[$i]["maxcodigo"];
			$maxorden = $qTotal[$i]["maxorden"];
			$maxcodi = $qTotal[$i]["maxcodigo"];
			$rescodi = substr($maxcodi, 4) +1;
			$etiq = substr($maxcodi, 0,4);
			$maxcodi2 = $etiq."".$rescodi;
			$maxorden = $maxorden+1;
		}

		$sidPadre = "	SELECT idLista
									FROM formularioAppElementosOpciones as formop
									where formop.formularioApp_id = $idForm
									and formop.formularioAppElementos_id = $pregForm";

		$qidPadre = consulta($sidPadre);

		for($i=0; $i<count($qidPadre); $i++)
		{
			$idPadre = $qidPadre[$i]["idLista"];
		}


		$sLista = "INSERT INTO elementosFormularioLista SELECT null, '$nombre', $maxorden, $idPadre, 0, null, 1, now(), now(), '$maxcodi2', 0;";

		//echo $sLista." \r\n";

		$qLista = consulta($sLista);
		header("Location: UI_detalle_form.php?idFormulario=$idForm");
	}

	if($ws == "cambiaEstadoFilaFormulario")
	{
		$id = $_REQUEST["id"];
		$estado = $_REQUEST["tipo"];

		$s = "
			UPDATE elementosFormularioLista SET activo = $estado WHERE id = $id;
		";

		mysql_query($s);

		$salida = "OK";
	}

	if($ws == "getDatosUsuario")
	{
		$idUsuario = $_REQUEST["id"];

		$sDatos = "SELECT id, rut, fotoPerfil, nombre, apepat, apemat, telefono, id_perfil FROM usuario WHERE id = $idUsuario";
		$salida["datosUsuario"] = consulta($sDatos);
	}

	if($ws == "guardaDatosUsuario")
	{
		$idUsuario = $_REQUEST["idUsuario"];
		$nombreUsuario = $_REQUEST["nombreUsuario"];
		$apepatUsuario = $_REQUEST["apepatUsuario"];
		$apematUsuario = $_REQUEST["apematUsuario"];
		$telefonoUsuario = $_REQUEST["telefonoUsuario"];
		$perfilUsuario = $_REQUEST["perfilUsuario"];
		$rutUsuario = $_REQUEST["rutUsuario"];

		$sDatos = "UPDATE usuario SET nombre = '$nombreUsuario', apepat = '$apepatUsuario', apemat = '$apematUsuario', telefono = '$telefonoUsuario', id_perfil = $perfilUsuario, fechaModificacion = now()  WHERE id = $idUsuario";


		$uid = uniqid();
		if(isset($_FILES['fotoPerfilUsu']) && is_uploaded_file($_FILES['fotoPerfilUsu']['tmp_name']))
		{
			$archivo	=	"profilePics/".$rutUsuario."_".$uid.".jpg";

			if (!move_uploaded_file($_FILES['fotoPerfilUsu']['tmp_name'], $archivo))
			{
				//error moving upload file
				//echo "<script>alert('Error al subir el archivo.')<script>";
			}
			else
			{
				exec(chmod($archivo, 0777));
				$uDatos = "UPDATE usuario SET fotoPerfil = '$archivo', fechaModificacion = now() WHERE id = $idUsuario";
				consulta($uDatos);
			}
		}

		consulta($sDatos);

		$salida = "OK";
	}

	if($ws == "cargaUsuariosMasivo")
	{
		if(isset($_FILES['archivoUsuarios']) && is_uploaded_file($_FILES['archivoUsuarios']['tmp_name']))
		{
			//upload directory
			$upload_dir = "csv_dir/USU_";

			//create file name
			$file_path = $upload_dir . date("d-m-Y-h_i_s")."-".$_FILES['archivoUsuarios']['name'];

			//move uploaded file to upload dir
			if (!move_uploaded_file($_FILES['archivoUsuarios']['tmp_name'], $file_path))
			{
				//error moving upload file
				//echo "<script>alert('Error al subir el archivo.')<script>";
			}
			else
			{

					//encontrando delimitador usado en el archivo
					$delimiters = array(';',',');
					$delim = ";";
					$tipo_encode = "";
					for($i=0; $i<count($delimiters); $i++)
					{
						$handle = fopen($file_path, 'r');
						while ($data = fgetcsv($handle, 1000, $delimiters[$i]))
						{
							if(utf8_encode(ltrim(rtrim($data[0]))) == "Usuario")
							{
								$delim = $delimiters[$i];
							}
							if($i!=0){
								if(mb_detect_encoding(ltrim(rtrim($data[0])), 'UTF-8, ISO-8859-1') === 'UTF-8'){
									$tipo_encode = 'UTF-8';
								}elseif(mb_detect_encoding(ltrim(rtrim($data[0])), 'UTF-8, ISO-8859-1') === 'ISO-8859-1'){
									$tipo_encode = 'ISO-8859-1';
								}else{
									$tipo_encode = "0";
								}
								if($i>2) break;
							}
						}
						fclose($handle);
					}


				//open the csv file for reading
				$handle = fopen($file_path, 'r');
				$i = 0;
				$exito = 0;

				$mensaje = "OK";

				while ($data = fgetcsv($handle, 1000, $delim))
				{
					//var_dump($data);

					if($i!=0)
					{
						if($tipo_encode === 'UTF-8'){
							$usuario 	= ltrim(rtrim($data[0]));
							$clave   	= ltrim(rtrim($data[1]));
							$nombres 	= ltrim(rtrim($data[2]));
							$apepat 	= ltrim(rtrim($data[3]));
							$apemat	 	= ltrim(rtrim($data[4]));
							$telefono = ltrim(rtrim($data[5]));
							$perfil 	= ltrim(rtrim($data[6]));
						}elseif($tipo_encode === 'ISO-8859-1'){
							$usuario 	= utf8_encode(ltrim(rtrim($data[0])));
							$usuario 	= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($usuario,'ISO-8859-1', 'UTF-8')));
							$clave 		= utf8_encode(ltrim(rtrim($data[1])));
							$clave 		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($clave,'ISO-8859-1', 'UTF-8')));
							$nombres 	= utf8_encode(ltrim(rtrim($data[2])));
							$nombres 	= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($nombres,'ISO-8859-1', 'UTF-8')));
							$apepat 	= utf8_encode(ltrim(rtrim($data[3])));
							$apepat 	= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($apepat,'ISO-8859-1', 'UTF-8')));
							$apemat 	= utf8_encode(ltrim(rtrim($data[4])));
							$apemat 	= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($apemat,'ISO-8859-1', 'UTF-8')));
							$telefono = utf8_encode(ltrim(rtrim($data[5])));
							$telefono = quoted_printable_decode(str_replace("�", '', mb_convert_encoding($telefono,'ISO-8859-1', 'UTF-8')));
							$perfil 	= utf8_encode(ltrim(rtrim($data[6])));
							$perfil 	= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($perfil,'ISO-8859-1', 'UTF-8')));

						}else{
							$usuario 	= utf8_encode(ltrim(rtrim($data[0])));
							$clave 		= utf8_encode(ltrim(rtrim($data[1])));
							$nombres 	= utf8_encode(ltrim(rtrim($data[2])));
							$apepat 	= utf8_encode(ltrim(rtrim($data[3])));
							$apemat 	= utf8_encode(ltrim(rtrim($data[4])));
							$telefono = utf8_encode(ltrim(rtrim($data[5])));
							$perfil 	= utf8_encode(ltrim(rtrim($data[6])));
						}

						$sUsu = "SELECT id FROM usuario WHERE rut = '$usuario';";
						$qUsu = consulta($sUsu);
						//echo $sUsu." \r\n";

						if(strtolower($perfil ) == 'merchan')
						{
							$idPerfil = 3;
						}

						if(strtolower($perfil ) == 'cliente')
						{
							$idPerfil = 4;
						}

						if(strtolower($perfil ) == 'administrador')
						{
							$idPerfil = 1;
						}


						if(count($qUsu)>0)
						{
							$idUsuario = $qUsu[0]["id"];
							if(!empty($clave)){
								$sUpdDatosUsuario = "UPDATE usuario SET nombre = '$nombres', apepat = '$apepat', apemat = '$apemat', telefono = '$telefono', id_perfil = '$idPerfil', clave = '$clave', fechaModificacion = now(), activo = 1 WHERE id = $idUsuario ";
							}else{
								$sUpdDatosUsuario = "UPDATE usuario SET nombre = '$nombres', apepat = '$apepat', apemat = '$apemat', telefono = '$telefono', id_perfil = '$idPerfil', fechaModificacion = now(), activo = 1 WHERE id = $idUsuario ";
							}

							$qUpdDatosUsuario = consulta($sUpdDatosUsuario);

							$mensaje .= "<span><i class='fa fa-warning'></i> El usuario <b>$usuario</b> ya existe. Se actualizaron los datos.</span><br>";

						}
						else
						{
							$sInsDatosUsuario = "INSERT INTO usuario SELECT null, '$usuario', '$nombres', '$apepat', '$apemat', '', '$telefono', '$clave', null, '$idPerfil', 1, now(), now();";
							//echo $sInsDatosUsuario." \r\n";
							$qInsDatosUsuario = mysql_query($sInsDatosUsuario);
							$mensaje .= "<span><i class='fa fa-check'></i> El usuario <b>$usuario</b> fue creado con éxito con perfil $perfil.</span><br>";

						}
					}
					$i++;

				}
				fclose($handle);

				$salida = $mensaje;
			}
		}
	}
	if($ws == "cargaLocalesMasivo")
	{
		
		if(isset($_FILES['archivoLocales']) && is_uploaded_file($_FILES['archivoLocales']['tmp_name']))
		{
			//upload directory
			$upload_dir = "csv_dir/LOC_";

			//create file name
			$file_path = $upload_dir . date("d-m-Y-h_i_s")."-".$_FILES['archivoLocales']['name'];

			//move uploaded file to upload dir
			if (!move_uploaded_file($_FILES['archivoLocales']['tmp_name'], $file_path))
			{
				//error moving upload file
				//echo "<script>alert('Error al subir el archivo.')<script>";
			}
			else
			{

				
				//encontrando delimitador usado en el archivo
				$delimiters = array(';',',');
				$delim = ";";
				$tipo_encode = "";
				for($i=0; $i<count($delimiters); $i++)
				{
					$handle = fopen($file_path, 'r');
					while ($data = fgetcsv($handle, 1000, $delimiters[$i]))
					{
						if(utf8_encode(ltrim(rtrim($data[0]))) == "SOLDTO")
						{
							$delim = $delimiters[$i];
						}
						if($i!=0){
							if(mb_detect_encoding(ltrim(rtrim($data[0])), 'UTF-8, ISO-8859-1') === 'UTF-8'){
								$tipo_encode = 'UTF-8';
							}elseif(mb_detect_encoding(ltrim(rtrim($data[0])), 'UTF-8, ISO-8859-1') === 'ISO-8859-1'){
								$tipo_encode = 'ISO-8859-1';
							}else{
								$tipo_encode = "0";
							}
							if($i>2) break;
						}
					}
					fclose($handle);
				}

				//asignando permnisos y open the csv file for reading
				exec(chmod($file_path, 0777));
				$handle = fopen($file_path, 'r');
				$i = 0;
				$ii = 0;
				$iii = 0;
				$iiii = 0;
				$exito = 0;

				$mensaje = "OK";
				$report = array();
				while ($data = fgetcsv($handle, 1000, $delim))
				{
					$errors = "";
					if($i!=0)
					{
						if($tipo_encode === 'UTF-8'){
							$nLocal 	 = ltrim(rtrim($data[0]));
							$nombre    = ltrim(rtrim($data[1]));
							$cadena 	 = ltrim(rtrim($data[2]));
							$cuenta 	 = ltrim(rtrim($data[3]));
							$comuna	 	 = ltrim(rtrim($data[4]));
							$region  	 = ltrim(rtrim($data[5]));
							$zonal 		 = ltrim(rtrim($data[6]));
							$direccion = ltrim(rtrim($data[7]));
						}elseif($tipo_encode === 'ISO-8859-1'){
							$nLocal 		= utf8_encode(ltrim(rtrim($data[0])));
							$nLocal 		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($nLocal,'ISO-8859-1', 'UTF-8')));
							$nombre 		= utf8_encode(ltrim(rtrim($data[1])));
							$nombre 		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($nombre,'ISO-8859-1', 'UTF-8')));
							$cadena 		= utf8_encode(ltrim(rtrim($data[2])));
							$cadena 		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($cadena,'ISO-8859-1', 'UTF-8')));
							$cuenta 		= utf8_encode(ltrim(rtrim($data[3])));
							$cuenta 		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($cuenta,'ISO-8859-1', 'UTF-8')));
							$comuna 		= utf8_encode(ltrim(rtrim($data[4])));
							$comuna 		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($comuna,'ISO-8859-1', 'UTF-8')));
							$region  		= utf8_encode(ltrim(rtrim($data[5])));
							$region  		= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($region ,	'ISO-8859-1', 'UTF-8')));
							$zonal 			= utf8_encode(ltrim(rtrim($data[6])));
							$zonal 			= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($zonal,'ISO-8859-1', 'UTF-8')));
							$direccion 	= utf8_encode(ltrim(rtrim($data[7])));
							$direccion 	= quoted_printable_decode(str_replace("�", '', mb_convert_encoding($direccion,'ISO-8859-1', 'UTF-8')));
						}else{
							$nLocal 	 = utf8_encode(ltrim(rtrim($data[0])));
							$nombre 	 = utf8_encode(ltrim(rtrim($data[1])));
							$cadena 	 = utf8_encode(ltrim(rtrim($data[2])));
							$cuenta 	 = utf8_encode(ltrim(rtrim($data[3])));
							$comuna 	 = utf8_encode(ltrim(rtrim($data[4])));
							$region 	 = utf8_encode(ltrim(rtrim($data[5])));
							$zonal 		 = utf8_encode(ltrim(rtrim($data[6])));
							$direccion = utf8_encode(ltrim(rtrim($data[7])));
						}

						/*echo('<br>'.'nLocal: '.$nLocal.'  / Nombre sala: '.$nombre.'<br>'.
									'cadena: '.$cadena.' / cuenta :'.$cuenta.'<br>'.
									'comuna: '.$comuna.' / region: '.$region.'<br>'.
									'zonal: '.$zonal.' / direccion: '.$direccion.'<br>');*/

						$idCadena = "";
						$idCuenta = "";
						$idZonal = "";
						$idComuna = "";
						$idRegion = "";

						$nombre 	 = strtoupper(normaliza($nombre));
						$cadena 	 = strtoupper(normaliza($cadena));
						$cuenta 	 = strtoupper(normaliza($cuenta));
						$comuna 	 = strtoupper(normaliza($comuna));
						$region 	 = strtoupper(normaliza($region));
						$zonal 		 = strtoupper(normaliza($zonal));
						$direccion   = strtoupper(normaliza($direccion)); 
												

						if(strlen($nLocal) > 20){
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."El número de Local posee más de 20 carácteres.";
						}
						if($nombre == "" || $nombre == null){
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."El Nombre no esta definido.";
						}
						if(strlen($direccion) > 300){
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."La Dirección del Local posee más de 300 carácteres.";
						}

						//----------------------------Cadena--------------------------//
						if(strlen($cadena) > 20){
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."La descripción de la Cadena posee más de 20 carácteres.";
						}else{	
							$sCadena = "SELECT * FROM cadena;";
							$qCadena = consulta($sCadena);

							if($cadena == "" or $cadena == null){
								$cadena = strtoupper(normaliza("NO DEFINIDO"));
							}

							$cadena_exist_id = null;
							foreach ($qCadena as $key => $value) {
								if(strtoupper(normaliza($value['descripcion'])) == $cadena){
									$cadena_exist_id = $value['id'];
								}
							}
							if ($cadena_exist_id == null) {
								mysql_query("INSERT INTO cadena SELECT null, '$cadena', 1, now(), now();");
								$idCadena = mysql_insert_id();
							}else{
								$idCadena = $cadena_exist_id;
							}
						}


						//----------------------------Cuenta---------------------------//
						if(strlen($cuenta) > 200){
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."La descripción de la Cuenta posee más de 200 carácteres.";
						}else{	
							$sCuenta = "SELECT * FROM cuenta;";
							$qCuenta = consulta($sCuenta);

							if($cuenta == "" or $cuenta == null){
								$cuenta = strtoupper(normaliza("NO DEFINIDO"));
							}
							
							$cuenta_exist_id = null;
							foreach ($qCuenta as $key => $value) {
								if(strtoupper(normaliza($value['descripcion'])) == $cuenta){
									$cuenta_exist_id = $value['id'];
								}
							}

							if ($cuenta_exist_id == null) {
								mysql_query("INSERT INTO cuenta SELECT null, '$cuenta', 1, now(), now();");
								$idCuenta = mysql_insert_id();
							}else{
								$idCuenta = $cuenta_exist_id;
							}
						}

						//--------------------------------Zonal--------------------------//

						
						if(strlen($zonal) > 150){
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."El nombre de la Zona posee más de 150 carácteres.";
						}else{

							if($zonal == "" or $zonal == null){
								$zonal = strtoupper(normaliza("NO DEFINIDO"));
							}

							$sZonal = "SELECT * FROM zonal;";
							$qZonal = consulta($sZonal);

							$zonal_exist_id = null;
							foreach ($qZonal as $key => $value) {
								if(strtoupper(normaliza($value['nombre'])) == $zonal){
									$zonal_exist_id = $value['id'];
								}
							}

							if ($zonal_exist_id == null) {
								mysql_query("INSERT INTO zonal SELECT null, '$zonal', 1, now(), now();");
								$idZonal = mysql_insert_id();
							}else{
								$idZonal = $zonal_exist_id;
							}
						}

						//--------------------------------Region--------------------------//

						if($region == "" or $region == null){
							$region = strtoupper(normaliza("NO DEFINIDO"));
						}

						$sRegion = "SELECT * FROM loc_region;";
						$qRegion = consulta($sRegion);

						$region_exist_id = null;
						foreach ($qRegion as $key => $value) {
							if(strtoupper(normaliza($value['descripcion'])) == $region){
								$region_exist_id = $value['id'];
							}
						}

						if ($region_exist_id == null) {
							$codigo = explode(' ', $region);
							mysql_query("INSERT INTO loc_region SELECT null, '$region', '$codigo[0]', 1, 1;");
							$idRegion = mysql_insert_id();
						}else{
							$idRegion = $region_exist_id;
						}

						//--------------------------Comuna---------------------------------//

						if($comuna == "" or $comuna == null){
							$comuna = strtoupper(normaliza("NO DEFINIDO"));
						}

						$sComuna = "SELECT * FROM loc_comuna;";
						$qComuna = consulta($sComuna);

						$comuna_exist_id = null;
						foreach ($qComuna as $key => $value) {
							if(strtoupper(normaliza($value['descripcion'])) == $comuna){
								$comuna_exist_id = $value['id'];
							}
						}

						if ($comuna_exist_id == null) {
							mysql_query("INSERT INTO loc_comuna SELECT null, '$comuna', $idRegion, 1;");
							$idComuna = mysql_insert_id();
						}else{
							$idComuna = $comuna_exist_id;
						}

						//-------------------------------Local-------------------------------------------//

						if($errors == ""){
							$ii++;
							$sUsu = "SELECT id FROM local WHERE numero_local = '$nLocal';";
							$qUsu = consulta($sUsu);

							if(count($qUsu)>0)
							{
								try {
									$iii++;
									$idLocal = $qUsu[0]["id"];
									$scuenta = "SELECT id FROM cuenta WHERE descripcion = '$cuenta'";

									$sUpdDatoLocal = "UPDATE local SET descripcion = '$nombre', direccion = '$direccion', id_cadena = $idCadena, id_cuenta = $idCuenta, id_comuna = $idComuna, id_zonal = $idZonal, fechaModificacion = now(), activo = 1 WHERE id = $idLocal ";

									$qUpdDatoLocal = mysql_query($sUpdDatoLocal);
									
								} catch (Exception $e) {
									array_push($data, "Error Al actualizar ".$nLocal);
									array_push($report, $data);
								}
							}
							else
							{
								try {
									$iiii++;
									$sInsDatosLocal = ("INSERT INTO local (descripcion, direccion, numero_local, id_cadena, id_cuenta, id_canal, id_comuna, latitud, longitud, activo, id_clasificacion, telefono, id_zonal, modelo_cafetera, rut_local, fechaCreacion, fechaModificacion, latLng) VALUES ('$nombre', '$direccion', '$nLocal', $idCadena, $idCuenta, 1, $idComuna, null, null, 1, 1, null, $idZonal, null, '$nLocal', now(), now(), null)");
									$qInsDatosLocal = mysql_query($sInsDatosLocal);									
								} catch (Exception $e) {
									array_push($data, "Error Al insertar ".$nLocal);
									array_push($report, $data);
								}
							}
						}else{
							array_push($data, $errors);
							array_push($report, $data);
						}
					}else{
						array_push($data, "Observaciones");
						array_push($report, $data);
					}
					
					$i++;
				}
				fclose($handle);
				if(count($report) > 1){
					if(is_writable("reporte_carga_local.csv")){
					    unlink("reporte_carga_local.csv");
					}
					$reportf = fopen("reporte_carga_local.csv", "w");
					foreach ($report as $key => $value) {
						fputcsv ( $reportf, $value, $delim);
					}
					fclose($reportf);
					$filename = 'reporte_carga_local.csv';
					$mimetype = 'text/csv';
					$data = file_get_contents($filename);
					$size = strlen($data);
					header("Content-Type: $mimetype");
					header('Content-Disposition: attachment; filename="'.$filename.'"');
					header('Expires: 0');
		            header('Cache-Control: must-revalidate');
		            header('Pragma: public');
					header("Content-Length: $size");
					readfile($filename);
					die();
				}
				//$salida = $mensaje." Lineas: ".$i.", Registros: ".$ii.", Update: ".$iii.", Create: ".$iiii;
				$salida = $mensaje;
			}
		}
	}

	if($ws=="desactivarUsuario")
	{
		$idUsuario = $_REQUEST["idUsuario"];

		mysql_query("UPDATE usuario SET activo = 0, fechaModificacion = now() WHERE id = $idUsuario;");

		$salida = "OK";
	}

	if($ws=="activarUsuario")
	{
		$idUsuario = $_REQUEST["idUsuario"];

		mysql_query("UPDATE usuario SET activo = 1, fechaModificacion = now() WHERE id = $idUsuario;");

		$salida = "OK";
	}

	if($ws == "crearNuevoUsuario")
	{
		$usuario = $_REQUEST["usuario"];

		$sUsuario = "SELECT id FROM usuario WHERE rut = '$usuario'";
		
		$qUsuario = consulta($sUsuario);		

		if(count($qUsuario) > 0)
		{
			$salida["mensaje"] = "error";
		}
		else
		{
			$nombres = $_REQUEST["nombreUsuario"];
			$apepat = $_REQUEST["apepatUsuario"];
			$apemat = $_REQUEST["apematUsuario"];
			$telefono = $_REQUEST["telefonoUsuario"];
			$clave = md5($_REQUEST["claveUsuario"]);
			$idPerfil = $_REQUEST["perfilUsuario"];

			$fotoPerfil = "";
			$uid = uniqid();

			$archivo	=	"profilePics/".$usuario."_".$uid.".jpg";

			if (move_uploaded_file($_FILES['fotoPerfilUsuario']['tmp_name'], $archivo))
			{
				$fotoPerfil = $archivo;
			}

			$sInsDatosUsuario = "INSERT INTO usuario SELECT null, '$usuario', '$nombres', '$apepat', '$apemat', '', '$telefono', '$clave', '$fotoPerfil', '$idPerfil', 1, now(), now();";
			//$salida["consulta"] = $sInsDatosUsuario;
			$qInsDatosUsuario = mysql_query($sInsDatosUsuario);

			//header("Location: UI_usuario.php");
			$salida["mensaje"] = "OK";
		}

	}

	if($ws=="cambioClave")
	{
		$idUsuario = $_REQUEST["idUsuario"];
		$clave = md5($_REQUEST["claveUsuario"]);

		mysql_query("UPDATE usuario SET clave = '$clave', fechaModificacion = now() WHERE id = $idUsuario;");

		$salida["consulta"] = "UPDATE usuario SET clave = '$clave', fechaModificacion = now() WHERE id = $idUsuario;";
		$salida["mensaje"] = "OK";
	}

	if($ws=="getDataMerchan"){
		
		$idmerchan = "";

		$id = "";

		if(isset($_REQUEST["idmerchan"]) && $_REQUEST["idmerchan"] != "" && $_REQUEST["idmerchan"] != null){
			$id = $_REQUEST["idmerchan"];
			$idmerchan = " AND id =  $id";
		}else{
			$idmerchan = "";
		}

		$query = "SELECT id,rut FROM usuario
			where activo = 1 AND id_perfil = 3 $idmerchan
			ORDER BY rut";
		
		$q = consulta($query);
		$salida["merchan"] = $q;		
						
	}

	if($ws=="getCampaingsByEstado"){
		
		$estado = "";

		$id = "";

		if(isset($_REQUEST["estado"]) && $_REQUEST["estado"] != "" && $_REQUEST["estado"] != null){
			$id = $_REQUEST["estado"];
			$estado = "AND formulario.estado_id =  $id";
		}else{
			$estado = "";
		}

		$query = "SELECT formulario.id,			
							formulario.nombre								
						FROM 		formularioApp 		as formulario                    
							WHERE formulario.activo = 1 AND formulario.idTipoFormulario = 2
							$estado
							AND YEAR(formulario.vigenteDesde) = '$anho'														
							ORDER BY formulario.id";

		$q = consulta($query);
		$salida["campaings"] = $q;							
	}

	if($ws=="changeMerchansCamp"){
		
		$errors = "";
		$mensaje = "OK";
		$idcampaing = $_REQUEST["idcampaing"];
		$estadoid = $_REQUEST["estadoid"];
		$merchanant = $_REQUEST["merchanant"];
		$merchannuevo = $_REQUEST["merchannuevo"];
		$estado ="";
		$campaing = "";
		
		if($idcampaing == null || $idcampaing =="" || $idcampaing == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de campaña es incorrecto, verifique.";
		}	

		if($estadoid == 0 || $estadoid == null || $estadoid =="" || $estadoid == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El estado de las campañas es incorrecto, verifique.";
		}

		if($merchanant == 0 || $merchanant == null || $merchanant =="" || $merchanant == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Merchan a Reemplazar no está llegando correctamente, verifique.";
		}	
		
		if($merchannuevo == 0 || $merchannuevo == null || $merchannuevo =="" || $merchannuevo == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Merchan Nuevo no está llegando correctamente, verifique.";
		}	

		if(!isset($_REQUEST["idcampaing"]) || !isset($_REQUEST["merchanant"]) || !isset($_REQUEST["merchannuevo"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";		
		}else{
			if($errors == ""){
				
				if(isset($_REQUEST["estadoid"]) && $_REQUEST["estadoid"] != "" && $_REQUEST["estadoid"] != null){
					$id = $_REQUEST["estadoid"];
					$estado = "AND formulario.estado_id =  $id";
				}else{
					$estado = "";
				}

				if(isset($_REQUEST["idcampaing"]) && $_REQUEST["idcampaing"] != "" && $_REQUEST["idcampaing"] != null){
					$id = $_REQUEST["idcampaing"];
					if($id>0){
						$campaing = "AND formulario.id =  $id";
					}else{
						$campaing = "";
					}										
				}
				
				try{
					$query = "SELECT formulario.id,			
							formulario.nombre								
						FROM 		formularioApp 		as formulario                    
							WHERE formulario.activo = 1 AND formulario.idTipoFormulario = 2
							$estado $campaing
							AND YEAR(formulario.vigenteDesde) = '$anho'														
								ORDER BY formulario.id";
					
					$q = consulta($query);
					
					if(count($q > 0)){
						foreach ($q as $ca) {	
							//modificamos las visitas del merchanant x merchannuevo
							$q1 = "UPDATE visitaHead
							SET usuario_id = $merchannuevo, fechaModificacion = now()
							where formularioApp_id= " . $ca["id"] . " AND usuario_id=$merchanant AND activo = 1;";
							
							mysql_query($q1);
						
							//modificamos merchan en tabla formularioAppGestiona
							$q2 = "UPDATE formularioAppGestiona
								SET     idItem = $merchannuevo, fechaModificacion = now()
							where formularioApp_id = ". $ca["id"] ." AND codigo = 'MERCHAN' AND idItem = $merchanant;";
														
							mysql_query($q2);
						}
												
						$salida["mensaje"] = "Felicidades!!! El cambio de merchan ha sido procesado con éxito.";
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al tratar de realizar el cambio de merchan";
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws=="getPreguntasForm"){
		$idFormulario = "";

		$id = "";

		if(isset($_REQUEST["idFormulario"]) && $_REQUEST["idFormulario"] != "" && $_REQUEST["idFormulario"] != null){
			$idFormulario = $_REQUEST["idFormulario"];			
		}else{
			$idFormulario = "";
		}

		$query = "SELECT DISTINCT
					fe.id as preguntaID,
					ef.codigo as tipo,
					fe.titulo as pregunta,
					fe.activo as activo					
						FROM formularioAppElementos as fe
						INNER JOIN formularioAppElementosOpciones as faeo ON fe.id = faeo.formularioAppElementos_id
							INNER JOIN elementosFormulario as ef ON faeo.elementosFormulario_id = ef.id
							LEFT JOIN elementosFormularioLista as efl ON faeo.idLista = efl.id					
								WHERE fe.formularioApp_id = $idFormulario";

		$q = consulta($query);
		$salida["preguntas"] = $q;	
	} 
	
	if($ws=="cambiaEstadoPregunta"){
		
		$errors = "";
		$mensaje = "OK";
		$idpregunta = $_REQUEST["idpregunta"];
		$estado = $_REQUEST["estado"];
		$pregunta = $_REQUEST["pregunta"];	

		if($idpregunta == 0 || $idpregunta == null || $idpregunta =="" || $idpregunta == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la pregunta es incorrecto, verifique.";
		}	

		if($estado == null || $estado =="" || $estado == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El campo estado de la pregunta es incorrecto, verifique.";
		}	

		if(!isset($_REQUEST["idpregunta"]) || !isset($_REQUEST["estado"]))
		{			
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";	
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";		
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE formularioAppElementos SET activo = $estado WHERE id = $idpregunta");
					
					$q	=	mysql_query($s);
					
					if(count($q) > 0){
						$salida["idpregunta"] = $idpregunta;
						if($estado == 0){
							$salida["mensaje"] = "Felicidades! La Pregunta ".$pregunta." ha sido eliminada con éxito.";
						}else{
							$salida["mensaje"] = "Felicidades! La Pregunta ".$pregunta." ha sido activada con éxito.";
						}
						
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar el estado de la pregunta : ".$pregunta;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}
	} 

	if($ws=="editarPregunta"){
		$errors = "";
		$mensaje = "OK";
		$idpregunta = $_REQUEST["idpregunta"];		
		$tituloPregunta = $_REQUEST["tituloPregunta"];
				
		if($idpregunta == 0 || $idpregunta == null || $idpregunta =="" || $idpregunta == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if(strlen($tituloPregunta) == 0 || $tituloPregunta == null || $tituloPregunta =="" || $tituloPregunta == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Título de la Pregunta no está llegando correctamente, verifique.";
		}	
		

		if(!isset($_REQUEST["idpregunta"]) || !isset($_REQUEST["tituloPregunta"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE formularioAppElementos SET titulo = '$tituloPregunta', fechaModificacion = now() WHERE id = $idpregunta");
					
					$q	=	mysql_query($s);
					if(count($q) > 0){
						$salida["idpregunta"] = $idpregunta;
						$salida["mensaje"] = "Felicidades! La Pregunta ".$tituloPregunta." ha sido actualizada con éxito.";
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar título de la pregunta : ".$tituloPregunta;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}


	
	if($ws=="getDataTipoPregunta"){
		
		$query = "SELECT id,nombre,codigo,activo 
					FROM elementosFormulario;";

		$q = consulta($query);
		$salida["tipos"] = $q;	
	}

	if($ws=="getOpcionesPreguntasForm"){
		$idFormulario = "";
		$idpregunta = "";
		$id = "";

		if(isset($_REQUEST["idFormulario"]) && $_REQUEST["idFormulario"] != "" && $_REQUEST["idFormulario"] != null){
			$idFormulario = $_REQUEST["idFormulario"];			
		}else{
			$idFormulario = "";
		}

		if(isset($_REQUEST["idpregunta"]) && $_REQUEST["idpregunta"] != "" && $_REQUEST["idpregunta"] != null){
			$idpregunta = $_REQUEST["idpregunta"];			
		}else{
			$idpregunta = "";
		}


		$query = "SELECT efl.id as idLista,
						 efl.idPadre as idPadre,
						 efl.nombre as nombreOpcion,
						efl.activo as activo,
						efl.admiteComentario,
						efl.valorDefecto
				FROM formularioAppElementosOpciones fe
				inner join elementosFormularioLista efl on efl.idPadre = fe.idLista    
				where fe.formularioApp_id = $idFormulario and fe.formularioAppElementos_id= $idpregunta;";

		$q = consulta($query);
		$salida["opciones"] = $q;	
	} 

	if($ws=="getIdPadreOpcionPreguntaForm"){
		$idFormulario = "";
		$idpregunta = "";
		$id = "";

		if(isset($_REQUEST["idFormulario"]) && $_REQUEST["idFormulario"] != "" && $_REQUEST["idFormulario"] != null){
			$idFormulario = $_REQUEST["idFormulario"];			
		}else{
			$idFormulario = "";
		}

		if(isset($_REQUEST["idpregunta"]) && $_REQUEST["idpregunta"] != "" && $_REQUEST["idpregunta"] != null){
			$idpregunta = $_REQUEST["idpregunta"];			
		}else{
			$idpregunta = "";
		}


		$query = "SELECT idLista as idPadre 
					FROM formularioAppElementosOpciones
						where formularioApp_id = $idFormulario and formularioAppElementos_id=$idpregunta;";

		$q = consulta($query);
		$salida["opcion"] = $q;	
	} 
								
	if($ws=="guardarNuevaPregunta"){
		$errors = "";
		$mensaje = "OK";
		$maxorden = 0;
		$maxcodi = "";
		$maxcodi2 = "";		
		$idPadre = $_REQUEST["idPadre"];			
		$idFormulario = $_REQUEST["idFormulario"];	
		$idTipoPreg = $_REQUEST["idTipoPreg"];	
		$tituloPregunta = $_REQUEST["tituloPregunta"];	
		$valorMinimo = $_REQUEST["valorMinimo"];	
		$valorMaximo = $_REQUEST["valorMaximo"];	
    							
		if($idPadre == null || $idPadre =="" || $idPadre == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El IdPadre de la opción es incorrecto, verifique.";
		}

		if($valorMinimo == null || $valorMinimo =="" || $valorMinimo == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El valor Mínimo de la pregunta es incorrecto, verifique.";
		}	

		if($valorMaximo == null || $valorMaximo =="" || $valorMaximo == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El valor Máximo de la pregunta es incorrecto, verifique.";
		}	

		if($idFormulario == 0 || $idFormulario == null || $idFormulario =="" || $idFormulario == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El IdFormulario de la pregunta es incorrecto, verifique.";
		}	

		if(strlen($tituloPregunta) == 0 || $tituloPregunta == null || $tituloPregunta =="" || $tituloPregunta == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Título de la Pregunta no está llegando correctamente, verifique.";
		}	
		
		if(!isset($_REQUEST["idTipoPreg"]) || !isset($_REQUEST["idFormulario"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				try{
					
					$max="SELECT max(orden) as max,max(codigo) as maxcod FROM formularioAppElementos
					WHERE formularioApp_id=$idFormulario;";

					$m= consulta($max);					
					$maxorden = $m[0]['max']+1;												
					$maxcodi = $m[0]['maxcod'];
					$rescodi = substr($maxcodi, -14) +1;
					$etiq = substr($maxcodi, 0,5);
					$maxcodi2 = $etiq."".$rescodi;

					$s = "INSERT INTO formularioAppElementos (titulo,formularioApp_id,icono,minimo,maximo,orden,codigo,activo,fechaCreacion,fechaModificacion,idPadre)
							VALUES('$tituloPregunta', $idFormulario, '',$valorMinimo, $valorMaximo, $maxorden, '$maxcodi2',1, now(), now(), $idPadre);";
					
					$q	=	mysql_query($s);
					$idFae = mysql_insert_id();						
					if(count($q) > 0){
						//inserta la fila correspondiente a la pregunta en elementosFormularioLista
						$sl = "INSERT INTO elementosFormularioLista (nombre,orden,idPadre,admiteComentario,valorDefecto,activo,fechaCreacion,fechaModificacion,codigo,idDependencia)
						VALUES('$maxcodi2', 0, null, null, null, 1, now(), now(), null, null);";		
						$q1	=	mysql_query($sl);
						$idLista = mysql_insert_id();
						if(count($q1) > 0){
							// insertamos fila en formularioAppElementosOpciones
							$s2 = "INSERT INTO formularioAppElementosOpciones (titulo,formularioApp_id,formularioAppElementos_id,icono,orden,minChars,maxChars,obligatorio,idPadre,elementosFormulario_id,idLista,activo,fechaCreacion,fechaModificacion)
							VALUES('', $idFormulario, $idFae,'', 1, $valorMinimo, $valorMaximo, 1, null, $idTipoPreg, $idLista, 1, now(), now());";							
							$q2	=	mysql_query($s2);
							if(count($q2) == 0){
								$errors = ($errors=="")? "" : $errors." | ";
								$errors = $errors."Error Al agregar título de la Pregunta en tabla  formularioAppElementosOpciones: ".$tituloPregunta;		
							}
						}else{
							$errors = ($errors=="")? "" : $errors." | ";
							$errors = $errors."Error Al agregar título de la Pregunta en tabla  elementosFormularioLista: ".$tituloPregunta;		
						}
					}else{
						$errors = ($errors=="")? "" : $errors." | ";
						$errors = $errors."Error Al agregar título de la Pregunta en tabla  formularioAppElementos: ".$tituloPregunta;		
					}

					if($errors == ""){
						$salida["idPadre"] = $idPadre;
						$salida["mensaje"] = "Felicidades! La Pregunta ".$tituloPregunta." ha sido agregada con éxito.";
						$salida["codigo"] = "OK";
					}else{
						$salida["codigo"] = "ERROR";
						$salida["mensaje"] = $errors;
						$errors = "";
					}			
																					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al agregar título de la Pregunta : ".$tituloPregunta;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}
		


	if($ws=="editarOpcionPregunta"){
		$errors = "";
		$mensaje = "OK";
		$idlista = $_REQUEST["idlista"];		
		$nombreOpcion = $_REQUEST["nombreOpcion"];
				
		if($idlista == 0 || $idlista == null || $idlista =="" || $idlista == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de región es incorrecto, verifique.";
		}	

		if(strlen($nombreOpcion) == 0 || $nombreOpcion == null || $nombreOpcion =="" || $nombreOpcion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Título de la Pregunta no está llegando correctamente, verifique.";
		}	
		

		if(!isset($_REQUEST["idlista"]) || !isset($_REQUEST["nombreOpcion"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE elementosFormularioLista SET nombre = '$nombreOpcion', fechaModificacion = now() WHERE id = $idlista");
					
					$q	=	mysql_query($s);
					if(count($q) > 0){
						$salida["idlista"] = $idlista;
						$salida["mensaje"] = "Felicidades! La Opción ".$nombreOpcion." ha sido actualizada con éxito.";
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar título de la Opción : ".$nombreOpcion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws=="guardarOpcionPregunta"){
		$errors = "";
		$mensaje = "OK";
		$maxorden = 0;		
		$idPadre = $_REQUEST["idPadre"];	
		$nombreOpcion = $_REQUEST["nombreOpcion"];
					
		if($idPadre == 0 || $idPadre == null || $idPadre =="" || $idPadre == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El IdPadre de la opción es incorrecto, verifique.";
		}	


		if(strlen($nombreOpcion) == 0 || $nombreOpcion == null || $nombreOpcion =="" || $nombreOpcion == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Título de la Pregunta no está llegando correctamente, verifique.";
		}	
		
		if(!isset($_REQUEST["idPadre"]) || !isset($_REQUEST["nombreOpcion"])){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";
		}else{
			if($errors == ""){
				try{
					
					$max="SELECT max(orden) as max FROM elementosFormularioLista
					WHERE idPadre=$idPadre;";

					$m= consulta($max);					
					$maxorden = $m[0]['max']+1;
					
					$s = "INSERT INTO elementosFormularioLista (nombre,orden,idPadre,admiteComentario,valorDefecto,activo,fechaCreacion,fechaModificacion,codigo,idDependencia)
							VALUES('$nombreOpcion', $maxorden, $idPadre, 0, null, 1, now(), now(), '', 0);";									
					$q	=	mysql_query($s);
					if(count($q) > 0){
						$salida["idPadre"] = $idPadre;
						$salida["mensaje"] = "Felicidades! La Opción ".$nombreOpcion." ha sido agregada con éxito.";
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al agregar título de la Opción : ".$nombreOpcion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}		    
	}

	if($ws=="cambiaEstadoOpcionPregunta"){
		
		$errors = "";
		$mensaje = "OK";
		$idlista = $_REQUEST["idlista"];
		$estado = $_REQUEST["estado"];
		$nombreOpcion = $_REQUEST["nombreOpcion"];	

		if($idlista == 0 || $idlista == null || $idlista =="" || $idlista == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El Id de la pregunta es incorrecto, verifique.";
		}	

		if($estado == null || $estado =="" || $estado == "undefined"){
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."El campo estado de la pregunta es incorrecto, verifique.";
		}	

		if(!isset($_REQUEST["idlista"]) || !isset($_REQUEST["estado"]))
		{			
			$errors = ($errors=="")? "" : $errors." | ";
			$errors = $errors."Los datos no llegaron correctamente.";	
			$salida["codigo"] = "ERROR";
			$salida["mensaje"] = $errors;
			$errors = "";		
		}else{
			if($errors == ""){
				try{
					$s = ("UPDATE elementosFormularioLista SET activo = $estado, fechaModificacion = now() WHERE id = $idlista");
					
					
					
					$q	=	mysql_query($s);
					
					if(count($q) > 0){
						$salida["idlista"] = $idlista;
						if($estado == 0){
							$salida["mensaje"] = "Felicidades! La Opción ".$nombreOpcion." ha sido eliminada con éxito.".$s;
						}else{
							$salida["mensaje"] = "Felicidades! La Opción ".$nombreOpcion." ha sido activada con éxito.";
						}
						
						$salida["codigo"] = "OK";
					}					
				} catch (Exception $e) {
					$errors = ($errors=="")? "" : $errors." | ";
					$errors = $errors."Error Al actualizar el estado de la Opción : ".$nombreOpcion;					
				}
			}else{				
				$salida["codigo"] = "ERROR";
				$salida["mensaje"] = $errors;
				$errors = "";
			}			
		}
	} 



	function formateaHora($hora)
	{

		$arrHora = explode(" ", $hora);

		$arrHoras = explode(":", $arrHora[0]);

		if($arrHoras[0]<10)
		{
			$hora = "0".$arrHoras[0];
		}

		if($arrHora[1] == "PM" and $hora < 10)
		{
			$hora = $hora + 12;
		}

		$salidaHora = $hora.":".$arrHoras[1];

		return $salidaHora;
	}

	function normaliza($cadena, $isANS = true){
		if($cadena != null){
		    $originales = 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûýýþÿŔŕ'."'";
		    $modificadas = 'aaaaaaaceeeeiiiidnoooooouuuuybsaaaaaaaceeeeiiiidnoooooouuuyybyRr ';
		    $cadena = utf8_decode($cadena);
		    $cadena = strtr($cadena, utf8_decode($originales), $modificadas);
		    $cadena = strtolower($cadena);
		    $cadena = utf8_encode($cadena);
		    if($isANS) $cadena = str_replace(',', '', $cadena);
		}
	    return $cadena;
	}

	echo json_encode($salida);
	?>