<?php
//=====================================================================================================
//=====>	INICIO_H
	include_once("../core/go.login.inc.php");
	include_once("../core/core.error.inc.php");
	include_once("../core/core.html.inc.php");
	include_once("../core/core.init.inc.php");
	$theFile					= __FILE__;
	$permiso					= getSIPAKALPermissions($theFile);
	if($permiso === false){		header ("location:../404.php?i=999");	}
	$_SESSION["current_file"]	= addslashes( $theFile );
//<=====	FIN_H
  
//=====================================================================================================
include_once("../core/entidad.datos.php");
include_once("../core/core.deprecated.inc.php");
include_once("../core/core.fechas.inc.php");
include_once("../core/core.config.inc.php");
include_once("../core/core.captacion.inc.php");
include_once("../core/core.riesgo.inc.php");
include_once("../core/core.seguimiento.inc.php");
include_once("../core/core.creditos.inc.php");
include_once("../core/core.creditos.utils.inc.php");
include_once("../core/core.operaciones.inc.php");
include_once("../core/core.common.inc.php");
include_once("../core/core.html.inc.php");

    ini_set("display_errors", "off");
    ini_set("max_execution_time", 1600);
    ini_set("memory_limit", SAFE_MEMORY_LIMIT);
    
    $key		 	= (isset($_GET["k"]) ) ? true : false;
    $parser			= (!isset($_GET["s"]) ) ? false : $_GET["s"];
    
    //Obtiene la llave del
//if ($key == MY_KEY) {
	$messages		= "";
	$fechaop		= parametro("f", false, MQL_DATE);
	$xF				= new cFecha(0, $fechaop);
	$fechaop		= $xF->getFechaISO($fechaop);
	/**
	 * Generar el Archivo HTMl del LOG
	 * eventos-del-cierre + fecha_de_cierre + .html
	 *
	 */

	$aliasFil	= getSucursal() . "-eventos-al-cierre-de-colocacion-del-dia-$fechaop";

	$xLog		= new cFileLog($aliasFil);
	$xQL		= new MQL();
	$xRec		= new cReciboDeOperacion(12);
	$xRec->setGenerarPoliza();
	$xRec->setForceUpdateSaldos();
	$idrecibo	= $xRec->setNuevoRecibo(DEFAULT_SOCIO, DEFAULT_CREDITO,$fechaop, 1, 12, "CIERRE_DE_COLOCACION_$fechaop", DEFAULT_CHEQUE, FALLBACK_TIPO_PAGO_CAJA, 
										DEFAULT_RECIBO_FISCAL, DEFAULT_GRUPO);

	$xRec->setNumeroDeRecibo($idrecibo);


	$messages 		.= "=======================================================================================\r\n";
	$messages 		.= "=========================\t" . EACP_NAME . " \r\n";
	$messages 		.= "=========================\t" . getSucursal() . " \r\n";
	$messages 		.= "=======================================================================================\r\n";
	$messages 		.= "=========================\tINICIANDO EL CIERRE DE COLOCACION\r\n";
	$messages 		.= "=========================\tRECIBO: $idrecibo\r\n";
	//procesos de correccion
	$xQL->setRawQuery("SET @fecha_de_corte='$fechaop';");
	$xQL->setRawQuery("CALL `sp_correcciones`()");
	$xDB			= new cSAFEData();
	$messages 		.= $xDB->setLowerSucursal();
	$xQL->setRawQuery("SET @fecha_de_corte='$fechaop';");
	//reconstruir db de pagos
	$xQL->setRawQuery("CALL `proc_historial_de_pagos` ");
	$xQL->setRawQuery("CALL `proc_creditos_a_final_de_plazo`()");
	$xQL->setRawQuery("CALL `proc_creditos_abonos_por_mes`()");		//una vez por mes
	$xQL->setRawQuery("CALL `proc_personas_extranjeras`()");		//personas extranjeras
	$xQL->setRawQuery("CALL `proc_creditos_abonos_totales`()");		//Abonos totales en tmp
	$xQL->setRawQuery("CALL `proc_creditos_abonos_parciales`()");	//Abonos mes y tipo columna
	
	$xCUtils		= new cUtileriasParaCreditos();
	
	$messages		.= $xCUtils->setEstatusDeCreditos($idrecibo, $fechaop, false, true, false, true );
	//setLog("pasa la memoria");
	if ( date("Y-m-t", strtotime($fechaop) ) == date("Y-m-d", strtotime($fechaop) )  ){
		$messages		.= $xCUtils->setGenerarMvtoFinDeMes( $xF->getDiaInicial(), $xF->getDiaFinal(), false, false, $idrecibo );
		
	}
	//$xLog->setWrite($messages);
	//$xLog->setClose();
	
	if(CREDITO_CIERRE_FORZAR_DEVENGADOS == true){
		
		$messages 		.= "=========================\tREESTRUCTURA SDPM DE PLANES\r\n";
		$messages		.= $xCUtils->setReestructurarSDPM_Planes(true, false, false, $fechaop, $xF->getDiaInicial(), true);
		$messages 		.= "=========================\tREESTRUCTURA SDPM\r\n";
		$messages		.= $xCUtils->setReestructurarSDPM(true, false, false, $fechaop, $xF->getDiaInicial(), true);
		$messages 		.= "=========================\tREGENERAR INTERES_DEVENGADO\r\n";
		$messages		.= $xCUtils->setRegenerarInteresDevengado(false, $xF->get(), $xF->get() );
		//Purgar error
		$xQL->setRawQuery("DELETE FROM operaciones_mvtos WHERE tipo_operacion=420 AND (SELECT COUNT(*) FROM creditos_solicitud WHERE numero_solicitud = operaciones_mvtos.docto_afectado AND estatus_actual >= 98) > 0 ");
		$messages 		.= "=========================\tACUMULAR INTERESES\r\n";
		$messages			.= $xCUtils->setAcumularIntereses(false); //var primero
		$messages 		.= "=========================\tACUMULAR MORA DE PARCIALIDADES\r\n";
		//===========  Acumular Mora de Planes
		$xQL->setRawQuery("CALL `proc_creditos_letras_del_dia`()");
		$messages		.= $xCUtils->setAcumularMoraDeParcialidades();
	}
	
	$xQL->setRawQuery("CALL `sp_tmp_personas_geografia`");
	
	//Cierre Anual
	if($xF->getInt($fechaop) == $xF->getInt($xF->getFechaFinAnnio() )){
		//Agregar Periodo general Anual
		$xF2			= new cFecha();
		$ejercicio		= $xF->anno() + 1;
		$xF2->set("$ejercicio-06-01" );
		$xPers			= new cPeriodoDeCredito();
		$xPers->add("$ejercicio-01-01", $xF2->getFechaFinAnnio());
		$messages		.= $xPers->getMessages();
	}	
	
	$xRec->setFinalizarRecibo(true);

	$xLog->setWrite($messages);
	$xLog->setClose();

	if(ENVIAR_MAIL_LOGS == true){ $xLog->setSendToMail("TR.Eventos del Cierre del colocacion");	}

	if ($parser != false){
		header("Location: ./cierre_de_captacion.frm.php?s=true&k=" . $key . "&f=$fechaop");
	}
//}

?>
