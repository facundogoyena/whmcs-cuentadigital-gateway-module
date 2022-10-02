<?php

function cuentadigital_getCurrencies() {
    $currencies = mysql_query("SELECT id, code, rate FROM tblcurrencies ORDER BY id ASC");
    $currenciesCount = mysql_num_rows($currencies);

    $currenciesLegend = "";
    $currenciesOptions = "";
    for ( $i = 1; $i <= $currenciesCount; $i++ ) {
        $currRow = mysql_fetch_array($currencies);
        $currenciesLegend .= $currRow['id'] . ": " . $currRow['code'];
        $currenciesLegend .= ($i != $currenciesCount ? ", " : "");

        $currenciesOptions .= $currRow['id'] . ($i != $currenciesCount ? "," : "");
    }

    return array(
        "currenciesLegend" => $currenciesLegend,
        "currenciesOptions" => $currenciesOptions
    );
}

function cuentadigital_checkDatabase() {
    $sql = "CREATE TABLE IF NOT EXISTS `tblinvoices_bccd` (`id` int(10) NOT NULL, `barcode` text COLLATE utf8_unicode_ci NOT NULL, `amount` decimal(10,2) NOT NULL, PRIMARY KEY (`id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
    @mysql_query($sql) or die("MySQL Error.");
}

function cuentadigital_updateBarcode($invoiceId, $barcode, $amount, $insert = false) {
    if ($insert === true) {
        $sql = "INSERT INTO tblinvoices_bccd VALUES('".$invoiceId."', '".$barcode."', '".$amount."')";
    } else {
        $sql = "UPDATE tblinvoices_bccd SET barcode='".$barcode."', amount='".$amount."' WHERE id='".$invoiceId."'";
    }

    @mysql_query($sql) or die ("MySQL Error.");
}

function cuentadigital_getBarcodeFromAPI($invoiceId, $accId) {
    $apiUrl = "https://www.cuentadigital.com/generador2.php?id=" . $accId . "&codigo=" . $invoiceId;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	
	// cuenta digital ssl error fix
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
	
    $barcode = curl_exec($ch);
    curl_close($ch);

    return $barcode;
}

function cuentadigital_checkBarcode($invoiceId, $accId, $amount) {
    cuentadigital_checkDatabase();

    $sql = "SELECT * FROM tblinvoices_bccd WHERE id = '".$invoiceId."' LIMIT 0,1";
    $res = mysql_query($sql) or die("MySQL Error.");
    $num = @mysql_num_rows($res);
    $row = @mysql_fetch_array($res);
    @mysql_free_result($res);

    if ($num <= 0 || $row['amount'] != $amount || empty($row['barcode'])) {
        $barcode = cuentadigital_getBarcodeFromAPI($invoiceId, $accId, $amount);
		
		// cuenta digital ssl error fix
		$barcode = str_replace("https:", "http:", $barcode);
		
        cuentadigital_updateBarcode($invoiceId, $barcode, $amount, ($num <= 0));
    }
}

function cuentadigital_getBarcode($invoiceId, $width, $height) {
    $err = "Error al obtener el C&oacute;digo de Barras";

    $sql = "SELECT barcode FROM tblinvoices_bccd WHERE id='".$invoiceId."'";
    $res = @mysql_query($sql) or die("MySQL Error.");

    if(mysql_num_rows($res) != 0) {
        $row = mysql_fetch_array($res);
        @mysql_free_result($res);

        if (empty($row['barcode'])) {
            return $err;
        }

		// cuenta digital ssl error fix
		$barcode = str_replace("https:", "http:", $row['barcode']);

        return "<img src='".$barcode."' width='".$width."' height='".$height."' alt='Codigo de Barras' />";
    }

    return $err;
}

