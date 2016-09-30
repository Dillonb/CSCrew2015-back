<?php
// LDAP
require_once('UVMLdap.php');

// Initialize Propel
require_once('vendor/propel/propel1/runtime/lib/Propel.php');
Propel::init('build/conf/cscrew-conf.php');
set_include_path("build/classes" . PATH_SEPARATOR . get_include_path());


function netid_info($netid) {
    $ld = new UVMLdap;
    $results = array();
    $filter = $ld->makeFilter("uid","=",$netid);
    $result = @ldap_search($ld->ldap, $ld->ldap_base, $filter);
    if ($result) {
        $entries = ldap_get_entries($ld->ldap,$result);
        if (count($entries) != 2 || (array_key_exists("count", $entries) && $entries["count"] != 1)) {
            return false;
        }
        $person = $entries["0"];
        return $person;
    }
    else {
        print ldap_error($ld->ldap);
    }
}

function apply_netid_info_to_user($netid_info, $user) {
	if (array_key_exists("givenname", $netid_info)) {
		$name = $netid_info["givenname"]["0"] . " " . $netid_info["sn"]["0"];
	}
	else {
		// User has no name in LDAP, set their name to their netid.
		$name = $netid;
	}
	$year = array_key_exists("ou", $netid_info) ? $netid_info["ou"]["0"] : false;

	$user->setNetid($netid);
	if ($year) {
		$user->setYear($year);
	}
	if ($name) {
		$user->setName($name);
	}
	$user->save();
	return $user;
}

function get_user($netid) {
    $q = new UserQuery();
    $user = $q->findOneByNetid($netid);
    // The user exists in the database
    if ($user) {
        $netid_info = netid_info($netid);
        if ($netid_info) {
	       $user = apply_netid_info_to_user($netid_info, $user);
	}
       return $user;
    }
    else {
        // We need to build it.
        $netid_info = netid_info($netid);
        if ($netid_info) {
		$user = new User();
		$user = apply_netid_info_to_user($netid_info, $user);
		return $user;
        }
        else {
            // User doesn't exist in ldap either
            return false;
        }
    }
}

function get_user_profile($netid) {
    $user = get_user($netid);
    $profile = $user->getmemberProfile();
    if (!$profile) {
        $profile = new memberProfile();
        $profile->setUser($user);
        $profile->save();
    }
    return $profile;
}

function signin_netid($netid, $reason_id) {
    $reason = signInReasonQuery::create()->findPK($reason_id);
    $beginOfDay = strtotime("midnight", time());
    if (!$reason) {
        return array("error"=>true,"message"=>"Invalid reason.");
    }
    $user = get_user($netid);
    if (!$user) {
        return array("error"=>true,"message"=>"Invalid NetID.");
    }
    $signins = signInQuery::create()->filterByCreatedAt(array('min'=>$beginOfDay))->filterByUser($user)->find();
    $signins = $signins->toArray();
    // If the user has signed in already today...
    if (!empty($signins)) {
        // Don't let them do it again
        return array("error"=>true,"message"=>"You've already signed in today.");
    }
    // Otherwise, sign them in.
    $signinRecord = new signIn();
    $signinRecord->setUser($user);
    $signinRecord->setsignInReason($reason);
    $signinRecord->save();
    return array("error"=>false,"message"=>"Sign-in successful.");
}

function process_signins_resultset($signins) {
    $signins_return = array();
    foreach ($signins as $signin) {
        $obj = $signin->toArray();
        $user = $signin->getUser();
        $count = signInQuery::create()->filterByUser($user)->count();
        $obj['user'] = $user->toArray();
        $obj['numSignIns'] = $count;
        $obj['reason'] = $signin->getsignInReason()->toArray();
        $signins_return[] = $obj;
    }
    return $signins_return;
}

function signins_n_days_ago($daysAgo) {
    $oneDayInMS = 24 * 60 * 60 * 100
    $beginOfDay = strtotime("midnight", time());
    $dayBeginning = $beginOfDay - ($oneDayInMS * $daysAgo)
    $dayEnd = $beginOfDay - ($oneDayInMS * ($daysAgo - 1))

    $signins = signInQuery::create()->filterByCreatedAt(array('min'=>$dayBeginning), 'max'=>$dayEnd)->find();

    return process_signins_resultset($signins);
}

function signins_today() {
    $beginOfDay = strtotime("midnight", time());
    $signins = signInQuery::create()->filterByCreatedAt(array('min'=>$beginOfDay))->find();

    return process_signins_resultset($signins);
}
function signins_all() {
    $beginOfDay = strtotime("midnight", time());
    $signins = signInQuery::create()->find();
    return process_signins_resultset($signins);
}

