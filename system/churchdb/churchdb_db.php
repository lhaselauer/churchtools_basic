<?php

include_once (CHURCHCORE . '/churchcore_db.php');

/**
 * get person data
 *
 * @param string $cond,
 *          additional sql where clause
 * @param string $fields,
 *          to set sql columns
 *          
 * @return array with person objects or nothing
 */
function churchdb_getAllowedPersonData($cond = '', 
    $fields = "p.id p_id, gp.id gp_id, name, vorname, spitzname, station_id stn_id, status_id sts_id, email as em, 
    if (telefonhandy='',telefonprivat, telefonhandy) as tl, geolat as lat, geolng as lng, archiv_yn") 
{
  global $user;
  $where = ($cond) ? "and $cond" : "";
  $allPersons = null;
  
  // Get ALL data about which person is allowed to view which department
  $sql_dep = db_query("SELECT person_id, bereich_id FROM {cdb_bereich_person}");
  // Get departments, the user is in or has rights for
  $allowedAndMyDeps = churchdb_getAllowedDeps();
  $departments = array ();
  // fill $departments[personId][depId]
  foreach ($sql_dep as $d) {
    if (isset($allowedAndMyDeps[$d->bereich_id])) {
      if (!isset($departments[$d->person_id])) $departments[$d->person_id] = array ();
      $departments[$d->person_id][$d->bereich_id] = $d->bereich_id;
    }
  }
  
  // get all data about persons in groups for later matching
  $sql_g = "SELECT gg.gemeindeperson_id gp_id, gg.gruppe_id id, gg.status_no leiter, 
         DATE_FORMAT(gg.letzteaenderung, '%Y-%m-%d') d, gg.aenderunguser user, 
         gg.followup_count_no, gg.followup_add_diff_days, followup_erfolglos_zurueck_gruppen_id, comment
                   FROM {cdb_gemeindeperson_gruppe} gg";
  $groups = db_query($sql_g);
  $arr_groups = array ();
  foreach ($groups as $group) {
    // if no followUp, nothing is needed.
    if ($group->followup_count_no == null) unset($group->followup_count_no);
    if ($group->followup_add_diff_days == null) unset($group->followup_add_diff_days);
    if ($group->comment == null) unset($group->comment);
    $arr_groups[$group->gp_id][$group->id] = $group;
  }
  
  // get all persons from VIEWALL departments
  $allowedDeps = user_access("view alldata", "churchdb");
  if ($allowedDeps != null) {
    $sql_p = "SELECT $fields
             FROM {cdb_person} p, {cdb_gemeindeperson} gp 
                    WHERE p.id=gp.person_id AND 1=1 " . $where; // whats the 1=1 for?
    $res = db_query($sql_p);
    foreach ($res as $p) {
      $res = false;
      foreach ($allowedDeps as $allowedDep) {
        if (isset($departments[$p->p_id][$allowedDep])) {
          $res = true;
        }
      }
      if ($res) {
        if (isset($departments[$p->p_id])) $p->access = $departments[$p->p_id];
        if (isset($arr_groups[$p->gp_id])) $p->groups = $arr_groups[$p->gp_id];
        $allPersons[$p->p_id] = $p;
      }
    }
  }
  
  // get all persons from groups the user is in or the user is district leader of group
  $myGroups = churchdb_getMyGroups($user->id, true);
  if (count($myGroups) > 0) {
    $sql_g = "SELECT $fields
              FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg 
              WHERE p.id=gp.person_id AND gpg.gemeindeperson_id=gp.id 
              AND gpg.gruppe_id in (" . implode(",", $myGroups) . ") " . $where;
    $res = db_query($sql_g);
    foreach ($res as $p) {
      if (!isset($allPersons[$p->p_id])) {
        if (isset($departments[$p->p_id])) $p->access = $departments[$p->p_id];
        if (isset($arr_groups[$p->gp_id])) $p->groups = $arr_groups[$p->gp_id];
        $allPersons[$p->p_id] = $p;
      }
    }
  }
  
  // inclued user, if not yet
  if (!isset($allPersons[$user->id])) {
    $p = db_query("SELECT $fields FROM {cdb_gemeindeperson} gp, {cdb_person} p 
                   WHERE gp.person_id=p.id AND p.id=:p_id", 
                   array (":p_id" => $user->id), false)
         ->fetch();
    if ($p != false) {
      if (isset($departments[$p->p_id])) $p->access = $departments[$p->p_id];
      if (isset($arr_groups[$p->gp_id])) $p->groups = $arr_groups[$p->gp_id];
      $allPersons[$user->id] = $p;
    }
  }
  
  // add district leader
  $db = db_query("SELECT * FROM {cdb_person_distrikt}");
  foreach ($db as $d) {
    if (isset($allPersons[$d->person_id])) {
      if (isset($allPersons[$d->person_id]->districts)) $districts = $allPersons[$d->person_id]->districts;
      else $districts = array ();
      $districts[$d->distrikt_id] = $d;
      $allPersons[$d->person_id]->districts = $districts;
    }
  }
  // add group leader
  $db = db_query("SELECT * FROM {cdb_person_gruppentyp}");
  foreach ($db as $d) {
    if (isset($allPersons[$d->person_id])) {
      if (isset($allPersons[$d->person_id]->gruppentypen)) $gruppentypen = $allPersons[$d->person_id]->gruppentypen;
      else $gruppentypen = array ();
      $gruppentypen[$d->gruppentyp_id] = $d;
      $allPersons[$d->person_id]->gruppentypen = $gruppentypen;
    }
  }
  
  return $allPersons;
}

/**
 * get auth for domain person
 *
 * @param int $id          
 */
function getAuthForPerson($id) {
  return getAuthForDomain($id, "person");
}

/**
 * get auth for domain
 * 
 * TODO: will be called for each person in arrays (e.g. from getSearchableData) - a single statement should be used which will be only executed for each person
 *
 * @param int $id person id         
 * @param string $domain_type; person, ..., ...       
 *
 * @return array auth
 */
function getAuthForDomain($id, $domain_type) {
  if (!user_access("administer persons", "churchcore")) return null;
  
  $res = db_query("SELECT * FROM {cc_domain_auth} WHERE domain_type='$domain_type' AND domain_id=" . $id);
  $arr_auth = null;
  foreach ($res as $p) {
    if ($p->daten_id == null) $arr_auth[$p->auth_id] = $p->auth_id;
    else {
      $arr = isset($arr_auth[$p->auth_id]) ? $arr_auth[$p->auth_id] : array(); 
      $arr[$p->daten_id] = $p->daten_id;
      $arr_auth[$p->auth_id] = $arr;
    }
  }
  return $arr_auth;
}

/**
 * Is person $user_id in one of $groups?
 *
 * @param int $user_id          
 * @param array $groups,
 *          array of group Ids
 *          
 * @return boolean
 */
function churchdb_isPersonInGroups($user_id, $groups) {
  $res = db_query("SELECT COUNT(*) c
               FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg
                      WHERE p.id=gp.person_id AND gpg.gemeindeperson_id=gp.id AND p.id=:id
           AND gpg.gruppe_id in (" .  implode(",", $groups) . ") ", array (':id' => $user_id))
         ->fetch();
  return ($res->c > 0); // use !empty()?
}

/**
 * get group data from all groups i have permission for
 * (all with right view alldetails, else my groups only
 *
 * @return array with group(object?)s
 */
function churchdb_getAllowedGroups() {
  global $user;
  if ((user_access("administer groups", "churchdb")) || (user_access("view alldetails", "churchdb"))) {
    return getAllGroups();
  }
  else return churchdb_GetMyGroups($user->id);
}

/**
 * Hole Array mit Ids der Gruppen in denen $user_pid ist.
 *
 * Wenn ich keine "view alldetails"-Rechte habe, dann nur wo ich Leiter bin und Abschluï¿½datum nicht lï¿½nger als
 * groupnotchoosable ist
 *
 * get groups the user has view rights for members:
 * - viewall right
 * - user is leader
 * - showing members is allowed
 *
 *
 * @param int $user_pid          
 * @param bool $only_ids,
 *          default=false, return array of IDs or of person data?
 * @param bool $onlyIAmLeader,
 *          default=false, only where user is group leader
 * @param bool $onlySuperGroup,
 *          default=false, only where user is district or grouptype leader
 *          
 * @return array, never null
 */
function churchdb_getMyGroups($user_pid, $only_ids = false, $onlyIAmLeader = false, $onlySuperGroups = false) {
  global $user, $config;
  if ($user_pid == null) return array ();
  
  $arrs = array ();
  if (!$onlySuperGroups) {
    $res = db_query("SELECT g.*, gpg.status_no, gt.anzeigen_in_meinegruppen_teilnehmer_yn, datediff(current_date, g.abschlussdatum) abschlusstage
           FROM {cdb_gruppe} g, {cdb_gemeindeperson_gruppe} gpg, {cdb_gemeindeperson} gp, {cdb_gruppentyp} gt
           WHERE gp.person_id=$user_pid AND gpg.gemeindeperson_id=gp.id AND gpg.gruppe_id=g.id AND g.gruppentyp_id=gt.id");
    foreach ($res as $a) {
      // if user is leader or no leadership needed
      if ((!$onlyIAmLeader) || ($a->status_no > 0)) {
        // if view members allowed and group not marked for deleting
        
        // TODO: use speaking constants for status numbers or something like group->isLeader($pId)
        // if user is leader and group termination is not to far in the past
        if (($a->anzeigen_in_meinegruppen_teilnehmer_yn == 1 && $a->status_no != -1)
            || ($a->status_no > 0 && ($a->abschlusstage == null || $a->abschlusstage < $config["churchdb_groupnotchoosable"]))
    // => Habe ich erst mal rausgenommen, denn sonst sieht man pl�tzlich  mit alldetails Leute aus Freizeiten oder mit gleichen Merkmalen!        
            // or user has permission to view all details 
            //|| ((isset($user->auth["churchdb"])) && (isset($user->auth["churchdb"]["view alldetails"]))))
            )
            // if group is hidden or user is leader
          if (($a->versteckt_yn == 0) || (($a->status_no > 0) && ($a->status_no < 4))) {
          if ($only_ids) $arrs[$a->id] = $a->id;
          else $arrs[$a->id] = $a;
        }
      }
    }
    
    // get groups the user has view permission for
    if (!$onlyIAmLeader) {
      $auth = user_access("view group", "churchdb");
      if ($auth != null) {
        $res = db_query("SELECT g.* FROM {cdb_gruppe} g WHERE g.id in (" . implode(",", $auth) . ")");
        foreach ($res as $a) if (!isset($arrs[$a->id])) {
          if ($only_ids) $arrs[$a->id] = $a->id;
          else $arrs[$a->id] = $a;
        }
      }
    }
  }
  
  // get groups the user is district leader for
  $res = db_query("SELECT g.* FROM {cdb_gruppe} g, {cdb_person_distrikt} pd
                           WHERE g.distrikt_id=pd.distrikt_id AND pd.person_id=$user_pid");
  foreach ($res as $a) {
    if (!isset($arrs[$a->id])) {
      if ($only_ids) {
        $arrs[$a->id] = $a->id;
      }
      else {
        $a->status_no = 2;
        $arrs[$a->id] = $a;
      }
    }
    
    // TODO: is this the same and better to read?
    // foreach ($res as $a) if (!isset($arrs[$a->id])){
    // $a->status_no=2;
    // $arrs[$a->id]= $only_ids ? $arrs[$a->id]=$a->id : $arrs[$a->id]=$a;
    // }
  }
  // get groups user is grouptype leader for
  $res = db_query("SELECT g.* FROM {cdb_gruppe} g, {cdb_person_gruppentyp} pd
                           WHERE g.gruppentyp_id=pd.gruppentyp_id AND pd.person_id=$user_pid");
  foreach ($res as $a) {
    if (!isset($arrs[$a->id])) {
      if ($only_ids) {
        $arrs[$a->id] = $a->id;
      }
      else {
        $a->status_no = 2;
        $arrs[$a->id] = $a;
      }
    }
  }
  return $arrs;
}

/**
 * Returns all groups for person p_id.
 *
 * For hidden groups only return groups which I am allowed to see
 *
 * @param int $p_id,
 *          PersonId
 * @param int $grouptype_id,
 *          grouptypeId or null
 *          
 * @return array with all groups or empty array
 */
function churchdb_getGroupsForPersonId($p_id, $grouptype_id = null) {
  $myGroups = churchdb_getMyGroups(null, true);
  $res = db_query("SELECT g.*, gpg.status_no 
                   FROM {cdb_gemeindeperson_gruppe} gpg, {cdb_gruppe} g, {cdb_gemeindeperson} gp 
                   WHERE gpg.gemeindeperson_id=gp.id AND gpg.gruppe_id=g.id AND gp.person_id=:p_id 
                         AND (:g_id is null or g.gruppentyp_id=:g_id)", array (
                   ":p_id" => $p_id, 
                                                                                            ":g_id" => $grouptype_id
  ));
  $groups = array ();
  foreach ($res as $g)
    if ($g->versteckt_yn == 0 || user_access("administer groups", "churchdb") 
        || (isset($myGroups[$g->id]))) {
      $groups[$g->id] = $g;
    }
  return $groups;
}

/**
 * Get departements user has view permission for (viewall or user in department)
 *
 * @return Array with department ids (array[id]=id)
 */
function churchdb_getAllowedDeps() {
  // get view all departments
  $allowedDeps = user_access("view alldata", "churchdb");
  if ($allowedDeps == null) $allowedDeps = array ();
  // add departments user is in
  $res = db_query("SELECT * FROM {cdb_bereich_person} WHERE person_id=" . $_SESSION["user"]->id);
  foreach ($res as $auth) {
    $allowedDeps[$auth->bereich_id] = $auth->bereich_id;
  }
  return $allowedDeps;
}

/**
 * Add event to group meetings
 * TODO: rename to addGroupMeeting
 *
 * @param unknown $params          
 * @return unknown
 */
function churchdb_addEvent($params) {
  global $user;
  
  $i = new CTInterface();
  $i->setParam("datumvon");
  $i->setParam("datumbis");
  $i->setParam("gruppe_id");
  $i->addModifiedParams();
  
  $id = db_insert("cdb_gruppentreffen")->fields($i->getDBInsertArrayFromParams($params))->execute(false);
  return $id;
}

/**
 * check authorisation
 * TODO: rename to checkAuthorisatien? What else then persons can be authorisated?

 * @param unknown authorisation
 * @param bool $iAmLeader          
 * @param bool $iAmSuperLeader          
 * @throws CTException
 *
 * @return boolean
 */
function _checkPersonAuthorisation($authorisation, $iAmLeader, $iAmSuperLeader) {
  global $config;
  if ($authorisation == null) return true;
  $ret = false;
  foreach (explode("||", $authorisation) as $auth) {
    $auth = trim($auth);
    if ($auth == "admin") {
      if (user_access('edit masterdata', "churchdb")) $ret = true;
    }
    else if ($auth == "viewalldetails") {
      if (user_access('view alldetails', "churchdb")) $ret = true;
    }
    else if ($auth == "viewaddress") {
      if (user_access('view address', "churchdb")) $ret = true;
    }
    else if ($auth == "leader") {
      if ($iAmLeader) $ret = true;
    }
    else if ($auth == "superleader") {
      if ($iAmSuperLeader) $ret = true;
    }
    else if ($auth == "changeownaddress") {
      if (isset($config["churchdb_changeownaddress"]) && ($config["churchdb_changeownaddress"] == 1)) $ret = true;
    }
    else
      throw new CTException("Unbekanntes Recht: '" . $auth . "'");
  }
  
  return $ret;
}

/**
 * get person details
 * TODO: create a class for persons
 *
 * @param int $id          
 * @param bool $withComments          
 *
 * @return person object
 */
function churchdb_getPersonDetails($id, $withComments = true) {
  global $user;
  
  $allowed = $user->id == $id;
  $iAmLeader = false;
  $iAmSuperLeader = false;
 
  // the export right give the permission to see everything!
  if (user_access("export data", "churchdb")) {
    $allowed = true;
    $iAmLeader = true;
    $iAmSuperLeader = true;
  }
  else {
    
    // user is super leader of person?
    if (churchdb_isPersonSuperLeaderOfPerson($user->id, $id)) {
      $iAmSuperLeader = true;
      $iAmLeader = true;
      $allowed = true;
    }
    // user is leader of person?
    if (churchdb_isPersonLeaderOfPerson($user->id, $id)) {
      $iAmLeader = true;
      $allowed = true;
    }
    // user is in group with person?
    if (!$allowed) {
      $myGroups = churchdb_getMyGroups($user->id, true, false);
      if (count($myGroups) > 0) {
        $res = db_query("SELECT COUNT(*) c
                 FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg
                 WHERE p.id=gp.person_id AND gpg.gemeindeperson_id=gp.id AND p.id=:id
                   AND gpg.gruppe_id in (" . implode(",", $myGroups) . ") ", array (':id' => $id
            ))->fetch();
        if ($res->c > 0) {
          $allowed = true;
        }
      }
    }
    if (!$allowed) {
      // TODO: maybe shorten next 2 lines to: if ($allowedDeps=user_access("view alldata", "churchdb")) {
      $allowedDeps = user_access("view alldata", "churchdb");
      if ($allowedDeps != null) {
        $res = db_query('SELECT COUNT(*) as c FROM {cdb_bereich_person}
            WHERE person_id=:p_id AND bereich_id in (' . implode(',', $allowedDeps). ')', 
            array (':p_id' => $id), false)
            ->fetch();
        if ($res->c > 0) $allowed = true;
      }
    }
    if (!$allowed) return "no access";
  }
  
  $res=db_query("SELECT f.*, fk.intern_code FROM {cdb_feld} f, {cdb_feldkategorie} fk WHERE f.feldkategorie_id=fk.id
              AND fk.intern_code IN ('f_address', 'f_church', 'f_category') AND aktiv_yn=1");
  
  $sqlFields = array ();
  $sqlFields[] = "p.id id";
  $sqlFields[] = "gp.id gp_id";
  $sqlFields[] = "geolat as lat";
  $sqlFields[] = "imageurl";
  $sqlFields[] = "geolng as lng";
  $sqlFields[] = "cmsuserid";

  foreach ($res as $res2) {
    if (($res2->autorisierung==null) || (_checkPersonAuthorisation($res2->autorisierung, $iAmLeader, $iAmSuperLeader))) {
      if (($res2->intern_code=="f_address") || ($iAmLeader) || (user_access('view alldetails',"churchdb"))){
        $sqlFields[]=$res2->db_spalte; 
      }
    }
  }
  $sql = "SELECT " . join($sqlFields, ",");
  if ($iAmLeader || user_access('view alldetails', "churchdb") || user_access('administer persons', "churchcore")) {
    $sql .= ', p.letzteaenderung, p.aenderunguser, p.createdate, if (loginstr IS NULL , 0 , 1) AS einladung, p.active_yn, p.lastlogin';
  }
  
  $sql .= ' FROM {cdb_person} p, {cdb_gemeindeperson} gp WHERE p.id=gp.person_id AND p.id=:pid';
  
  $person = db_query($sql, array (':pid' => $id))->fetch();
  if ($withComments) {
    $comments = db_query("SELECT id, text, person_id, datum, comment_viewer_id, relation_name 
                          FROM {cdb_comment}
                          WHERE relation_id=:relid AND relation_name like 'person%'
                          ORDER BY datum desc", 
                          array (':relid' => $id));
    $auth = user_access("view comments", "churchdb");
    if (($comments) && ($auth != null)) { // TODO: test for auth before DB query? if ($withComments &&
                                        // $auth=user_access("view comments","churchdb"))
      $arrs = null;
      foreach ($comments as $arr) {
        if ((isset($auth[$arr->comment_viewer_id])) && ($auth[$arr->comment_viewer_id] == $arr->comment_viewer_id)) $arrs[] = $arr;
      }
      $person->comments = $arrs;
    }
  }
  $person->auth = getAuthForPerson($id);
  return $person;
}

/**
 * is user superleader of person?
 *
 * @param int $superleader_id          
 * @param int $person_id          
 *
 * @return boolean
 */
function churchdb_isPersonSuperLeaderOfPerson($superleader_id, $person_id) {
  $myGroups = churchdb_getMyGroups($superleader_id, true, false, true);
  if (count($myGroups) > 0) {
    return churchdb_isPersonInGroups($person_id, $myGroups);
  }
  return false;
}

/**
 * is user leader of person?
 *
 * @param int $leader_id          
 * @param int $person_id          
 * @return boolean
 */
function churchdb_isPersonLeaderOfPerson($leader_id, $person_id) {
  $myGroups = churchdb_getMyGroups($leader_id, true, true);
  if (count($myGroups) > 0) {
    return churchdb_isPersonInGroups($person_id, $myGroups);
  }
  return false;
}

// Gibt ein Array zurï¿½ck mit allen p_ids in den Gruppen myGroups
// myGroups: array mit gruppen, z.B. zu holen ï¿½ber churchdb_getMyGroups()
/**
 * get ids of all persons in groups $myGroups
 *
 * @param array $myGroups
 *          with groups
 *          
 * @return array with person ids
 */
function churchdb_getAllPeopleIdsFromGroups($myGroups) {
  $allPersons = null;
  if (count($myGroups) > 0) {
    $sql_g = "SELECT p.id p_id
             FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg 
                    WHERE p.id=gp.person_id AND gpg.gemeindeperson_id=gp.id 
                     AND gpg.gruppe_id IN (" . implode(",", $myGroups) . ") ";
    $res = db_query($sql_g);
    foreach ($res as $p) {
      // FIXME: add "GROUP BY p_id" to sql to prevent double ids - also applicable for other queries here
      if (!isset($allPersons[$p->p_id])) {
        $allPersons[$p->p_id] = $p->p_id;
      }
    }
  }
  return $allPersons;
}

/**
 * get last used id of table cdb_log
 */
function churchdb_getLastLogId() {
  $arr = db_query("SELECT max(id) id FROM {cdb_log}")->fetch();
  return $arr->id;
}

/**
 * get log news newer then $last_id log
 *
 * @param int $last_id          
 *
 * @return array containing [lastlogid]=id, [logs]=array
 */
function churchdb_pollForNews($last_id) {
  global $user;
  $res = db_query("SELECT * FROM {cdb_log} WHERE id > $last_id AND person_id!=$user->id");
  $arr_logs = Array ();
  foreach ($res as $log) {
    $arr_logs[$log->id] = $log;
  }
  $return = Array ();
  $return["lastLogId"] = churchdb_getLastLogId();
  $return["logs"] = $arr_logs;
  return $return;
}

/**
 * Get all departments
 * 
 * @return object db result containing departments
 */
function getAllDepartments() {
  return churchcore_getTableData("cdb_bereich");
}

/**
 * Get all relations of all persons
 * 
 * @return Array
 */
function getAllRelations() {
  $res = db_query('SELECT id, vater_id v_id, kind_id k_id, beziehungstyp_id typ_id FROM {cdb_beziehung}');
  $arrs = null;
  foreach ($res as $arr) {
    $arrs[$arr->id] = $arr;
  }
  return $arrs;
}

/**
 * Get all groups
 * 
 * @return object db result containing groups
 */
function getAllGroups() {
  $arr = churchcore_getTableData("cdb_gruppe", "bezeichnung");
  if (!$arr) return null;
  foreach ($arr as $val) {
    $tags = db_query("SELECT tag_id FROM {cdb_gruppe_tag} gt WHERE gruppe_id=:gruppe_id", 
                     array (":gruppe_id" => $val->id));
    $ids = array();
    foreach ($tags as $tag) $ids[] = $tag->tag_id;
    $arr[$val->id]->tags = $ids;
    $arr[$val->id]->auth = getAuthForDomain($val->id, "gruppe");
  }
  return $arr;
}

/**
 * Get all tags
 * 
 * @return object db result containing tags
 */
function getAllTags() {
  return churchcore_getTableData("cdb_tag", "bezeichnung");
}

/**
 * Get group data (name, type, mail flag) of $g_id
 *
 * @param int $g_id
 *          
 * @return object db result
 */
function getGroupInfo($g_id) {
  $sql = "SELECT g.id, g.bezeichnung gruppe, gt.bezeichnung gruppentyp, g.mail_an_leiter_yn 
        FROM {cdb_gruppe} g, {cdb_gruppentyp} gt 
        WHERE g.gruppentyp_id=gt.id AND g.id=:g_id";
  $return = db_query($sql, array (':g_id' => $g_id))->fetch();
  return $return;
}

/**
 * get church person id from person id
 * TODO: rename function
 *
 * @param int $p_id          
 *
 * @return int id
 */
function _churchdb_getGemeindepersonIdFromPersonId($p_id) {
  $person = db_query('SELECT person_id p_id, id gp_id FROM {cdb_gemeindeperson} 
                      WHERE person_id=:person_id', array (':person_id' => $p_id))
            ->fetch();
  return $person->gp_id;
}

/**
 * get person id from church person id
 * TODO: rename function
 * What means Gemeindeperson? churchmember data? If yes, its not a person, but additional data.
 * Why not use the person id as unique id?
 *
 * @param int $p_id          
 *
 * @return int id
 */
function _churchdb_getPersonIdFromGemeindepersonId($gp_id) {
  $person = db_query('SELECT person_id p_id, id gp_id FROM {cdb_gemeindeperson} WHERE id=:id', 
                      array (':id' => $gp_id))
            ->fetch();
  return $person->p_id;
}

/**
 * get person - group relation
 * 
 * @param int $gp_id          
 * @param int $g_id          
 *
 * @return object db result
 */
function getPersonGroupRelation($gp_id, $g_id) {
  $res = db_query("SELECT * FROM {cdb_gemeindeperson_gruppe} 
                   WHERE gemeindeperson_id=:gp_id AND gruppe_id=:g_id", 
                   array (':gp_id' => $gp_id, ':g_id' => $g_id));
  return $res->fetch();
}

/**
 * get relation types
 * 
 * @return object db result
 */
function getAllRelationTypes() {
  return churchcore_getTableData("cdb_beziehungstyp");
}

/**
 * get comment viewers
 * 
 * @return object db result
 */
function getAllCommentViewer() {
  return churchcore_getTableData("cdb_comment_viewer", "bezeichnung");
}

/**
 * get birthday list
 *
 * TODO: make date format adaptable / maybe country specific?
 * change parameter names to understand meaning
 *
 * @param int $diff_from;
 *          age?
 * @param int $diff_to;
 *          age?
 *          
 * @return array
 */
function getBirthdayList($diff_from, $diff_to) {
  $list = array ();
  $status_id = variable_get('churchdb_birthdaylist_status', '1');
  $station_id = variable_get('churchdb_birthdaylist_station', '1,2,3');
  
  // TODO: fields this_year and diff contain the same. What is the meaning of bla?
  $sql = "SELECT * FROM 
          (SELECT *, if (abs(this_year)<abs(next_year), 
                          if (abs(last_year)<abs(this_year),last_year,this_year),
                          if (abs(last_year)<abs(next_year),last_year,next_year)
                        ) as bla FROM 
            (SELECT person_id,
               datediff(DATE_ADD(geburtsdatum,INTERVAL (YEAR(CURDATE())-YEAR(geburtsdatum)) YEAR),CURDATE()) as this_year, 
               datediff(DATE_ADD(geburtsdatum,INTERVAL (YEAR(CURDATE())-YEAR(geburtsdatum)-1) YEAR),CURDATE()) as last_year, 
               datediff(DATE_ADD(geburtsdatum,INTERVAL (YEAR(CURDATE())-YEAR(geburtsdatum)+1) YEAR),CURDATE()) as next_year,  
               datediff(DATE_ADD(geburtsdatum,INTERVAL (YEAR(CURDATE())-YEAR(geburtsdatum)) YEAR), CURDATE()) as diff, 
               name, vorname, spitzname, DATE_FORMAT(geburtsdatum, '%d.%m.%Y') geburtsdatum_d, DATE_FORMAT(geburtsdatum, '%d.%m.') geburtsdatum_compact, geburtsdatum, 
               (DATE_FORMAT(NOW(), '%Y') - DATE_FORMAT(geburtsdatum, '%Y') - (DATE_FORMAT(NOW(), '00-%m-%d') < DATE_FORMAT(geburtsdatum, '00-%m-%d'))) as age,
               YEAR(geburtsdatum) as jahr,
               s.bezeichnung, gp.imageurl imageurl, status.bezeichnung status
             FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_station} s, {cdb_status} status
             WHERE p.id=gp.person_id AND status_id IN (" . $status_id . ") AND gp.station_id IN (" . $station_id . ") 
               AND geburtsdatum IS NOT NULL AND YEAR(geburtsdatum)<7000
               AND status.id=gp.status_id AND s.id=gp.station_id AND archiv_yn=0
             ) AS t
          ) AS t2 
        WHERE ((bla>=$diff_from) AND (bla<=$diff_to))
        ORDER BY bla, name, vorname";
  
  $sqlDepartment = "SELECT bp.person_id, bezeichnung FROM {cdb_bereich_person} bp, {cdb_bereich} b 
                    WHERE bp.bereich_id=b.id AND bp.person_id=:p_id 
                    ORDER BY bezeichnung";
  
  $res = db_query($sql);
  foreach ($res as $p) {
    if ($p->jahr == 1004) {
      $p->age = "";
      $p->geburtsdatum_compact = "";
      $p->geburtsdatum = "";
      $p->geburtsdatum_d = substr($p->geburtsdatum_d, 0, 6);
    }
    
    $resDepartments = db_query($sqlDepartment, array (":p_id" => $p->person_id));
    $bereich="";
    foreach ($resDepartments as $department) $bereich .= $department->bezeichnung. "<br/>";
    $p->bereich=$bereich;
//     //TODO: maybe use this instead
//     $bereich=array();
//     foreach ($resDepartments as $department) $bereich[] = $department->bezeichnung;
//     $p->bereich=implode("<br/>", $bereich);
    $list[] = $p;
  }
  
  return $list;
}

/**
 * TODO: not used? check for success
 *
 * @param unknown_type $g_id, Id der Gruppe
 * @param unknown_type $p_ids, Kommasepartierte IDs der person_ids
 *          
 * @return string "ok"
 */
function churchdb_delFromGroup($g_id, $p_ids) {
  // Wandle PersonIds in GemeindepersonIds
  $sql = "SELECT id FROM {cdb_gemeindeperson} WHERE person_id IN(%s)";
  $res = db_query($sql, $p_ids);
  
  $gp_ids = "-1";
  foreach ($res as $arr) {
    $gp_ids = $gp_ids . "," . $arr->id;
  }
  $sql = "DELETE FROM {cdb_gemeindeperson_gruppe} WHERE gemeindeperson_id IN (%s) AND gruppe_id=%s";
  db_query($sql, $gp_ids, $g_id);
  return "ok";
}

/**
 * set username for person
 *
 * TODO: only used by function with same function name! in churchdb_ajax.php, check for success
 *
 * @param int $id          
 * @param string $username          
 *
 * @return string "ok"
 */
function setCMSUser($id, $username) {
  if (!$id || !$username) return "id ($id) oder username ($username) nicht definiert!";
  
  $sql = "SELECT id FROM {cdb_person} WHERE cmsuserid='$username'";
  if ($arr = db_query($sql)->fetch()) return "Eine Person (" . $arr->id .
       ") hat den Usernamen $username schon, bitte wende Dich an den Administrator!";
  
  $sql = "SELECT cmsuserid FROM {cdb_person} WHERE id=$id";
  if ($arr = db_query($sql)->fetch()) if (($arr != null) && ($arr->cmsuserid != "")) {
    return "Fehler: Diese Person hat schon einen Usernamen! Bitte wende Dich an einen Adminstrator!";
  }
  
  $sql = "UPDATE {cdb_person} SET cmsuserid='$username' WHERE id=$id";
  db_query($sql);
  return "ok";
}
// TODO: changed function setCMSUser, use if you like it; use :parameters in sql
// function setCMSUser($id, $username) {
// $err = array();

// if (empty($id)) $err[] = t("id.not.defined");
// if (empty($username)) $err[] = t("username.not.defined");
// if (!empty($err))
// return implode('<br/>', $err);

// //TODO: use :parameters
// if ($arr=db_query("SELECT id FROM {cdb_person} WHERE cmsuserid='$username'")->fetch())
// return t("username.not.available.ask.admin", $username); //is the id important to echo? Is the ask.admin part
// important?

// if ($arr=db_query("SELECT cmsuserid FROM {cdb_person} WHERE id=$id AND cmsuserid > ''")->fetch()) //sql not tested
// return t("person.already.has.an.username.ask.admin");

// db_query("UPDATE {cdb_person} SET cmsuserid='$username' WHERE id=$id");
// return "ok";
// }

/**
 * Get a group meeting
 * TODO: optimize sql requests
 *
 * @param int $id          
 *
 * @return boject
 */
function getGroupMeeting($id) {
  $meetings = null;
  $res = db_query("SELECT * FROM {cdb_gruppentreffen} WHERE gruppe_id=:id ORDER BY datumbis", 
                   array (":id" => $id));
  foreach ($res as $meeting) {
    $res2 = db_query("SELECT gp.person_id p_id, treffen_yn FROM {cdb_gruppentreffen_gemeindeperson} gtg, {cdb_gemeindeperson} gp 
  	       WHERE gtg.gemeindeperson_id=gp.id AND gtg.gruppentreffen_id='$meeting->id'");
    $entries = array ();
    foreach ($res2 as $entry) $entries[] = $entry;
    $meeting->entries = $entries;
    
    // TODO: why not like this? Not tested
    // $meeting->entries=array();
    // foreach ($res2 as $entry) $meeting->entries[]=$entry;

    $meetings[] = $meeting;
  }

  return $meetings;
}

/**
 * create group meetings
 *
 * TODO: check for success, maybe combine first two sqls into one request?
 *
 * @return string "ok"
 */
function createGroupMeetings() {
  $res = db_query("SELECT id FROM {cdb_gruppe} WHERE treffen_yn=1");
  $sql = "SELECT * FROM {cdb_gruppentreffen} WHERE gruppe_id=:id AND datumbis>=CURDATE() 
          ORDER BY datumbis desc";
  foreach ($res as $meeting) {
    $res2 = db_query($sql, array (":id" => $meeting->id))
            ->fetch();
    if ($res2 == null) {
      cdb_log("Erstelle Gruppentreffen fuer Gruppe " . $meeting->id, 3, -1, 'cron');
      db_query("INSERT INTO {cdb_gruppentreffen} (gruppe_id, datumvon, datumbis,eintragerfolgt_yn,ausgefallen_yn)
   	    VALUES ($meeting->id, CURDATE() - interval (dayofweek(CURDATE())-2) day, curdate() + interval (8-dayofweek(curdate())) day,0,0)");
    }
  }
  return "ok";
}

/**
 * cancel group meeting (set flag in DB)
 *
 * @param int $id          
 *
 * @return string "ok"
 */
function cancelGroupMeeting($id) {
  $res = db_query("UPDATE {cdb_gruppentreffen} 
                   SET ausgefallen_yn=1, eintragerfolgt_yn=1 WHERE id=:id", 
                   array (":id" => $id));
  return "ok";
}

/**
 * entry for group meeting
 *
 * @param int $g_id          
 * @param int $gt_id          
 * @param array $participant          
 * @return string
 */
function entryGroupMeeting($g_id, $gt_id, $participants) {
  global $user;
  
  $sql = "SELECT person_id, gemeindeperson_id 
          FROM {cdb_gemeindeperson_gruppe} gpg, {cdb_gemeindeperson} gp 
        WHERE gpg.gemeindeperson_id=gp.id AND gruppe_id=:id";
  $res = db_query($sql, array (":id" => $g_id));
  $dt = new DateTime();
  foreach ($res as $p) {
    db_insert("cdb_gruppentreffen_gemeindeperson")
      ->fields(array("gruppentreffen_id" => $gt_id, 
                     "gemeindeperson_id" => $p->gemeindeperson_id, 
                     "modified_date" => $dt->format('Y-m-d H:i:s'), 
                     "modified_pid" => $user->id,
                     "treffen_yn" => in_array($p->person_id, $participants) ? 1 : 0,
      ))->execute();
  }
  
  $res = db_query("UPDATE {cdb_gruppentreffen} SET ausgefallen_yn=0, eintragerfolgt_yn=1 WHERE id=:id", array (
                                                                                                                ":id" => $gt_id
  ));
  return "ok";
}

/**
 * delete statistic of group meeting
 *
 * @param int $id, gruppentreffen_id
 * @return strin "ok"
 */
function deleteGroupMeetingStats($id) {
  db_query("DELETE FROM {cdb_gruppentreffen_gemeindeperson} WHERE gruppentreffen_id=:id", 
           array (":id" => $id));
  db_query("DELETE FROM {cdb_gruppentreffen} WHERE id=:id", 
           array (":id" => $id));
  return "ok";
}

/**
 *
 * @param array $params          
 * @return string "ok"
 */
function editCheckinGroupMeetingStats($params) {
  global $user;
  $dt = new DateTime();
  
  db_query("UPDATE {cdb_gruppentreffen} SET eintragerfolgt_yn=1 WHERE id=:id", 
            array (":id" => $params["gruppentreffen_id"]));
  
  $gp_id = _churchdb_getGemeindepersonIdFromPersonId($params["p_id"]);
  db_query("INSERT INTO {cdb_gruppentreffen_gemeindeperson} (gruppentreffen_id, gemeindeperson_id, treffen_yn, modified_date, modified_pid) 
            VALUES (:gruppentreffen_id, :gemeindeperson_id, :treffen_yn, :modified_date, :modified_pid) 
            ON DUPLICATE KEY UPDATE treffen_yn=:treffen_yn, modified_date=:modified_date, modified_pid=:modified_pid", 
            array( ":gruppentreffen_id" => $params["gruppentreffen_id"], 
                   ":gemeindeperson_id" => $gp_id, 
                   ":treffen_yn" => $params["treffen_yn"], 
                   ":modified_date" => $dt->format('Y-m-d H:i:s'), 
                   ":modified_pid" => $user->id
            ));
  return "ok";
}

/**
 *
 * @param array $params          
 * @throws CTException
 */
function savePropertiesGroupMeetingStats($params) {
  $i = new CTInterface();
  $i->setParam("id");
  $i->setParam("anzahl_gaeste");
  $i->setParam("kommentar");
  $i->setParam("datumvon", false);
  $i->setParam("datumbis", false);
  $i->addModifiedParams();
  
  $id = db_update("cdb_gruppentreffen")
        ->fields($i->getDBInsertArrayFromParams($params))->condition("id", $params["id"], "=")
        ->execute(false);
  
  if (isset($params["entries"])) {
    if (entryGroupMeeting($params["g_id"], $params["id"], $params["entries"]) != "ok") 
      throw new CTException("Problem beim Speichern der einzelnen Teilnahmerdaten");
  }
}

/**
 * get statistics of group meetings
 *
 * @param $id; id, -1 means all groups
 */
function getGroupMeetingStats($id = -1) {
  $where = ($id == -1) ? "" : " AND gg.gruppe_id=$id ";
  $sql = "SELECT gp.person_id id, gg.gruppe_id g_id, SUM(ausgefallen_yn) ausgefallen, 
                 COUNT(eintragerfolgt_yn) stattgefunden, SUM(gtp.treffen_yn) dabei, 
                 MAX(if (gtp.treffen_yn=1,datumbis,0)) as max_datumbis 
			    FROM {cdb_gemeindeperson_gruppe} gg, {cdb_gemeindeperson} gp, {cdb_gruppentreffen} gt, 
               {cdb_gruppentreffen_gemeindeperson} gtp 
	     WHERE gg.gruppe_id=gt.gruppe_id AND gg.gemeindeperson_id=gp.id 
                AND gg.gemeindeperson_id=gtp.gemeindeperson_id AND gtp.gruppentreffen_id=gt.id 
                AND eintragerfolgt_yn=1 $where
         GROUP BY gp.person_id, gg.gruppe_id";
  $res = db_query($sql);
  $stats = null;
  foreach ($res as $s) {
    $new_grp["ausgefallen"] = $s->ausgefallen;
    $new_grp["stattgefunden"] = $s->stattgefunden;
    $new_grp["dabei"] = $s->dabei;
    $new_grp["datum"] = $s->max_datumbis;
    
    $new[$s->g_id] = $new_grp;
    
    $stats[$s->id] = $new;
  }
  return $stats;
}

/**
 *
 * @param string $txt          
 * @param int $level          
 * @param int $domainid          
 * @param string $domaintype          
 * @param int $schreibzugriff_yn          
 * @param string $_user          
 */
function cdb_log($txt, $level = 3, $domainid = -1, $domaintype = CDB_LOG_PERSON, $schreibzugriff_yn = 0, $_user = null) {
  ct_log($txt, $level, $domainid, $domaintype, $schreibzugriff_yn, $_user);
}

/**
 * get all mail notifications
 *
 * @return object DB result
 */
function getAllMailNotifys() {
  $res = db_query('SELECT * FROM {cdb_mailnotify} WHERE enabled=1');
  $arrs = null;
  foreach ($res as $arr) {
    $arrs[$arr->id] = $arr;
  }
  return $arrs;
}

/**
 * delete user and all related data
 *
 * @param int $id          
 * @return string "ok"
 */
function deleteUser($p_id) {
  $arr = db_query("SELECT id gp_id FROM {cdb_gemeindeperson} 
                   WHERE person_id=" . $p_id)
            ->fetch();
  $gp_id = $arr->gp_id;
  
  db_query("DELETE FROM {cdb_bereich_person} WHERE person_id=$p_id");
  db_query("DELETE FROM {cdb_beziehung} WHERE vater_id=$p_id");
  db_query("DELETE FROM {cdb_beziehung} WHERE kind_id=$p_id");
  db_query("DELETE FROM {cdb_comment} WHERE relation_id=$p_id and relation_name='person'");
  db_query("DELETE FROM {cdb_gemeindeperson_gruppe} WHERE gemeindeperson_id=$gp_id");
  db_query("DELETE FROM {cdb_gruppentreffen_gemeindeperson} WHERE gemeindeperson_id=$gp_id");
  db_query("DELETE FROM {cdb_gemeindeperson} WHERE id=$gp_id");
  db_query("DELETE FROM {cc_domain_auth} WHERE domain_type='person' and domain_id=$p_id");
  db_query("DELETE FROM {cdb_person} WHERE id=$p_id");
  
  return "ok";
}

/**
 * archive user
 * set archive flag to 1, on undo to 0
 *
 * @param int $p_id          
 * @param bool $undo
 *          default false
 *          
 * @return string "ok"
 */
function archiveUser($p_id, $undo = false) {
  if (!$undo) {
    db_update("cdb_person")
      ->fields(array ("archiv_yn" => 1))
      ->condition("id", $p_id, "=")
      ->execute();
  }
  else
    db_update("cdb_person")
      ->fields(array ("archiv_yn" => 0))
      ->condition("id", $p_id, "=")
      ->execute();
  
  return "ok";
}

/**
 * add relation
 *
 * @param int $parent_id          
 * @param int $child_id          
 * @param int $relation_id          
 *
 * @return string "ok"
 */
function addRelation($parent_id, $child_id, $relation_id) {
  db_query("INSERT INTO {cdb_beziehung} (vater_id, kind_id, beziehungstyp_id, datum) 
            VALUES ($parent_id, $child_id, $relation_id, CURRENT_DATE)");
  return "ok";
}

/**
 * delete last group statistic
 *
 * @param int $id          
 * @throws CTFail
 */
function churchdb_deleteLastGroupStatistik($id) {
  $res = db_query("SELECT id FROM {cdb_gruppentreffen} 
                WHERE gruppe_id=:g_id and eintragerfolgt_yn=1 
                   ORDER BY datumvon DESC LIMIT 1", array (":g_id" => $id))
           ->fetch();
  
  if ($res == false) throw new CTFail("Es ist keine Gruppenteilnahme mehr gepflegt.");
  
  db_query("DELETE FROM {cdb_gruppentreffen_gemeindeperson} 
            WHERE gruppentreffen_id=:gruppentreffen_id", 
            array (":gruppentreffen_id" => $res->id));
  db_update("cdb_gruppentreffen")
    ->fields(array ("eintragerfolgt_yn" => 0, "ausgefallen_yn" => 0))
    ->condition("id", $res->id, "=")
    ->execute();
}

/**
 * delete relation
 *
 * @param int $id          
 *
 * @return string "ok"
 */
function delRelation($id) {
  db_query("DELETE FROM {cdb_beziehung} WHERE id=:id", array (":id" => $id));
  return "ok";
}

/**
 * get a link for ???
 *
 * @param int $id          
 * @param string $txt          
 *
 * @return string "ok"
 */
function _churchdb_a($id, $txt) {
  $a = url("churchdb", array('absolute' => TRUE));

  return "<a href=\"$a?id=$id\">$txt</a>";
}

/**
 * get personal newsletter for person p_id
 *
 * @param
 *          $p_id
 */
function getPersonalNews($p_id) {
  $user; // ???
  
  $sql = 'SELECT p.name, p.vorname, gp.id gp_id, p.cmsuserid 
          FROM {cdb_person} p, {cdb_gemeindeperson} gp 
          WHERE gp.person_id=p.id and p.id=:id';
  $person = db_query($sql, array (":id" => $p_id))->fetch();
  
  if (!$person || !$person->cmsuserid) return "";
  if (!$user = user_load($person->cmsuserid)) return "";
  
  // new persons in group
  $sql_gruppen = 'SELECT gpg.gruppe_id g_id, g.bezeichnung FROM {cdb_gemeindeperson_gruppe} gpg, {cdb_gruppe} g
                  WHERE g.id=gpg.gruppe_id and gpg.status_no>0 AND ((g.abschlussdatum is null) 
                    OR (datediff(g.abschlussdatum,CURRENT_DATE)>-100 ))
                    AND gpg.gemeindeperson_id=' . $person->gp_id . '
                  ORDER BY g.bezeichnung';
  
  $sql_teilnehmer = 'SELECT vorname, name, gpg.letzteaenderung, gpg.aenderunguser, g.bezeichnung gruppe, gt.bezeichnung gruppentyp, p.id p_id 
        FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg, {cdb_gruppe} g, {cdb_gruppentyp} gt
                     WHERE p.id=gp.person_id AND gpg.gemeindeperson_id=gp.id AND gpg.gruppe_id=g.id 
                       AND g.gruppentyp_id=gt.id AND gpg.gruppe_id=:gruppen_id
                       AND (datediff(gpg.letzteaenderung,CURRENT_DATE)>=-31)';
  $curtxt = array ();
  $resGroups = db_query($sql_gruppen);
  foreach ($resGroups as $group) {
    $resPart = db_query($sql_teilnehmer, array (":gruppen_id" => $group->g_id));
    foreach ($resPart as $p) {
      // TODO: translate, optimize sql requests
      $curtxt[] = _churchdb_a($p->p_id, "$p->vorname $p->name") .
           " ($p->gruppentyp $p->gruppe seit $p->letzteaenderung, eingepflegt von $p->aenderunguser)";
    }
  }
  $txt = "";
  if (count($curtxt)) {
    $txt .= "<h3>Neue Personen in Deinen Gruppen</h3>" . implode("<br/>", $curtxt);
  }
  
  // please (re?)view following persons
  $sql_teilnehmer = 'SELECT p.id, gp.id gp_id, vorname, name FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg, {cdb_gruppe} g, {cdb_gruppentyp} gt
       WHERE p.id=gp.person_id and gpg.gemeindeperson_id=gp.id AND gpg.gruppe_id=g.id AND g.gruppentyp_id=gt.id AND gpg.status_no=0
              AND gpg.gruppe_id=:teilnehmer';
  // $sql_beziehung_ich="SELECT * FROM {cdb_beziehung} WHERE vater_id=$p_id AND kind_id=%n AND datum+30>CURRENT_DATE";
  $sql_beziehung_ich = "SELECT COUNT(*) c FROM {cdb_log} WHERE userid='" . $user->cmsuserid .
       "' AND domain_id=:person_id AND domain_type='person' AND (datediff(datum,CURRENT_DATE)>-100)";
  
  $sql_beziehung_alle = "SELECT COUNT(*) c FROM {cdb_log} WHERE domain_id=:person_id AND domain_type='person' AND (datediff(datum,CURRENT_DATE)>-100)";
  $sql_gruppentreffen = "SELECT COUNT(gt.id) c FROM {cdb_gruppentreffen_gemeindeperson} gtgp, {cdb_gruppentreffen} gt 
                          WHERE gtgp.gruppentreffen_id=gt.id AND datumbis+30>CURRENT_DATE AND gt.gruppe_id=:g_id AND gtgp.gemeindeperson_id=:gp_id";
  $res = db_query($sql_gruppen);
  $curtxt = array ();
  foreach ($res as $arr) {
    $res2 = db_query($sql_teilnehmer, array (":teilnehmer" => $arr->g_id));
    $txt2 = array();
    foreach ($res2 as $p) {
      $count_ich = db_query($sql_beziehung_ich, array (":person_id" => $p->id))->fetch();
      $count_bez_alle = db_query($sql_beziehung_alle, array (":person_id" => $p->id))->fetch();
      $count_gruppentreffen = db_query($sql_gruppentreffen, array (":g_id" => $arr->g_id, ":gp_id" => $p->id))->fetch();
      if (($count_ich->c == 0) && ($count_bez_alle->c < 3) && ($count_gruppentreffen->c == 0)) $txt2[] = _churchdb_a($p->id, $p->vorname .
           " " . $p->name) . " ($count_bez_alle->c/$count_gruppentreffen->c)";
    }
    if (count($txt2)) $curtxt[] = "<i>In Gruppe $arr->bezeichnung</i><br/>" . implode("<br/>", $txt2);
  }
  if (!$curtxt) {
    $txt .= "<br/><h3>Folgende Personen bitte einmal anschauen</h3>" . implode("<br/>", $curtxt);
  }
  
  // Geburtstage
  // TODO: why select in select???
  $sql_geb = "SELECT * FROM (
                SELECT person_id, datediff(DATE_ADD(geburtsdatum,INTERVAL (year(curdate())-year(geburtsdatum)) year),curdate()) as diff, name, 
                       vorname, geburtsdatum, (year(curdate())-year(geburtsdatum) - (RIGHT(CURDATE(),5)<RIGHT(geburtsdatum,5))) as 'alter'
                FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg
                WHERE p.id=gp.person_id AND geburtsdatum is not null AND gpg.gemeindeperson_id=gp.id
                AND gpg.gruppe_id=:gp_id) 
      as t WHERE t.diff>=0 AND t.diff<=31 ORDER BY t.diff";
  // ORDER BY t.diff, MONTH(geburtsdatum ), DAYOFMONTH(geburtsdatum ), name, vorname";
  
  $res = db_query($sql_gruppen);
  $curtxt = array ();
  foreach ($res as $arr) {
    $res2 = db_query($sql_geb, array (":gp_id" => $arr->g_id));
    $txt2 = "";
    foreach ($res as $p) {
      $txt2 = $txt2 . _churchdb_a($p->person_id, "$p->vorname $p->name") . " $p->geburtsdatum (in $p->diff Tagen) <br/>";
    }
    if ($txt2) $curtxt[] = "<i>In Gruppe $arr->bezeichnung</i><br/>$txt2<br/>";
  }
  if ($curtxt) {
    $txt .= "<br/><h3>Geburtstage Deiner Personen in den n&auml;chsten 31 Tagen</h3>" . implode("<br/>", $curtxt);
  }
  
  $sql_teilnehmer = "SELECT vorname, name, c.text, c.datum, c.userid, p.id p_id FROM {cdb_person} p, {cdb_gemeindeperson} gp, {cdb_gemeindeperson_gruppe} gpg, {cdb_gruppe} g, {cdb_gruppentyp} gt, {cdb_comment} c
       WHERE p.id=gp.person_id AND gpg.gemeindeperson_id=gp.id AND gpg.gruppe_id=g.id AND g.gruppentyp_id=gt.id
              AND gpg.gruppe_id=:g_id AND c.comment_viewer_id=0 AND c.relation_id=p.id AND c.relation_name='person' AND (datediff(c.datum,CURRENT_DATE)>=-31) 
               ORDER BY c.datum desc";
  $res = db_query($sql_gruppen);
  $curtxt = array ();
  foreach ($res as $arr) {
    $res2 = db_query($sql_teilnehmer, array (":g_id" => $arr->g_id
    ));
    foreach ($res2 as $p) {
      $curtxt[] = _churchdb_a($p->p_id, "$p->vorname $p->name") . " - \"$p->text\" ($p->datum von $p->userid)<br/>";
    }
  }
  if (count($curtxt)) {
    $txt .= "<br/><h3>Neue Kommentare zu Deinen Personen</h3>" . implode("<br/>", $curtxt);
  }
  
  if ($txt) $txt = "<div style=\"margin:3px;padding:5px\"><h2>Persönliche Infos für $person->vorname $person->name</h2>$txt</div>";
  
  return $txt;
}

/**
 * count member of Group
 * 
 * @param int $g_id          
 */
function churchdb_countMembersInGroup($g_id) {
  $res = db_query("SELECT COUNT(*) c FROM {cdb_gemeindeperson_gruppe} p WHERE gruppe_id=$g_id AND status_no=0")->fetch();
  return $res->c;
}




