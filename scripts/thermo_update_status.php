<?php
$start_time = microtime(true);
require(dirname(__FILE__).'/../common.php');

global $util;

$util::logInfo( 'status: Start.' );

/**
  * This script periodically (once a minute) queries each thermostat and writes the status into
  * the hvac_status table.  There is just one record in the hvac_status table for each
  * thermostat and it shows the current status of the heat, cool, and fan, plus the
  * time it saw that those first started.
  *
  * For each run the status is updated but not the start time.  Once it goes from off to on, the start_time is updated.
  * When it goes from on to off, an entry is added to hvac_cycles
  * Date is simply the last time the status was updated
  */

/**
  * Need to add a touch file at the top of the file and a delete at the bottom
  * And then on start execution test for the touch file which will report abnormal termination of previous instance.
  * For extra credit the touch file could contian the PID of the task that made it and the test could also see if it is still running or not.
  */

// Bandaid to keep things moving
//$util::logDebug( 'status: 0' );
$database = new Database();
$pdo = $database->dbConnection();
if( is_null( $pdo ) ){
  $util::logError( 'status: Cannot connect to DB, bailing.  Really ought to email someone about this.' );
  die();
}
//$util::logDebug( 'status: A' );
try{
//$util::logDebug( 'status: B' );
//$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION, PDO::ERRMODE_WARNING );

  $sql = "
SELECT stat.thermostat_id
      ,stat.tstat_uuid
      ,stat.ip
      ,stat.user_id
 FROM {$database->table_prefix}thermostats AS stat";
//$util::logDebug( "status: C --->$sql<---" );
  $stmt = $pdo->prepare( $sql );
//$util::logDebug( 'status: D' );
}
catch( Exception $e ){
//$util::logDebug( 'status: E' );
  $util::logError( 'status: DB Exception while preparing SQL: ' . $e->getMessage() );
  die();
}
//$util::logDebug( 'status: F' );
$stmt->execute();
//$util::logDebug( 'status: G' );
$allThermostats = $stmt->fetchAll( PDO::FETCH_ASSOC );
//$util::logDebug( 'status: H' );

try{
  // Query to location info about the thermostat.  Might find nothing if this is the first time.
  $sql = "SELECT * FROM {$database->table_prefix}hvac_status WHERE tstat_uuid=?"; // Really should name columns instead of using *
  $getStatInfo = $pdo->prepare( $sql );

  // If this was thr first contact, add info about the stat to the DB
  $sql = "INSERT INTO {$database->table_prefix}hvac_status( tstat_uuid, date, start_date_heat, start_date_cool, start_date_fan, heat_status, cool_status, fan_status ) VALUES( ?, ?, ?, ?, ?, ?, ?, ? )";
  $insertStatInfo = $pdo->prepare( $sql );

  $sql = "
UPDATE {$database->table_prefix}hvac_status
   SET date = ?
      ,start_date_heat = ?
      ,start_date_cool = ?
      ,start_date_fan = ?
      ,heat_status = ?
      ,cool_status = ?
      ,fan_status = ?
 WHERE tstat_uuid = ?";
  $updateStatStatus = $pdo->prepare( $sql );

  $sql = "INSERT INTO {$database->table_prefix}hvac_cycles( thermostat_id, system, start_time, end_time ) VALUES( ?, ?, ?, ? )";
  $cycleInsert = $pdo->prepare( $sql );

  // Query to retrieve prior setpoint.  Might find nothing if this is the first time.
  $sql = "SELECT set_point FROM {$database->table_prefix}setpoints WHERE thermostat_id=? ORDER BY switch_time DESC LIMIT 1";
  $getPriorSetPoint = $pdo->prepare( $sql );

  $sql = "INSERT INTO {$database->table_prefix}setpoints( thermostat_id, set_point, switch_time ) VALUES( ?, ?, ? )";
  $insertSetPoint = $pdo->prepare( $sql );
//$util::logDebug( 'status: I' );
}
catch( Exception $e ){
  $util::logError( 'status: DB Exception while preparing SQL: ' . $e->getMessage() );
  die();
}
global $lockFile;

