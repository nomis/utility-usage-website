<?
// TODO: adjust for day of the week when comparing to last year

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
$atitle=$mtitle=$dtitle=$htitle="";
if (isset($_GET["data"])) {
	if ($_GET["data"]=="today") { $_GET["data"]=date("Ymd"); }
	if (preg_match('/^([0-9]{4})([0-9]{2})?([0-9]{2})?([0-9]{2})?$/',$_GET["data"],$matches)) {
		$title=$_GET["data"];
		$xtitle=$ytitle=$matches[1];
		if ($matches[2]!="") { $xtitle.="-".$matches[2]; $mtitle=$matches[2]; }
		if ($matches[3]!="") { $xtitle.="-".$matches[3]; $dtitle=$matches[3]; }
		if ($matches[4]!="") { $xtitle.=" ".$matches[4]; $htitle=$matches[4]; }
	} else if (strlen($_GET["data"]) > 0) {
		header("HTTP/1.1 404 No Data");
		header("Content-Type: text/plain");
		echo "No data for ".$_GET["data"]."\n";
		die();
	}
}

$id=$meter;
$uri="";
$meter=isset($_GET["meter"]) ? ".".$_GET["meter"] : "";

define("UX",0);

$monlen=Array(0,
	31,(date("L", mktime(0,0,0,1,1,substr($title,0,4))) ? 29 : 28),31,
	30,31,30,
	31,31,30,
	31,30,31);
$lmonlen=Array(31,
	31,(date("L", mktime(0,0,0,1,1,substr($title,0,4)-1)) ? 29 : 28),31,
	30,31,30,
	31,31,30,
	31,30,31);

$neardow=Array();
for ($i=0;$i<7;$i++) {
	for ($j=0;$j<7;$j++) {
		$t1=$i-$j;
		$t2=$t1 < 0 ? $t1+7 : $t1-7;
		if (abs($t1) <= abs($t2)) {
			$neardow[$i.$j]=$t1;
		} else {
			$neardow[$i.$j]=$t2;
		}
	}
}

if (isset($data)) { unset($data); }
if (isset($ldata)) { unset($ldata); }

$usage="Usage";
$start_day=1;
$output=Array();

