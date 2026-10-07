<?php

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);

error_reporting(E_ALL);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
require_once 'scripts/common.php';
$config = get_config();

// Only a real YYYY-MM-DD reaches the queries and the chart path.
$theDate = date('Y-m-d');
if (isset($_GET['date']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $_GET['date'], $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
  $theDate = $_GET['date'];
}
$chart = "Combo-$theDate.png";

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

$statement1 = $db->prepare("SELECT COUNT(*) FROM detections WHERE Date == :date");
ensure_db_ok($statement1);
$statement1->bindValue(':date', $theDate);
$result1 = $statement1->execute();
$totalcount = $result1->fetchArray(SQLITE3_ASSOC);

if(isset($_GET['blocation']) ) {

	header("Content-type: text/csv");
	header("Content-Disposition: attachment; filename=result_file.csv");
	header("Pragma: no-cache");
	header("Expires: 0");

	$list = array ();

	//$hrsinday = intval(($sunset-$sunrise)/60/60);
	$hrsinday = 24;
	for($i=0;$i<$hrsinday;$i++) {
		$starttime = strtotime("12 AM") + (3600*$i);

		$statement1 = $db->prepare("SELECT DISTINCT(Com_Name), COUNT(*) FROM detections WHERE Date == \"$theDate\" AND Time > '".date("H:i", $starttime)."' AND Time < '".date("H:i",$starttime + 3600)."' AND Confidence > 0.75 GROUP By Com_Name ORDER BY COUNT(*) DESC");
		ensure_db_ok($statement1);
		$result1 = $statement1->execute();

		$detections = [];
		while($detection=$result1->fetchArray(SQLITE3_ASSOC))
		{
			$detections[$detection["Com_Name"]] = $detection["COUNT(*)"];
		}
		foreach($detections as $com_name=>$scount)
		{
			array_push($list, array($com_name,'','','1','',$_GET['blocation'],$config["LATITUDE"],$config["LONGITUDE"],date("m/d/Y", strtotime($theDate)), date("H:i", $starttime), $_GET['state'], $_GET['country'], $_GET['protocol'], $_GET['num_observers'], '60', 'Y', $_GET['dist_traveled'],'',$_GET['notes'] ) );
		}
	}

	$output = fopen("php://output", "w");
    foreach ($list as $row) {
        fputcsv($output, $row);
    }
    fclose($output);

	die();
}
if (get_included_files()[0] === __FILE__) {
	echo '<!DOCTYPE html>
<html lang="en">
<head>

<style>
#attribution-dialog p {
	display:inline;
}
#attribution-dialog {
	text-align: left;
}
</style>
</head>
<body>';
	}
?>
<script src="static/dialog-polyfill.js"></script>
<div class="history centered">

<dialog id="attribution-dialog">
  <p style="display:none" id="filename"></p>
  <h1 id="modalHeading">Enter Checklist Information</h1>
  <p id="modalText">Location Name:</p> <input placeholder="My house" id="blocation"><br><br>
  <p id="modalText">State (2 letter code):</p> <input maxlength="3" id="state"><br><br>
  <p id="modalText">Country (2 letter code):</p> <input maxlength="2" id="country"><br><br>
  <p id="modalText">Protocol:</p> <select id="protocol">
  <option value="casual">casual</option>
  <option value="stationary">stationary</option>
  <option value="traveling">traveling</option>
  <option value="area">area</option>
</select>
<br><br>
  <p id="modalText">Number of observers:</p> <input type="number" id="num_observers"><br><br>
  <p id="modalText">Distance traveled (miles):</p> <input type="number" id="dist_traveled"><br><br>
  <p id="modalText">Notes:</p> <input id="notes"><br><br>
  <button onclick="submitID()">Submit</button>
</dialog>
<script>
var dialog = document.querySelector('dialog');
dialogPolyfill.registerDialog(dialog);

function showDialog() {
  document.getElementById('attribution-dialog').showModal();
}