$now = date( 'Y-m-d H:i:00' );
foreach( $allThermostats as $thermostatRec ){
//$util::logDebug( 'status: J' );
  $lockFileName = $lockFile . $thermostatRec['thermostat_id'];
  $lock = @fopen( $lockFileName, 'w' );
  if( !$lock ){
    $util::logError( "status: Could not write to lock file $lockFileName" );
    continue;
  }

  if( flock($lock, LOCK_EX) ){
    try{
      // Query thermostat info
      $stat = new Stat( $thermostatRec );

      /**
        * This catches the uuid which is required for data insert.
        *
        * Really should use a surrogate key (thermostat_id) instead of the uuid for data storage.
        *
        * What do we do when there is a changed thermostat?  The history is tied to the uuid. That is BAD
        * Need a system generated surrogate key instead of uuid to join from thermostat table to data table.
        * Should compare the detected uuid back to the thermostat table record
        * On match, do nothing.  On 'no match', make sure it matches no other record too and then update existing record (and log it)
        */
//$util::logDebug( 'status: K' );
      $stat->getSysInfo();
//$util::logDebug( 'status: L' );

// there is an update to tlib - this error check no longer helps.  tlib throws an exception if it got no data
      if( $stat->connectOK != 0 ){
//$util::logDebug( 'status: M' );
        $util::logWarn( "status: connectOK is not zero!  We should not proceed!  connectOK = ($stat->connectOK).  Perhaps for a macro level retry even though the micro level retry already failed?" );
        // An error here may not need to be fatal, but if it worked, should verify that stat uuid matches expected uuid in DB
        // If it does not match expected, does it match ANY?  Email admin if the user ID for matched does not match user ID of expected!! (possible hacking?)
      }

      // Get thermostat state (time, temp, mode, hold, override)
//$util::logDebug( 'status: N' );
      $stat->getStat();
//$util::logDebug( 'status: O' );
      if( $stat->connectOK == 0 ){
//$util::logDebug( 'status: P' );
        $heatStatus = ($stat->tstate == 1) ? 1 : 0;
        $coolStatus = ($stat->tstate == 2) ? 1 : 0;
        $fanStatus  = ($stat->fstate == 1) ? 1 : 0;
//$util::logDebug( 'status: P0 RAW $stat->tstate ' . $stat->tstate );
//$util::logDebug( 'status: P1 $heatStatus ' . $heatStatus );
//$util::logDebug( 'status: P2 $coolStatus ' . $coolStatus );
//$util::logDebug( 'status: P3 $fanStatus ' . $fanStatus );

        // Get current setPoint from thermostat
        // t_heat or t_cool may not exist if thermostat is running in battery mode (will it even talk on WiFi if the power is out?)
        $setPoint = ($stat->tmode == 1) ? $stat->t_heat : $stat->t_cool;
      }
      else{
//$util::logDebug( 'status: Q' );
        $util::logError( 'status: Thermostat failed to respond with present status' );
        // Instead of continue, I should throw a thermostat exception!
        continue; // Cannot continue workting on this thermostat, try the next one in the list.
      }
//$util::logDebug( 'status: R' );

      // Get prior setPoint from database
      $getPriorSetPoint->execute(array($thermostatRec['thermostat_id']));
      $priorSetPoint = $getPriorSetPoint->fetchColumn();
//$util::logDebug( 'status: S' );

      // Get prior state info from DB
      $priorStartDateHeat = null;
      $priorStartDateCool = null;
      $priorStartDateFan = null;
      $priorHeatStatus = 0; // For some reason false stopped working for me.  I had to switch to 0.  So I also changed true to 1 even though it was still working.  Seriously WTF?
      $priorCoolStatus = 0;
      $priorFanStatus = 0;

// Possibly controversial code change here.  This assumes the uuid never changes once set.
      // Look up thermostat previous status based on the uuid (uuid as reported by the thermostat - BAD IDEA)
      $getStatInfo->execute( array( $stat->thermostat_id ) );
//$util::logDebug( 'status: T' );

      if( $getStatInfo->rowCount() < 1 ){
        // not found - this is the first time connection for this thermostat
//$util::logDebug( 'status: I think I found a new/different thermostat at the specified URL' );
// Perhaps key in on this logic to drive the deep query for the stat??
        $startDateHeat = ($heatStatus) ? $now : null;
        $startDateCool = ($coolStatus) ? $now : null;
        $startDateFan = ($fanStatus) ? $now : null;

//$util::logDebug( "status: Inserting record for a brand new never before seen thermostat with time = ($now) H $heatStatus C $coolStatus F $fanStatus SDH $startDateHeat SDC $startDateCool SDF $startDateFan for UUID $stat->uuid" );
// Have been getting really lucky here.  Communicatiuon errors with the stat leave NULLs in that do not match existing stats.
// Leading to false idea that the stat we're talking to is new (because existing stats not equal NULL on uuid)
// So attempt to insert record for new stat, but fail because no NULLs allowed in key columns.  So no new record.  Lucky!
// Proper fix is to abort when the stat was not able to be reached.
// Also proper fix includes not trying to insert NULLs and catching SQL errors when something happens like that (and log it)
        $insertStatInfo->execute( array( $stat->thermostat_id, $now, $startDateHeat, $startDateCool, $startDateFan, $heatStatus, $coolStatus, $fanStatus ) );

//$util::logDebug( "setpoints: Inserting record for a brand new never before seen thermostat with setpoint=$setPoint, time=($now) " );
        $insertSetPoint->execute( array( $thermostatRec['thermostat_id'], $setPoint, $now ) );
      }
      else{
        $hvacStatusChanged = 0;
        while( $row = $getStatInfo->fetch( PDO::FETCH_ASSOC ) ){
          // This SQL had _BETTER_ pull only one row or else there is a data integrity problem!
          // and without an ORDER BY on the SELECT there is no way to know you're geting the same row from this each time
          $priorStartDateHeat = $row['start_date_heat'];
          $priorStartDateCool = $row['start_date_cool'];
          $priorStartDateFan = $row['start_date_fan'];
          $priorHeatStatus = $row['heat_status'];
          $priorCoolStatus = $row['cool_status'];
          $priorFanStatus = $row['fan_status'];
//$util::logDebug( 'status: T1 $priorHeatStatus ' . $heatStatus . ' from ' . $row['heat_status'] );
//$util::logDebug( 'status: T2 $priorCoolStatus ' . $coolStatus . ' from ' . $row['cool_status'] );
//$util::logDebug( 'status: T3 $priorFanStatus ' . $fanStatus . ' from ' . $row['fan_status'] );
        }
//$util::logDebug( "status:  uuid = ($stat->uuid) // $thermostatRec[tstat_uuid] GOT PRIOR STATE H $priorHeatStatus C $priorCoolStatus F $priorFanStatus SDH $priorStartDateHeat SDC $priorStartDateCool SDC $priorStartDateFan" );

        // update start dates if the cycle just started
        $newStartDateHeat = (!$priorHeatStatus && $heatStatus) ? $now : $priorStartDateHeat;
        $newStartDateCool = (!$priorCoolStatus && $coolStatus) ? $now : $priorStartDateCool;
        $newStartDateFan = (!$priorFanStatus && $fanStatus) ? $now : $priorStartDateFan;

        // if status has changed from on to off, update hvac_cycles
        if( $priorHeatStatus && !$heatStatus ){
//$util::logDebug( "status: $stat->uuid // $thermostatRec[tstat_uuid] Finished Heat Cycle - Adding Hvac Cycle Record for $stat->thermostat_id 1 $priorStartDateHeat $now" );
          $cycleInsert->execute( array( $stat->thermostat_id, 1, $priorStartDateHeat, $now ) );
          $newStartDateHeat = null;
          $hvacStatusChanged = 1;
        }
        if( $priorCoolStatus && !$coolStatus ){
//$util::logDebug( "status: $stat->uuid // $thermostatRec[tstat_uuid] Finished Cool Cycle - Adding Hvac Cycle Record for $stat->thermostat_id 2 $priorStartDateCool $now" );
          $cycleInsert->execute( array( $stat->thermostat_id, 2, $priorStartDateCool, $now ) );
          $newStartDateCool = null;
          $hvacStatusChanged = 1;
        }
        if( $priorFanStatus && !$fanStatus ){
//$util::logDebug( "status: $stat->uuid // $thermostatRec[tstat_uuid] Finished Fan Cycle - Adding Hvac Cycle Record for $stat->thermostat_id 3 $priorStartDateFan $now" );
          $cycleInsert->execute( array( $stat->thermostat_id, 3, $priorStartDateFan, $now ) );
          $newStartDateFan = null;
          $hvacStatusChanged = 1;
        }
//$util::logDebug( 'status: U $hvacStatusChanged is ' . $hvacStatusChanged );
        if( $hvacStatusChanged == 1 ){
          // update the status table
//$util::logDebug( "status: Updating record with $now SDH $newStartDateHeat SDC $newStartDateCool SDF $newStartDateFan H $heatStatus C $coolStatus F $fanStatus for UUID $stat->uuid" );
          $updateStatStatus->execute( array( $now, $newStartDateHeat, $newStartDateCool, $newStartDateFan, $heatStatus, $coolStatus, $fanStatus, $stat->uuid) );
//$util::logDebug( 'status: I think there were (' . $updateStatStatus->rowCount() . ') rows updated (expecting 1).' );
        }
else{
//$util::logDebug( 'status: No change so no update' );
}
        //Update the setpoints table
        if( $setPoint != $priorSetPoint ){
          $util::logDebug( "status: Inserting changed setpoint record SP=$setPoint, old=($priorSetPoint), time=($now) " );
          $insertSetPoint->execute( array( $thermostatRec['thermostat_id'], $setPoint, $now ) );
        }
      }
    }
    catch( Exception $e ){
      $util::logError( 'status: Thermostat Exception ' . $e->getMessage() );
    }
    flock( $lock, LOCK_UN );
  }
  else{
    $util::logError( "status: Couldn't get file lock for thermostat {$thermostatRec['thermostat_id']}" );
  }
  fclose( $lock );
}
//$database->disconnect();
$util::logInfo( 'status: End.  Execution time was ' . (microtime(true) - $start_time) . ' seconds.' );

?>