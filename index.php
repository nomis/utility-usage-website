<?
date_default_timezone_set("Europe/London");
include("setup.php");
include("median.php");

if (isset($_GET["meter"]) && $_GET["meter"]!="") {
   if (preg_match('/^[0-9]+$/',$_GET["meter"])) {
      $meter=$_GET["meter"];
   } else {
      $meter="";
   }
} else {
   if (isset($_GET["meter"])) { unset($_GET["meter"]); }
   $meter="1";
}
if ($meter == "") {
   header("HTTP/1.1 404 No Data");
   header("Content-Type: text/plain");
   echo "No data for $meter\n";
	die();
}

header("Content-Type: text/html; charset=UTF-8");
$title=date("Y");
$xtitle=$ytitle=$title;
$atitle=$mtitle=$dtitle="";
if ($_GET["data"]=="today") { $_GET["data"]=date("Ymd"); }
if (preg_match('/^([0-9]{4})([0-9]{2})?([0-9]{2})?$/',$_GET["data"],$matches)) {
	$title=$_GET["data"];
	$xtitle=$ytitle=$matches[1];
	if ($matches[2]!="") { $xtitle.="-".$matches[2]; $mtitle=$matches[2]; }
	if ($matches[3]!="") { $xtitle.="-".$matches[3]; $dtitle=$matches[3]; }
} else if (strlen($_GET["data"]) > 0) {
	header("HTTP/1.1 404 No Data");
	header("Content-Type: text/plain");
	echo "No data for ".$_GET["data"]."\n";
	die();
}

$id=$meter;
$uri="";
$meter=isset($_GET["meter"]) ? ".".$_GET["meter"] : "";
define("DAY",$dtitle!="");

define("UX",0);

$monlen=Array(0,
	31,(date("L", mktime(0,0,0,1,1,substr($title,0,4))) ? 29 : 28),31,
	30,31,30,
	31,31,30,
	31,30,31);

if (isset($data)) { unset($data); }

$usage="Usage";
$start_day=1;

if (strlen($title) == 4) { // year
	$name="Month";

	$start=mktime(0,0,0,1,$start_day,$ytitle);
	$start=mktime(0,0,0,1,$start_day,$ytitle);
	$finish=mktime(0,0,0,1,$start_day,$ytitle+1);

	$output=Array(
		Array('id'=>'01','url'=>$ytitle.'01',name=>'January',sname=>'Jan',start=>mktime(0,0,0,1,$start_day,$ytitle),stop=>mktime(0,0,0,2,$start_day,$ytitle)),
		Array('id'=>'02','url'=>$ytitle.'02',name=>'February',sname=>'Feb',start=>mktime(0,0,0,2,$start_day,$ytitle),stop=>mktime(0,0,0,3,$start_day,$ytitle)),
		Array('id'=>'03','url'=>$ytitle.'03',name=>'March',sname=>'Mar',start=>mktime(0,0,0,3,$start_day,$ytitle),stop=>mktime(0,0,0,4,$start_day,$ytitle)),
		Array('id'=>'04','url'=>$ytitle.'04',name=>'April',sname=>'Apr',start=>mktime(0,0,0,4,$start_day,$ytitle),stop=>mktime(0,0,0,5,$start_day,$ytitle)),
		Array('id'=>'05','url'=>$ytitle.'05',name=>'May',sname=>'May',start=>mktime(0,0,0,5,$start_day,$ytitle),stop=>mktime(0,0,0,6,$start_day,$ytitle)),
		Array('id'=>'06','url'=>$ytitle.'06',name=>'June',sname=>'Jun',start=>mktime(0,0,0,6,$start_day,$ytitle),stop=>mktime(0,0,0,7,$start_day,$ytitle)),
		Array('id'=>'07','url'=>$ytitle.'07',name=>'July',sname=>'Jul',start=>mktime(0,0,0,7,$start_day,$ytitle),stop=>mktime(0,0,0,8,$start_day,$ytitle)),
		Array('id'=>'08','url'=>$ytitle.'08',name=>'August',sname=>'Aug',start=>mktime(0,0,0,8,$start_day,$ytitle),stop=>mktime(0,0,0,9,$start_day,$ytitle)),
		Array('id'=>'09','url'=>$ytitle.'09',name=>'September',sname=>'Sep',start=>mktime(0,0,0,9,$start_day,$ytitle),stop=>mktime(0,0,0,10,$start_day,$ytitle)),
		Array('id'=>'10','url'=>$ytitle.'10',name=>'October',sname=>'Oct',start=>mktime(0,0,0,10,$start_day,$ytitle),stop=>mktime(0,0,0,11,$start_day,$ytitle)),
		Array('id'=>'11','url'=>$ytitle.'11',name=>'November',sname=>'Nov',start=>mktime(0,0,0,11,$start_day,$ytitle),stop=>mktime(0,0,0,12,$start_day,$ytitle)),
		Array('id'=>'12','url'=>$ytitle.'12',name=>'December',sname=>'Dec',start=>mktime(0,0,0,12,$start_day,$ytitle),stop=>mktime(0,0,0,1,$start_day,$ytitle+1)));
} else if (strlen($title) == 6) { // month
	$name="Day";

	$next_month=($mtitle!=12 ? $mtitle+1 : 1);
	$next_year=($mtitle!=12 ? $ytitle : $ytitle + 1);

	$start=mktime(0,0,0,$mtitle,$start_day,$ytitle);
	$finish=mktime(0,0,0,$next_month,$start_day,$next_year);

	$days_max=$monlen[intval(substr($title,4,2))];

	for ($i=1;$i<=$days_max;$i++) { $days[]=$i; }
	$days=array_merge(
		array_slice($days,$start_day-1,($days_max-$start_day)+1),
		array_slice($days,0,$start_day-1));

	$month=$mtitle;
	$year=$ytitle;
	foreach ($days as $i) {
		$day=str_pad($i,2,"0",STR_PAD_LEFT);
		$output[$i]['id']=$day;
		$output[$i]['start']=mktime(0,0,0,$month,$i,$year);
		$output[$i]['stop']=mktime(0,0,0,($i!=$days_max ? $month : $next_month),
			($i!=$days_max ? $i+1 : 1),
			($i!=$days_max ? $year : $next_year));
		$output[$i]['url']=date("Ymd",$output[$i]['start']);
		if ($i==$days_max) {
			$month=$next_month;
			$year=$next_year;
		}
	}
} else if (strlen($title) == 8) { // day
	$name="Hour";

	$days_max=$monlen[intval(substr($title,4,2))];

	$next_month=($dtitle!=$days_max ? $mtitle : ($mtitle!=12 ? $mtitle+1 : 1));
	$next_year=($dtitle!=$days_max ? $ytitle : ($mtitle!=12 ? $ytitle : $ytitle + 1));

	$start=mktime(0,0,0,$mtitle,$dtitle,$ytitle);
	$finish=mktime(0,0,0,($dtitle!=$days_max ? $mtitle : $next_month),
		($dtitle!=$days_max ? $dtitle+1 : 1),
		($dtitle!=$days_max ? $ytitle : $next_year));

	for ($i=$start;$i<$finish;$i+=3600) {
		$hour=date("H",$i);
		$hour=str_pad($hour,2,"0",STR_PAD_LEFT);
		$output[]=Array('id'=>$hour,'name'=>$hour.":00–".$hour.":59",'sname'=>$hour,'start'=>$i,'stop'=>$i+3600);
	}
}

