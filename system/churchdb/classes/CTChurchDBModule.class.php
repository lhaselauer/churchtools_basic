<?php
/**
 *
 * @author
 *
 */
class CTChurchDBModule extends CTAbstractModule {

  /**
   * get master data
   * @see CTModuleInterface::getMasterData()
   *
   * @return array
   */
  public function getMasterData() {
    global $user, $base_url, $files_dir, $config;

    $res = churchdb_getMasterDataTables();
    $res["feldtyp"] = churchcore_getTableData("cdb_feldtyp");
    $res["fields"] = getAllFields();
    $res["groups"] = getAllGroups();
    $res["tags"] = getAllTags();
    $res["FUNachfolgeDomains"] = array (
        "0" => array ("id" => "0", "bezeichnung" => "Kein"),
        "1" => array ("id" => "1", "bezeichnung" => $res["fields"]["f_group"]["fields"]["gruppentyp_id"]["text"]),
        "2" => array ("id" => "2", "bezeichnung" => $res["fields"]["f_group"]["fields"]["distrikt_id"]["text"]),
        "3" => array ("id" => "3", "bezeichnung" => t("group")),
    );
    $res["groupMemberTypes"] = getGroupMemberTypes();
    $res["groupFilterTypes"] = churchdb_getGroupFilterTypes();

    // master data information for maintain masterdata and statistics
    if (user_access("edit masterdata", "churchdb") || user_access("view statistics", "churchdb"))
      $res["masterDataTables"] = churchdb_getMasterDataTablenames();

    $res["user_pid"] = $user->id;
    $res["userid"] = $user->vorname. " ". $user->cmsuserid. " [". $user->id. "]";
    $res["auth"] = churchdb_getAuthForAjax();
    $res["site_name"] = readConf('site_name', 'ChurchTools');
    $res["modulespath"] = churchdb_getModulesPath();
    $res["files_url"] = $base_url. $files_dir;
    $res["modulename"] = "churchdb";
    $res["max_uploadfile_size_kb"] = readConf('max_uploadfile_size_kb');
    $res["adminemail"] = readConf('site_mail', '');
    $res["max_exporter"] = readConf('churchdb_maxexporter', '150');
    $res["groupnotchoosable"] = readConf('churchdb_groupnotchoosable', 30);
    $res["home_lat"] = readConf('churchdb_home_lat', '53.568537');
    $res["home_lng"] = readConf('churchdb_home_lng', '10.03656');
    $res["settings"] = churchdb_getUserSettings($user->id);
    $res["last_log_id"] = churchdb_getLastLogId();
    $res["mailchimp"] = readConf('churchdb_mailchimp_apikey')!= "";
    if (user_access("administer persons", "churchcore")) $res["auth_table"] = churchdb_getAuthTable();

    if (isset($res["auth"]["edit newsletter"])) {
      $nl = churchdb_getTableData("cdb_newsletter");
      $newsletter = array ();
      foreach ($res["auth"]["edit newsletter"] as $n) {
        $newsletter = $nl[$n];
      }
      $res["newsletter"] = $newsletter;
    }
    return $res;
  }


  public function getAllRels($params) {
    return getAllRelations();
  }


  public function mailchimp($params) {
    if ($params["sub"]== "load") return churchdb_loadMailchimp();
    else if ($params["sub"]== "add") return churchdb_addMailchimpRelation($params);
    else if ($params["sub"]== "del") return churchdb_delMailchimpRelation($params);
  }


  public function sendsms($params) {
    $this->checkPerm("send sms");
    return churchdb_sendsms($params["ids"], $params["smstxt"]);
  }


  public function pollForNews($params) {
    return churchdb_pollForNews($params["last_id"]);
  }

  public function getAllPersonData($params) {
    if (isset($params["p_id"])) {
      // Check against SQL-Injection
      if (!is_numeric($params["p_id"])) {
        echo "Unallowed access!";
        return null;
      }
      ;
      return churchdb_getAllowedPersonData('archiv_yn=0 AND p.id='. $params["p_id"]);
    }
    else
      return churchdb_getAllowedPersonData('archiv_yn=0');
  }

  public function getAllPersonArchiveData($params) {
    return churchdb_getAllowedPersonData('archiv_yn=1');
  }

  public function getPersonDetails($params) {
    $this->logPerson($params, 3);
    return churchdb_getPersonDetails($params["id"]);
  }

  public function getPersonDetailsLogs($params) {
    return churchdb_getPersonDetailsLogs($params["id"]);
  }

