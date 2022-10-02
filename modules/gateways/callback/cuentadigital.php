<?php

if (file_exists("../../../init.php")) {
    include("../../../init.php");
    $whmcs->load_function('gateway');
    $whmcs->load_function('invoice');
} else {
    include("../../../dbconnect.php");
    include("../../../includes/functions.php");
    include("../../../includes/gatewayfunctions.php");
    include("../../../includes/invoicefunctions.php");
}

$gatewaymodule = "cuentadigital";
$GATEWAY = getGatewayVariables($gatewaymodule);
if (!$GATEWAY["type"]) {
    die("Module Not Activated");
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.cuentadigital.com/exportacion.php?control=" . $GATEWAY['cd_control']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

// cuenta digital ssl error fix
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

$exportacion = curl_exec($ch);
curl_close($ch);

if (empty($exportacion)) {
    logTransaction($GATEWAY["name"], array(), "No hay pagos para importar.");
    die();
}

$operaciones = explode("\n", $exportacion);

foreach ($operaciones as $operacion) {
    $datosOperacion = explode($GATEWAY['cd_separator'], $operacion);
    if (count($datosOperacion) != 7 && !empty($datosOperacion[0])) {
        logTransaction($GATEWAY["name"], $operacion, "Exportacion mal configurada.");
        continue;
    }

    $pago = array(
        'total' => $datosOperacion[1],
        'comision' => $datosOperacion[3],
        'id' => mysql_real_escape_string($datosOperacion[5]),
        'invoiceId' => mysql_real_escape_string($datosOperacion[4])
    );

    $invoiceQuery = @mysql_query("SELECT i.id FROM `tblinvoices` as i WHERE i.id = '" . $pago['invoiceId'] . "'");
    if (@mysql_num_rows($invoiceQuery) <= 0) {
        continue;
    }
    @mysql_free_result($invoiceQuery);

    $transactionQuery = @mysql_query("SELECT t.id FROM `tblaccounts` as t WHERE t.transid = '" . $pago['id'] . "'");
    if (@mysql_num_rows($transactionQuery) > 0) {
        continue;
    }
    @mysql_free_result($transactionQuery);

    $clientQuery = @mysql_query("SELECT c.currency, cr.rate FROM `tblinvoices` as i, `tblclients` as c, `tblcurrencies` as cr WHERE i.id = '" . $pago['invoiceId'] . "' AND c.id = i.userid AND c.currency = cr.id");
    $clientCurrency = @mysql_result($clientQuery, 0);
    $callbackCurrency = $GATEWAY['cd_currency'];
    $clientCurrencyRate = (FLOAT) @mysql_result($clientQuery, 1);
    @mysql_free_result($clientQuery);

    if ( $callbackCurrency != $clientCurrency ) {
        $pago['neto'] /= $clientCurrencyRate;
        $pago['total'] /= $clientCurrencyRate;
        $pago['comision'] /= $clientCurrencyRate;
    }

    addInvoicePayment($pago['invoiceId'], $pago['id'], $pago['total'], $pago['comision'], $gatewaymodule);
    logTransaction($GATEWAY["name"], $pago, "Pago imputado exitosamente.");
}

?>