function get_loggedin_info() {
    $who = array();
    if (array_key_exists("WEBAUTH_USER", $_SERVER)) {
        $who['authenticated'] = true;
        $who['username'] = $_SERVER['WEBAUTH_USER'];
        $who['user'] = get_user($who['username'])->toArray();
    } else { $who['authenticated'] = false;
        $who['username'] = 'anonymous';
    }
    return $who;
}

function all_users() {
    return UserQuery::create()->find();
}

function skills_netid($netid) {
    $user = get_user($netid);
    $allSkills = SkillQuery::create()->find();
    $userSkills = SkillQuery::create()->filterByUser($user)->find();
    $userSkillsArray = array();
    // First, build an array of all the skills the user knows, keyed by their id.
    while (!$userSkills->isEmpty()) {
        $skill = $userSkills->pop();
        $userSkillsArray[$skill->getId()] = $skill->toArray();
    }
    $finalArray = array();
    // Then, build a final array of every skill with a new "known" property.
    while (!$allSkills->isEmpty()) {
        $skill = $allSkills->pop()->toArray();
        $skill['known'] = array_key_exists($skill['Id'], $userSkillsArray);
        array_push($finalArray,$skill);
    }
    //return array($allSkills->toArray(),$userSkills->toArray());
    return $finalArray;
}
function update_skills($netid, $data) {
    $user = get_user($netid);
    $temp_allSkills = SkillQuery::create()->find();
    $allSkills = array();
    // Key all the skills by their id
    foreach ($temp_allSkills as $skill) {
        $allSkills[$skill->getId()] = $skill;
    }
    //Loop through the data given
    foreach ($data as $skill) {
        // Add a relationship if the skill is known (propel will take care of the case if the relationship already exists)
        if ($skill['known']) {
            $allSkills[$skill['Id']]->addUser($user);
        }
        // Or remove it if the skill is not known
        else {
            $allSkills[$skill['Id']]->removeUser($user);
        }
        $allSkills[$skill['Id']]->save();
    }
}
function convert_timezone($time) {
    $dt = new DateTime($time, new DateTimeZone("UTC"));
    $dt->setTimezone(new DateTimeZone("America/New_York"));
    return $dt->format("H:i");
}

function is_helphour_signedin($helphour) {
    $count = helpHourSigninQuery::create()
            ->filterByCreatedAt(array(
                'min' => $helphour->getStartTime(),
                'max' => $helphour->getEndTime()
                ))
            ->filterbyhelphour($helphour)
            ->count();
    if ($count == 0) {
        return false;
    }
    return true;
}

function is_helphour_active($helphour) {
    return (intval($helphour->getStartTime("U")) < time() && intval($helphour->getEndTime("U")) > time() && $helphour->onWeekday(date('l')));
}

function signin_helphour($helphour) {
    if (!is_helphour_signedin($helphour) && is_helphour_active($helphour)) {
        $helphour->addhelpHourSignin(new helpHourSignin());
        $helphour->save();
        return true;
    }
    return false;
}

function process_helphour_resultset($helpHours) {
    // Extract skills
    $skills = array();
    // Get signin status
    $signins = array();
    foreach ($helpHours as $helphour) {
        // Skills
        if (!array_key_exists($helphour->getUser()->getNetid(), $skills)) {
            $skills[$helphour->getUser()->getNetid()] = $helphour->getUser()->getSkills()->toArray();
        }
        $signins[$helphour->getId()] = is_helphour_signedin($helphour);
    }

    // Convert everything to an array and merge it all
    $helpHours = $helpHours->toArray();
    foreach ($helpHours as $key=>$value) {
        $helpHours[$key]['User']['Skills'] = $skills[$value['User']['Netid']];
        $helpHours[$key]['SignedIn'] = $signins[$value['Id']];
    }
    return $helpHours;
}
function process_member_resultset($members) {
   $processedMembers = array();

   foreach ($members as $member) {
        $processedMember = $member->toArray();
        $processedMember['User'] = $member->getUser()->toArray();
        $processedMember['User']['Skills'] = $member->getUser()->getSkills()->toArray();
        $processedMembers[] = $processedMember;
   }
   return $processedMembers;
}
function send_contactform_email($fromName, $fromEmail, $message) {
    mail("uvm.cscrew@gmail.com", "CS Crew Contact Form", "From: ${fromName} <". $fromEmail . ">\n" . $message);
}
?>
