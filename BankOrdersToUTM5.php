<?php

@ini_set("display_errors", "1"); error_reporting(E_ALL);
//@ini_set("display_errors", "0"); error_reporting(0);

class BankOrdersToUTM5Config {
	// ИНН
	protected $INN = "110***********";
	// Расчетный счет
	protected $BankAccount = "40702**********************";	

	protected $PaymentDayAge = 60;

	protected $LogFile = "/var/log/utm5/payments_bank/payments_bank_";

	// Путь до URFA-PHP https://github.com/k-shym/URFAClient
	protected $URFA_Path = "/opt/URFAClient-1.3.0/URFAClient.php";
	// 
	protected $PaymentMethodBankID = 2;
	protected $URFA_AdminParams = array(
		'login'    => '***************',
		'password' => '***************',
    'address'  => '127.0.0.1',
    'port'     => 11758,
    'timeout'  => 30,
//    'protocol' => 'ssl',
//   'protocol' => 'tls',
		'protocol' => 'none',
    'admin'    => true,
		'log'      => true,
	);

	protected $SQL_Params = array(
		"host" => "localhost",
		"base" => "UTM5",
		"user" => "***********",
		"password" => "************",
	);

	protected $Debug = false;	
}

class BankExchangeParser {

	protected $documents = array(); 

	function __construct($Content) {
		$ContentArray = explode("\r\n" , $Content);
		$docid = 0;
		foreach ($ContentArray as $Line) {
			$Line = rtrim($Line);
			$Line = mb_convert_encoding($Line, "utf-8", "windows-1251");
			$LineArray = explode('=', $Line);
			foreach ($LineArray as &$value)
				$value = trim($value);				
			if (count($LineArray) == 2) { 
				if ($LineArray[0] == 'СекцияДокумент') {
					$LineArray[0] = 'Документ';
					$document = array(); 
				}
				$document[$LineArray[0]] = $LineArray[1]; 
			} else { 
				if ($LineArray[0] == 'КонецДокумента') { 
					$this->documents[$docid] = $document; 
					$docid++; 
				}
			}
		}
	}

	public function GetAllDocumet() { 
		return $this->documents; // Отдаем документы по запросу
	}

	public function GetAllPaymentOrder() { 
		$ResultArray = array();
		foreach ($this->documents as $Document)
			if ($Document["Документ"] == "Платежное поручение")
				$ResultArray[] = $Document;
		return $ResultArray;
	}
            
}

class BankOrdersToUTM5 extends BankOrdersToUTM5Config {
		
	protected $documents = array(); // Свойство для хранения "отпарсенных" документов
	private $URFA_Admin;
	private $mysqli;

	public $Error = false;
	public $ErrorMessage = "";

	//Конструктор принимает на вход путь к файлу
	function __construct($Debug = false) {
		$this->Debug = $Debug;
		$this->InitUrfa();
		$this->InitMysqli();
	}

	private function Log($Message, $MessageSecond = false) {
		if ($MessageSecond !== false) {
			if (is_array($MessageSecond))
				$Message.= "\n".print_r($MessageSecond, true);
			else
				$Message.= " : ".$MessageSecond;
		}				
		if ($this->Debug)
			echo $Message."\n";	
		$Message = date("Y.m.d H:i:s")." $Message\n";
		$LogFile = $this->LogFile.date("Y.m.d").".log";
		file_put_contents($LogFile, $Message, FILE_APPEND);	
	}

	private function InitUrfa() {
		$Result = true;
		include_once($this->URFA_Path);
		try {
			$this->URFA_Admin = URFAClient::init($this->URFA_AdminParams);
		} catch (Exception $exception) { 
			$this->Log("Error in line ", $exception->getLine());
			$this->Log($exception->getMessage()); 
			$Result = false;
			return $Result;                                                         
		}
		return $Result;
	}