$debug="start: ".microtime(TRUE)."\n";
$db = new PDO("pgsql:dbname=gasmeter", NULL, NULL);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user = NULL;
$pass = NULL;
//$db->exec("SELECT set_curcfg('default')");
$db->beginTransaction();

$stmt = $db->prepare("SELECT EXTRACT(EPOCH FROM MIN(start)) AS min FROM pulses WHERE meter=:meter");
$stmt->bindParam("meter", $id);
$stmt->execute();
$min=$stmt->fetch(PDO::FETCH_OBJ)->min;
$stmt->closeCursor();
unset($stmt);

foreach ($output as $key => $period) {
	$debug.="load: ".$id."/".$period['start']."..".$period['stop']." to ".$period['id']."\n";
	if ($period['stop'] <= $min) { continue; }

	$stmt = $db->prepare("SELECT (reading_calculate(:meter, to_timestamp(:stop)) - reading_calculate(:meter, to_timestamp(:start))) * :conv AS usage");
	$stmt->bindParam("meter", $id);
	$stmt->bindParam("start", $period['start']);
	$stmt->bindParam("stop", $period['stop']);
	$stmt->bindParam("conv", $conv);
	$stmt->execute();
	$data[$key][UX]+=$stmt->fetch(PDO::FETCH_OBJ)->usage;
	$stmt->closeCursor();
	unset($stmt);
}
$debug.="end: ".microtime(TRUE)."\n";
$db->commit();

if (!isset($data)) {
	header("HTTP/1.1 404 No Data");
	header("Content-Type: text/plain");
	echo "No data for $title\n";
	echo $debug;
	die();
}