function cuentadigital_config() {
    $currencies = cuentadigital_getCurrencies();

    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "Cuenta Digital"),
        "cd_accid" => array("FriendlyName" => "ID Cuenta", "Type" => "text", "Size" => "50", "Description" => "Ej. 123456"),
        "cd_mode" => array("FriendlyName" => "Modo", "Type" => "dropdown", "Options" => "Boton,Codigo de Barras", "Description" => "El modo es como se mostrara el modulo, si prefiere el boton de compra o directamente un codigo de barras."),
        "cd_button" => array("FriendlyName" => "URL del Bot&oacute;n", "Type" => "text", "Size" => "50", "Description" => "Ej. https://www.dominio.com/botondepago.jpg"),
        "cd_width" => array("FriendlyName" => "Ancho", "Type" => "text", "Size" => "13", "Description" => "Ancho del C&oacute;digo de Barras. Recomendado: 230"),
        "cd_height" => array("FriendlyName" => "Alto", "Type" => "text", "Size" => "13", "Description" => "Alto del C&oacute;digo de Barras. Recomendado: 52"),
        "cd_leyendasup" => array("FriendlyName" => "Leyenda Superior", "Type" => "text", "Size" => "50", "Description" => "Leyenda superior del c&oacute;digo de barras. (HTML)"),
        "cd_leyendainf" => array("FriendlyName" => "Leyenda Inferior", "Type" => "text", "Size" => "50", "Description" => "Leyenda inferior del c&oacute;digo de barras. (HTML)"),
        "cd_concept" => array("FriendlyName" => "Concepto", "Type" => "text", "Size" => "20", "Description" => "Ej. Factura #"),
        "cd_control" => array("FriendlyName" => "ID Control", "Type" => "text", "Size" => "50", "Description" => "Se obtiene en la secci&oacute;n Exportacion, es de 32 caracteres."),
        "cd_separator" => array("FriendlyName" => "Separador", "Type" => "dropdown", "Options" => "|,$,/,!,;", "Description" => "El separador seleccionado en Cuenta Digital. La coma no es posible por limitaci&oacute;n de WHMCS."),
        "cd_currency" => array("FriendlyName" => "!Callback currency", "Type" => "dropdown", "Options" => $currencies['currenciesOptions'], "Description" => "<b>CAMPO REQUERIDO!</b> Elija la moneda de su sistema que coincida con la moneda local de su cuenta de CuentaDigital.<br>Monedas configuradas en su sistema: (" . $currencies['currenciesLegend'] . ").<br>Monedas locales de CuentaDigital: ARS."),
        "cd_dummy2" => array("FriendlyName" => "Versi&oacute;n", "Type" => "dropdown", "Options" => "1.0.0", "Description" => "Versi&oacute;n del m&oacute;dulo."),
    );

    return $configarray;
}

function cuentadigital_link($params) {
    if (strtolower($params['cd_mode']) == 'codigo de barras') {
        cuentadigital_checkBarcode($params['invoiceid'], $params['cd_accid'], $params['amount']);

        $code = htmlspecialchars_decode($params['cd_leyendasup'], ENT_NOQUOTES);
        $code .= cuentadigital_getBarcode($params['invoiceid'], $params['cd_width'], $params['cd_height']);
        $code .= htmlspecialchars_decode($params['cd_leyendainf'], ENT_NOQUOTES);
    } else {
        $code = '<form target="_blank" action="https://www.cuentadigital.com/api.php" method="GET">';
        $code .= '<p><input type="image" border="0" name="submit" src="' . $params['cd_button'] . '" /></p>';
        $code .= '<input type="hidden" name="id" value="' . $params['cd_accid'] . '" />';
        $code .= '<input type="hidden" name="precio" value="' . $params['amount'] . '" />';
        $code .= '<input type="hidden" name="codigo" value="' . $params['invoiceid'] . '" />';
        $code .= '<input type="hidden" name="concepto" value="' . $params['cd_concept'] . '" />';
        $code .= '<input type="hidden" name="moneda" value="ARS" />';
        $code .= '<input type="hidden" name="m0" value="1" />';
        $code .= '<input type="hidden" name="m1" value="1" />';
        $code .= '<input type="hidden" name="m2" value="1" />';
        $code .= '</form>';
    }

    return $code;
}

?>
