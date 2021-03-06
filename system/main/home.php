<?php

function home_main() {
  global $config, $files_dir, $mapping;
  
  if ($m = readConf("admin_message")) addErrorMessage($m);
  
  checkFilesDir();
  
  $btns = churchcore_getModulesSorted();
  
  if (isset($_SESSION["family"])) addInfoMessage(t('there.are.more.users.with.the.same.email'));
  
  $txt = '
  <div class="hero-unit hidden-phone">
    <h1>'. $config["welcome"]. '</h1>
    <p class="hidden-phone">'. $config["welcome_subtext"]. '</p>
    <p>';
  
  foreach ($btns as $key) if (isset($config[$key. "_startbutton"]) 
                              && $config[$key. "_startbutton"]== "1" 
                              && user_access("view", $key)) {
    $txt .= '<a class="btn btn-large" href="?q='. $key. '">'. $config[$key. "_name"]. '</a>&nbsp;';
  }
  $txt .= '</p>';
  $txt .= '</div>';
  
  $txt .= '<div class="well visible-phone">
    <h1>'. t("welcome"). '!</h1>
    <p>'. $_SESSION["user"]->vorname. ', '. t("chose.your.possibilities"). ':</p>
    <ul class="nav nav-pills nav-stacked">';
  
  foreach ($btns as $key) {
    if ((isset($config[$key. "_name"]))&& ($config[$key. "_name"]!= "")&& (user_access("view", $key))) {
      include_once (SYSTEM. '/'. $mapping[$key]);
      $txt .= '<li><a class="btn btn-large" href="?q='. $key. '">'. $config[$key. "_name"]. '</a> ';
    }
  }
  $txt .= '</ul>';
  $txt .= '</div>';
  
  // blocks[]: label, col(1,2,3) sortkey, html
  $blocks = null;
  foreach ($btns as $key) {
    if (!empty($config[$key. "_name"])) {
      include_once (SYSTEM. '/'. $mapping[$key]);
      if (function_exists($key. "_blocks")) {
        $b = call_user_func($key. "_blocks");
        foreach ($b as $block)
          $blocks[$block["col"]][] = $block;
      }
    }
  }
  $txt .= '<div class="row-fluid">';
  for($i = 1; $i<= 3; $i++) {
    $txt .= '<ul class="span4">';
    if (isset($blocks[$i])) {
      churchcore_sort($blocks[$i], "sortkey");
      foreach ($blocks[$i] as $block) {
        if (($block["html"]!= null)&& ($block["html"]!= "")) {
          $txt .= '<li class="ct_whitebox';
          if (isset($block["class"])) $txt .= ' '. $block["class"];
          $txt .= '">';
          $txt .= '<label class="ct_whitebox_label">'. $block["label"]. "</label>";
          if (isset($block["help"])) {
            $txt .= '<div style="float:right;margin:-34px -12px">';
            $txt .= '<a href="http://intern.churchtools.de?q=help&doc='. $block["help"]. '" title="'. t("open.help").
                 '" target="_clean"><i class="icon-question-sign"></i></a>';
            $txt .= '</div>';
          }
          
          $txt .= $block["html"];
        }
      }
    }
    $txt .= '</ul>';
  }
  $txt .= '</div>';
  
  drupal_add_js(MAIN. '/home.js');
  
  return $txt;
}

/**
 * check if needed site directories exists and writeable
 * create them if needed
 *
 * TODO: is this related to homo or need it to be tested on install only?
 */
function checkFilesDir() {
  global $files_dir;
  if (!file_exists($files_dir. "/files")) {
    mkdir($files_dir. "/files", 0777, true);
  }
  
  if (!is_writable($files_dir. "/files")) {
    addErrorMessage("The directory $files_dir/files has to be writeable. Please adjust permissions!");
  }
  else {
    if (!file_exists($files_dir. "/files/.htaccess")) {
      $handle = fopen($files_dir. "/files/.htaccess", 'w+');
      if ($handle) {
        fwrite($handle, "Allow from all\n");
        fclose($handle);
      }
    }
    
    if (!file_exists($files_dir. "/fotos/.htaccess")) {
      $handle = fopen($files_dir. "/fotos/.htaccess", 'w+');
      if ($handle) {
        fwrite($handle, "Allow from all\n");
        fclose($handle);
      }
    }
  }
  
  if (!file_exists($files_dir. "/.htaccess")) {
    $handle = fopen($files_dir. "/.htaccess", 'w+');
    if ($handle) {
      fwrite($handle, "Deny from all\n");
      fclose($handle);
    }
  }
}

