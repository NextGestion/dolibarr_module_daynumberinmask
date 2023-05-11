<?php 

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';


class daynumberinmask extends Commonobject 
{
    /**
     * @var string ID to identify managed object
     */
    public $element = 'daynumberinmask';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'daynumberinmask';

    /**
     * @var string picto
     */
    public $picto = 'model';

    public function __construct($db)
    {   
        global $conf, $langs;

        $this->db = $db;

    
    }


    /**
     * Return last or next value for a mask (according to area we should not reset)
     *
     * @param   DoliDB      $db             Database handler
     * @param   string      $mask           Mask to use
     * @param   string      $table          Table containing field with counter
     * @param   string      $field          Field containing already used values of counter
     * @param   string      $where          To add a filter on selection (for exemple to filter on invoice types)
     * @param   Societe     $objsoc         The company that own the object we need a counter for
     * @param   string      $date           Date to use for the {y},{m},{d} tags.
     * @param   string      $mode           'next' for next value or 'last' for last value
     * @param   bool        $bentityon      Activate the entity filter. Default is true (for modules not compatible with multicompany)
     * @param   User        $objuser        Object user we need data from.
     * @param   int         $forceentity    Entity id to force
     * @return  string                      New value (numeric) or error message
     */
    function daynumberinmask_get_next_value($db, $mask, $table, $field, $where = '', $objsoc = '', $date = '', $mode = 'next', $bentityon = true, $objuser = null, $forceentity = null)
    {
        global $conf, $user;

        if (!is_object($objsoc)) {
            $valueforccc = $objsoc;
        } elseif ($table == "commande_fournisseur" || $table == "facture_fourn") {
            $valueforccc = dol_string_unaccent($objsoc->code_fournisseur);
        } else {
            $valueforccc = dol_string_unaccent($objsoc->code_client);
        }

        $sharetable = $table;
        if ($table == 'facture' || $table == 'invoice') {
            $sharetable = 'invoicenumber'; // for getEntity function
        }

        // Clean parameters
        if ($date == '') {
            $date = dol_now(); // We use local year and month of PHP server to search numbers
        }
        // but we should use local year and month of user

        // For debugging
        //dol_syslog("mask=".$mask, LOG_DEBUG);
        //include_once(DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php');
        //$mask='FA{yy}{mm}-{0000@99}';
        //$date=dol_mktime(12, 0, 0, 1, 1, 1900);
        //$date=dol_stringtotime('20130101');

        $hasglobalcounter = false;
        $reg = array();
        // Extract value for mask counter, mask raz and mask offset
        if (preg_match('/\{(0+)([@\+][0-9\-\+\=]+)?([@\+][0-9\-\+\=]+)?\}/i', $mask, $reg)) {
            $masktri = $reg[1].(!empty($reg[2]) ? $reg[2] : '').(!empty($reg[3]) ? $reg[3] : '');
            $maskcounter = $reg[1];
            $hasglobalcounter = true;
        } else {
            // setting some defaults so the rest of the code won't fail if there is a third party counter
            $masktri = '00000';
            $maskcounter = '00000';
        }

        $maskraz = -1;
        $maskoffset = 0;
        $resetEveryMonth = false;
        if (dol_strlen($maskcounter) < 3 && empty($conf->global->MAIN_COUNTER_WITH_LESS_3_DIGITS)) {
            return 'ErrorCounterMustHaveMoreThan3Digits';
        }

        // Extract value for third party mask counter
        $regClientRef = array();
        if (preg_match('/\{(c+)(0*)\}/i', $mask, $regClientRef)) {
            $maskrefclient = $regClientRef[1].$regClientRef[2];
            $maskrefclient_maskclientcode = $regClientRef[1];
            $maskrefclient_maskcounter = $regClientRef[2];
            $maskrefclient_maskoffset = 0; //default value of maskrefclient_counter offset
            $maskrefclient_clientcode = substr($valueforccc, 0, dol_strlen($maskrefclient_maskclientcode)); //get n first characters of client code where n is length in mask
            $maskrefclient_clientcode = str_pad($maskrefclient_clientcode, dol_strlen($maskrefclient_maskclientcode), "#", STR_PAD_RIGHT); //padding maskrefclient_clientcode for having exactly n characters in maskrefclient_clientcode
            $maskrefclient_clientcode = dol_string_nospecial($maskrefclient_clientcode); //sanitize maskrefclient_clientcode for sql insert and sql select like
            if (dol_strlen($maskrefclient_maskcounter) > 0 && dol_strlen($maskrefclient_maskcounter) < 3) {
                return 'ErrorCounterMustHaveMoreThan3Digits';
            }
        } else {
            $maskrefclient = '';
        }

        // fail if there is neither a global nor a third party counter
        if (!$hasglobalcounter && ($maskrefclient_maskcounter == '')) {
            return 'ErrorBadMask';
        }

        // Extract value for third party type
        $regType = array();
        if (preg_match('/\{(t+)\}/i', $mask, $regType)) {
            $masktype = $regType[1];
            $masktype_value = dol_substr(preg_replace('/^TE_/', '', $objsoc->typent_code), 0, dol_strlen($regType[1])); // get n first characters of thirdparty typent_code (where n is length in mask)
            $masktype_value = str_pad($masktype_value, dol_strlen($regType[1]), "#", STR_PAD_RIGHT); // we fill on right with # to have same number of char than into mask
        } else {
            $masktype = '';
            $masktype_value = '';
        }

        // Extract value for user
        $regType = array();
        if (preg_match('/\{(u+)\}/i', $mask, $regType)) {
            $lastname = 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX';
            if (is_object($objuser)) {
                $lastname = $objuser->lastname;
            }

            $maskuser = $regType[1];
            $maskuser_value = substr($lastname, 0, dol_strlen($regType[1])); // get n first characters of user firstname (where n is length in mask)
            $maskuser_value = str_pad($maskuser_value, dol_strlen($regType[1]), "#", STR_PAD_RIGHT); // we fill on right with # to have same number of char than into mask
        } else {
            $maskuser = '';
            $maskuser_value = '';
        }

        // Personalized field {XXX-1} à {XXX-9}
        $maskperso = array();
        $maskpersonew = array();
        $tmpmask = $mask;
        $regKey = array();
        while (preg_match('/\{([A-Z]+)\-([1-9])\}/', $tmpmask, $regKey)) {
            $maskperso[$regKey[1]] = '{'.$regKey[1].'-'.$regKey[2].'}';
            $maskpersonew[$regKey[1]] = str_pad('', $regKey[2], '_', STR_PAD_RIGHT);
            $tmpmask = preg_replace('/\{'.$regKey[1].'\-'.$regKey[2].'\}/i', $maskpersonew[$regKey[1]], $tmpmask);
        }

        if (strstr($mask, 'user_extra_')) {
            $start = "{user_extra_";
            $end = "\}";
            $extra = get_string_between($mask, "user_extra_", "}");
            if (!empty($user->array_options['options_'.$extra])) {
                $mask = preg_replace('#('.$start.')(.*?)('.$end.')#si', $user->array_options['options_'.$extra], $mask);
            }
        }
        $maskwithonlyymcode = $mask;
        $maskwithonlyymcode = preg_replace('/\{(0+)([@\+][0-9\-\+\=]+)?([@\+][0-9\-\+\=]+)?\}/i', $maskcounter, $maskwithonlyymcode);
        $maskwithonlyymcode = preg_replace('/\{dd\}/i', 'dd', $maskwithonlyymcode);
        $maskwithonlyymcode = preg_replace('/\{(c+)(0*)\}/i', $maskrefclient, $maskwithonlyymcode);
        $maskwithonlyymcode = preg_replace('/\{(t+)\}/i', $masktype_value, $maskwithonlyymcode);
        $maskwithonlyymcode = preg_replace('/\{(u+)\}/i', $maskuser_value, $maskwithonlyymcode);
        foreach ($maskperso as $key => $val) {
            $maskwithonlyymcode = preg_replace('/'.preg_quote($val, '/').'/i', $maskpersonew[$key], $maskwithonlyymcode);
        }
        $maskwithnocode = $maskwithonlyymcode;
        $maskwithnocode = preg_replace('/\{yyyy\}/i', 'yyyy', $maskwithnocode);
        $maskwithnocode = preg_replace('/\{yy\}/i', 'yy', $maskwithnocode);
        $maskwithnocode = preg_replace('/\{y\}/i', 'y', $maskwithnocode);
        $maskwithnocode = preg_replace('/\{mm\}/i', 'mm', $maskwithnocode);
        // Now maskwithnocode = 0000ddmmyyyyccc for example
        // and maskcounter    = 0000 for example
        //print "maskwithonlyymcode=".$maskwithonlyymcode." maskwithnocode=".$maskwithnocode."\n<br>";
        //var_dump($reg);

        // If an offset is asked
        if (!empty($reg[2]) && preg_match('/^\+/', $reg[2])) {
            $maskoffset = preg_replace('/^\+/', '', $reg[2]);
        }
        if (!empty($reg[3]) && preg_match('/^\+/', $reg[3])) {
            $maskoffset = preg_replace('/^\+/', '', $reg[3]);
        }

        // Define $sqlwhere
        $sqlwhere = '';
        $yearoffset = 0; // Use year of current $date by default
        $yearoffsettype = false; // false: no reset, 0,-,=,+: reset at offset SOCIETE_FISCAL_MONTH_START, x=reset at offset x

        // If a restore to zero after a month is asked we check if there is already a value for this year.
        if (!empty($reg[2]) && preg_match('/^@/', $reg[2])) {
            $yearoffsettype = preg_replace('/^@/', '', $reg[2]);
        }
        if (!empty($reg[3]) && preg_match('/^@/', $reg[3])) {
            $yearoffsettype = preg_replace('/^@/', '', $reg[3]);
        }

        //print "yearoffset=".$yearoffset." yearoffsettype=".$yearoffsettype;
        if (is_numeric($yearoffsettype) && $yearoffsettype >= 1) {
            $maskraz = $yearoffsettype; // For backward compatibility
        } elseif ($yearoffsettype === '0' || (!empty($yearoffsettype) && !is_numeric($yearoffsettype) && $conf->global->SOCIETE_FISCAL_MONTH_START > 1)) {
            $maskraz = $conf->global->SOCIETE_FISCAL_MONTH_START;
        }
        //print "maskraz=".$maskraz;    // -1=no reset

        if ($maskraz > 0) {   // A reset is required
            if ($maskraz == 99) {
                $maskraz = date('m', $date);
                $resetEveryMonth = true;
            }
            if ($maskraz > 12) {
                return 'ErrorBadMaskBadRazMonth';
            }

            // Define posy, posm and reg
            if ($maskraz > 1) { // if reset is not first month, we need month and year into mask
                if (preg_match('/^(.*)\{(y+)\}\{(m+)\}/i', $maskwithonlyymcode, $reg)) {
                    $posy = 2;
                    $posm = 3;
                } elseif (preg_match('/^(.*)\{(m+)\}\{(y+)\}/i', $maskwithonlyymcode, $reg)) {
                    $posy = 3;
                    $posm = 2;
                } else {
                    return 'ErrorCantUseRazInStartedYearIfNoYearMonthInMask';
                }

                if (dol_strlen($reg[$posy]) < 2) {
                    return 'ErrorCantUseRazWithYearOnOneDigit';
                }
            } else // if reset is for a specific month in year, we need year
            {
                if (preg_match('/^(.*)\{(m+)\}\{(y+)\}/i', $maskwithonlyymcode, $reg)) {
                    $posy = 3;
                    $posm = 2;
                } elseif (preg_match('/^(.*)\{(y+)\}\{(m+)\}/i', $maskwithonlyymcode, $reg)) {
                    $posy = 2;
                    $posm = 3;
                } elseif (preg_match('/^(.*)\{(y+)\}/i', $maskwithonlyymcode, $reg)) {
                    $posy = 2;
                    $posm = 0;
                } else {
                    return 'ErrorCantUseRazIfNoYearInMask';
                }
            }
            // Define length
            $yearlen = $posy ?dol_strlen($reg[$posy]) : 0;
            $monthlen = $posm ?dol_strlen($reg[$posm]) : 0;
            // Define pos
            $yearpos = (dol_strlen($reg[1]) + 1);
            $monthpos = ($yearpos + $yearlen);
            if ($posy == 3 && $posm == 2) {     // if month is before year
                $monthpos = (dol_strlen($reg[1]) + 1);
                $yearpos = ($monthpos + $monthlen);
            }
            //print "xxx ".$maskwithonlyymcode." maskraz=".$maskraz." posy=".$posy." yearlen=".$yearlen." yearpos=".$yearpos." posm=".$posm." monthlen=".$monthlen." monthpos=".$monthpos." yearoffsettype=".$yearoffsettype." resetEveryMonth=".$resetEveryMonth."\n";

            // Define $yearcomp and $monthcomp (that will be use in the select where to search max number)
            $monthcomp = $maskraz;
            $yearcomp = 0;

            if (!empty($yearoffsettype) && !is_numeric($yearoffsettype) && $yearoffsettype != '=') {    // $yearoffsettype is - or +
                $currentyear = date("Y", $date);
                $fiscaldate = dol_mktime('0', '0', '0', $maskraz, '1', $currentyear);
                $newyeardate = dol_mktime('0', '0', '0', '1', '1', $currentyear);
                $nextnewyeardate = dol_mktime('0', '0', '0', '1', '1', $currentyear + 1);
                //echo 'currentyear='.$currentyear.' date='.dol_print_date($date, 'day').' fiscaldate='.dol_print_date($fiscaldate, 'day').'<br>';

                // If after or equal of current fiscal date
                if ($date >= $fiscaldate) {
                    // If before of next new year date
                    if ($date < $nextnewyeardate && $yearoffsettype == '+') {
                        $yearoffset = 1;
                    }
                } elseif ($date >= $newyeardate && $yearoffsettype == '-') {
                    // If after or equal of current new year date
                    $yearoffset = -1;
                }
            } elseif (date("m", $date) < $maskraz && empty($resetEveryMonth)) {
                // For backward compatibility
                $yearoffset = -1;
            }   // If current month lower that month of return to zero, year is previous year

            if ($yearlen == 4) {
                $yearcomp = sprintf("%04d", date("Y", $date) + $yearoffset);
            } elseif ($yearlen == 2) {
                $yearcomp = sprintf("%02d", date("y", $date) + $yearoffset);
            } elseif ($yearlen == 1) {
                $yearcomp = substr(date('y', $date), 1, 1) + $yearoffset;
            }
            if ($monthcomp > 1 && empty($resetEveryMonth)) {    // Test with month is useless if monthcomp = 0 or 1 (0 is same as 1) (regis: $monthcomp can't equal 0)
                if ($yearlen == 4) {
                    $yearcomp1 = sprintf("%04d", date("Y", $date) + $yearoffset + 1);
                } elseif ($yearlen == 2) {
                    $yearcomp1 = sprintf("%02d", date("y", $date) + $yearoffset + 1);
                }

                $sqlwhere .= "(";
                $sqlwhere .= " (SUBSTRING(".$field.", ".$yearpos.", ".$yearlen.") = '".$db->escape($yearcomp)."'";
                $sqlwhere .= " AND SUBSTRING(".$field.", ".$monthpos.", ".$monthlen.") >= '".str_pad($monthcomp, $monthlen, '0', STR_PAD_LEFT)."')";
                $sqlwhere .= " OR";
                $sqlwhere .= " (SUBSTRING(".$field.", ".$yearpos.", ".$yearlen.") = '".$db->escape($yearcomp1)."'";
                $sqlwhere .= " AND SUBSTRING(".$field.", ".$monthpos.", ".$monthlen.") < '".str_pad($monthcomp, $monthlen, '0', STR_PAD_LEFT)."') ";
                $sqlwhere .= ')';
            } elseif ($resetEveryMonth) {
                $sqlwhere .= "(SUBSTRING(".$field.", ".$yearpos.", ".$yearlen.") = '".$db->escape($yearcomp)."'";
                $sqlwhere .= " AND SUBSTRING(".$field.", ".$monthpos.", ".$monthlen.") = '".str_pad($monthcomp, $monthlen, '0', STR_PAD_LEFT)."')";
            } else { // reset is done on january
                $sqlwhere .= "(SUBSTRING(".$field.", ".$yearpos.", ".$yearlen.") = '".$db->escape($yearcomp)."')";
            }
        }
        //print "sqlwhere=".$sqlwhere." yearcomp=".$yearcomp."<br>\n";  // sqlwhere and yearcomp defined only if we ask a reset
        //print "masktri=".$masktri." maskcounter=".$maskcounter." maskraz=".$maskraz." maskoffset=".$maskoffset."<br>\n";

        // Define $sqlstring
        if (function_exists('mb_strrpos')) {
            $posnumstart = mb_strrpos($maskwithnocode, $maskcounter, 0, 'UTF-8');
        } else {
            $posnumstart = strrpos($maskwithnocode, $maskcounter);
        }   // Pos of counter in final string (from 0 to ...)
        if ($posnumstart < 0) {
            return 'ErrorBadMaskFailedToLocatePosOfSequence';
        }
        $sqlstring = "SUBSTRING(".$field.", ".($posnumstart + 1).", ".dol_strlen($maskcounter).")";

        // Define $maskLike
        $maskLike = dol_string_nospecial($mask);
        $maskLike = str_replace("%", "_", $maskLike);

        // Replace protected special codes with matching number of _ as wild card caracter
        $maskLike = preg_replace('/\{yyyy\}/i', '____', $maskLike);
        $maskLike = preg_replace('/\{yy\}/i', '__', $maskLike);
        $maskLike = preg_replace('/\{y\}/i', '_', $maskLike);
        $maskLike = preg_replace('/\{mm\}/i', '__', $maskLike);
        $maskLike = preg_replace('/\{dd\}/i', '__', $maskLike);
        $maskLike = preg_replace('/\{zz\}/i', '__', $maskLike);
        $maskLike = str_replace(dol_string_nospecial('{'.$masktri.'}'), str_pad("", dol_strlen($maskcounter), "_"), $maskLike);
        if ($maskrefclient) {
            $maskLike = str_replace(dol_string_nospecial('{'.$maskrefclient.'}'), str_pad("", dol_strlen($maskrefclient), "_"), $maskLike);
        }
        if ($masktype) {
            $maskLike = str_replace(dol_string_nospecial('{'.$masktype.'}'), $masktype_value, $maskLike);
        }
        if ($maskuser) {
            $maskLike = str_replace(dol_string_nospecial('{'.$maskuser.'}'), $maskuser_value, $maskLike);
        }
        foreach ($maskperso as $key => $val) {
            $maskLike = str_replace(dol_string_nospecial($maskperso[$key]), $maskpersonew[$key], $maskLike);
        }

        // Get counter in database
        $counter = 0;
        $sql = "SELECT MAX(".$sqlstring.") as val";
        $sql .= " FROM ".MAIN_DB_PREFIX.$table;
        $sql .= " WHERE ".$field." LIKE '".$db->escape($maskLike)."'";
        $sql .= " AND ".$field." NOT LIKE '(PROV%)'";

        // To ensure that all variables within the MAX() brackets are integers
        if (getDolGlobalInt('MAIN_NUMBERING_FILTER_ON_INT_ONLY')) {
            $sql .= " AND ". $db->regexpsql($sqlstring, '^[0-9]+$', true);
        }

        if ($bentityon) { // only if entity enable
            $sql .= " AND entity IN (".getEntity($sharetable).")";
        } elseif (!empty($forceentity)) {
            $sql .= " AND entity IN (".$db->sanitize($forceentity).")";
        }
        if ($where) {
            $sql .= $where;
        }
        if ($sqlwhere) {
            $sql .= " AND ".$sqlwhere;
        }

        //print $sql.'<br>';
        dol_syslog("functions2::get_next_value mode=".$mode."", LOG_DEBUG);
        $resql = $db->query($sql);
        if ($resql) {
            $obj = $db->fetch_object($resql);
            $counter = $obj->val;
        } else {
            dol_print_error($db);
        }

        // Check if we must force counter to maskoffset
        if (empty($counter)) {
            $counter = $maskoffset;
        } elseif (preg_match('/[^0-9]/i', $counter)) {
            $counter = 0;
            dol_syslog("Error, the last counter found is '".$counter."' so is not a numeric value. We will restart to 1.", LOG_ERR);
        } elseif ($counter < $maskoffset && empty($conf->global->MAIN_NUMBERING_OFFSET_ONLY_FOR_FIRST)) {
            $counter = $maskoffset;
        }

        if ($mode == 'last') {  // We found value for counter = last counter value. Now need to get corresponding ref of invoice.
            $counterpadded = str_pad($counter, dol_strlen($maskcounter), "0", STR_PAD_LEFT);

            // Define $maskLike
            $maskLike = dol_string_nospecial($mask);
            $maskLike = str_replace("%", "_", $maskLike);
            // Replace protected special codes with matching number of _ as wild card caracter
            $maskLike = preg_replace('/\{yyyy\}/i', '____', $maskLike);
            $maskLike = preg_replace('/\{yy\}/i', '__', $maskLike);
            $maskLike = preg_replace('/\{y\}/i', '_', $maskLike);
            $maskLike = preg_replace('/\{mm\}/i', '__', $maskLike);
            $maskLike = preg_replace('/\{dd\}/i', '__', $maskLike);
            $maskLike = preg_replace('/\{zz\}/i', '__', $maskLike);
            $maskLike = str_replace(dol_string_nospecial('{'.$masktri.'}'), $counterpadded, $maskLike);
            if ($maskrefclient) {
                $maskLike = str_replace(dol_string_nospecial('{'.$maskrefclient.'}'), str_pad("", dol_strlen($maskrefclient), "_"), $maskLike);
            }
            if ($masktype) {
                $maskLike = str_replace(dol_string_nospecial('{'.$masktype.'}'), $masktype_value, $maskLike);
            }
            if ($maskuser) {
                $maskLike = str_replace(dol_string_nospecial('{'.$maskuser.'}'), $maskuser_value, $maskLike);
            }

            $ref = '';
            $sql = "SELECT ".$field." as ref";
            $sql .= " FROM ".MAIN_DB_PREFIX.$table;
            $sql .= " WHERE ".$field." LIKE '".$db->escape($maskLike)."'";
            $sql .= " AND ".$field." NOT LIKE '%PROV%'";
            if ($bentityon) { // only if entity enable
                $sql .= " AND entity IN (".getEntity($sharetable).")";
            } elseif (!empty($forceentity)) {
                $sql .= " AND entity IN (".$db->sanitize($forceentity).")";
            }
            if ($where) {
                $sql .= $where;
            }
            if ($sqlwhere) {
                $sql .= " AND ".$sqlwhere;
            }

            dol_syslog("functions2::get_next_value mode=".$mode."", LOG_DEBUG);
            $resql = $db->query($sql);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                if ($obj) {
                    $ref = $obj->ref;
                }
            } else {
                dol_print_error($db);
            }

            $numFinal = $ref;
        } elseif ($mode == 'next') {
            $counter++;
            $maskrefclient_counter = 0;

            // If value for $counter has a length higher than $maskcounter chars
            if ($counter >= pow(10, dol_strlen($maskcounter))) {
                $counter = 'ErrorMaxNumberReachForThisMask';
            }

            if (!empty($maskrefclient_maskcounter)) {
                //print "maskrefclient_maskcounter=".$maskrefclient_maskcounter." maskwithnocode=".$maskwithnocode." maskrefclient=".$maskrefclient."\n<br>";

                // Define $sqlstring
                $maskrefclient_posnumstart = strpos($maskwithnocode, $maskrefclient_maskcounter, strpos($maskwithnocode, $maskrefclient)); // Pos of counter in final string (from 0 to ...)
                if ($maskrefclient_posnumstart <= 0) {
                    return 'ErrorBadMask';
                }
                $maskrefclient_sqlstring = 'SUBSTRING('.$field.', '.($maskrefclient_posnumstart + 1).', '.dol_strlen($maskrefclient_maskcounter).')';
                //print "x".$sqlstring;

                // Define $maskrefclient_maskLike
                $maskrefclient_maskLike = dol_string_nospecial($mask);
                $maskrefclient_maskLike = str_replace("%", "_", $maskrefclient_maskLike);
                // Replace protected special codes with matching number of _ as wild card caracter
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{yyyy}'), '____', $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{yy}'), '__', $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{y}'), '_', $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{mm}'), '__', $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{dd}'), '__', $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{zz}'), '__', $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{'.$masktri.'}'), str_pad("", dol_strlen($maskcounter), "_"), $maskrefclient_maskLike);
                $maskrefclient_maskLike = str_replace(dol_string_nospecial('{'.$maskrefclient.'}'), $maskrefclient_clientcode.str_pad("", dol_strlen($maskrefclient_maskcounter), "_"), $maskrefclient_maskLike);

                // Get counter in database
                $maskrefclient_sql = "SELECT MAX(".$maskrefclient_sqlstring.") as val";
                $maskrefclient_sql .= " FROM ".MAIN_DB_PREFIX.$table;
                //$sql.= " WHERE ".$field." not like '(%'";
                $maskrefclient_sql .= " WHERE ".$field." LIKE '".$db->escape($maskrefclient_maskLike)."'";
                if ($bentityon) { // only if entity enable
                    $maskrefclient_sql .= " AND entity IN (".getEntity($sharetable).")";
                } elseif (!empty($forceentity)) {
                    $sql .= " AND entity IN (".$db->sanitize($forceentity).")";
                }
                if ($where) {
                    $maskrefclient_sql .= $where; //use the same optional where as general mask
                }
                if ($sqlwhere) {
                    $maskrefclient_sql .= ' AND '.$sqlwhere; //use the same sqlwhere as general mask
                }
                $maskrefclient_sql .= " AND (SUBSTRING(".$field.", ".(strpos($maskwithnocode, $maskrefclient) + 1).", ".dol_strlen($maskrefclient_maskclientcode).") = '".$db->escape($maskrefclient_clientcode)."')";

                dol_syslog("functions2::get_next_value maskrefclient", LOG_DEBUG);
                $maskrefclient_resql = $db->query($maskrefclient_sql);
                if ($maskrefclient_resql) {
                    $maskrefclient_obj = $db->fetch_object($maskrefclient_resql);
                    $maskrefclient_counter = $maskrefclient_obj->val;
                } else {
                    dol_print_error($db);
                }

                if (empty($maskrefclient_counter) || preg_match('/[^0-9]/i', $maskrefclient_counter)) {
                    $maskrefclient_counter = $maskrefclient_maskoffset;
                }
                $maskrefclient_counter++;
            }

            // Build numFinal
            $numFinal = $mask;

            // We replace special codes except refclient
            if (!empty($yearoffsettype) && !is_numeric($yearoffsettype) && $yearoffsettype != '=') {    // yearoffsettype is - or +, so we don't want current year
                $numFinal = preg_replace('/\{yyyy\}/i', date("Y", $date) + $yearoffset, $numFinal);
                $numFinal = preg_replace('/\{yy\}/i', date("y", $date) + $yearoffset, $numFinal);
                $numFinal = preg_replace('/\{y\}/i', substr(date("y", $date), 1, 1) + $yearoffset, $numFinal);
            } else // we want yyyy to be current year
            {
                $numFinal = preg_replace('/\{yyyy\}/i', date("Y", $date), $numFinal);
                $numFinal = preg_replace('/\{yy\}/i', date("y", $date), $numFinal);
                $numFinal = preg_replace('/\{y\}/i', substr(date("y", $date), 1, 1), $numFinal);
            }
            $numFinal = preg_replace('/\{mm\}/i', date("m", $date), $numFinal);
            $numFinal = preg_replace('/\{dd\}/i', date("d", $date), $numFinal);
            $numFinal = preg_replace('/\{zz\}/i', date("z"), $numFinal);

            // Now we replace the counter
            $maskbefore = '{'.$masktri.'}';
            $maskafter = str_pad($counter, dol_strlen($maskcounter), "0", STR_PAD_LEFT);
            //print 'x'.$numFinal.' - '.$maskbefore.' - '.$maskafter.'y';exit;
            $numFinal = str_replace($maskbefore, $maskafter, $numFinal);

            // Now we replace the refclient
            if ($maskrefclient) {
                //print "maskrefclient=".$maskrefclient." maskrefclient_counter=".$maskrefclient_counter." maskwithonlyymcode=".$maskwithonlyymcode." maskwithnocode=".$maskwithnocode." maskrefclient_clientcode=".$maskrefclient_clientcode." maskrefclient_maskcounter=".$maskrefclient_maskcounter."\n<br>";exit;
                $maskrefclient_maskbefore = '{'.$maskrefclient.'}';
                $maskrefclient_maskafter = $maskrefclient_clientcode;
                if (dol_strlen($maskrefclient_maskcounter) > 0) {
                    $maskrefclient_maskafter .= str_pad($maskrefclient_counter, dol_strlen($maskrefclient_maskcounter), "0", STR_PAD_LEFT);
                }
                $numFinal = str_replace($maskrefclient_maskbefore, $maskrefclient_maskafter, $numFinal);
            }

            // Now we replace the type
            if ($masktype) {
                $masktype_maskbefore = '{'.$masktype.'}';
                $masktype_maskafter = $masktype_value;
                $numFinal = str_replace($masktype_maskbefore, $masktype_maskafter, $numFinal);
            }

            // Now we replace the user
            if ($maskuser) {
                $maskuser_maskbefore = '{'.$maskuser.'}';
                $maskuser_maskafter = $maskuser_value;
                $numFinal = str_replace($maskuser_maskbefore, $maskuser_maskafter, $numFinal);
            }
        }

        dol_syslog("functions2::get_next_value return ".$numFinal, LOG_DEBUG);
        return $numFinal;
    }
}