  public function getSearchableData($params) {
    $res["searchable"] = getSearchableData();
    $res["oldGroupRelations"] = getOldGroupRelations();
    $res["tagRelations"] = getTagRelations();
    return $res;
  }

  public function f_geocode_person($params) {
    saveGeocodePerson($params["id"], $params["lat"], $params["lng"]);
  }

  public function f_geocode_gruppe($params) {
    saveGeocodeGruppe($params["id"], $params["lat"], $params["lng"]);
  }

  public function createAddress($params) {
    $this->checkPerm("create person");
    $res = createAddress($params);
    if (isset($res["id"])) $this->logPerson($params, 2);
    return $res;
  }

  public function createGroup($params) {
    $res = createGroup($params["name"], $params["Inputf_grouptype"], $params["Inputf_district"], (isset($params["force"]) ? $params["force"] : null));
    $this->logGroup($params, 2);
    return $res;
  }

  public function deleteLastGroupStatistic($params) {
    return churchdb_deleteLastGroupStatistik($params["id"]);
  }

  public function deleteGroup($params) {
    $res = deleteGroup($params["id"]);
    $this->logGroup($params, 2);
    return $res;
  }

  public function f_image($params) {
    $res = saveImage($params["id"], $params["url"]);
    $this->logPerson($params, 2);
    return $res;
  }

  public function f_bereich($params) {
    $res = saveBereich($params);
    $this->logPerson($params, 2);
    return $res;
  }

  public function f_note($params) {
    $res = saveNote($params["id"], $params["note"], $params["comment_viewer"], (isset($params["relation_name"]) ? $params["relation_name"] : "person"));
    if (isset($params["followup_count_no"])) {
      $gp_id = _churchdb_getGemeindepersonIdFromPersonId($params["id"]);
      db_query("UPDATE {cdb_gemeindeperson_gruppe} SET followup_count_no=". $params["followup_count_no"].
      ", followup_add_diff_days=". $params["followup_add_diff_days"]. " WHERE gemeindeperson_id=". $gp_id.
      " AND gruppe_id=". $params["followup_gid"]);
    }
    $this->logPerson($params, 2);
    return $res;
  }

  public function add_rel($params) {
    $res = addRelation($params["id"], $params["child_id"], $params["rel_id"]);
    $this->logPerson($params, 2);
    return $res;
  }

  public function del_rel($params) {
    $res = delRelation($params["rel_id"]);
    $this->logPerson($params, 2);
    return $res;
  }

  public function GroupMeeting($params) {
    $this->logGroup($params, 3);

    if ($params["sub"]== "getList") $res = getGroupMeeting($params["g_id"]);
    else if ($params["sub"]== "canceled") $res = cancelGroupMeeting($params["gt_id"]);
    else if ($params["sub"]== "create") createGroupMeetings();
    else if ($params["sub"]== "stats") $res = getGroupMeetingStats($params["id"]);
    else if ($params["sub"]== "delete") $res = deleteGroupMeetingStats($params["id"]);
    else if ($params["sub"]== "saveProperties") $res = savePropertiesGroupMeetingStats($params);
    else if ($params["sub"]== "editCheckin") $res = editCheckinGroupMeetingStats($params);
    else throw new CTException("Error in GroupMeeting, unkown sub.");
    return $res;
  }

  public function addEvent($params) {
    return churchdb_addEvent($params);
  }

  public function del_note($params) {
    $this->checkPerm("write access");
    $sql = "DELETE FROM {cdb_comment} WHERE id=". $params["comment_id"];
    db_query($sql);
    $this->logPerson($params);
  }

  public function setCMSUser($params) {
    global $user;
    $this->logPerson($params);
    return setCMSUser($params["id"], $user->cmsuserid);
  }

  public function send_email($params) {
    $this->log($params);
    churchdb_send_mail($params["subject"], $params["body"], $params["to"]);
  }

  public function deletePerson($params) {
    if ((user_access("administer persons", "churchcore"))|| (user_access("edit masterdata", "churchdb"))) {
      $this->logPerson($params, 1);
      return deleteUser($params["id"]);
    }
    throw new CTNoPermission("administer persons");
  }

  public function archivePerson($params) {
    $this->checkPerm("push/pull archive");
    $this->logPerson($params, 1);
    return archiveUser($params["id"]);
  }