?><!DOCTYPE HTML>
<html lang="en-GB"><head><title><?=$site?>: <?=$xtitle.$atitle?></title><style type="text/css">tr.c0 { color: #000; background-color: #fff; } tr.c1 { color: #000; background-color: #eee; } em { border-top: 1px dotted; border-bottom: 1px dotted; }</style></head><body><center><h1><?
if (strlen($title) == 4) {
	echo $xtitle.$atitle;
} else {
	if (DAY && $dtitle < $start_day) {
		echo '<a href="/'.$uri;
		$y=$ytitle;
		$m=$mtitle-1;
		if ($m==0) { $y--; $m=12; }
		$m=str_pad($m,2,"0",STR_PAD_LEFT);
		echo $y.$m;
		echo $meter;
		echo '">'.substr($xtitle,0,strlen($xtitle)-3).'</a>'.substr($xtitle,strlen($xtitle)-3).$atitle;
	} else {
		echo '<a href="/'.$uri.substr($title,0,strlen($title)-2).$meter.'">'.substr($xtitle,0,strlen($xtitle)-3).'</a>'.substr($xtitle,strlen($xtitle)-3).$atitle;
	}
}
?></h1>
<?
$div=1000;
$units=Array(
/*	$div*$div*$div*$div*$div*$div*$div*$div=>'YW·h',
	$div*$div*$div*$div*$div*$div*$div=>'ZW·h',
	$div*$div*$div*$div*$div*$div=>'EW·h',
	$div*$div*$div*$div*$div=>'PW·h',
	$div*$div*$div*$div=>'TW·h',
	$div*$div*$div=>'GW·h',
	$div*$div=>'MW·h', */
	$div=>'kW·h',
	1=>'W·h');
$use=Array(1,'W·h');
$max=0;
foreach ($output as $key => $period) {
	if ($data[$key][UX] > $max) { $max=$data[$key][UX]; }
}
foreach ($units as $key => $unit) {
	if ($max/$key < 10000) {
		$use=Array($key,$unit);
	}
}

$exe="./graph.pl";
$exe.=" ".$name;
$exe.=" \"".$use[1]."\"";
foreach ($output as $key => $period) {
	if (isset($period['sname'])) { $sname=$period['sname']; } else { $sname=$period['id']; }
	$exe.=" $sname,".$data[$key][UX]/$use[0];
}
echo '<!--'.$exe.'-->';
echo '<img src="data:image/png;base64,';
ob_start();
system($exe);
$img=ob_get_contents();
ob_end_clean();
echo base64_encode($img);
echo '">';

echo '<table border="1" width="50%">';
echo '<tr>';
echo '<th width="10%" bgcolor="#808080">'.$name.'</th>';
echo '<th width="10%" bgcolor="#6666ff">'.$usage.' ('.$use[1].')</th>';
//echo '<th width="10%" bgcolor="#0000ff">Total ('.$use[1].')</th>';
echo '</tr>';
$ux=$cx=Array();
$rows=0;
$cls=0;
foreach ($output as $key => $period) {
	if (isset($period['name'])) { $sname=$period['name']; } else { $sname=$period['id']; }
	if ($cls==0) { $cls=1; } else { $cls=0; }

	echo '<tr class="c'.$cls.'" align="right">';
	echo '<td align="left">';
	if (($data[$key][UX] == 0) || !isset($period['url'])) {
		echo $sname;
	} else {
		echo '<a href="/'.$uri.$period['url'].$meter.'">'.$sname.'</a>';
	}
	echo '</td>';
	echo '<td>'.sprintf("%01.2f",round($data[$key][UX]/$use[0],2)).'</td>';
//	echo '<td>'.sprintf("%01.2f",round($data[$key][UX]/$use[0],2)).'</td>';
/*
	echo '<td align="center">';
	if ($other != "") {
		echo ratios($data[$key][RX], $data[$key][TX], $data[$key][OX]);
	} else {
		echo ratios($data[$key][RX], $data[$key][TX]);
	}
	echo '</td>';
*/
	echo '</tr>';

	if ($data[$key][UX] == 0) { continue; } else { $rows++; }
	$ux[]=$data[$key][UX];
	$cx[]=$data[$key][UX];
}

if ($rows>0) {
	$aux=array_sum($ux)/$rows;
	echo '<tr align="right">';
	echo '<th align="left">Average</th>';
	echo '<td><strong>'.sprintf("%01.2f",round($aux/$use[0],2)).'</strong></td>';
//	echo '<td><strong>'.sprintf("%01.2f",round(*$aux)/$use[0],2)).'</strong></td>';
//	echo '<td>&nbsp;</td>';
	echo '</tr>';
}

$mux=median($ux);
$mcx=median($cx);
echo '<tr align="right">';
echo '<th align="left">Median</th>';
echo '<td><strong>'.sprintf("%01.2f",round($mux/$use[0],2)).'</strong></td>';
//echo '<td><strong>'.sprintf("%01.2f",round($mcx/$use[0],2)).'</strong></td>';
/*
echo '<td align="center"><strong>';
if ($other != "") {
	echo ratios($mrx, $mtx, $mox);
} else {
	echo ratios($mrx, $mtx);
}
echo '</strong></td>';
*/
echo '</tr>';

$ux=array_sum($ux);
echo '<tr align="right">';
echo '<th align="left">Total</th>';
echo '<td><strong>'.sprintf("%01.2f",round($ux/$use[0],2)).'</strong></td>';
//echo '<td><strong>'.sprintf("%01.2f",round(($ux)/$use[0],2)).'</strong></td>';
/*
echo '<td align="center"><strong>';
if ($other != "") {
	echo ratios($rx, $tx, $ox);
} else {
	echo ratios($rx, $tx);
}
echo '</strong></td>';
*/
echo '</tr>';

echo '</table>';
?>
</center></body></html>
<!--
start: <?=$start?>

finish: <?=$finish?>

duration: <?=$finish-$start?>

<?=$debug?>
-->
