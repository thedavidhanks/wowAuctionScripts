<?php
$script_start_date = date('r');
//DELETE?  header('Content-type: text/html; charset=utf-8');
include('include/functions.php'); 

//CONFIGURATION//
$APIkey = 'ywqeka7jd3h6y6m9xad88zy8zgquwupf';
$RegionName = 'us';
$LocaleName = 'en_US';
$GameName = 'wow';
$RealmName = str_replace(' ', '%20', 'stonemaul');

$ProductionMode = TRUE;

//AH_dumps is where all the downloaded json AH files can be found
$dir_AH = "AH_dumps";
$dir_month = date('m');  // date('m',mktime(0,0,0,1,date("d"),date("Y"))); //Test January (01)
$dir_year = date('Y');
$dir_thisMonth = "{$dir_AH}/{$dir_year}/{$dir_month}";

//CREATE directories if they don't exist
if(!is_dir($dir_thisMonth)){
	if(!is_dir("{$dir_AH}/{$dir_year}")){
		mkdir("{$dir_AH}/{$dir_year}");
		if(!$ProductionMode){echo "Another year of auctions...".PHP_EOL;}
	}
	mkdir($dir_thisMonth);
	if(!$ProductionMode){echo "New Month! Created folder: {$dir_thisMonth}".PHP_EOL;}
}

//LOG FILE

$logfile = startLog();
$log_entry="SaveAuctionHrData_forbat.php---------------------------------------------------------------------------".PHP_EOL."LOG STARTED $script_start_date".PHP_EOL.PHP_EOL;
fwrite($logfile,$log_entry);

$json_wow_api_url = file_get_contents('https://'.$RegionName.'.api.battle.net/'.$GameName.'/auction/data/'.$RealmName.'?locale='.$LocaleName.'&apikey='.$APIkey.'');
$result=json_decode($json_wow_api_url,true);
$auc_json_url=$result['files'][0]['url'];
$auction_jsondata_api_url=file_get_contents($result['files'][0]['url']);
$auction_jsondata_date=$result['files'][0]['lastModified'];
$new_filename="auct_".$auction_jsondata_date.".json";
if(!file_exists($dir_thisMonth."/".$new_filename)){
		
	if (!copy($auc_json_url,$dir_thisMonth."/".$new_filename)){
		$msg_copyError = "failed to copy $auc_json_url...".PHP_EOL;
		 echo $msg_copyError;
		 fwrite($logfile, $msg_copyError);
	}
	$msg_FileCreated = $new_filename." was saved.".PHP_EOL;
	echo $msg_FileCreated;
	fwrite($logfile, $msg_FileCreated);
}
else{
	$msg_FileExists = $new_filename." exists. Try again later".PHP_EOL;
	echo $msg_FileExists;
	fwrite($logfile, $msg_FileExists);
	
}
$logEnd = "Log End-------------------------------------".PHP_EOL.PHP_EOL;
fwrite($logfile,$logEnd);
fclose($logfile);
?>