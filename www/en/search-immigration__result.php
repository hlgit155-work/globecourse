<?php

session_start();

require_once dirname(__FILE__) . './../include/config.inc.php';
require '../../config/conn.php';

define('EMPTY_UNAVAILABLE', 'Unavailable');

$state = isset($_REQUEST['state']) ? $_REQUEST['state'] : $_SESSION['state'];
$regional = isset($_REQUEST['regional']) ? $_REQUEST['regional'] : $_SESSION['regional'];
$level = isset($_REQUEST['schoolType']) ? $_REQUEST['schoolType'] : $_SESSION['level'];
$keyword = isset($_REQUEST['courseName']) ? $_REQUEST['courseName'] : $_SESSION['keyword'];
$field_p = isset($_REQUEST['broadField']) ? $_REQUEST['broadField'] : $_SESSION['field_p'];
$field_c = isset($_REQUEST['narrowField']) ? $_REQUEST['narrowField'] : $_SESSION['field_c'];

$_SESSION['state'] = $state;
$_SESSION['regional'] = $regional;
$_SESSION['level'] = $level;
$_SESSION['keyword'] = $keyword;
$_SESSION['field_p'] = $field_p;
$_SESSION['field_c'] = $field_c;

$where = "";

// $sql = "SELECT id FROM institution WHERE regional=$regional";
// $stmt = $conn->prepare($sql);
// $stmt->execute();
// $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
// if (count($rows)) {
//     $where .= "AND inst_id IN(" . implode(",", $rows) . ") ";
// }

if ($regional) {
    $sql = "SELECT DISTINCT field_id FROM immi_field_state WHERE state_id <> 0";
} else {
    $sql = "SELECT DISTINCT field_id FROM immi_field_state WHERE state_id = 0";
}

$stmt = $conn->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
$where .= " AND c.field_id_c IN(" . implode(",", $rows) . ") ";

if ($state) {
    $sql = "SELECT id FROM institution WHERE state_id=$state";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($rows)) {
        $where .= " AND inst_id IN(" . implode(",", $rows) . ") ";
    }
}

if ($level) {
    $where .= "AND level_id = $level ";
}

if ($keyword) {
    $where .= "AND c.name like '%$keyword%' ";
} else {
    if ($field_p) {
        $where .= "AND field_id_p = $field_p ";
    }
    if ($field_c) {
        $where .= "AND field_id_c = $field_c ";
    }
}

$where .= " AND i.regional = $regional ";

$sql = "SELECT c.id,
                IF(c.name_en IS NULL OR c.name_en = '', c.`name`, c.name_en) AS `name`,
                c.hours,
                c.months,
                c.inst_id,
                i.name_en AS inst ,
                l.name_en AS `level`,
                s.name_en AS `state`,
                c.fees
      FROM course c
      LEFT JOIN institution i ON i.id = c.inst_id
      LEFT JOIN `level` l ON l.id = c.level_id
      LEFT JOIN `state` s ON s.id = i.state_id
      WHERE c.status > 0
      $where
      ";

// die($sql);
// $stmt = $conn->prepare($sql);
// $stmt->execute();
// $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$dopage->GetPage($sql, 10);

?>

<link href="./../css/common.css" type="text/css" rel="stylesheet" />
<link rel='stylesheet' href='kingster-plugins/goodlayers-core/include/css/page-builder.min.css' type='text/css' media='all' />
<link rel='stylesheet' href='kingster-css/style-core.min.css' type='text/css' media='all' />
<link rel='stylesheet' href='kingster-css/kingster-style-custom.min.css' type='text/css' media='all' />
<link rel="stylesheet" type="text/css" href="custom-css/table.css">


<!-- ============================================================================================== -->
<!-- ______________________________        Table [responsive]        ______________________________ -->
<!-- ============================================================================================== -->
<div class="ctm-table__container">
<h2><small>Found </small> <?php echo $dopage->GetResult_num(); ?> <small> results</small></h2>
  <ul class="ctm__responsive-table">
    <li class="ctm-table__header">
    <div class="ctm-table__col ctm-table__6col-1 ctm-table__col-1">Course Name</div>
      <div class="ctm-table__col ctm-table__6col-2">Education</div>
      <div class="ctm-table__col ctm-table__6col-3">Institution</div>
      <div class="ctm-table__col ctm-table__6col-4">State</div>
      <div class="ctm-table__col ctm-table__6col-5">Duration(Months)</div>
      <div class="ctm-table__col ctm-table__6col-6">Fees(yearly)</div>
    </li>

    <?php
while ($row = $dosql->GetArray()) {; // foreach ($rows as $row) {
    if ($row['fees'] == 0) {
        $fees_format = EMPTY_UNAVAILABLE;
    } else {
        $fees = $row['fees'];
        if (empty($_COOKIE['gc_currency'])) {
            $currency_code = 'AUD';
        } else {
            $currency_code = str_replace('"', "", $_COOKIE['gc_currency']);
        }
        $c_base = $dosql->GetOne("SELECT code,name,rate,symbol FROM `currency` WHERE id = 1;");
        $c_base = $c_base['rate'];
        $c_target = $dosql->GetOne("SELECT code,name,rate,symbol FROM `currency` WHERE code = '$currency_code' ;");
        $fees = $fees * $c_target['rate'] / $c_base;
        $fees = round($fees, -3);
        $fees_bf_3 = substr($fees, 0, -3);
        $fees_last_3 = substr($fees, -3);
        $fees_format = $c_target['code'] . ' ' . $c_target['symbol'] . $fees_bf_3 . ',' . $fees_last_3;
    }
    $link = 'course-info.php?cid=' . $row['inst_id'] . '&id=' . $row['id'];
    $months = $row['months'] ? $row['months'] . " months" : EMPTY_UNAVAILABLE;
    ?>
        <!-- <li class="ctm-table__row" onclick="parent.location.href='/iframe_parent/<?php //echo($zypage);;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;?>?cid=<?php //echo($row['cbh']);;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;?>&id=<?php //echo($row['id']);;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;?>';"> -->
        <li class="ctm-table__row" onclick="parent.location.href='<?php echo $link; ?>';">
        <div class="ctm-table__col ctm-table__6col-1 ctm-table__col-1" data-label=""><?php echo ($row['name']); ?></div>
          <div class="ctm-table__col ctm-table__6col-2 ctm-table__embed-courseType" data-label=""><?php echo $row['level']; ?></div>
          <div class="ctm-table__col ctm-table__6col-3 ctm-table__embed-School" data-label=""><?php echo ($row['inst']); ?></div>
          <div class="ctm-table__col ctm-table__6col-4 ctm-table__embed-State" data-label=""><?php echo ($row['state']); ?></div>
          <div class="ctm-table__col ctm-table__6col-5 ctm-table__embed-Duration" data-label=""><?php echo ($months); ?></div>
          <div class="ctm-table__col ctm-table__6col-6 ctm-table__embed-Fes" data-label=""><?php echo $fees_format; ?></div>
        </li>
    <?php
}
?>
  </ul>
  <!-- <div style="display: flex; justify-content: center; align-items: center; line-height:30px; height:30px; padding-left:20px; font-size:14px;"><?php //echo $dopage->GetList(); ;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;?></div> -->
</div>

<div class="ctm-table__pageBtn" style=""><?php echo $dopage->GetList(); ?></div>