function home_getMemberList() {
  global $base_url, $files_dir;
  $status_id = variable_get('churchdb_memberlist_status', '1');
  if ($status_id== "") $status_id = "-1";
  $station_id = variable_get('churchdb_memberlist_station', '1,2,3');
  if ($station_id== "") $station_id = "-1";
  
  $sql = 'select person_id, name, vorname, strasse, ort, plz, land,
         year(geburtsdatum) year, month(geburtsdatum) month, day(geburtsdatum) day, 
        DATE_FORMAT(geburtsdatum, \'%d.%m.%Y\') geburtsdatum, DATE_FORMAT(geburtsdatum, \'%d.%m.\') geburtsdatum_compact,
         (case when geschlecht_no=1 then \''. t("mr."). '\' when geschlecht_no=2 then \''. t("mrs."). '\' else \'\' end) "anrede",
         telefonprivat, telefongeschaeftlich, telefonhandy, fax, email, imageurl
         from {cdb_person} p, {cdb_gemeindeperson} gp where gp.person_id=p.id and gp.station_id in ('. $station_id. ')
          and gp.status_id in ('. $status_id. ') and archiv_yn=0 order by name, vorname';
  $db = db_query($sql);
  $res = array ();
  foreach ($db as $r) {
    $res[] = $r;
  }
  return $res;
}

function home__memberlist() {
  global $base_url, $files_dir, $config;
  
  if (!user_access("view memberliste", "churchdb")) {
    addErrorMessage(t("no.permission.for", t("list.of.members")));
    return " ";
  }
  
  $fields = _home__memberlist_getSettingFields()->fields;
  
  $txt = '<small><i><a class="cdb_hidden" href="?q=home/memberlist_printview" target="_clean">'. t("printview").
       '</a></i></small>';
  if (user_access("administer settings", "churchcore")) $txt .= '&nbsp; <small><i><a class="cdb_hidden" href="?q=home/memberlist_settings">'.
       t("admin.settings"). '</a></i></small>';
  
  $txt .= '<table class="table table-condensed"><tr><th><th>'. t("salutation"). '<th>'. t("name"). '<th>'. t("address").
       '<th>'. t("birth."). '<th>'. t("contact.information"). '</tr><tr>';
  $link = $base_url;
  $res = home_getMemberList();
  foreach ($res as $m) {
    
    if (!$m->imageurl) $m->imageurl = "nobody.gif";
    $txt .= "<tr><td><img width=\"65px\"src=\"$base_url$files_dir/fotos/". $m->imageurl. "\"/>";
    $txt .= '<td><div class="dontbreak">'. $m->anrede. '<br/>&nbsp;</div><td><div class="dontbreak">';
    
    if ((user_access("view", "churchdb"))&& (user_access("view alldata", "churchdb"))) 
      $txt .= "<a href='$link?q=churchdb#PersonView/searchEntry:#". $m->person_id. "'>". $m->name. ", ". $m->vorname. "</a>";
    else $txt .= $m->name. ", ". $m->vorname;
    
    $txt .= '<br/>&nbsp;</div><td><div class="dontbreak">'. $m->strasse. "<br/>". $m->plz. " ". $m->ort. "</div>";
    
    $birthday = "";
    if ($m->geburtsdatum!= null) {
      if ($m->year< 7000) $birthday = "$m->day.$m->month.";
      if ($m->year!= 1004&& $fields["memberlist_birthday_full"]->getValue()) {
        if ($m->year< 7000) $birthday = $birthday. $m->year;
        else $birthday = $birthday. $m->year- 7000;
      }
    }
    
    $txt .= "<td><div class=\"dontbreak\">$birthday<br/>&nbsp;</div><td><div class=\"dontbreak\">";
    if (($fields["memberlist_telefonprivat"]->getValue())&& ($m->telefonprivat!= "")) $txt .= $m->telefonprivat. "<br/>";
    if (($fields["memberlist_telefonhandy"]->getValue())&& ($m->telefonhandy!= "")) $txt .= $m->telefonhandy. "<br/>";
    if (($m->telefonprivat== "")&& ($m->telefonhandy== "")) {
      if (($fields["memberlist_telefongeschaeftlich"]->getValue())&& ($m->telefongeschaeftlich!= "")) $txt.= $m->telefongeschaeftlich.
           "<br/>";
      if (($fields["memberlist_fax"]->getValue())&& ($m->fax!= "")) $txt .= $m->fax. " (Fax)<br/>";
    }
    if (($fields["memberlist_email"]->getValue())&& ($m->email!= "")) $txt .= '<a href="mailto:'. $m->email. '">'.
         $m->email. '</a><br/>';
    $txt .= "</div>";
  }
  
  $txt .= "</table>";
  return $txt;
}

function home__memberlist_printview() {
  global $base_url, $files_dir, $config;
  // $content='<html><head><meta http-equiv="Content-Type" content="application/pdf; charset=utf-8" />';
  // drupal_add_css(BOOTSTRAP.'/css/bootstrap.min.css');
  // drupal_add_css(CHURCHDB.'/cdb_printview.css');
  // $content=$content.drupal_get_header();
  if (!user_access("view memberliste", "churchdb")) {
    addErrorMessage(t("no.permission.for", t("list.of.members")));
    return " ";
  }
  
  require_once (ASSETS. '/fpdf17/fpdf.php');
  $compact = true;
  if (isset($_GET["compact"])) $compact = $_GET["compact"];

  
  // Instanciation of inherited class
  $pdf = new PDF('P', 'mm', 'A4');
  $pdf->AliasNbPages();
  $pdf->AddPage();
  $pdf->SetFont('Arial', '', 9);
  $res = home_getMemberList();
  $pdf->SetLineWidth(0.4);
  $pdf->SetDrawColor(200, 200, 200);
  $fields = _home__memberlist_getSettingFields()->fields;
  foreach ($res as $p) {
    $pdf->Line(8, $pdf->GetY()- 1, 204, $pdf->GetY()- 1);
    $pdf->Cell(10, 10, "", 0);
    if (($p->imageurl== null)|| (!file_exists("$files_dir/fotos/$p->imageurl"))) $p->imageurl = "nobody.gif";
    $pdf->Image("$files_dir/fotos/$p->imageurl", $pdf->GetX()- 10, $pdf->GetY()+ 1, 9);
    $pdf->Cell(2);
    $pdf->Cell(13, 9, $p->anrede, 0, 0, 'L');
    $pdf->Cell(48, 9, utf8_decode("$p->name, $p->vorname"), 0, 0, 'L');
    $pdf->Cell(45, 9, utf8_decode("$p->strasse"), 0, 0, 'L');
    

// TODO: second occurence of code part - whats this for?
    $birthday = "";
    if ($p->geburtsdatum!= null) {
      if ($p->year< 7000) $birthday = "$p->day.$p->month.";
      if ($p->year!= 1004&& $fields["memberlist_birthday_full"]->getValue()) {
        if ($p->year< 7000) $birthday = $birthday. $p->year;
        else $birthday = $birthday. $p->year- 7000;
      }
    }
    $pdf->Cell(20, 9, $birthday, 0, 0, 'L');
    
    if (($fields["memberlist_telefonprivat"]->getValue())&& ($p->telefonprivat!= "")) $pdf->Cell(30, 9, $p->telefonprivat, 0, 0, 'L');
    else if (($fields["memberlist_telefongeschaeftlich"]->getValue())&& ($p->telefongeschaeftlich!= "")) $pdf->Cell(30, 9, $p->telefongeschaeftlich, 0, 0, 'L');
    else if (($fields["memberlist_telefongeschaeftlich"]->getValue())&& ($p->fax!= "")) $pdf->Cell(30, 9, $p->fax.
         " (Fax)", 0, 0, 'L');
    else $pdf->Cell(30, 9, "", 0, 0, 'L');
    if (($fields["memberlist_telefonhandy"]->getValue())&& ($p->telefonhandy!= "")) $pdf->Cell(30, 9, $p->telefonhandy, 0, 0, 'L');
    
    // Zeilenumbruch
    $pdf->Ln(5);
    $pdf->Cell(73);
    $pdf->Cell(48, 10, "$p->plz ". utf8_decode($p->ort), 0, 0, 'L');
    $pdf->Cell(17);
    if (($fields["memberlist_email"]->getValue())&& ($p->email!= "")) {
      $pdf->SetFont('Arial', '', 8);
      $pdf->Cell(30, 9, $p->email);
      $pdf->SetFont('Arial', '', 9);
    }
    $pdf->Ln(12);
  }
  $pdf->Output(t("list.of.members"). '.pdf', 'I');
}

function home__memberlist_saveSettings($form) {
  if (isset($_POST["btn_1"])) {
    header("Location: ?q=home/memberlist");
    return null;
  }
  else {
    foreach ($form->fields as $key => $value) {
      db_query("INSERT INTO {cc_config} (name, value) 
                VALUES (:name,:value) ON DUPLICATE KEY UPDATE value=:value",
                array (":name" => $key, ":value" => $value)
               );
    }
    loadDBConfig();
  }
}

function _home__memberlist_getSettingFields() {
  global $config;
  
  $model = new CTForm("AdminForm", "home__memberlist_saveSettings");
  $model->setHeader("Einstellungen f&uuml;r die Mitgliederliste", "Der Administrator kann hier Einstellung vornehmen.");
  $model->addField("churchdb_memberlist_status", "", "INPUT_REQUIRED", "Kommaseparierte Liste mit Status-Ids f&uuml;r Mitgliederliste");
  $model->fields["churchdb_memberlist_status"]->setValue($config["churchdb_memberlist_status"]);
  $model->addField("churchdb_memberlist_station", "", "INPUT_REQUIRED", "Kommaseparierte Liste mit Station-Ids f&uuml;r Mitgliederliste");
  $model->fields["churchdb_memberlist_station"]->setValue($config["churchdb_memberlist_station"]);
  
  $model->addField("memberlist_telefonprivat", "", "CHECKBOX", "Anzeige der privaten Telefonnummer");
  $model->fields["memberlist_telefonprivat"]->setValue((isset($config["memberlist_telefonprivat"]) ? $config["memberlist_telefonprivat"] : true));
  $model->addField("memberlist_telefongeschaeftlich", "", "CHECKBOX", "Anzeige der gesch&auml;ftlichen Telefonnummer");
  $model->fields["memberlist_telefongeschaeftlich"]->setValue((isset($config["memberlist_telefongeschaeftlich"]) ? $config["memberlist_telefongeschaeftlich"] : true));
  $model->addField("memberlist_telefonhandy", "", "CHECKBOX", "Anzeige der Mobil-Telefonnumer");
  $model->fields["memberlist_telefonhandy"]->setValue((isset($config["memberlist_telefonhandy"]) ? $config["memberlist_telefonhandy"] : true));
  $model->addField("memberlist_fax", "", "CHECKBOX", "Anzeige der FAX-Nummer");
  $model->fields["memberlist_fax"]->setValue((isset($config["memberlist_fax"]) ? $config["memberlist_fax"] : true));
  $model->addField("memberlist_email", "", "CHECKBOX", "Anzeige der EMail-Adresse");
  $model->fields["memberlist_email"]->setValue((isset($config["memberlist_email"]) ? $config["memberlist_email"] : true));
  $model->addField("memberlist_birthday_full", "", "CHECKBOX", "Anzeige des gesamten Geburtsdatums (inkl. Geburtsjahr)");
  $model->fields["memberlist_birthday_full"]->setValue((isset($config["memberlist_birthday_full"]) ? $config["memberlist_birthday_full"] : false));
  
  return $model;
}

function home__memberlist_settings() {
  $model = _home__memberlist_getSettingFields();
  $model->addButton("Speichern", "ok");
  $model->addButton("Zur&uuml;ck", "arrow-left");
  
  return $model->render();
}


function home__ajax() {
  $module = new CTHomeModule("home");
  $ajax = new CTAjaxHandler($module);
  
  drupal_json_output($ajax->call());
}

?>