if (strlen($title) == 4) { // year
	$name="Month";

	$start=mktime(0,0,0,1,$start_day,$ytitle);
	$start=mktime(0,0,0,1,$start_day,$ytitle);
	$finish=mktime(0,0,0,1,$start_day,$ytitle+1);

	foreach (Array('January','February','March','April','May','June','July','August','September','October','November','December') as $i => $k) {
		$i1=$i+1;
		$i2=$i+2;
		$y0=$ytitle;
		$y1=$ytitle;
		if ($i2 == 13) { $i2 = 1; $y1=$y1+1; }
		$output[]=Array(
			'id'=>sprintf("%02d",$i+1),
			'url'=>$ytitle.sprintf("%02d",$i+1),
			'name'=>$k,'sname'=>substr($k,0,3),
			'start'=>mktime(0,0,0,$i1,$start_day,$y0),
			'stop'=>mktime(0,0,0,$i2,$start_day,$y1),
			'lstart'=>mktime(0,0,0,$i1,$start_day,$y0-1),
			'lstop'=>mktime(0,0,0,$i2,$start_day,$y1-1)
		);
	}
} else if (strlen($title) == 6) { // month
	$name="Day";

	$next_month=($mtitle!=12 ? $mtitle+1 : 1);
	$next_year=($mtitle!=12 ? $ytitle : $ytitle + 1);

	$start=mktime(0,0,0,$mtitle,$start_day,$ytitle);
	$finish=mktime(0,0,0,$next_month,$start_day,$next_year);

	$days_max=$monlen[intval(substr($title,4,2))];
	$ldays_max=$lmonlen[intval(substr($title,4,2))];

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

		if ($i<=$ldays_max) {
			$td1=$i;
			$tm1=$month;
			$ty1=$year-1;
			$td2=($i!=$ldays_max ? $i+1 : 1);
			$tm2=($i!=$ldays_max ? $month : $next_month);
			$ty2=($i!=$ldays_max ? $year : $next_year)-1;
		} else {
			$td1=1;
			$tm1=$next_month;
			$ty1=$next_year-1;
			$td2=2;
			$tm2=$next_month;
			$ty2=$next_year-1;
		}

		$output[$i]['lstart']=mktime(0,0,0,$tm1,$td1,$ty1);

		$dow=$neardow[date("w",$output[$i]['start']).date("w",$output[$i]['lstart'])];

		$td1+=$dow;
		$td2+=$dow;

		if ($td1 < 1) { $tm1--; }
		if ($tm1 < 1) { $ty1--; $tm1=12; }
		if ($td1 < 1) { $td1+=$lmonlen[intval($tm1)]; }
		if ($td1 > $lmonlen[intval($tm1)]) { $tm1++; $td1=1; }
		if ($tm1 > 12) { $tm1=1; $ty1++;}

		if ($td2 < 1) { $tm2--; }
		if ($tm2 < 1) { $ty2--; $tm2=12; }
		if ($td2 < 1) { $td2+=$lmonlen[intval($tm2)]; }
		if ($td2 > $lmonlen[intval($tm2)]) { $tm2++; $td2=1; }
		if ($tm2 > 12) { $tm2=1; $ty2++;}

		$output[$i]['lstart']=mktime(0,0,0,$tm1,$td1,$ty1);
		$output[$i]['lstop']=mktime(0,0,0,$tm2,$td2,$ty2);

		$output[$i]['url']=date("Ymd",$output[$i]['start']);
		if ($i==$days_max) {
			$month=$next_month;
			$year=$next_year;
		}
	}
} else if (strlen($title) == 8) { // day
	$name="Hour";

	$days_max=$monlen[intval(substr($title,4,2))];
	$ldays_max=$lmonlen[intval(substr($title,4,2)-1)];

	$next_month=($dtitle!=$days_max ? $mtitle : ($mtitle!=12 ? $mtitle+1 : 1));
	$next_year=($dtitle!=$days_max ? $ytitle : ($mtitle!=12 ? $ytitle : $ytitle + 1));

	$start=mktime(0,0,0,$mtitle,$dtitle,$ytitle);
	$finish=mktime(0,0,0,($dtitle!=$days_max ? $mtitle : $next_month),
		($dtitle!=$days_max ? $dtitle+1 : 1),
		($dtitle!=$days_max ? $ytitle : $next_year));

	for ($i=$start;$i<$finish;$i+=3600) {
		$hour=date("H",$i);
		$hour=str_pad($hour,2,"0",STR_PAD_LEFT);

		$p=Array('id'=>$hour,'name'=>$hour.":00–".$hour.":59",'sname'=>$hour,'start'=>$i,'stop'=>$i+3600,'url'=>date("YmdH",$i));

		$lstart=mktime(0,0,0,$mtitle,$dtitle,$ytitle-1);
		$lfinish=mktime(0,0,0,($dtitle!=$ldays_max ? $mtitle : $next_month),
			($dtitle!=$ldays_max ? $dtitle+1 : 1),
			($dtitle!=$ldays_max ? $ytitle : $next_year)-1);
		for ($j=$lstart;$j<$lfinish;$j+=3600) {
			$lhour=date("H",$j);
			$lhour=str_pad($lhour,2,"0",STR_PAD_LEFT);
			if ($lhour==$hour) {
				$p['lstart']=$j;
				$p['lstop']=$j+3600;
			}
		}

		$output[]=$p;
	}
} else if (strlen($title) == 10) { // hour
	$name="Minute";

	$days_max=$monlen[intval(substr($title,4,2))];

	$next_month=($dtitle!=$days_max ? $mtitle : ($mtitle!=12 ? $mtitle+1 : 1));
	$next_year=($dtitle!=$days_max ? $ytitle : ($mtitle!=12 ? $ytitle : $ytitle + 1));

	$start=mktime($htitle,0,0,$mtitle,$dtitle,$ytitle);
	if ($htitle == 23) {
		$finish=mktime(0,0,0,($dtitle!=$days_max ? $mtitle : $next_month),
			($dtitle!=$days_max ? $dtitle+1 : 1),
			($dtitle!=$days_max ? $ytitle : $next_year));
	} else {
		$finish=mktime($htitle+1,0,0,$mtitle,$dtitle,$ytitle);
	}

	$comb=1;
	for ($i=$start;$i<$finish;$i+=$comb*60) {
		$minute=date("i",$i);
		$minute=str_pad($minute,2,"0",STR_PAD_LEFT);
		$minute2=str_pad($minute+$comb,2,"0",STR_PAD_LEFT);
		if ($comb == 1) { $sname=$minute; }
		else { $sname=$minute."-".$minute2; }
		$output[]=Array('id'=>$minute,'name'=>$minute.":00–".$minute2.":59",'sname'=>$sname,'start'=>$i,'stop'=>$i+$comb*60);
	}
}

$debug="start: ".microtime(TRUE)."\n";
$db = new PDO("pgsql:sslmode=verify-full host=proxima.lp0.eu user=lp0_gas dbname=gasmeter", NULL, NULL);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$user = NULL;
$pass = NULL;
//$db->exec("SELECT set_curcfg('default')");
$db->beginTransaction();

