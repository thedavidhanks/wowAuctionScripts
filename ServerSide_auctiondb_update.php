<?php
//auctiondb_update.php
/*	The intent of this file is to read the json files available and record the auction data to a database
 * 	
 *  //UPDATE - CONSIDER USING MYSQL JSON DATATYPE FOR QUICKER PROCESSING - http://dev.mysql.com/doc/refman/5.7/en/json.html
 *
 *	-PSEUDO CODE-
 *
 *Record every auction seen and update the auction if it's seen in the next scan
 *
 * $added_aucs=0;
 * $updated_aucs=0;
 *1 - Open the AH_dumps directory and read every json file
 *2 - for each json file do the following
 * 	get the $file_timestamp of the file
 *	For each auction_id in the json file
 * 		(2.1)if the auction_id does not exist, then
 *	 		record in the table- new id, bn_auc_id, realm_id, item_id, seller, buyout, bid_firstseen, updated=today(), firstseen=$file_timestamp, initial_timeleft="very_long, Long, medium, or short" ;
 *	  		$added_aucs=$added_aucs+1
 * 	 	(2.2)if the auction_id exists in the mysql db, then
 *	 		if the $file_timestamp < firstseen, then (this file is older than our previous record)
 *	 			updated the first seen time to the current file we're looking at. 
 *	 			update values bid_lastseen=bid_firstseen, bid_firstseen=bid, updated=today(), lastseen=firstseen, firstseen=$file_timestamp, db_initial_timeleft=json_timeleft;  
 *	 		NOTE:  I suspect this won't happen often since we'll be looking at the files in order from oldest to newest, but just in case we'll update the dates
 *	 		else if the $file_timestamp > firstseen, then (This file is newer than our first record)
 *	  			if the $file_timestamp > lastseen, then (We are working with the latest file)
 *	 				update bid_lastseen, lastseen, updated=today();
 *	  				Calculate the time the item has been on the AH
 *	  				Function $time_on_ah = $file_timestamp - firstseen				
 *	  				update db_time_on_ah=$time_on_ah;
 *	  			else (this file is between the first time we saw it an the last.)
 *	  				do nothing?
 * 
 * Log or echo $added_aucs, $updated_aucs, date();
 * 
 *--------------------------------------- 
 * Read every auction entry to determine how it was sold
 * For each auc_id
 * 	if the $time_on_ah was within an hour of the initial time it was posted for AND the last_time was "SHORT" or "MEDIUM" AND the bid_lastseen > bid_first seen, then it BID_SOLD
 * 		updated db with sell_type = BID_SOLD
 *  if the $time_on_ah was within an hour of the initial time it was posted for AND the last_time was "SHORT" or "MEDIUM" and the bid_lastseen = bid_first seen, then it EXPIRED
 * 		update db with sell_type = EXPIRED
 * 	if the $time_on_ah was shorter than the initial time by more than an hour AND the last_TIME was "LONG" or "VERY_LONG" or "MEDIUM", then it SOLD
 * 		update db with sell_type = SOLD
 * 		NOTE: We really have no way of knowing if the auction was sold or was cancelled, but we're going to assume they all sold initally.  
 * 				In another section we'll determine if some of the sold auctions were cancelled by seeing if their cancel price was way outside of an average, 
 *				of course this assumes the only reason an auction was cancelled was because it was priced too high.
 *   
 *	Calculate a 30, 14, 5 day moving average and deviations
 * 	record in current item prices
 * 
 * 	Key to shorthand of auction processed output.
 *		+  Auction added
		^- Auction updated but not changed
		^e Auction updated with earlier record
		^l Auction updated with later record
 */
include('include/functions.php'); 
$script_start_time = microtime(TRUE);
$script_start_date = date('r');

/***CONFIGURATION***/

$auc_table = "auctions";  // "auctions" for real; "auctions_test" for test mode
$ProductionMode = TRUE; 			// FALSE to provide more detail in logs and CLI.  TRUE if in production mode.
$CLI_LOGGING = "minimal";

//mysql
$servername = "localhost";
$username = "auctionuser"; //should be 'auctionuser'
$password = "fun3t!mes";
$dbname = "mydb";
ini_set('memory_limit', '1024M'); // increase memory to 1gig

//Amount of elapsed time to ignore a file without checking database.
$olderThanHrs = 50;  //default 50 hours because the longest an auction should be available is 48 hrs.  Excess bidding may extend this time.
$olderThanSec = $olderThanHrs*60*60; //seconds