  public function undoArchivePerson($params) {
    $this->checkPerm("push/pull archive");
    $this->logPerson($params, 1);
    return archiveUser($params["id"], true);
  }

  public function delPersonTag($params) {
    $this->logPerson($params);
    $gp_id = _churchdb_getGemeindepersonIdFromPersonId($params["id"]);
    db_query("DELETE FROM {cdb_gemeindeperson_tag} WHERE tag_id=". $params["tag_id"]. " AND gemeindeperson_id=$gp_id");
  }

  public function delGroupTag($params) {
    db_query("DELETE FROM {cdb_gruppe_tag} WHERE tag_id=". $params["tag_id"]. " AND gruppe_id=". $params["id"]);
  }

  public function addNewTag($params) {
    global $user;
    $dt = new DateTime();
    $new_id = db_insert('cdb_tag')->fields(array (
        "bezeichnung" => $params["bezeichnung"],
        "letzteaenderung" => $dt->format('Y-m-d H:i:s'),
        "aenderunguser" => $user->cmsuserid,
    ))->execute();
    return $new_id;
    cdb_log("addNewTag: ". $params["bezeichnung"], 2, $new_id, CDB_LOG_TAG); // never executed!
  }

  public function addPersonTag($params) {
    $dt = new DateTime();
    $gp_id = _churchdb_getGemeindepersonIdFromPersonId($params["id"]);
    $new_id = db_insert('cdb_gemeindeperson_tag')->fields(array (
        "gemeindeperson_id" => $gp_id,
        "tag_id" => $params["tag_id"],
        "letzteaenderung" => $dt->format('Y-m-d H:i:s'),
    ))->execute();
    $this->logPerson($params);
  }

  public function addGroupTag($params) {
    $dt = new DateTime();
    $new_id = db_insert('cdb_gruppe_tag')->fields(array (
        "gruppe_id" => $params["id"],
        "tag_id" => $params["tag_id"],
        "letzteaenderung" => $dt->format('Y-m-d H:i:s'),
    ))->execute();
    $this->logPerson($params);
  }

  public function addPersonDistrictRelation($params) {
    global $user;
    $this->checkPerm("administer groups");
    $dt = new DateTime();
    $new_id = db_insert('cdb_person_distrikt')->fields(array (
        "person_id" => $params["id"],
        "distrikt_id" => $params["distrikt_id"],
        "modified_pid" => $user->id,
        "modified_date" => $dt->format('Y-m-d H:i:s'),
    ))->execute();
    $this->logPerson($params);
  }

  public function delPersonDistrictRelation($params) {
    $this->checkPerm("administer groups");
    db_query("DELETE FROM {cdb_person_distrikt} WHERE person_id=:id AND distrikt_id=:distrikt_id", array (
    ":id" => $params["id"],
    ":distrikt_id" => $params["distrikt_id"],
    ));
    $this->logPerson($params);
  }

  public function addPersonGruppentypRelation($params) {
    global $user;
    $this->checkPerm("administer groups");
    $dt = new DateTime();
    $new_id = db_insert('cdb_person_gruppentyp')->fields(array (
        "person_id" => $params["id"],
        "gruppentyp_id" => $params["gruppentyp_id"],
        "modified_pid" => $user->id,
        "modified_date" => $dt->format('Y-m-d H:i:s')
    ))->execute();
    $this->logPerson($params);
  }

  public function delPersonGruppentypRelation($params) {
    $this->checkPerm("administer groups");
    db_query("DELETE FROM {cdb_person_gruppentyp} WHERE person_id=:id AND gruppentyp_id=:gruppentyp_id", array (
    ":id" => $params["id"],
    ":gruppentyp_id" => $params["gruppentyp_id"],
    ));
    $this->logPerson($params);
  }

  public function delPersonGroupRelation($params) {
    return _churchdb_delPersonGroupRelation($params["id"], $params["g_id"]);
  }

  public function editPersonGroupRelation($params) {
    if (isset($params["followup_count_no"])) $f = $params["followup_count_no"];
    else $f = "null";

    return _churchdb_editPersonGroupRelation($params["id"], $params["g_id"], $params["leader"], $params["date"], $f,
        (isset($params["comment"]) ? $params["comment"] : null));
  }

  public function addPersonGroupRelation($params) {
    return churchdb_addPersonGroupRelation($params["id"], $params["g_id"], $params["leader"], $params["date"],
        (isset($params["followup_count_no"]) ? $params["followup_count_no"] : null),
        (isset($params["followup_erfolglos_zurueck_gruppen_id"]) ? $params["followup_erfolglos_zurueck_gruppen_id"] : null),
        (isset($params["comment"]) ? $params["comment"] : null));
  }