$stmt = $db->prepare("SELECT EXTRACT(EPOCH FROM MIN(start)) AS min,EXTRACT(EPOCH FROM MAX(start)) AS max FROM pulses WHERE meter IN (:meter, :meter2)");
$stmt->bindParam("meter", $id);
$stmt->bindValue("meter2", $id+1);
$stmt->execute();
$f=$stmt->fetch(PDO::FETCH_OBJ);
$min=$f->min;
$max=$f->max;
$stmt->closeCursor();
unset($stmt);

foreach ($output as $key => $period) {
	$debug.="load: ".$id."/".$period['start']."..".$period['stop']." to ".$period['id']."\n";
	if ($period['stop'] <= $min) { continue; }

	$stmt = $db->prepare("SELECT (reading_calculate(:meter, to_timestamp(:stop)) - reading_calculate(:meter, to_timestamp(:start))) AS usage");
	$stmt->bindParam("meter", $id);
	$stmt->bindParam("start", $period['start']);
	$stmt->bindParam("stop", $period['stop']);
	$stmt->execute();
	$data[$key][UX]=$stmt->fetch(PDO::FETCH_OBJ)->usage;
	$stmt->closeCursor();
	unset($stmt);

	$stmt = $db->prepare("SELECT (reading_calculate(:meter, to_timestamp(:stop)) - reading_calculate(:meter, to_timestamp(:start))) AS usage");
	$stmt->bindValue("meter", $id+1);
	$stmt->bindParam("start", $period['start']);
	$stmt->bindParam("stop", $period['stop']);
	$stmt->execute();
	$data[$key][UX]+=$stmt->fetch(PDO::FETCH_OBJ)->usage;
	$stmt->closeCursor();
	unset($stmt);

	$ldata[$key][UX]=$data[$key][UX];
	if (isset($period['lstart'])) {
		$debug.="lload: ".$id."/".$period['lstart']."..".$period['lstop']." to ".$period['id']."\n";
		if ($period['lstop'] <= $min) { continue; }
#		if ($period['start'] > $max) { continue; }

		$stmt = $db->prepare("SELECT (reading_calculate(:meter, to_timestamp(:stop)) - reading_calculate(:meter, to_timestamp(:start))) AS usage");
		$stmt->bindParam("meter", $id);
		$stmt->bindParam("start", $period['lstart']);
		$stmt->bindParam("stop", $period['lstop']);
		$stmt->execute();
		$ldata[$key][UX]=$stmt->fetch(PDO::FETCH_OBJ)->usage;
		$stmt->closeCursor();
		unset($stmt);

		$stmt = $db->prepare("SELECT (reading_calculate(:meter, to_timestamp(:stop)) - reading_calculate(:meter, to_timestamp(:start))) AS usage");
		$stmt->bindValue("meter", $id+1);
		$stmt->bindParam("start", $period['lstart']);
		$stmt->bindParam("stop", $period['lstop']);
		$stmt->execute();
		$ldata[$key][UX]+=$stmt->fetch(PDO::FETCH_OBJ)->usage;
		$stmt->closeCursor();
		unset($stmt);
	}
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
} else if ($htitle!="") {
	echo '<a href="/'.$uri;
	$y=$ytitle;
	$m=$mtitle;
	$d=$dtitle;
	echo $y.$m.$d;
	echo $meter;
	echo '">'.substr($xtitle,0,strlen($xtitle)-3).'</a>'.substr($xtitle,strlen($xtitle)-3).$atitle;
} else if ($dtitle!="" && $dtitle < $start_day) {
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
$units=Array(1=>'m³');
$use=Array(1,'m³');
$max=0;
foreach ($output as $key => $period) {
	if ($data[$key][UX] > $max) { $max=$data[$key][UX]; }
}
foreach ($units as $key => $unit) {
	if ($max/$key < 10000) {
		$use=Array($key,$unit,str_replace("³", "^3", $unit));
	}
}

$exe="./graph.pl";
$exe.=" ".$name;
$exe.=" \"".$use[2]."\"";
foreach ($output as $key => $period) {
	if (isset($period['sname'])) { $sname=$period['sname']; } else { $sname=$period['id']; }
	$exe.=" $sname,".($data[$key][UX]/$use[0]).",".(($data[$key][UX] - $ldata[$key][UX])/$use[0]);
}
$exe.=" 2>&1";
echo '<!--'.str_replace(" "," \\\n",$exe).'-->';
ob_start();
system($exe);
$img=ob_get_contents();
ob_end_clean();
if (substr($img,0,8) == "\211PNG\r\n\032\n") {
	echo '<img src="data:image/png;base64,';
	echo base64_encode($img);
	echo '">';
} else {
	echo "<tt>".htmlentities($img)."</tt>";
}

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