/***END CONFIG****/

//Initiate variables
$added_aucs=0;
$updated_aucs=0;
$ignored_aucs=0;
$auc_files=0;
$total_aucs=0;
$aucs_earlier=0; //auctions updated with earlier records
$aucs_later=0;  //auctions updated with later records
$files_ignore=0; //the file has been processed in the database already
$files_tooOld=0; //the file is older than the specified $olderThanHrs
$total_auc_modded = 0;
$updated_time = date("Y-m-d H:i:s");
$summary="";
$summary_processed="";
$summary_ignored="";
$summary_tooOld ="";
$error_log="";
$cli_log="";
 
//AH_dumps is where all the downloaded json AH files can be found
$dir_AH = "AH_dumps";
$dir_month = date('m');  // date('m',mktime(0,0,0,1,date("d"),date("Y"))); //Test January (01)
$dir_year = date('Y');
$dir_thisMonth = "{$dir_AH}/{$dir_year}/{$dir_month}";
if($dir_month == "01"){
	$dir_lastMonth = "12";
	$dir_lastYear = date('Y')-1;
	$dir_lastMonth = "{$dir_AH}/{$dir_lastYear}/{$dir_lastMonth}";
}else{
	$dir_lastMonth = intval(date('m')-1) < 10 ? "0".(date('m')-1) : date('m')-1;  //Make sure the previous month starts with a "0" or "1"
	$dir_lastMonth = "{$dir_AH}/{$dir_year}/{$dir_lastMonth}";
}

//Removed create directories

// Create connection
$conn2 = mysqli_connect($servername, $username, $password, $dbname);
// Check connection
if (!$conn2) {
    die("Connection failed: " . mysqli_connect_error());
}

//Create a logfile for each day.  If today's log exists write to it, if not, create a new one.
// 20160106_log.txt would be the log file for Jan 5, 2016
$log_dir = "logs/";
$logfilename=date('Ymd')."_log.txt";
if (file_exists($log_dir.$logfilename)) {$log_action = "a";}
else{$log_action = "w";}
$logfile = fopen($log_dir.$logfilename,$log_action) or die ("Unable to Open file!");
$log_entry="---------------------------------------------------------------------------------------------".PHP_EOL."LOG STARTED $updated_time".PHP_EOL.PHP_EOL;
fwrite($logfile,$log_entry);

/*******START PreSummary******/
$pre_summaryLog ="";



//To save time reviewing all the files and checking the database for each
// -only review files that aren't older than $olderThanHrs
// -only look at files in this month's folder and the prior month's
foreach([$dir_lastMonth,$dir_thisMonth] as $dir){
	//Summary of the files to be processed
	//X files in directory. to evaluate
	//Y will be evaluated.
	//Z will be ignored
	//AA total auctions to review
	$pre_file_total = 0;
	$pre_eval_total[$dir] = 0;
	$pre_ignore_total = 0;
	$pre_auc_total = 0;

	if ($quick_handle = opendir($dir)){
		while (false !== ($entry = readdir($quick_handle))) {  	//if there's a file let's do some work.
			if (strpos($entry,".json")!==false){				//if it's a JSON let's do more work.
					$pre_file_total +=1;
					if($script_start_time-$olderThanSec < AucTimeFromFile($entry)){
						//The file was made within the last 50 hrs, so see if this script has processed the file.
						$pre_auction_array_data=json_decode(file_get_contents("$dir/".$entry),true);  //turns the json into an array.
						$auc_total_currentfile=count($pre_auction_array_data['auctions']); 
						$select_existing_file = "SELECT * FROM `files_processed` WHERE `filename`='$entry' AND `auctions`=$auc_total_currentfile AND `completed`=1";
						$pre_result_files = mysqli_query($conn2, $select_existing_file);
						if (!mysqli_num_rows($pre_result_files)) {
							//for not ignored auctions
							$pre_message="Evaluate {$entry}".PHP_EOL;
							$pre_eval_total[$dir]+=1;
							$pre_auc_total+=$auc_total_currentfile; //testing
						}
						else{
							$pre_ignore_total+=1;
							$pre_message="Ignore(DBcheck) {$entry}".PHP_EOL;
						}	
					}else{
						//The file is older than 50 hrs.  Don't even ask the database, just skip the file.
						$pre_ignore_total+=1;
						$pre_message="Ignore {$entry}".PHP_EOL;
					}
					
					//if(!$ProductionMode){echo $pre_message;}
			}
		}

		$pre_summary = "SUMMARY OF FILES TO BE PROCESSED in $dir:".PHP_EOL."\t$pre_file_total json files in directory.".PHP_EOL."\t$pre_eval_total[$dir] files will be evaluated.".PHP_EOL."\t$pre_ignore_total files will be ignored.".PHP_EOL."\t$pre_auc_total auctions will be reviewed.".PHP_EOL.PHP_EOL;
		echo $pre_summary;
		$pre_summaryLog.=$pre_summary;
	}
}
fwrite($logfile,$pre_summaryLog);
/*******END PreSummary******/
if(!$ProductionMode){
	echo "Continue?  ";
	$handle = fopen("php://stdin","r");
	$line = fgets($handle);
	if(trim($line)!=('y'||'Y')){
		echo "ABORTED BY USER".PHP_EOL;
		exit;
	}
	echo "\n";
	echo "Continuing...";
}