  public function getPersonByName($params) {
    return _churchdb_getPersonByName($params["searchpattern"], isset($params["withmydeps"]));
  }

  public function getPersonById($params) {
    return _churchdb_getPersonById($params["id"]);
  }

  public function sendInvitationMail($params) {
    churchdb_invitePersonToSystem($params["id"], $_SESSION["user"]);
  }

  public function setPersonPassword($params) {
    $this->checkPerm("administer persons", "churchcore");
    return churchdb_setPersonPassword($params["id"], $params["password"]);
  }

  public function sendEMailToPersonIds($params) {
    return churchcore_sendEMailToPersonIds($_POST["ids"], $_POST["betreff"], $_POST["inhalt"], null, true, false);
  }

  public function loadAuthData($params) {
    if (user_access("administer persons", "churchcore")) {
      $res["cdb_bereich"] = (object) churchcore_getTableData("cdb_bereich");
      $res["cdb_comment_viewer"] = (object) churchcore_getTableData("cdb_comment_viewer");
      $res["cs_servicegroup"] = (object) churchcore_getTableData("cs_servicegroup");
      $res["cs_songcategory"] = (object) churchcore_getTableData("cs_songcategory");
      $res["cc_wikicategory"] = (object) churchcore_getTableData("cc_wikicategory");
      $res["cc_calcategory"] = (object) churchcore_getTableData("cc_calcategory");
      $res["cr_resource"] = (object) churchcore_getTableData("cr_resource");
      return $res;
    }
  }

  public function deactivatePerson($params) {
    $this->checkPerm("administer persons", "churchcore");
    return churchdb_deactivatePerson($params["id"]);
  }

  public function activatePerson($params) {
    $this->checkPerm("administer persons", "churchcore");
    return churchdb_activatePerson($params["id"]);
  }

  public function getGroupAutomaticEMail($params) {
    $this->checkPerm("administer groups");
    return db_query("SELECT * FROM {cdb_gruppenteilnehmer_email} WHERE gruppe_id=:gruppe_id AND status_no=:status_no", array (
        ":gruppe_id" => $params["id"],
        ":status_no" => $params["status_no"]
    ))->fetch();
  }

  public function saveGroupAutomaticEMail($params) {
    $this->checkPerm("administer groups");
    db_query("INSERT INTO {cdb_gruppenteilnehmer_email} (gruppe_id, status_no, aktiv_yn, sender_pid, email_betreff, email_inhalt)
         VALUES (:gruppe_id, :status_no, :aktiv_yn, :sender_pid, :email_betreff, :email_inhalt)
         ON DUPLICATE KEY UPDATE aktiv_yn=:aktiv_yn, sender_pid=:sender_pid, email_betreff=:email_betreff, email_inhalt=:email_inhalt", array (
         ':gruppe_id' => $params["id"],
         ':status_no' => $params["status_no"],
         ':aktiv_yn' => $params["aktiv_yn"],
         ':sender_pid' => $params["sender_pid"],
         ':email_betreff' => $params["email_betreff"],
         ':email_inhalt' => $params["email_inhalt"],
    ));
  }

  public function addPersonAuth($params) {
    $this->checkPerm("administer persons", "churchcore");
    return churchdb_addPersonAuth($params["id"], $params["auth_id"]);
  }

  public function saveDomainAuth($params) {
    $this->checkPerm("administer persons", "churchcore");
    return churchdb_saveDomainAuth($params);
  }

  public function getImportTables($params) {
    $this->checkPerm("edit masterdata");
    $db = db_query('show tables');
    $arr = array ();
    foreach ($db as $table) {
      foreach ($table as $row) if (!isCTDBTable($row)) {
        $arr[$row] = array ("id" => $row, "bezeichnung" => $row);
      }
    }
    return $arr;
  }

  public function getTableContent($params) {
    $this->checkPerm("edit masterdata");
    return churchcore_getTableData($params["table"]);
  }

  public function f_address($params) {
    return f_functions($params);
  }

  public function f_church($params) {
    return f_functions($params);
  }

  public function f_category($params) {
    return f_functions($params);
  }

  public function f_group($params) {
    return f_functions($params);
  }

  public function getMasterDataTablenames() {
    return churchdb_getMasterDataTablenames();
  }

}
?>