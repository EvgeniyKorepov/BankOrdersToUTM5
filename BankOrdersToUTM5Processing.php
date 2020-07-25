  <?php

@ini_set("display_errors", "1"); error_reporting(E_ALL);
//@ini_set("display_errors", "0"); error_reporting(0);

// https://github.com/EvgeniyKorepov/InternetBankOpen
include("/opt/InternetBankOpen/InternetBankOpen.php");

// https://github.com/EvgeniyKorepov/BankOrdersToUTM5
include("/opt/BankOrdersToUTM5/BankOrdersToUTM5.php");

$Debug = true;
//$Debug = false;

$InternetBankOpenAPI = new InternetBankOpenAPI($Debug);
if ($InternetBankOpenAPI->Error) {
	echo "$InternetBankOpenAPI->Message\n";
	exit;	
}

// Расчетный счет
$AccountNumber = "40702******************";
$StatementFormat = "TXT";
//$StatementFormat = "JSON";
$DateFrom = "2020-07-01";
$DateTo = false;
$Print = true;

$StatementContent = $InternetBankOpenAPI->GetStatement($AccountNumber, $StatementFormat, $DateFrom, $DateTo, $Print);
if ($InternetBankOpenAPI->Error) {
	echo $InternetBankOpenAPI->Message . "\n";
	exit;
}

$BankOrdersToUTM5 = new BankOrdersToUTM5($Debug); 
$BankOrdersToUTM5->ProcessingContent($StatementContent);
if ($BankOrdersToUTM5->Error)
	echo $BankOrdersToUTM5->ErrorMessage . "\n";