	private function InitMysqli() {
		$Result = true;
		mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); 
		$this->mysqli = new mysqli($this->SQL_Params["host"], $this->SQL_Params["user"], $this->SQL_Params["password"], $this->SQL_Params["base"]);
		if ($this->mysqli->connect_errno) {
			$this->Log("Не удалось подключиться к MySQL: " . $this->mysqli->connect_error);
			$Result = false;	
		}
//	$mysqli->set_charset("utf8");
		return $Result;
	}

	private function AddSpanNoWrap($Value) {
		return "<span style=\"white-space:nowrap;\">$Value<span/>";
	}

	private function SetPaymentURFA($ClientAccountID, $document) {
		$URFAParams =	array (
			'account_id' => $ClientAccountID,
			'payment' => $document["Сумма"],
			'payment_date' => strtotime($document["Дата"]),
			'payment_method' => $this->PaymentMethodBankID,
			'admin_comment' => $document["НазначениеПлатежа"],
			'comment' => $document["НазначениеПлатежа"],
			'payment_ext_number' => $document["Номер"],
			'turn_on_inet' => 1,
		);

		$this->Log("SetPaymentURFA URFAParams: ", $URFAParams);
//print_r($URFAParams);
//echo "\n";
  	$PaymentResult = $this->URFA_Admin->rpcf_add_payment_for_account($URFAParams);

	  if (isset($PaymentResult["payment_transaction_id"])) {
			return true;
		} else
			return false;
	}

	private function GetClientAccountID($document) {	
		$ClientINN = $document["ПлательщикИНН"];
		$query = "
			SELECT
				users.basic_account
			FROM
				users
			INNER JOIN accounts ON users.basic_account = accounts.id
			WHERE
				users.is_deleted = 0 AND
				users.tax_number = '$ClientINN' AND
				accounts.is_deleted = 0 AND
				accounts.is_blocked <= 16
		";
		$mysqli_result = $this->mysqli->query($query);
		if ($mysqli_result->num_rows != 1)
			return false;
		$row = $mysqli_result->fetch_array(MYSQLI_ASSOC);
		return $row["basic_account"]; 
	}

	private function ExistsPayment($ClientAccountID, $document) {	
		$ClientINN = $document["ПлательщикИНН"];
		if (trim($ClientINN) == "")
			return false;		
		$OrderNumber = $document["Номер"];
		$Sum = $document["Сумма"];
		$PaymentDate = strtotime($document["Дата"]);
		$PaymentMethodBankID = $this->PaymentMethodBankID;
		$PaymentDayAge = $this->PaymentDayAge;
		$query = "
			SELECT
				payment_transactions.id
			FROM
				payment_transactions
			WHERE
				payment_transactions.is_canceled = 0 AND
				payment_transactions.cancel_id = 0 AND
				payment_transactions.account_id = $ClientAccountID AND
				payment_transactions.payment_ext_number = $OrderNumber AND
				payment_transactions.method = $PaymentMethodBankID AND
				payment_transactions.payment_absolute = $Sum AND
				payment_transactions.actual_date >= $PaymentDate AND payment_transactions.actual_date < ($PaymentDate + 3600 * 24)
		";
//		$this->Log("ExistsPayment: ", $query);
//echo "$query\n";
/*
 AND 
				payment_transactions.actual_date > UNIX_TIMESTAMP(DATE_ADD(NOW(),INTERVAL -$PaymentDayAge DAY))
*/
		$mysqli_result = $this->mysqli->query($query);
		if ($mysqli_result->num_rows == 0)
			return false;
		$row = $mysqli_result->fetch_array(MYSQLI_ASSOC);
		$this->Log("ExistsPayment result: ", $row);
		return $row["id"]; 
	}


	private function ProcessingOrders() {
		foreach($this->documents as $document) {

			if ($document["ПлательщикИНН"] == $this->INN)
				continue;
			if ($document["ПолучательИНН"] != $this->INN)
				continue;
			if ($document["ПолучательРасчСчет"] != $this->BankAccount)
				continue;
			$ClientAccountID = $this->GetClientAccountID($document);
			if ($ClientAccountID === false)
				continue;
			$this->Log("--------------------------------------------------------------------------------------------------------------");
			if ($this->ExistsPayment($ClientAccountID, $document)) {
				$this->Log("Платеж для ЛС $ClientAccountID уже проведен.");
				continue;
			}
			if ($this->SetPaymentURFA($ClientAccountID, $document))
				$this->Log("Платеж для ЛС $ClientAccountID успешно проведен", $document);
			else
				$this->Log("Платеж для ЛС $ClientAccountID не удалось провести", $document);
		}
	}

	public function ProcessingFile($FileName) {
		$FileExtension = pathinfo($FileName, PATHINFO_EXTENSION);
		if ($FileExtension == "zip") {
			$FileContent = "";
			$zip = zip_open($FileName);
			if ($zip) {
				while ($zip_entry = zip_read($zip)) {

		      if (zip_entry_open($zip, $zip_entry, "r")) {

						$FileContent = zip_entry_read($zip_entry, zip_entry_filesize($zip_entry));
					}
					zip_entry_close($zip_entry);		
				}
			}
			zip_close($zip);
			if ($FileContent == "") {
				$this->Error = true;
				$this->ErrorMessage = "Не удалось распаковать архив";
				$this->Log("Не удалось распаковать архив");
				return;
			}
		} else {
			$FileContent = file_get_contents($FileName);
			if ($FileContent === false) {
				$this->Error = true;
				$this->ErrorMessage = "Не удалось прочитать файл";
				$this->Log("Не удалось прочитать файл");
				return;
			}
		}

		$BankExchangeParser = new BankExchangeParser($FileContent); // Запускаем парсинг
		$this->documents = $BankExchangeParser->GetAllPaymentOrder(); //Получаем все спарсенные документы

		$this->ProcessingOrders();

	}

	public function ProcessingContent($Content) {

		$BankExchangeParser = new BankExchangeParser($Content); // Запускаем парсинг
		$this->documents = $BankExchangeParser->GetAllPaymentOrder(); //Получаем все спарсенные документы

		$this->ProcessingOrders();

	}


	public function GetPayments($MonthCount) {	
		$PaymentMethodBankID = $this->PaymentMethodBankID;
		$MonthCount = $MonthCount - 1;
		$DateStart = strtotime( date("Y-m-01")." -$MonthCount month");
		$MouthArrayRus = array('', 'январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь');
		$query = "
			SELECT
				payment_transactions.id,
				payment_transactions.account_id,
				users.login,
				users.full_name,
				users.tax_number,
				payment_transactions.payment_absolute,
				payment_transactions.actual_date,
				payment_transactions.payment_enter_date,
				payment_transactions.payment_ext_number,
				payment_transactions.comments_for_user,
				payment_transactions.comments_for_admins,
				accounts.balance
			FROM
				payment_transactions
			INNER JOIN users ON payment_transactions.account_id = users.basic_account
			INNER JOIN accounts ON payment_transactions.account_id = accounts.id
			WHERE
				payment_transactions.is_canceled = 0 AND
				payment_transactions.cancel_id = 0 AND
				payment_transactions.method = $PaymentMethodBankID AND
				payment_transactions.actual_date > $DateStart AND
				users.is_deleted = 0
			ORDER BY
				payment_transactions.actual_date DESC
		";
//		$this->Log("GetPayments(MonthCount=$MonthCount): ", $query);
//echo "$query\n";
		$mysqli_result = $this->mysqli->query($query);

		$ResultArray = array();		
		$RootKey = "";
		while ($row = $mysqli_result->fetch_array(MYSQLI_ASSOC)) {
//		  $RootKey = date("F Y", $row["actual_date"]);
		  $RootKey = $MouthArrayRus[date("n", $row["actual_date"])]." ".date("Y", $row["actual_date"]);
		  $Key = $row["id"];

			$ResultArray[$RootKey][$Key] = array(
				"№№</br>билинга" => $row["id"],
				"ЛС" => $row["account_id"],
				"Логин" => $row["login"],
				"Наименование" => $this->AddSpanNoWrap($row["full_name"]),
				"ИНН" => $row["tax_number"],
				"Номер</br>П/П" => $row["payment_ext_number"],
				"Дата</br> П/П" => date("d.m.Y", $row["actual_date"]),
				"Дата внесения" => $this->AddSpanNoWrap(date("d.m.Y H.i", $row["payment_enter_date"])),
				"Сумма" => $row["payment_absolute"],
				"Текущий баланс" => $row["balance"],
//				"Комментарий для абонента" => $row["comments_for_user"],
//				"Комментарий для администратора" => $row["comments_for_admins"],
				"Комментарий" => $row["comments_for_admins"],
			);		
		}
		return $ResultArray; 
	}
            
}