//Only evaluate months where we found entries to process.
//$dir_array_to_review = array();
//if($pre_file_total_last > 0 ){array_push($dir_array_to_review,$dir_lastMonth);}
//if($pre_file_total_this > 0 ){array_push($dir_array_to_review,$dir_thisMonth);}

foreach([$dir_lastMonth,$dir_thisMonth] as $dir){
	if ($handle = opendir($dir)) {
		$files_processed_count=0;
		/* This is the correct way to loop over the directory. */
		while (false !== ($entry = readdir($handle))) {
			//Setup values for the new file
			$total_file_auc_modded=0;
			$file_added_aucs=0;
			$file_updated_aucs=0;
			$file_ignored_aucs=0;
			$file_earlier_aucs=0;
			$file_later_aucs=0;  //auctions that have been updated with a later snapshot within the file.
			
			//2 - 	If it's a JSON file and is not older than $olderThanHrs, then continue 
			// $entry is the file name (Ex. auct_1481344008000.json)
			if (strpos($entry,".json")!==false && ($script_start_time-$olderThanSec < AucTimeFromFile($entry))){
				$files_processed_count++;
				$entry_timestamp=AucTimeFromFile($entry);
				$entry_date=AucTimeFromFile($entry,"mysql_time");
				$new_row_values = "";
				$update_row_values = "";
				$update_earlier = array();
				$update_later = array();
				
				$auction_array_data=json_decode(file_get_contents("$dir/".$entry),true);  //turns the json into an array.
				$auc_total_currentfile=count($auction_array_data['auctions']); 
				
				
				// Create connection
				$conn = mysqli_connect($servername, $username, $password, $dbname);
				// Check connection
				if (!$conn) {
					die("Connection failed: " . mysqli_connect_error());
				}

				if(!$ProductionMode){echo "Reviewing {$entry} because it is less than {$olderThanHrs} old".PHP_EOL;}
				// IF THIS FILE has not BEEN PROCESSED, then continue
				$select_existing_file = "SELECT * FROM `files_processed` WHERE `filename`='$entry' AND `auctions`=$auc_total_currentfile AND `completed`=1";
				$result_files = mysqli_query($conn, $select_existing_file);
				if (!mysqli_num_rows($result_files)) {
					if(!$ProductionMode){echo "Processing {$entry} because it was not in the database".PHP_EOL;}
					$auc_files+=1;

					$log_update = PHP_EOL.PHP_EOL."$entry: Reviewing $auc_total_currentfile auctions.".PHP_EOL;
					fwrite($logfile,$log_update);
					echo $log_update;  //testing
					$total_aucs=$auc_total_currentfile+$total_aucs;
					$num=1;
					foreach($auction_array_data['auctions'] as $json_auction){	//cycle through each auction
						//UPDATE -- give error if ['auctions'] does not exist or format is incorrect.
						//open the mysql db, find out if the $auction is already recorded	
						
						$sql = "SELECT bn_auc_id, firstseen, lastseen FROM $auc_table where bn_auc_id = {$json_auction['auc']}";   //UPDATE - Only look back 48-50 hrs prior to the timestamp of the file.  No need to look at  the entire database after it's setup.
						$result = mysqli_query($conn, $sql); //ERROR ON UPDATE.
						if (!$result){
							$a = "SQL Error description: " . mysqli_error($conn);
							echo $a;
							fwrite($logfile,$a);
							exit("SQL error. Quit script");
						 }
						$updated_time = date("Y-m-d H:i:s");
						
						if (mysqli_num_rows($result) > 0) {
							
							// output data of each row in the mysql query 
							while($db_row = mysqli_fetch_assoc($result)) {
									
								//(2.2)if the database has the auction in the json file, then we need to update the database 
								$bn_auc_id=$json_auction['auc'];
								$db_firstseen_timestamp = strtotime($db_row['firstseen']);
								$db_lastseen_timestamp = strtotime($db_row['lastseen']); 							
								if($CLI_LOGGING !== "minimal"){echo $ProductionMode ? "^" : "$num:{$json_auction['auc']} - Located in DB - ";}  //. First:{$db_row['firstseen']}| Last:{$db_row['lastseen']} | Current:$entry_date - "; //testing
								
								//Update the database record
								//if the file's date is before the auction record in the database was first seen....(not likely to happen often)
								if ($entry_timestamp < $db_firstseen_timestamp){
										
									//This is an earlier file.  Update the first seen time, the initial timeleft, first bid in the database
									if($CLI_LOGGING !== "minimal"){echo $ProductionMode ? "e" : "Updating with earlier record.".PHP_EOL;}  //TESTING purposes
									$update_firstseen = AucTimeFromFile($entry,"mysql_time");
									$update_initial_timeleft = $json_auction['timeLeft'];
									$update_bid_firstseen = $json_auction['bid'];
									$update_TimeonAH = $db_lastseen_timestamp-$entry_timestamp;
																	
									$update_earlier[] = array( "bn_auc_id" => $bn_auc_id, "firstseen" => $update_firstseen, "initial_timeleft" => $update_initial_timeleft, "bid_firstseen" => $update_bid_firstseen, "time_onAH" => $update_TimeonAH);							
									$file_updated_aucs+=1;
									$file_earlier_aucs+=1;								
																
								}
									
								//if the file's date is after the auction record in the database....
								elseif ($entry_timestamp > $db_firstseen_timestamp){
									if ($entry_timestamp > $db_lastseen_timestamp){
										  
										//This is the latest file.  Update bid_lastseen, lastseen, updated today, time on ah
										if($CLI_LOGGING !== "minimal"){echo $ProductionMode ? "l" : "Updating with latest record".PHP_EOL;}  //TESTING purposes
										$update_bid_lastseen = $json_auction['bid'];
										$update_lastseen = AucTimeFromFile($entry,"mysql_time");
										$update_TimeonAH = $entry_timestamp-$db_firstseen_timestamp; //gmdate('H:i:s',$entry_timestamp-$db_firstseen_timestamp); //time in seconds
										
										$update_later[] = array( "bn_auc_id" => $bn_auc_id, "bid_lastseen" => $update_bid_lastseen, "lastseen" => $update_lastseen, "updated" => $updated_time, "time_onAH" => $update_TimeonAH);				
										$file_updated_aucs+=1;
										$file_later_aucs+=1;
																												
									} 
									elseif($entry_timestamp==$db_lastseen_timestamp){
										if($CLI_LOGGING !== "minimal"){echo $ProductionMode ? "i" : "Ignored. Auction up to date.".PHP_EOL;}
										$file_ignored_aucs+=1;
									}
									else{ //I don't expect this else will be seen since all possibilities are covered.
										//echo "timestamp=$entry_timestamp not earlier than first seen ($db_firstseen_timestamp) nor later than last seen($db_lastseen_timestamp).  No action taken".PHP_EOL;  //testing purposes
										echo "Ignored. ..for some reason".PHP_EOL;
										$file_ignored_aucs+=1;	
									}
									
								}
								else {  //$entry_timestamp == $db_firstseen_timestamp
									if($CLI_LOGGING !== "minimal"){echo $ProductionMode ? "-" : "Not updated - Same as first seen".PHP_EOL;}  //testing purposes
									$file_ignored_aucs+=1;
								}
						}
					} 
					elseif(mysqli_num_rows($result) == 0) {
								
						//(2.1)if the auction in the json file was not found, then we need to add it to the mysql database.
						if($CLI_LOGGING !== "minimal"){echo $ProductionMode ? "+" : "$num:{$json_auction['auc']} - Adding".PHP_EOL;}
						$new_bn_auc_id = $json_auction['auc'];
						$new_realm_id = 1; //UPDATE - only setup for stonemaul realms right now.  When more realms are incorporated, this should update automatically
						$new_item_id = $json_auction['item'];
						$new_owner = $json_auction['owner'];
						$new_ownerRealm = $json_auction['ownerRealm'];
						$new_buyout = $json_auction['buyout'];
						$new_bid_firstseen = $json_auction['bid'];
						$new_bid_lastseen = $json_auction['bid'];
						$new_qty = $json_auction['quantity'];
						$new_firstseen = AucTimeFromFile ($entry,"mysql_time");
						$new_lastseen = AucTimeFromFile ($entry,"mysql_time");
						$new_initial_timeleft = $json_auction['timeLeft'];
						$new_rand = $json_auction['rand'];
						
						//INSERT INTO `auctions` (`bn_auc_id`, `realm_id`, `item_id`, `owner`, `owner_realm`, `buyout`, `bid_firstseen`, `bid_lastseen`, `qty`, `firstseen`, `lastseen`, `initial_timeleft`)VALUES (368022746, 1, 124461, 'Myzery', 'Dunemaul', 11800000, 11600250, 11600250, 10, '2016-12-09 22:26:48', '2016-12-09 22:26:48', 'LONG')
						//que up several inserts and do them all at once.
						if ($file_added_aucs ==0){
							$new_row_values = "($new_bn_auc_id, $new_realm_id, $new_item_id, '$new_owner', '$new_ownerRealm', $new_buyout, $new_bid_firstseen, $new_bid_lastseen, $new_qty, '$new_firstseen', '$new_lastseen', '$new_initial_timeleft', '$updated_time', $new_rand, 0)";
							$file_added_aucs+=1;	
						}
						//add comma for 2nd - final row
						else{
							$new_row_values .= " , ($new_bn_auc_id, $new_realm_id, $new_item_id, '$new_owner', '$new_ownerRealm', $new_buyout, $new_bid_firstseen, $new_bid_lastseen, $new_qty, '$new_firstseen', '$new_lastseen', '$new_initial_timeleft', '$updated_time', $new_rand, 0)";
							$file_added_aucs+=1;	
						}
					}
					else{
						//$result did not yield a result	
					}
						if( $num == $auc_total_currentfile){  //$num % 1000 == 0 || $num == $auc_total_currentfile){
							$log_update = "$num auctions processing - ADDING:$file_added_aucs; UPDATING WITH LATER:$file_later_aucs; UPDATING WITH EARLIER:$file_earlier_aucs; IGNORING:$file_ignored_aucs".PHP_EOL;
							echo PHP_EOL.PHP_EOL.$log_update;
							fwrite($logfile,$log_update);
						}
						
						//UPDATE - GIVE THE OPTION TO WRITE A % COMPLETE TO THE COMMAND LINE
						//  below will "clear" the CLI
						//	system("mode con:lines=80");  
						$num+=1;				
					}
					
					
					/****START UPDATE DATABASE *****/
					//Add new rows if we found some to be added.
					if(!empty($new_row_values)){
						//INSERT new rows
						$new_rows = "INSERT INTO `$auc_table` (`bn_auc_id`, `realm_id`, `item_id`, `owner`, `owner_realm`, `buyout`, `bid_firstseen`, `bid_lastseen`, `qty`, `firstseen`, `lastseen`, `initial_timeleft`,`updated`,`rand`,`time_on_ah`) ";
						$new_rows = $new_rows."VALUES $new_row_values";
						if (mysqli_query($conn, $new_rows)){
							//Log records added.
							$a="ADDED: $file_added_aucs".PHP_EOL;  //testing purposes
							echo $a;
							fwrite($logfile,$a);
							
						}
						else{
							
							//UPDATE - log error in inserting record.
							$error = "Error adding: " . mysqli_error($conn).PHP_EOL; //"Tried to run $new_rows".PHP_EOL;
							echo $error;
							$error_log.=$error;
							
							//TESTING
							fwrite($logfile,$error_log);
							exit("SQL error. Quit script");
						}
					}
					
					//Update database rows if we found some earlier auctions to be updated
					if(!empty($update_earlier)){
						
						/* Update rows using the following format:
							UPDATE auctions_test SET
								firstseen = CASE bn_auc_id
									WHEN 367997479 THEN 'Dave'
									WHEN 368657291 THEN 'Sarah'
									WHEN 367943876 THEN 'Carl'
								END,
								initial_timeleft = CASE bn_auc_id
									WHEN 367997479 THEN 'Lawrence'
									WHEN 368657291 THEN 'Los Angeles'
									WHEN 367943876 THEN 'Dallas'
								END
							WHERE bn_auc_id IN (367997479, 368657291, 367943876);
						
						 * Update these fields
						 * `firstseen`='$update_firstseen', `initial_timeleft`='$update_initial_timeleft', `bid_firstseen`=$update_bid_firstseen, `time_on_ah`='$update_TimeonAH' WHERE `bn_auc_id`=$bn_auc_id"; 
						 * 
						 */
						 
						$update_rows = "UPDATE `$auc_table` SET `firstseen` = CASE `bn_auc_id` ";
						//Loop through arrays "WHEN $bn_auc_id THEN $firstseen_value"
						foreach ($update_earlier as $key => $value) {
							$update_rows .= "WHEN {$update_earlier[$key]['bn_auc_id']} THEN '{$update_earlier[$key]['firstseen']}' ";
						}
						
						$update_rows.="END, `initial_timeleft` = CASE `bn_auc_id` ";
						//Loop through arrays to construct "WHEN $bn_auc_id THEN $initial_timeleft_value"
						foreach ($update_earlier as $key => $value) {
							$update_rows .= "WHEN {$update_earlier[$key]['bn_auc_id']} THEN '{$update_earlier[$key]['initial_timeleft']}' ";
						}
						
						$update_rows.="END, `bid_firstseen` = CASE `bn_auc_id` ";
						//Loop through arrays to construct "WHEN $bn_auc_id THEN $bid_firstseen_value"
						foreach ($update_earlier as $key => $value) {
							$update_rows .= "WHEN {$update_earlier[$key]['bn_auc_id']} THEN {$update_earlier[$key]['bid_firstseen']} ";
						}
						
						$update_rows.="END, `time_on_ah` = CASE `bn_auc_id` ";
						//Loop through arrays to construct "WHEN $bn_auc_id THEN $time_on_ah_value"
						foreach ($update_earlier as $key => $value) {
							$update_rows .= "WHEN {$update_earlier[$key]['bn_auc_id']} THEN {$update_earlier[$key]['time_onAH']} ";
						}				
						$update_rows.="END WHERE `bn_auc_id` IN ";
						//Create string "(bn_id_1, bn_id_2, bn_id_3)";
						$arry_bn_aucs=array();
						foreach ($update_earlier as $key => $value) {
							$arry_bn_aucs[] = $update_earlier[$key]['bn_auc_id'];
						}
						$update_rows.= "(".implode(",",$arry_bn_aucs).")";
						
						if (mysqli_query($conn, $update_rows)){
							
							//Log earlier records that were updated successfully
							$a="UPDATED WITH EARLIER: $file_earlier_aucs".PHP_EOL;  //testing purposes
							echo $a;
							fwrite($logfile,$a);
						}
						else{
							
							//UPDATE - log error with updating earlier record.
							$error = "Error description: " . mysqli_error($conn).PHP_EOL; //"Tried to run $update_rows".PHP_EOL;
							echo "Error updating earlier record".PHP_EOL."Error description: ".mysqli_error($conn).PHP_EOL;
							$error_log.=$error;
							
							//TESTING
							fwrite($logfile,$error_log);
							exit("SQL error. Quit script");
						}
					}
					
					//Update database rows if we found some later auctions to be updated				
					if (!empty($update_later)){
						/*
						 * Update these fields
						 * `bid_lastseen`=$update_bid_lastseen, `lastseen`='$update_lastseen', `updated`='$updated_time', `time_on_ah`='$update_TimeonAH' WHERE `bn_auc_id`=$bn_auc_id"; 
						 * 
						 * $update_later = array( "bn_auc_id" => $bn_auc_id, "bid_lastseen" => $update_bid_lastseen, "lastseen" => $update_lastseen, "updated" => $updated_time, "time_onAH" => $update_TimeonAH);
						 * 
						 */					
						
						
						
						//Use some manageable pieces so mySQL doesn't timeout.
						$chunk_processed = 0;
						$laterAucsTotalProcessed = 0;
						foreach(array_chunk($update_later,100) as $chunk){
							$update_rows = "UPDATE `$auc_table` SET `bid_lastseen` = CASE `bn_auc_id` ";
							//Loop through arrays "WHEN $bn_auc_id THEN $firstseen_value"
							foreach ($chunk as $key => $value) {
								$update_rows .= "WHEN {$chunk[$key]['bn_auc_id']} THEN '{$chunk[$key]['bid_lastseen']}' ";
							}
							
							$update_rows.="END, `lastseen` = CASE `bn_auc_id` ";
							//Loop through arrays to construct "WHEN $bn_auc_id THEN $initial_timeleft_value"
							foreach ($chunk as $key => $value) {
								$update_rows .= "WHEN {$chunk[$key]['bn_auc_id']} THEN '{$chunk[$key]['lastseen']}' ";
							}
							
							$update_rows.="END, `updated` = CASE `bn_auc_id` ";
							//Loop through arrays to construct "WHEN $bn_auc_id THEN $initial_timeleft_value"
							foreach ($chunk as $key => $value) {
								$update_rows .= "WHEN {$chunk[$key]['bn_auc_id']} THEN '{$chunk[$key]['updated']}' ";
							}
							
							$update_rows.="END, `time_on_ah` = CASE `bn_auc_id` ";
							//Loop through arrays to construct "WHEN $bn_auc_id THEN $initial_timeleft_value"
							foreach ($chunk as $key => $value) {
								$update_rows .= "WHEN {$chunk[$key]['bn_auc_id']} THEN '{$chunk[$key]['time_onAH']}' ";
							}
							$update_rows.="END WHERE `bn_auc_id` IN ";
							
							//Create string "(bn_id_1, bn_id_2, bn_id_3)";
							$arry_bn_aucs=array();
							foreach ($chunk as $key => $value) {
								$arry_bn_aucs[] = $chunk[$key]['bn_auc_id'];
							}
							$update_rows.= "(".implode(",",$arry_bn_aucs).")";
							
							
							if (mysqli_query($conn, $update_rows)){
								$chunk_processed++;	
								//Log later records that were updated successfully
								//$a="UPDATED WITH LATER: $file_later_aucs".PHP_EOL;  //testing purposes
								$chunkSize = count($chunk);
								$laterAucsTotalProcessed+=$chunkSize;
								$a="UPDATED WITH LATER(CHUNK#{$chunk_processed}): $chunkSize".PHP_EOL;  //testing purposes
								if($CLI_LOGGING !== "minimal"){echo $a;}
								//fwrite($logfile,$a);
							}
							else{
								
								//Log error in inserting record.
								$error = "Error description: " . mysqli_error($conn).PHP_EOL."Tried to run $update_rows".PHP_EOL;
								echo "Error updating later record".PHP_EOL."Error description: ".mysqli_error($conn).PHP_EOL;
								$error_log.=$error;
								
								//TESTING
								fwrite($logfile,$error_log);
								exit("SQL error. Quit script");
							}
						}
						$Stringlater_processed = ($CLI_LOGGING == 'minimal') ? "UPDATED WITH LATER: $laterAucsTotalProcessed".PHP_EOL : "UPDATED WITH LATER (TOTAL): $laterAucsTotalProcessed".PHP_EOL;
						echo $Stringlater_processed;
						fwrite($logfile,$Stringlater_processed);
						$file_later_aucs=$laterAucsTotalProcessed;
					}
					
					$total_file_auc_processed = $file_added_aucs+$file_earlier_aucs+$file_later_aucs+$file_ignored_aucs;
					
					
					
					//ADD FILE TO FILES PROCESSED	Only add if the auctions contained in the file initially were all processed
					//	
					if($auc_total_currentfile == $total_file_auc_processed){	
						$new_row_file = "INSERT INTO `files_processed` (`filename`,`completed`,`auctions`, `updated`,`file_date`) VALUES ('$entry', 1, $total_file_auc_processed,'$updated_time','$entry_date')";
						if (mysqli_query($conn, $new_row_file)){
								//UPDATE - log files that were added successfully
								echo "SUCCESS: Added $entry database".PHP_EOL;
								echo "$files_processed_count of $pre_eval_total[$dir] files in $dir complete".PHP_EOL.PHP_EOL;//testing purposes
								$summary_processed.=$entry.PHP_EOL;
						}
						else{
								//UPDATE - log error in inserting record.
								$error="Failure to add a processed file to the mySQL table.  Error description: " . mysqli_error($conn).PHP_EOL; //"Tried to run $new_row_file".PHP_EOL.PHP_EOL;
								//echo $error;
								$error_log.=$error;
								
								//TESTING
								fwrite($logfile,$error_log);
								exit("SQL error. Quit script");  //testing
						}
					}
					else{
						$a="File not recorded: $entry".PHP_EOL."REVIEWED: $auc_total_currentfile {PHP_EOL}NEW: $file_added_aucs{PHP_EOL}UPDATED WITH EARLIER: $file_earlier_aucs{PHP_EOL}UPDATED WITH LATER: $file_later_aucs{PHP_EOL}IGNORED: $file_ignored_aucs{PHP_EOL}";
						echo $a;
						fwrite($logfile,$a);
					}
					/****END UPDATE DATABASE *****/

					$added_aucs+=$file_added_aucs;
					$updated_aucs+=$file_updated_aucs;
					$ignored_aucs+=$file_ignored_aucs;
					$aucs_earlier+=$file_earlier_aucs;
					$aucs_later+=$file_later_aucs;
					$total_auc_modded = $added_aucs+$aucs_earlier+$aucs_later;
					mysqli_close($conn);
				}else{
					
					//File already exists in db and can be ignored
					//Show ignored json files in command line interface (CLI) and long if not in production mode.
					if(!$ProductionMode){echo "Skipping {$entry} because it was entered in the database".PHP_EOL;}
					$files_ignore+=1;
					if(!$ProductionMode){
						$log_update = "$entry: - ignored".PHP_EOL;
						$cli_log .= $log_update;
						echo $log_update;
						fwrite($logfile,$log_update);
					}
					$summary_ignored.=$entry.PHP_EOL;
				}
			}else{
				if(strpos($entry,".json")!==false && ($script_start_time-$olderThanSec > AucTimeFromFile($entry))){
				//File is older than time specified
				$files_tooOld+=1;  
				$summary_tooOld.=$entry.PHP_EOL;
				}
			}
		}

		closedir($handle);
	}
}

