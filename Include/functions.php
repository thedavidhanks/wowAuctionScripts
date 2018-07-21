<?php
//functions.php

date_default_timezone_set('America/Chicago');

function AucTimeFromFile ($filename,$format="Epoch"){
	//function AucTimeFromFile - get the time the json file was recorded based on the filename
	// file formats are auc_1234567890123.json where "1234567890123" is the unix timestamp of when the auction data was pulled
	// $format is the return format
	//    $format="Epoch" is the unix timestamp as a number
	//    $format="string"

	//extract unix time stamp from $filename
	
	//use date() to reformat to MM/DD/YYYY HH:MM (24hr)
	$timestamp=rtrim(ltrim($filename,"auct_"),"000.json");
	
	if ($format=="date"){
		$time = date("m/d/Y H:i:s" , $timestamp);
		return $time;
	}
	elseif($format=="mysql_time"){
		$time = date("Y-m-d H:i:s", $timestamp);
		return $time;	
	} 
	else{
		$time=intval($timestamp);		
		return $time;
	}
}

function last_seen ($auc_json,$auc_id){
	//determines if the auc_id was last seen in the given auction
	
	//if the auction was in the file, continue, if not return false
	
	//determine the next file after $auc_json
	//if the $auc_id is not in the next file, return TRUE, and remaining time in $auc_file
	//if the $auc_id is seen in $auc_next_json return false
}

function next_auc_json ($auc_json){
	//given the time determines the immediatly next json file
}

function prev_auc_json ($auc_json){
	//given the time determines the immediatly prev json file
}

function copper_to_gold($c){
	//returns the amount in gold
	$g=$c/10000;
	return $g; 
}

?>