function closeDialog() {
  document.getElementById('attribution-dialog').close();
}

function submitID() {
  blocation = document.getElementById("blocation").value;
  state = document.getElementById("state").value;
  country = document.getElementById("country").value;
  protocol = document.getElementById("protocol").value;
  num_observers = document.getElementById("num_observers").value;
  dist_traveled = document.getElementById("dist_traveled").value;
  notes = document.getElementById("notes").value;

  window.open("history.php?blocation="+blocation+"&state="+state+"&country="+country+"&protocol="+protocol+"&num_observers="+num_observers+"&dist_traveled="+dist_traveled+"&notes="+notes+"&date="+"<?php echo $theDate; ?>");

  document.getElementById('attribution-dialog').innerHTML = "<h3>Success!</h3><p>Your checklist will start downloading momentarily.<br><br>Refer to <a target='_blank' href='https://ebird.org/content/eBirdCommon/docs/ebird_import_data_process.pdf'>this guide</a> for information on how to import it in eBird. The checklist file format is: 'eBird Record Format (Extended)'.<br><br><span style='font-size:small'>Note: Only detections with a confidence > 0.75 were included, and entries have been limited to 1 detection per hour per species, to comply with eBird's data quality guidelines.<br>It's always good practice to manually verify your checklist before submitting, especially for nocturnal hours.</span></p><br><br><button onclick=\"closeDialog()\">Close</button>";

}

</script>  

<?php
$prevDay = date('Y-m-d', strtotime("$theDate -1 day"));
$nextDay = date('Y-m-d', strtotime("$theDate +1 day"));
?>
<div class="day-bar">
  <a class="day-step" href="views.php?view=Daily+Charts&amp;date=<?php echo $prevDay; ?>" rel="prev">&larr; <?php echo date('D j M', strtotime($prevDay)); ?></a>
  <form action="views.php" method="GET">
    <input type="date" name="date" value="<?php echo $theDate;?>" max="<?php echo date('Y-m-d'); ?>" aria-label="Day">
    <button type="submit" name="view" value="Daily Charts">Submit Date</button>
  </form>
<?php if ($theDate < date('Y-m-d')) { ?>
  <a class="day-step" href="views.php?view=Daily+Charts&amp;date=<?php echo $nextDay; ?>" rel="next"><?php echo date('D j M', strtotime($nextDay)); ?> &rarr;</a>
<?php } else { ?>
  <span class="day-step is-none" aria-hidden="true"></span>
<?php } ?>
</div>
<br>
<table class="overview">
  <tr>
    <th>Total Detections For The Day</th>
    <td><?php echo $totalcount['COUNT(*)']; ?></td>
    <td style="padding:unset"><img src="images/spinner.gif" id="SwipeSpinner" hidden style="height:30px;"></td>
  </tr>
</table>
    	<?php // <br><button type="button" onclick="showDialog()">Export as CSV for eBird</button><br><br> ?>
<?php
$time = time();

if (file_exists('./Charts/'.$chart)) {
  // Phones get the stacked layout daily_plot.py draws alongside the wide one.
  $phoneChart = "Combo-phone-$theDate.png";
  echo "<picture>";
  if (file_exists('./Charts/'.$phoneChart)) {
    echo "<source media=\"(max-width: 700px)\" srcset=\"/Charts/$phoneChart?nocache=$time\">";
  }
  echo "<img src=\"/Charts/$chart?nocache=$time\" alt=\"Species heard on $theDate, by hour\"></picture>";
} elseif ($totalcount['COUNT(*)'] > 0) {
  echo "<p class=\"chart-note\">The chart for $theDate is being drawn. It appears within a few minutes.</p>";
} else {
  echo "<p class=\"chart-note\">No detections on $theDate, so there is no chart.</p>";
}
echo "</div>";
if (get_included_files()[0] === __FILE__) {
	echo '</html>
</body>';
}