//SUMMARY - Generate a summary of auctions processed.
$script_end_time = microtime(TRUE);
$script_end_date = date('r');
$execution_time=gmdate("H:i:s", $script_end_time-$script_start_time);
$modpersec=number_format($total_auc_modded/($script_end_time-$script_start_time),1);
//$summary.= PHP_EOL.PHP_EOL.PHP_EOL."----SUMMARY----".PHP_EOL."TOTAL FILES PROCESSED: $auc_files".PHP_EOL.$summary_processed.PHP_EOL.PHP_EOL."FILES IGNORED: $files_ignore".PHP_EOL.$summary_ignored.PHP_EOL.PHP_EOL;
$summary.= PHP_EOL.PHP_EOL.PHP_EOL."----SUMMARY----".PHP_EOL."TOTAL FILES PROCESSED: $auc_files".PHP_EOL."FILES PREVIOULY PROCESSED: $files_ignore".PHP_EOL."OLD FILES skipped: $files_tooOld".PHP_EOL.PHP_EOL;
$summary.="AUCTIONS REVIEWED: $total_aucs".PHP_EOL."ADDED: $added_aucs".PHP_EOL."UPDATE TOTAL: $updated_aucs".PHP_EOL."UPDATED WITH EARLIER: $aucs_earlier".PHP_EOL."UPDATED WITH LATER: $aucs_later".PHP_EOL."UNTOUCHED: $ignored_aucs".PHP_EOL."STARTED: $script_start_date".PHP_EOL."FINISHED: $script_end_date".PHP_EOL."ELAPSED TIME: $execution_time s".PHP_EOL."MODS PER SEC: $modpersec".PHP_EOL.PHP_EOL;
if (!empty($error_log)){$summary.= "ERRORS: ".PHP_EOL.$error_log.PHP_EOL.PHP_EOL.PHP_EOL.PHP_EOL;}
else{$summary.="NO ERRORS".PHP_EOL.PHP_EOL.PHP_EOL.PHP_EOL;}

//OUTPUT a summary to the COMMAND LINE//
echo $summary;

//OUTPUT a summary to the logfile and close it.
fwrite($logfile,$summary);
fclose($logfile);
?>