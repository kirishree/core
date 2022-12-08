<?php

namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Sensei\Sensei;

class PolicyController extends ApiControllerBase
{

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    private $policyRestrictNumber;

    public function PolicyController()
    {
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'POST' && $sensei->isPremium()) {
                $sensei->haNotice('ha_status_notice_policy');
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }
        }
    }

    private function policyExists($sensei, $parameters, $schedules)
    {
        try {
            $stmt = $sensei->database->prepare('select name,count(*) as total from policies where ' .
                'usernames=:usernames and groups=:groups and ' .
                'interfaces=:interfaces and vlans=:vlans and ' .
                'macaddresses=:macaddresses and ' .
                'networks=:networks and directions=:directions and delete_status=0 and id!=:id' .
                ' and id not in (select policy_id from policies_schedules)');
            foreach ($parameters as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $results = $stmt->execute();
            $row = $results->fetchArray($mode = SQLITE3_ASSOC);
            if ($row['total'] == 0) {
                return [false];
            } elseif (count($schedules) > 0) {
                return [false];
            }

            return [true, $row['name']];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [false];
        }
    }

    private function policyEnableCheck($sensei)
    {
        try {
            $license_plan = '';
            try {
                $license_plan = (string) $sensei->getNodeByReference('general.license.plan');
            } catch (\Exception $e) {
                //throw $th;
            }
            $result = true;
            if ($license_plan == 'opnsense_home') {
                $row = $sensei->database->querySingle('select count(*) as total from policies where ' .
                    'delete_status=0', true);
                if ($row['total'] > 2) {
                    $this->policyRestrictNumber = 'three';
                    return false;
                }
            }
            if ($license_plan == 'opnsense_soho') {
                $row = $sensei->database->querySingle('select count(*) as total from policies where ' .
                    'delete_status=0', true);
                if ($row['total'] > 4) {
                    $this->policyRestrictNumber = 'five';
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            $this->policyRestrictNumber = '';
            return false;
        }
    }

    public function allPolicyAction()
    {
        try {
            $sensei = new Sensei();
            $response = [];
            $policies = $sensei->database->query('SELECT * FROM policies');
            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                $row['security'] = true;
                $row['app'] = false;
                $row['web'] = true;
                $row['tls'] = false;
                $schedules = $sensei->database->query('select s.name,s.description from policies_schedules p, schedules s where p.schedule_id=s.id and p.policy_id=' . $row['id']);
                $schedules_arr = [];
                while ($row_s = $schedules->fetchArray($mode = SQLITE3_ASSOC)) {
                    $schedules_arr[] = $row_s['name'] . '|' . $row_s['description'];
                }
                $row['schedules'] = implode('<br>', $schedules_arr);
                array_push($response, $row);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    private function getPolicy($sensei)
    {
        try {
            $response = [];
            $policies = $sensei->database->query('SELECT * FROM policies where delete_status=0 order by sort_number');
            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                # default policy
                $count = 0;

                $count = $sensei->database->querySingle("select count(*) from policy_web_categories p,web_categories c where c.id=p.web_categories_id and  c.is_security_category=1 and p.action='reject' and policy_id=" . $row['id']);
                //}
                $row['security'] = ($count == 0 ? false : true);

                # default policy
                $count = 0;
                $count = $sensei->database->querySingle("select count(*) from policy_app_categories where action='reject' and policy_id=" . $row['id']);
                //}
                $row['app'] = ($count == 0 ? false : true);
                $count = 0;
                $count = $sensei->database->querySingle("select count(*) from policy_web_categories p,web_categories c where c.id=p.web_categories_id and  c.is_security_category=0 and p.action='reject' and policy_id=" . $row['id']);
                if ($count == 0) {
                    $count = $sensei->database->querySingle("select count(*) from policy_custom_web_categories p, custom_web_categories c where p.custom_web_categories_id=c.id and c.action='reject' and p.policy_id=" . $row['id']);
                }
                // }
                $row['web'] = ($count == 0 ? false : true);

                $row['tls'] = false;
                array_push($response, $row);
            }
            return $response;
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception:: Cant read policy from sqlite db.' . $e->getMessage());
            return [];
        }
    }

    /*
     * Main action
     */

    public function indexAction()
    {
        $sensei = new Sensei();
        if ($this->request->getMethod() == 'GET') {
            return $this->getPolicy($sensei);
            /* save policy and default sub values. app and web categories */
        } elseif ($this->request->getMethod() == 'POST') {
            try {

                $sensei->logger('Policy saving');
                $policyID = $this->request->getPost('policyId');
                $action = $this->request->getPost('action');
                // create policy record.
                if ($action == 'U') {
                    $stmt = $sensei->database->prepare('UPDATE policies SET ' .
                        'status=:status,`name`=:p_name, usernames=:usernames, groups=:groups, interfaces=:interfaces,' .
                        'vlans=:vlans, networks=:networks, macaddresses=:macaddresses,directions=:directions, status=:status,decision_is_block=:decision_is_block where id=:id');
                    $stmt->bindValue(':id', $policyID);
                    $parameters[':id'] = $policyID;
                } else {
                    $policyID = time();
                    $parameters[':id'] = -1;
                    $stmt = $sensei->database->prepare('INSERT INTO policies (id,uuid,status,`name`, usernames, groups, interfaces, vlans, networks,macaddresses, directions,decision_is_block,sort_number,cloud_id)' .
                        ' VALUES(:id,:uuid,:status,:p_name, :usernames, :groups, :interfaces, :vlans, :networks,:macaddresses,:directions,:decision_is_block,:sort_number,:cloud_id)');
                    $stmt->bindValue(':uuid', $sensei->generateUUID());
                    $stmt->bindValue(':id', $policyID);
                    $stmt->bindValue(':cloud_id', '');
                    $stmt->bindValue(':sort_number', random_int(-200, 0));
                }
                $sensei->logger('preparing policy data');
                $interfaces = [];
                foreach ($this->request->getPost('interfaces') as $interface) {
                    if (isset($interface['action']) && $interface['action'] == 'true') {
                        if (!empty($interface['interface']) && $interface['interface'] != 'null')
                            $interfaces[] = $interface['interface'];
                    }
                }
                $vlans = [];
                $post_vlans = $this->request->getPost('vlans', null, []);
                foreach ($post_vlans as $vlan) {
                    if ($vlan['status'] == 'true') {
                        if (!empty($vlan['network']) && $vlan['network'] != 'null')
                            $vlans[] = $vlan['network'];
                    }
                }

                $networks = [];
                $post_networks = $this->request->getPost('networks', null, []);
                foreach ($post_networks as $net) {
                    if ($net['status'] == 'true') {
                        if (!empty($net['network']) && $net['network'] != 'null')
                            $networks[] = $net['network'];
                    }
                }

                $macaddresses = [];
                $post_macaddress = $this->request->getPost('macaddresses', null, []);
                foreach ($post_macaddress as $net) {
                    if ($net['status'] == 'true') {
                        if (!empty($net['macaddresses']) && $net['macaddresses'] != 'null')
                            $macaddresses[] = $net['macaddresses'];
                    }
                }

                $stmt->bindValue(':status', ($this->request->getPost('status') == 'true' ? 1 : 0));
                $stmt->bindValue(':decision_is_block', ($this->request->getPost('decision_is_block', null, 'false') == 'true' ? 1 : 0));

                $parameters[':usernames'] = implode(',', $this->request->getPost('users', null, []));
                $parameters[':groups'] = implode(',', $this->request->getPost('groups', null, []));
                $parameters[':interfaces'] = implode(',', $interfaces);
                $parameters[':vlans'] = implode(',', $vlans);
                $parameters[':networks'] = implode(',', $networks);
                $parameters[':macaddresses'] = implode(',', $macaddresses);
                $parameters[':directions'] = $this->request->getPost('directions_str', null, 'inout');
                if (!$this->policyEnableCheck($sensei) && $action == 'I') {
                    return ['error' => 'With this subscription, you can have up to ' . $this->policyRestrictNumber . ' policies', 'title' => 'Warning'];
                }

                $schedules = $this->request->getPost('schedule', null, []);
                if (($check = $this->policyExists($sensei, $parameters, $schedules))[0] == true) {
                    return ['error' => 'I\'ve found an existing policy (Policy ' . $check[1] . ') which is exactly the same with the policy you\'re trying to create.
Please specify a different set of criteria for this policy.', 'title' => 'Warning: Duplicate policy'];
                }

                $stmt->bindValue(':p_name', $this->request->getPost('name'));
                $stmt->bindValue(':usernames', implode(',', $this->request->getPost('users', null, [])));
                $stmt->bindValue(':groups', implode(',', $this->request->getPost('groups', null, [])));
                $stmt->bindValue(':interfaces', implode(',', $interfaces));
                $stmt->bindValue(':vlans', implode(',', $vlans));
                $stmt->bindValue(':networks', implode(',', $networks));
                $stmt->bindValue(':macaddresses', implode(',', $macaddresses));
                $stmt->bindValue(':directions', $this->request->getPost('directions_str', null, 'inout'));
                try {
                    $checkRow = $sensei->database->querySingle("select id,name,delete_status from policies where name='" . $this->request->getPost('name') . "'", true);
                    if (!empty($checkRow['id']) && $checkRow['delete_status'] == 0) {
                        if (strtolower($checkRow['name']) == strtolower($this->request->getPost('name')) && $checkRow['id'] != $policyID) {
                            return ['error' => $this->request->getPost('name') . ' policy name is already exists'];
                        }
                    }
                    $sensei->database->enableExceptions(true);
                    $stmt->execute();
                    $sensei->logger('inserted policy data');
                    //return ['error' => $sensei->database->lastErrorMsg()];
                } catch (Error $e) {
                    // $this->flash->error($e->getMessage());
                    $sensei->logger('when insert policy data error occured : ' . $e->getMessage());
                    return ['error' => $e->getMessage()];
                }

                //del schedules for policy id
                $stmt = $sensei->database->prepare('DELETE FROM policies_schedules where policy_id=:policy_id');
                $stmt->bindValue(':policy_id', $policyID);
                $stmt->execute();

                $stmt = $sensei->database->prepare('INSERT INTO policies_schedules(policy_id,schedule_id) VALUES(:policy_id,:schedule_id)');
                foreach ($schedules as $schedule) {
                    $stmt->bindValue(':policy_id', $policyID);
                    $stmt->bindValue(':schedule_id', $schedule['id']);
                    $stmt->execute();
                }
                $sensei->logger('inserted policy schedule data');

                //del networks for policy id
                $stmt = $sensei->database->prepare('DELETE FROM policies_networks where type=1 and policy_id=:policy_id');
                $stmt->bindValue(':policy_id', $policyID);
                $stmt->execute();

                //insert networks for policy id
                $stmt = $sensei->database->prepare('INSERT INTO policies_networks(policy_id,type,network,desc,status) VALUES(:policy_id,1,:network,:desc,:status)');
                foreach ($post_networks as $net) {
                    $stmt->bindValue(':policy_id', $policyID);
                    $stmt->bindValue(':network', $net['network']);
                    $stmt->bindValue(':desc', empty($net['desc']) ? '' : $net['desc']);
                    $stmt->bindValue(':status', $net['status'] == 'true' ? 1 : 0);
                    $stmt->execute();
                }

                //del macaddress for policy id
                $stmt = $sensei->database->prepare('DELETE FROM policies_macaddresses where policy_id=:policy_id');
                $stmt->bindValue(':policy_id', $policyID);
                $stmt->execute();

                //insert macaddress for policy id
                $stmt = $sensei->database->prepare('INSERT INTO policies_macaddresses(policy_id,macaddresses,desc,status) VALUES(:policy_id,:macaddresses,:desc,:status)');
                foreach ($post_macaddress as $net) {
                    $stmt->bindValue(':policy_id', $policyID);
                    $stmt->bindValue(':macaddresses', $net['macaddresses']);
                    $stmt->bindValue(':desc', empty($net['desc']) ? '' : $net['desc']);
                    $stmt->bindValue(':status', $net['status'] == 'true' ? 1 : 0);
                    $stmt->execute();
                }
                $sensei->logger('inserted policy macaddress data');

                //del vlans for policy id
                $stmt = $sensei->database->prepare('DELETE FROM policies_networks where type=2 and policy_id=:policy_id');
                $stmt->bindValue(':policy_id', $policyID);
                $stmt->execute();

                //insert vlans for policy id
                $stmt = $sensei->database->prepare('INSERT INTO policies_networks(policy_id,type,network,desc,status) VALUES(:policy_id,2,:network,:desc,:status)');
                foreach ($post_vlans as $vlan) {
                    $stmt->bindValue(':policy_id', $policyID);
                    $stmt->bindValue(':network', $vlan['network']);
                    $stmt->bindValue(':desc', empty($vlan['desc']) ? '' : $vlan['desc']);
                    $stmt->bindValue(':status', $vlan['status'] == 'true' ? 1 : 0);
                    $stmt->execute();
                }

                $sensei->logger('inserted policy networks data');
                if ($action == 'U') {
                    $cloud_result = $sensei->sendDataCloud('update', $policyID);
                    return ['policyid' => $policyID, 'status' => 'OK', 'cloud_status' => $cloud_result];
                }

                /* insert web categories
                first get all web categories
                set uuid
                insert policy_web_categories table
                 */

                $stmt = $sensei->database->prepare('SELECT * FROM web_categories');
                $results = $stmt->execute();

                //get all web categories
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_web_categories (policy_id, web_categories_id, uuid, action) VALUES' .
                        '(:policy_id, :web_categories_id, :uuid, :action)');
                    $stmtIn->bindValue(':policy_id', $policyID);
                    $stmtIn->bindValue(':web_categories_id', $row['id']);
                    $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                    $stmtIn->bindValue(':action', 'accept');
                    $stmtIn->execute();
                }

                $sensei->logger('inserted policy web categories');
                /* insert app categories
                first get all app categories
                set uuid
                insert policy_app_categories table
                 */

                $stmt = $sensei->database->prepare('SELECT * FROM applications');
                $results = $stmt->execute();

                //get all app categories
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_app_categories (policy_id, application_id, uuid,action ,writetofile) VALUES' .
                        '(:policy_id, :application_id, :uuid, :action ,:writetofile)');
                    $stmtIn->bindValue(':policy_id', $policyID);
                    $stmtIn->bindValue(':application_id', $row['id']);
                    $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                    $stmtIn->bindValue(':action', 'accept');
                    $stmtIn->bindValue(':writetofile', 'on');
                    $stmtIn->execute();
                }

                $sensei->logger('inserted policy web applications');

                $customApps = $sensei->database->query('SELECT * FROM custom_applications');
                while ($row = $customApps->fetchArray($mode = SQLITE3_ASSOC)) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_app_categories (policy_id, custom_application_id, uuid,action ,writetofile) VALUES' .
                        '(:policy_id, :custom_application_id, :uuid, :action ,:writetofile)');
                    $stmtIn->bindValue(':policy_id', $policyID);
                    $stmtIn->bindValue(':custom_application_id', $row['id']);
                    $stmtIn->bindValue(':uuid', 'custom-' . $sensei->generateUUID());
                    $stmtIn->bindValue(':action', 'accept');
                    $stmtIn->bindValue(':writetofile', 'on');
                    if (!$stmtIn->execute()) {
                        $sensei->logger(__METHOD__ . " SQL Error policy_custom_app_categories: " . $sensei->database->lastErrorMsg());
                    }
                }

                $customList = [['Whitelisted', 'accept'], ['Blacklisted', 'reject']];
                // add one custom web category
                foreach ($customList as $key => $custom) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
                    $stmtIn->bindValue(':name', $custom[0]);
                    $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                    $stmtIn->bindValue(':action', $custom[1]);
                    $stmtIn->execute();
                    $sensei->logger('inserted policy custom web applications');
                    $customWebID = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);
                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id) VALUES(:policy_id,:custom_web_categories_id)');
                    $stmtIn->bindValue(':policy_id', $policyID);
                    $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
                    $stmtIn->execute();
                }
                $sensei->logger('inserted policy custom web applications 2');
                $cloud_result = $sensei->sendDataCloud('update', $policyID);
                return ['policyid' => $policyID, 'status' => 'OK', 'cloud_status' => $cloud_result];
            } catch (\Exception $e) {
                $sensei->logger(__METHOD__ . '::Exception:: Cant write policy from sqlite db.' . $e->getMessage());
                return ['error' => "Can't write policy to database."];
            }
        }
    }
    public function syncpolicyAction()
    {
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policyId');
            $action = $this->request->getPost('action', null, 'update');
            $sensei->logger('Policy will be sync. Id is ' . $policyId);
            $cloud_result = $sensei->sendDataCloud($action, $policyId);
            return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
            $sensei->logger('Policy synced...');
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception:: Cant sync policy to cloud.' . $e->getMessage());
            return ['error' => 'Can\'t sync policy.', 'status' => 'ERR'];
        }
    }

    public function clonepolicyAction()
    {
        try {
            $sensei = new Sensei();
            $sensei->logger('Policy Clone Starting..');
            $pPolicyID = $this->request->getPost('policyId');
            $pName = $this->request->getPost('originName');
            $pPolicyName = $this->request->getPost('name');
            if (empty($pPolicyName)) {
                $pPolicyName = 'Clone of ' . $pName . '_' . time();
            }

            $checkRow = $sensei->database->querySingle("select id,name,delete_status from policies where name='" . $pPolicyName . "'", true);
            if (!empty($checkRow['id']) && $checkRow['delete_status'] == 0) {
                return ['error' => $pPolicyName . ' policy name is already exists'];
            }

            if (!$this->policyEnableCheck($sensei)) {
                return ['error' => 'With this subscription, you can have up to ' . $this->policyRestrictNumber . ' policies', 'title' => 'Warning'];
            }
            $pStmt = $sensei->database->prepare('SELECT * FROM policies where id=:id');
            $pStmt->bindValue(':id', $pPolicyID);
            $result = $pStmt->execute();
            $policyRow = $result->fetchArray($mode = SQLITE3_ASSOC);
            if (empty($policyRow['name'])) {
                return ['error' => "Couldn't found policy"];
            }
            $policyID = time();
            $parameters[':id'] = -1;
            $stmt = $sensei->database->prepare('INSERT INTO policies (id,uuid,status,`name`, usernames, groups, interfaces, vlans, networks, directions,decision_is_block,sort_number)' .
                ' VALUES(:id,:uuid,:status,:p_name, :usernames, :groups, :interfaces, :vlans, :networks, :directions,:decision_is_block,:sort_number)');
            $stmt->bindValue(':uuid', $sensei->generateUUID());
            $stmt->bindValue(':id', $policyID);
            $stmt->bindValue(':sort_number', random_int(-200, 0));
            $stmt->bindValue(':status', $policyRow['status']);
            $stmt->bindValue(':decision_is_block', $policyRow['decision_is_block']);
            $stmt->bindValue(':p_name', $pPolicyName);
            $stmt->bindValue(':usernames', $policyRow['usernames']);
            $stmt->bindValue(':groups', $policyRow['groups']);
            $stmt->bindValue(':interfaces', $policyRow['interfaces']);
            $stmt->bindValue(':vlans', $policyRow['vlans']);
            $stmt->bindValue(':networks', $policyRow['networks']);
            $stmt->bindValue(':directions', $policyRow['directions']);
            try {
                $sensei->database->enableExceptions(true);
                $stmt->execute();
                $sensei->logger('inserted policy data');
                //return ['error' => $sensei->database->lastErrorMsg()];
            } catch (\Exception $e) {
                // $this->flash->error($e->getMessage());
                $sensei->logger('When insert policy data error occured : ' . $e->getMessage());
                return ['error' => 'When insert policy data error occured'];
            }

            //del schedules for policy id
            $stmt = $sensei->database->prepare('DELETE FROM policies_schedules where policy_id=:policy_id');
            $stmt->bindValue(':policy_id', $policyID);
            $stmt->execute();

            $pStmt = $sensei->database->prepare('SELECT * FROM policies_schedules where policy_id=:policy_id');
            $pStmt->bindValue(':policy_id', $pPolicyID);
            $result = $pStmt->execute();
            $schedules = [];
            while ($row = $result->fetchArray($mode = SQLITE3_ASSOC)) {
                $schedules[] = $row['schedule_id'];
            }

            $stmt = $sensei->database->prepare('INSERT INTO policies_schedules(policy_id,schedule_id) VALUES(:policy_id,:schedule_id)');
            foreach ($schedules as $schedule) {
                $stmt->bindValue(':policy_id', $policyID);
                $stmt->bindValue(':schedule_id', $schedule);
                $stmt->execute();
            }
            $sensei->logger('inserted policy schedule data.');

            //del networks for policy id
            $stmt = $sensei->database->prepare('DELETE FROM policies_networks where policy_id=:policy_id');
            $stmt->bindValue(':policy_id', $policyID);
            $stmt->execute();

            $pStmt = $sensei->database->prepare('SELECT * FROM policies_networks where policy_id=:policy_id');
            $pStmt->bindValue(':policy_id', $pPolicyID);
            $result = $pStmt->execute();
            $networks = [];
            while ($row = $result->fetchArray($mode = SQLITE3_ASSOC)) {
                $networks[] = $row;
            }

            //insert networks for policy id
            $stmt = $sensei->database->prepare('INSERT INTO policies_networks(policy_id,type,network,desc,status) VALUES(:policy_id,:type,:network,:desc,:status)');
            foreach ($networks as $net) {
                $stmt->bindValue(':policy_id', $policyID);
                $stmt->bindValue(':type', $net['type']);
                $stmt->bindValue(':network', $net['network']);
                $stmt->bindValue(':desc', empty($net['desc']) ? '' : $net['desc']);
                $stmt->bindValue(':status', $net['status']);
                $stmt->execute();
            }

            $sensei->logger('inserted policy networks data');

            $pStmt = $sensei->database->prepare('SELECT * FROM policy_web_categories where policy_id=:policy_id');
            $pStmt->bindValue(':policy_id', $pPolicyID);
            $results = $pStmt->execute();

            //get all web categories
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $stmtIn = $sensei->database->prepare('INSERT INTO policy_web_categories (policy_id, web_categories_id, uuid, action) VALUES' .
                    '(:policy_id, :web_categories_id, :uuid, :action)');
                $stmtIn->bindValue(':policy_id', $policyID);
                $stmtIn->bindValue(':web_categories_id', $row['web_categories_id']);
                $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                $stmtIn->bindValue(':action', $row['action']);
                $stmtIn->execute();
            }

            $sensei->logger('inserted policy web categories');
            /* insert app categories
            first get all app categories
            set uuid
            insert policy_app_categories table
             */

            $pStmt = $sensei->database->prepare('SELECT * FROM policy_app_categories where policy_id=:policy_id');
            $pStmt->bindValue(':policy_id', $pPolicyID);
            $results = $pStmt->execute();

            //get all app categories
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $stmtIn = $sensei->database->prepare('INSERT INTO policy_app_categories (policy_id, application_id, uuid,action ,writetofile) VALUES' .
                    '(:policy_id, :application_id, :uuid, :action ,:writetofile)');
                $stmtIn->bindValue(':policy_id', $policyID);
                $stmtIn->bindValue(':application_id', $row['application_id']);
                $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                $stmtIn->bindValue(':action', $row['action']);
                $stmtIn->bindValue(':writetofile', $row['writetofile']);
                $stmtIn->execute();
            }

            $sensei->logger('inserted policy app categories');

            $pStmt = $sensei->database->prepare('SELECT * FROM policy_custom_app_categories p where p.policy_id=:policy_id');
            $pStmt->bindValue(':policy_id', $pPolicyID);
            $results = $pStmt->execute();
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_app_categories (policy_id, custom_application_id, uuid,action ,writetofile) VALUES' .
                    '(:policy_id, :custom_application_id, :uuid, :action ,:writetofile)');
                $stmtIn->bindValue(':policy_id', $policyID);
                $stmtIn->bindValue(':custom_application_id', $row['custom_application_id']);
                $stmtIn->bindValue(':uuid', 'custom-' . $sensei->generateUUID());
                $stmtIn->bindValue(':action', $row['action']);
                $stmtIn->bindValue(':writetofile', $row['writetofile']);
                if (!$stmtIn->execute()) {
                    $sensei->logger(__METHOD__ . " SQL Error policy_custom_app_categories: " . $sensei->database->lastErrorMsg());
                }
            }
            $sensei->logger('inserted policy custom applications');

            $pStmt = $sensei->database->prepare('select c.id,c.name,c.action,s.site from policy_custom_web_categories p,custom_web_categories c,custom_web_category_sites s where p.custom_web_categories_id = c.id and s.custom_web_categories_id = c.id and p.policy_id=:policy_id order by c.name');
            $pStmt->bindValue(':policy_id', $pPolicyID);
            $results = $pStmt->execute();
            $cCategory = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                if (empty($cCategory[$row['name']])) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
                    $stmtIn->bindValue(':name', $row['name']);
                    $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                    $stmtIn->bindValue(':action', $row['action']);
                    $stmtIn->execute();
                    $customWebID = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);
                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id) VALUES(:policy_id,:custom_web_categories_id)');
                    $stmtIn->bindValue(':policy_id', $policyID);
                    $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
                    $stmtIn->execute();
                    $cCategory[$row['name']] = true;
                }
                $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(custom_web_categories_id,site,uuid) VALUES(:custom_web_categories_id,:site,:uuid)');
                $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
                $stmtIn->bindValue(':site', $row['site']);
                $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                $stmtIn->execute();
            }
            if (count($cCategory) == 0) {
                $customList = [['Whitelisted', 'accept'], ['Blacklisted', 'reject']];
                // add one custom web category
                foreach ($customList as $key => $custom) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
                    $stmtIn->bindValue(':name', $custom[0]);
                    $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                    $stmtIn->bindValue(':action', $custom[1]);
                    $stmtIn->execute();
                    $sensei->logger('inserted policy custom web applications');
                    $customWebID = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);
                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id) VALUES(:policy_id,:custom_web_categories_id)');
                    $stmtIn->bindValue(':policy_id', $policyID);
                    $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
                    $stmtIn->execute();
                }
            }

            $sensei->logger('inserted policy custom web applications 2');
            $cloud_result = $sensei->sendDataCloud('update', $policyID);
            return ['error' => '', 'policyid' => $policyID, 'status' => 'OK', 'cloud_status' => $cloud_result];
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception:: Cant write policy from sqlite db.' . $e->getMessage());
            return ['error' => 'Can\'t write policy from sqlite db.'];
        }
    }

    public function detailsAction($policyId = null)
    {
        $response = [];
        try {
            $sensei = new Sensei();

            if (is_numeric($policyId)) {
                $response = $sensei->database->querySingle('SELECT * FROM policies WHERE id=' . $policyId, true);
                $stmt = $sensei->database->prepare('select s.* from policies_schedules p,schedules s
                                                 where p.schedule_id=s.id and p.policy_id = :policy_id
                                                    ORDER BY s.name');
                $stmt->bindValue(':policy_id', $policyId);
                $results = $stmt->execute();
                $schedules = [];
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $schedules[] = $row;
                }
                $response['schedule'] = $schedules;

                $stmt = $sensei->database->prepare('select * from policies_networks n
                                                 where n.policy_id = :policy_id
                                                    ORDER BY n.network');
                $stmt->bindValue(':policy_id', $policyId);
                $results = $stmt->execute();
                $networks = ['vlans' => [], 'networks' => []];
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    if ($row['type'] == 1) {
                        $networks['networks'][] = $row;
                    }

                    if ($row['type'] == 2) {
                        $networks['vlans'][] = $row;
                    }
                }
                $stmt = $sensei->database->prepare('select * from policies_macaddresses n
                                                 where n.policy_id = :policy_id
                                                    ORDER BY n.macaddresses');
                $stmt->bindValue(':policy_id', $policyId);
                $results = $stmt->execute();
                $macaddresses = [];
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $macaddresses[] = $row;
                }
                $response['macaddresses'] = $macaddresses;
                $response['vlans'] = $networks['vlans'];
                if (count($networks['networks']) == 0 && isset($response['networks']) && $response['networks'] != '') {
                    $networks_split = explode(',', $response['networks']);
                    foreach ($networks_split as $net) {
                        $networks['networks'][] = ['ip' => $net, 'status' => 1];
                    }
                }
                $response['networks'] = $networks['networks'];
                $response['users'] = empty($response['usernames']) ? [] : explode(',', $response['usernames']);
                $response['groups'] = empty($response['groups']) ? [] : explode(',', $response['groups']);
            }
            return $response;
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . '::Exception:: Cant write policy from sqlite db.' . $e->getMessage());
            return $response;
        }
    }

    public function setStatusAction()
    {
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policy_id');
            if ($policyId == 0 or empty($policyId)) {
                return 'ERROR';
            }

            //delete policies
            $stmt = $sensei->database->prepare('update policies set status=:status where id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $stmt->bindValue(':status', $this->request->getPost('status'));
            $stmt->execute();
            $cloud_result = $sensei->sendDataCloud('update', $policyId);
            return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage(), 'status' => 'ERR'];
        }
    }

    public function blockPolicyAction()
    {
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policy_id');
            if ($policyId == 0 or empty($policyId)) {
                return 'ERROR';
            }

            //delete policies
            $stmt = $sensei->database->prepare('update policies set decision_is_block=:decision_is_block where id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $stmt->bindValue(':decision_is_block', $this->request->getPost('block'));
            $stmt->execute();
            $cloud_result = $sensei->sendDataCloud('update', $policyId);
            return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage(), 'status' => 'ERR'];
        }
    }

    public function delPolicyAction()
    {
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policy_id');
            if ($policyId == 0 or empty($policyId)) {
                return 'ERROR';
            }

            //delete policies
            $stmt = $sensei->database->prepare('update policies set delete_status=1 where id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $stmt->execute();
            //delete web categories
            $stmt = $sensei->database->prepare('delete from policy_web_categories where policy_id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $stmt->execute();
            //delete app categories
            $stmt = $sensei->database->prepare('delete from policy_app_categories where policy_id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $stmt->execute();
            //delete custom web categories
            $stmt = $sensei->database->prepare('select * from policy_custom_web_categories where policy_id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $results = $stmt->execute();
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $stmtin = $sensei->database->prepare('select * from custom_web_categories where id=:id');
                $stmtin->bindValue(':id', $row['custom_web_categories_id']);
                $resultsin = $stmtin->execute();
                while ($rowin = $resultsin->fetchArray($mode = SQLITE3_ASSOC)) {
                    $stmt_del = $sensei->database->prepare('delete from custom_web_category_sites where custom_web_categories_id=:id');
                    $stmt_del->bindValue(':id', $rowin['id']);
                    $stmt_del->execute();
                }
                $stmt = $sensei->database->prepare('delete from custom_web_categories where id=:id');
                $stmt->bindValue(':id', $row['custom_web_categories_id']);
                $stmt->execute();
            }

            $stmt = $sensei->database->prepare('delete from policy_custom_web_categories where policy_id=:policy_id');
            $stmt->bindValue(':policy_id', $policyId);
            $stmt->execute();
            $sensei->saveChanges();
            $cloud_result = $sensei->sendDataCloud('delete', $policyId);
            return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage(), 'status' => 'ERR'];
        }
    }

    public function webCategoriesAction()
    {
        $response = [];
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'GET') {
                $policyId = $this->request->getQuery('policy');
                $isSecurity = $this->request->getQuery('is_security');
                $deployment = $sensei->database->querySingle('select mode from interface_settings limit 1', false);
                //            if ($policyId != 0) {
                $stmt = $sensei->database->prepare('select w.*,c.* from policy_web_categories w,web_categories c
                                                    where w.web_categories_id = c.id and w.policy_id = :policy_id
                                                    and c.is_security_category = :is_security_category ORDER BY c.name');
                $stmt->bindValue(':policy_id', $policyId);
                $stmt->bindValue(':is_security_category', $isSecurity == 'true' ? 1 : 0);
                $results = $stmt->execute();
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $row['sort'] = isset(Sensei::security_order[$row['name']]) ? Sensei::security_order[$row['name']] : 0;
                    $row['premium'] = in_array($row['name'], Sensei::security_premium);
                    $row['c_status'] = in_array($row['name'], Sensei::security_coming_premium) ? 'true' : 'false';
                    $row['is_new'] = in_array($row['name'], Sensei::security_new_premium) ? true : false;
                    array_push($response, $row);
                }
                usort($response, function ($a, $b) {
                    return ($a['sort'] > $b['sort'] ? 1 : -1);
                });
                // return $response;
                return ['webcategories' => $response, 'mode' => $deployment];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function appCategoriesAction()
    {
        $response = [];
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'GET') {
                $policyId = $this->request->getQuery('policy');
                $isSecurity = $this->request->getQuery('is_security');
                $stmt = $sensei->database->prepare('select w.*,c.* from policy_web_categories w,web_categories c
                                                 where w.web_categories_id = c.id and w.policy_id = :policy_id
                                                    and c.is_security_category = :is_security_category ORDER BY c.name');
                $stmt->bindValue(':policy_id', $policyId);
                $stmt->bindValue(':is_security_category', $isSecurity == 'true' ? 1 : 0);
                $results = $stmt->execute();
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    array_push($response, $row);
                }
                return $response;
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function webAction()
    {
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'GET') {
                //default policies
                $policyId = $this->request->getQuery('policy');
                $deployment = $sensei->database->querySingle('select mode from interface_settings limit 1', false);
                $webCategories = [];
                $stmt = $sensei->database->prepare('select w.uuid,c.name,w.action,w.policy_id,p.webcategory_type from policy_web_categories w,web_categories c,policies p
                                                          where p.id=w.policy_id and w.web_categories_id = c.id and c.is_security_category=0 and w.policy_id =:policy_id order by c.name');
                $stmt->bindValue(':policy_id', $policyId);
                $results = $stmt->execute();
                $webcategoriesType = 'permissive';
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $webcategoriesType = $row['webcategory_type'] ?? 'permissive';
                    array_push($webCategories, [
                        'uuid' => $row['uuid'],
                        'name' => $row['name'],
                        'action' => $row['action'],
                        'policyid' => $row['policy_id'],
                        'category_type' => Sensei::getWebCatType($row['name']),
                    ]);
                }
                header('Content-type:application/json;charset=utf-8');
                echo json_encode(['webCategories' => $webCategories, 'webcategoriesType' => $webcategoriesType, 'mode' => $deployment]);

                // return ['webCategories' => $webCategories, 'webcategoriesType' => $webcategoriesType];
            } elseif ($this->request->getMethod() == 'POST') {
                try {
                    $policyId = $this->request->getPost('policy', null, -1);
                    $filters = $this->request->getPost('filters', null, []);
                    $webCategoryType = $this->request->getPost('webcategorytype');

                    if ($policyId == -1 || count($filters) == 0) {
                        return 'ERR';
                    }

                    $stmtIn = $sensei->database->prepare('Update policies set webcategory_type=:webcategorytype where id=:id');
                    $stmtIn->bindValue(':webcategorytype', $webCategoryType);
                    $stmtIn->bindValue(':id', $policyId);
                    $stmtIn->execute();

                    $stmtIn = $sensei->database->prepare('Update policy_web_categories set action=:action where uuid=:uuid');
                    foreach ($filters as $filter) {
                        $stmtIn->bindValue(':action', $filter['action']);
                        $stmtIn->bindValue(':uuid', $filter['uuid']);
                        $stmtIn->execute();
                    }
                    $sensei->saveChanges();
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
                } catch (\Exception $e) {
                    $sensei->logger(__METHOD__ . ':' . $e->getMessage());
                    return ['error' => $e->getMessage(), 'status' => 'ERR'];
                }
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage(), 'status' => 'ERR'];
        }
    }

    public function web20Action()
    {
        $sensei = new Sensei();
        if ($this->request->getMethod() == 'GET') {
            //default policies
            $policyId = $this->request->getQuery('policy');
            $web20Apps = [];
            // not default policy id;

            $results_cat = $sensei->database->query('select * from web_20_categories order by name');
            while ($row_web = $results_cat->fetchArray($mode = SQLITE3_ASSOC)) {
                $web20Apps[$row_web['name']] = [
                    'name' => $row_web['name'],
                    'apps' => [],
                ];
                $apps = [];
                $results = $sensei->database->query('select p.application_id,p.action,p.uuid,p.id,p.policy_id,a.name,w.name as web_20,a.web_20_category_id from policy_app_categories p, applications a,web_20_categories w
                                                        where p.application_id=a.id and w.id = a.web_20_category_id and w.id=' . $row_web['id'] . ' and p.policy_id=' . $policyId . ' order by a.name');
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $apps[] = [
                        'uuid' => $row['uuid'],
                        'name' => $row['name'],
                        'action' => $row['action'],
                        'policy_id' => $row['policy_id'],
                    ];
                }
                $web20Apps[$row_web['name']] = [
                    'name' => $row_web['name'],
                    'apps' => $apps,
                ];
            }
            return array_values($web20Apps);
        } elseif ($this->request->getMethod() == 'POST') {
            $policyId = $this->request->getQuery('policy');
            $apps = $this->request->getPost('apps');
            $stmtIn = $sensei->database->prepare('Update policy_app_categories set action=:action where uuid=:uuid');
            foreach ($apps as $app) {
                $stmtIn->bindValue(':action', $app['action']);
                $stmtIn->bindValue(':uuid', $app['uuid']);
                $stmtIn->execute();
            }
            $sensei->saveChanges();
            return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
        }
    }

    public function customWebAction($uuid = null)
    {
        $sensei = new Sensei();
        try {
            if ($this->request->getMethod() == 'PUT') {
                $category = $this->request->getPut('category');
                $policyId = $this->request->getPut('policy');
                $sendcategory = $this->request->getPut('sendcategory', null, '');
                $policyId = $policyId ?? 0;
                // not default policy
                $stmt = $sensei->database->prepare('select count(*) as total from web_categories where lower(name)=lower(:name)');
                $stmt->bindValue(':name', $category);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if ($row['total'] > 0) {
                    return ['success' => false, 'message' => 'Category name is already exists.'];
                }

                $stmt = $sensei->database->prepare('select count(*) as total from policy_custom_web_categories p, custom_web_categories c
                                                    where p.custom_web_categories_id = c.id and p.policy_id=:policy_id and lower(c.name)=lower(:name)');
                $stmt->bindValue(':policy_id', $policyId);
                $stmt->bindValue(':name', $category);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if ($row['total'] > 0) {
                    return ['success' => false, 'message' => 'Custom Category name is already exists.'];
                }

                $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
                $uuid = $sensei->generateUUID();
                $stmtIn->bindValue(':name', $category);
                $stmtIn->bindValue(':uuid', $uuid);
                $stmtIn->bindValue(':action', 'accept');
                $stmtIn->execute();
                $customWebID = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);

                //add custom web category to policies
                $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id)
                                                  VALUES(:policy_id,:custom_web_categories_id)');
                $stmtIn->bindValue(':policy_id', $policyId);
                $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
                $stmtIn->execute();
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
                return ['success' => true, 'message' => '', 'uuid' => $uuid];

                // get list
            } elseif ($this->request->getMethod() == 'GET') {
                $policyId = $this->request->getQuery('policy');
                $categories = [];
                $stmt = $sensei->database->prepare('select * from policy_custom_web_categories p, custom_web_categories c
                                                    where p.custom_web_categories_id = c.id and p.policy_id=:policy_id order by lower(name)');
                $stmt->bindValue(':policy_id', $policyId);
                $results = $stmt->execute();
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    array_push($categories, [
                        'uuid' => $row['uuid'],
                        'name' => $row['name'],
                        'action' => $row['action'],
                        'policyid' => $row['policy_id'],
                    ]);
                }
                return $categories;

                // post save
            } elseif ($this->request->getMethod() == 'POST') {
                if ($this->request->hasPost('filters')) {
                    $policyId = $this->request->getPost('policy');
                    $filters = $this->request->getPost('filters');
                    // default system
                    $stmtIn = $sensei->database->prepare('Update custom_web_categories set action=:action where uuid=:uuid');
                    foreach ($filters as $filter) {
                        $stmtIn->bindValue(':action', $filter['action']);
                        $stmtIn->bindValue(':uuid', $filter['uuid']);
                        if (!$stmtIn->execute()) {
                            $sensei->logger(__METHOD__ . '-> SQL Error ->' . $sensei->database->lastErrorMsg());
                            return ['success' => false, 'message' => 'Error Occured, please try again'];
                        }
                    }
                    $sensei->saveChanges();
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    return ['success' => true, 'message' => '', 'cloud_status' => $cloud_result];
                }
                return 'OK';
            } elseif ($this->request->getMethod() == 'DELETE') {
                $policyId = $this->request->getQuery('policy');
                $uuid = $this->request->getQuery('uuid');
                $customID = $sensei->database->querySingle('select id from custom_web_categories where uuid="' . $uuid . '"', false);
                if (!empty($customID)) {
                    $stmt = $sensei->database->prepare('delete from custom_web_category_sites where custom_web_categories_id=:id');
                    $stmt->bindValue(':id', $customID);
                    $stmt->execute();

                    $stmt = $sensei->database->prepare('delete from custom_web_categories where uuid=:uuid');
                    $stmt->bindValue(':uuid', $uuid);
                    $stmt->execute();
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    return ['success' => true, 'message' => '', 'cloud_status' => $cloud_result];
                }
                return 'ERROR';
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ':' . $e->getMessage());
            return ['success' => false, 'message' => 'Error Occured'];
        }
    }

    public function sendCategorizationData($data, $sendcategory = '')
    {
        try {
            $sensei = new Sensei();
            if ($sendcategory == '') {
                $sendcategory = (string) $sensei->getNodeByReference('general.sendcategory');
            }

            if ($sendcategory == 'false') {
                return true;
            }

            //if (empty($data['email']))
            //    $data['email'] = (string) $sensei->getNodeByReference('general.clientemail');
            $sensei->sendJson($data, 'https://health.sunnyvalley.io/new_categorization_sensei.php');
            return 'OK';
        } catch (\Exception $e) {
            $sensei = new Sensei();
            $sensei->logger(__METHOD__ . ':' . $e->getMessage());
            return 'ERR';
        }
    }

    public function blockWhiteListAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();

            if ($this->request->getMethod() == 'GET') {
                try {
                    $policyId = $this->request->getQuery('policy', null, 0);
                    $rules = [];
                    $categories = [];
                    $stmt = $sensei->database->prepare("select c.uuid,c.name from custom_web_categories c, policy_custom_web_categories p where c.id = p.custom_web_categories_id and c.name in ('Whitelisted','Blacklisted') and p.policy_id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    $category = [];
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $category[$row['name']] = $row['uuid'];
                        array_push($categories, [
                            'category' => $row['name'],
                            'category_uuid' => $row['uuid'],
                        ]);
                    }

                    $stmt = $sensei->database->prepare("select id,site,uuid,action,policy_id from global_sites order by action");
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        array_push($rules, [
                            'category' => $row['action'] == 'accept' ? 'Whitelisted' : 'Blacklisted',
                            'category_uuid' => $category[$row['action'] == 'accept' ? 'Whitelisted' : 'Blacklisted'],
                            'uuid' => $row['uuid'],
                            'site' => $row['site'],
                            'is_global' => 1,
                            'policy_id' => $row['policy_id'],
                        ]);
                    }

                    $stmt = $sensei->database->prepare("select c.uuid as cuuid,c.name,s.site,c.action,s.uuid as suuid,s.is_global from custom_web_categories c,custom_web_category_sites s,policy_custom_web_categories p
                                                        where c.id = s.custom_web_categories_id and p.custom_web_categories_id=c.id and c.name in ('Whitelisted','Blacklisted')
                                                        and p.policy_id=:policy_id
                                                        order by s.site");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        array_push($rules, [
                            'category' => $row['name'],
                            'category_uuid' => $row['cuuid'],
                            'uuid' => $row['suuid'],
                            'site' => $row['site'],
                            'is_global' => $row['is_global'],
                            'policy_id' => $policyId,
                        ]);
                    }
                    $stmt = $sensei->database->prepare("select * from policies where id=:policy_id");
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    $isCentralized = 0;
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $isCentralized = $row['is_centralized'];
                    }

                    return [
                        'sites' => $rules,
                        'categories' => $categories,
                        'isCentralized' => $isCentralized,
                    ];
                } catch (\Exception $e) {
                    $sensei->logger(__METHOD__ . ":" . $e->getMessage());
                    return [
                        'sites' => $rules,
                        'categories' => $categories,
                    ];
                }
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ':' . $e->getMessage());
            return 'ERROR';
        }
    }
    public function deleteExclusionAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            if ($this->request->getMethod() == 'POST') {
                $uuid = $this->request->getPost('uuid', null, 0);
                $sensei->logger($uuid . ': sites of custom_web_categories will delete');
                $stmt = $sensei->database->prepare('delete from custom_web_category_sites where custom_web_categories_id in (select id from custom_web_categories where uuid=:uuid)');
                $stmt->bindValue(':uuid', $uuid);
                if (!$stmt->execute()) {
                    $this->sensei->logger(__METHOD__ . ":" . $sensei->database->lastErrorMsg());
                }
                $backend->configdRun('sensei policy reload');
                $policyId = $this->request->getPost('policy');
                $cloud_result = $sensei->sendDataCloud('update', $policyId);

                return ['error' => false, 'msg' => '', 'cloud_result' => $cloud_result];
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ':' . $e->getMessage());
            return ['error' => true, 'msg' => $e->getMessage()];
        }
    }

    public function customWebRuleAction($uuid = null)
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            if ($this->request->getMethod() == 'POST') {
                $policyId = $this->request->getPost('policy', null, 0);
                $site = $this->request->getPost('site', null, '');
                if (empty($site)) {
                    return ['error' => 'Domain must be fill', 'status' => 'ERR'];
                }

                $isGlobal = $this->request->getPost('isGlobal');
                $stmt = $sensei->database->prepare('delete from custom_web_category_sites where uuid=:uuid');
                $stmt->bindValue(':uuid', $site['uuid']);
                if (!$stmt->execute()) {
                    $this->sensei->logger(__METHOD__ . ":" . $sensei->database->lastErrorMsg());
                }
                if ($isGlobal == 'true') {
                    # $stmt = $sensei->database->prepare('delete from custom_web_category_sites where is_global=1 and site=:site');
                    $stmt = $sensei->database->prepare('delete from global_sites where site=:site');
                    $stmt->bindValue(':site', $site['site']);
                    if (!$stmt->execute()) {
                        $this->sensei->logger(__METHOD__ . ":" . $sensei->database->lastErrorMsg());
                    }
                }
                $backend->configdRun('sensei policy reload');
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
                return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
            } elseif ($this->request->getMethod() == 'GET') {
                try {
                    $policyId = $this->request->getQuery('policy', null, 0);
                    $uuid = $this->request->getQuery('uuid', null, '');
                    $rules = [];
                    $name = '';
                    $stmt = $sensei->database->prepare('select c.name,s.site,c.action,s.uuid,s.is_global from custom_web_categories c,custom_web_category_sites s
                                                       where c.id = s.custom_web_categories_id and c.uuid=:uuid order by s.site');
                    $stmt->bindValue(':uuid', $uuid);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        array_push($rules, [
                            'uuid' => $row['uuid'],
                            'site' => $row['site'],
                            'is_global' => $row['is_global'],
                        ]);
                        $name = $row['name'];
                    }

                    $stmt = $sensei->database->prepare("select id,site,uuid,action from global_sites order by action");
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        array_push($rules, [
                            'uuid' => $row['uuid'],
                            'site' => $row['site'],
                            'is_global' => 1,
                        ]);
                    }

                    return [
                        'name' => (string) $name,
                        'sites' => $rules,
                    ];
                } catch (\Exception $e) {
                    $sensei->logger(__METHOD__ . ":" . $e->getMessage());
                    return [
                        'name' => (string) $name,
                        'sites' => $rules,
                    ];
                }
            } elseif ($this->request->getMethod() == 'PUT') {
                try {
                    $policyId = $this->request->getPut('policy', null, 0);
                    $rule = $this->request->getPut('rule', null, '');
                    if (empty($rule)) {
                        return ['error' => 'Domain must be fill', 'status' => 'ERR'];
                    }

                    $uuid = $this->request->getPut('uuid');
                    $category_type = $this->request->getPut('type', null, 'domain');
                    $sendcategory = $this->request->getPut('sendcategory', null, '');
                    $categoryId = $sensei->database->querySingle('select id,name,action from custom_web_categories where uuid="' . $uuid . '"', true);
                    if (!empty($categoryId['id'])) {
                        $total = $sensei->database->querySingle(sprintf('select count(*) as total from custom_web_category_sites where custom_web_categories_id="%d" and site="%s"', $categoryId['id'], $rule), false);
                        if ($total > 0) {
                            return ['error' => "Entry already exists in Custom $category_type", 'status' => 'ERR'];
                        }

                        $total = $sensei->database->querySingle(sprintf('select count(*) as total from global_sites where site="%s"', $rule), false);
                        if ($total > 0) {
                            return ['error' => "Entry already exists in Global $category_type.", 'status' => 'ERR'];
                        }

                        $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(category_type,custom_web_categories_id,site,uuid) VALUES(:category_type,:custom_web_categories_id,:site,:uuid)');
                        $uuid_site = $sensei->generateUUID();
                        $stmtIn->bindValue(':category_type', $category_type);
                        $stmtIn->bindValue(':custom_web_categories_id', $categoryId['id']);
                        $stmtIn->bindValue(':site', $rule);
                        $stmtIn->bindValue(':uuid', $uuid_site);
                        $stmtIn->execute();
                        $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => $categoryId['name'], 'site' => $rule]], $sendcategory);
                        $backend->configdRun('sensei policy reload');
                        $cloud_result = $sensei->sendDataCloud('update', $policyId);
                        return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
                    }
                } catch (\Exception $e) {
                    $sensei->logger(__METHOD__ . ":" . $e->getMessage());
                    return ['error' => $e->getMessage(), 'status' => 'ERR'];
                }
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ':' . $e->getMessage());
            return ['error' => $e->getMessage(), 'status' => 'ERR'];
        }
    }

    public function appsAction()
    {
        $sensei = new Sensei();
        $categories = [];
        if ($this->request->getMethod() == 'GET') {
            try {
                $policyId = $this->request->getQuery('policy', null, 0);
                // not defult policy system policy
                $deployment = $sensei->database->querySingle('select mode from interface_settings limit 1', false);
                $applications_last_id = $sensei->database->querySingle('select id from applications_last_id', false);
                $results_cat = $sensei->database->query('select * from application_categories order by name');
                while ($row_cat = $results_cat->fetchArray($mode = SQLITE3_ASSOC)) {
                    $categories[$row_cat['name']] = [
                        'name' => $row_cat['name'],
                        'apps' => [],
                    ];
                    $apps = [];
                    $stmt = $sensei->database->prepare('select a.id as aid,p.application_id,p.action,p.uuid,p.id,p.policy_id,a.name,c.name as category,a.web_20_category_id,a.description from
                                                     policy_app_categories p, applications a,application_categories c
                                                     where p.application_id=a.id and c.id = a.application_category_id and p.policy_id=:policy_id and c.id=:cat_id order by a.name');
                    $stmt->bindValue(':cat_id', $row_cat['id']);
                    $stmt->bindValue(':policy_id', $policyId);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {

                        // if (empty($row['web_20_category_id'])) {
                        $apps[] = [
                            'application_id' => $row['aid'],
                            'uuid' => $row['uuid'],
                            'name' => $row['name'],
                            //     'web20' => empty($row['web_20_category_id']) ? 'no' : 'yes',
                            'web20' => 'no',
                            'description' => $row['description'],
                            'action' => $row['action'],
                            'policy_id' => $row['policy_id'],
                        ];
                        //}
                    }
                    $stmtIn = $sensei->database->prepare('select * from policy_custom_app_categories p, custom_applications c
                                                    where p.custom_application_id=c.id and p.policy_id=:policy_id  and c.application_category_id=:cat_id order by c.name');
                    $stmtIn->bindValue(':cat_id', $row_cat['id']);
                    $stmtIn->bindValue(':policy_id', $policyId);
                    $results = $stmtIn->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        $apps[] = [
                            'uuid' => $row['uuid'],
                            'name' => $row['name'] . ' (Custom)',
                            'web20' => empty($row['web_20_category_id']) ? 'no' : 'yes',
                            'description' => $row['description'],
                            'action' => $row['action'],
                            'policy_id' => $row['policy_id'],
                        ];
                    }
                    $categories[$row_cat['name']] = [
                        'name' => $row_cat['name'],
                        'apps' => $apps,
                    ];
                }
                header('Content-type:application/json;charset=utf-8');
                echo json_encode(['apps' => array_values($categories), 'mode' => $deployment, 'applications_last_id' => empty($applications_last_id) ? 0 : $applications_last_id]);

                // return array_values($categories);
            } catch (Exception $e) {
                $sensei->logger(__METHOD__ . ' Exeption : ' . $e->getMessage());
                return array_values($categories);
            }
        } elseif ($this->request->getMethod() == 'POST') {
            try {
                $sensei->logger('Apps Save Starting');
                $apps = $this->request->getPost('apps', null, []);
                $capps = $this->request->getPost('capps', null, []);
                $update = $this->request->getPost('update', null, 'true');
                $policyId = $this->request->getPost('policy', null, 0);

                if ($update == 'true') {
                    $sensei->logger('Policy Apps Update as Accepting....' . var_export($update, true));
                    $stmtUp = $sensei->database->prepare('Update policy_app_categories set action=:action where policy_id=:policy_id');
                    $stmtUp->bindValue(':action', 'accept');
                    $stmtUp->bindValue(':policy_id', $policyId);
                    $stmtUp->execute();
                    $sensei->logger('Policy Apps Update as Accepted....');
                }
                $sensei->logger('Policy Apps will be Update as Reject');
                if (count($apps) > 0) {
                    $stmtIn = $sensei->database->prepare('Update policy_app_categories set action=:action where uuid=:uuid');
                    foreach ($apps as $app) {
                        $stmtIn->bindValue(':action', $app['action']);
                        $stmtIn->bindValue(':uuid', $app['uuid']);
                        $stmtIn->execute();
                    }
                }
                $stmtC = $sensei->database->prepare('Update policy_custom_app_categories set action=:action where uuid=:uuid');
                foreach ($capps as $app) {
                    $stmtC->bindValue(':action', $app['action']);
                    $stmtC->bindValue(':uuid', $app['uuid']);
                    $stmtC->execute();
                }
                $sensei->saveChanges();
                $sensei->logger('Policy Apps Updated');
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
                return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
            } catch (Exception $e) {
                $sensei->logger(__METHOD__ . ' Exeption : ' . $e->getMessage());
                return ['error' => $e->getMessage(), 'status' => 'ERR'];
            }
        }
    }

    public function securityAction()
    {
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'POST') {
                $rules = $this->request->getPost('rules');
                $policyId = $this->request->getPost('policy');
                $stmtIn = $sensei->database->prepare('Update policy_web_categories set action=:action where uuid=:uuid');
                foreach ($rules as $rule) {
                    $stmtIn->bindValue(':action', $rule['action']);
                    $stmtIn->bindValue(':uuid', $rule['uuid']);
                    $stmtIn->execute();
                }
                $sensei->saveChanges();
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
                $sensei->logger(var_export($cloud_result, true));
                return ['error' => '', 'status' => 'OK', 'cloud_status' => $cloud_result];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage(), 'status' => 'ERR'];
        }
    }

    public function getScheduleOfPolicyAction()
    {
        $schedules = [];
        try {
            $sensei = new Sensei();
            $policy_id = $this->request->getPost('policy_id');
            $results = $sensei->database->query("select s.* from schedules s,policies_schedules p where  p.schedule_id=s.id and p.policy_id={$policy_id} order by s.name");
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $schedules[] = $row;
            }
            return $schedules;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $schedules;
        }
    }

    public function getScheduleAction()
    {
        $schedules = [];
        try {
            $sensei = new Sensei();
            $schedule_id = $this->request->getPost('schedule_id');
            if (empty($schedule_id)) {
                $results = $sensei->database->query('select * from schedules order by name');
            } else {
                $results = $sensei->database->query("select * from schedules where id={$schedule_id} order by name");
            }

            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $schedules[] = $row;
            }
            return $schedules;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $schedules;
        }
    }

    public function saveScheduleAction()
    {
        try {
            $sensei = new Sensei();
            $errors = [];
            $start_t = $this->request->getPost('start_t', null, '00');
            if ($start_t == 0) {
                return ['result' => 'ERR', 'error' => ['Start Time parameters must be not null']];
            }

            $stop_t = $this->request->getPost('stop_t', null, '00');
            if ($stop_t == 0) {
                return ['result' => 'ERR', 'error' => ['Stop Time parameters must be not null']];
            }

            $start_hour = (int) $start_t['hour'] ?? 0;
            if ($start_hour < 10) {
                $start_hour = '0' . $start_hour;
            }

            $start_min = (int) $start_t['min'] ?? 0;
            if ($start_min < 10) {
                $start_min = '0' . $start_min;
            }

            $stop_hour = (int) $stop_t['hour'] ?? 0;
            if ($stop_hour < 10) {
                $stop_hour = '0' . $stop_hour;
            }

            $stop_min = (int) $stop_t['min'] ?? 0;
            if ($stop_min < 10) {
                $stop_min = '0' . $stop_min;
            }

            $id = $this->request->getPost('id');
            $start_time = $start_hour . ':' . $start_min;
            $start_timestamp = (intval($start_hour) * 60 * 60) + (intval($start_min) * 60);
            $stop_time = $stop_hour . ':' . $stop_min;
            $stop_timestamp = (intval($stop_hour) * 60 * 60) + (intval($stop_min) * 60);

            $stmtIn = $sensei->database->prepare('update schedules set name=:name,mon_day=:mon_day,tue_day=:tue_day,wed_day=:wed_day,thu_day=:thu_day,fri_day=:fri_day,sat_day=:sat_day,sun_day=:sun_day,start_time=:start_time,stop_time=:stop_time,start_timestamp=:start_timestamp,stop_timestamp=:stop_timestamp,description=:description' .
                ' where id=:id');
            $stmtIn->bindValue(':id', $id);
            $stmtIn->bindValue(':name', $this->request->getPost('name'));
            $stmtIn->bindValue(':mon_day', $this->request->getPost('mon_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':tue_day', $this->request->getPost('tue_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':wed_day', $this->request->getPost('wed_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':thu_day', $this->request->getPost('thu_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':fri_day', $this->request->getPost('fri_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':sat_day', $this->request->getPost('sat_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':sun_day', $this->request->getPost('sun_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':start_time', $start_time);
            $stmtIn->bindValue(':start_time', $start_time);
            $stmtIn->bindValue(':stop_time', $stop_time);
            $stmtIn->bindValue(':start_timestamp', $start_timestamp);
            $stmtIn->bindValue(':stop_timestamp', $stop_timestamp);
            $description = [];
            //mon{07:00-23:59}, tue{07:00-23:59},
            if ($this->request->getPost('mon_day') == 'true') /**/ {
                $description[] = 'mon{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('tue_day') == 'true') {
                $description[] = 'tue{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('wed_day') == 'true') {
                $description[] = 'wed{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('thu_day') == 'true') {
                $description[] = 'thu{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('fri_day') == 'true') {
                $description[] = 'fri{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('sat_day') == 'true') {
                $description[] = 'sat{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('sun_day') == 'true') {
                $description[] = 'sun{' . $start_time . '-' . $stop_time . '}';
            }

            $stmtIn->bindValue(':description', implode(',', $description));

            if ($stmtIn->execute()) {
                $schedulesId = $sensei->database->querySingle('select seq from sqlite_sequence where name="schedules"', false);
                return ['result' => 'OK', 'id' => $schedulesId];
            } else {
                return ['result' => 'ERR'];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['result' => 'ERR'];
        }
    }

    public function deleteScheduleAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $id = $this->request->getPost('id', null, 0);
            $stmt = $sensei->database->prepare('DELETE FROM policies_schedules where schedule_id=:schedule_id');
            $stmt->bindValue(':schedule_id', $id);
            if (!$stmt->execute()) {
                return ['result' => 'ERR', 'errors' => [$sensei->database->lastErrorMsg()]];
            }
            $stmtIn = $sensei->database->prepare('DELETE FROM schedules where id=:id');
            $stmtIn->bindValue(':id', $id);
            if ($stmtIn->execute()) {
                $backend->configdRun('sensei policy reload');
                return ['result' => 'OK'];
            } else {
                return ['result' => 'ERR', 'errors' => [$sensei->database->lastErrorMsg()]];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['result' => 'ERR', 'errors' => [$e->getMessage()]];
        }
    }

    public function setScheduleAction()
    {
        try {
            $sensei = new Sensei();
            $scheduleName = $sensei->database->querySingle('select name from schedules where name="' . $this->request->getPost('name') . '"', false);
            if (!empty($scheduleName)) {
                return ['result' => 'DOUBLE'];
            }

            $start_time = $this->request->getPost('start_t')['hour'] . ':' . $this->request->getPost('start_t')['min'];
            $start_timestamp = ($this->request->getPost('start_t')['hour'] * 60 * 60) + ($this->request->getPost('start_t')['min'] * 60);
            $stop_time = $this->request->getPost('stop_t')['hour'] . ':' . $this->request->getPost('stop_t')['min'];
            $stop_timestamp = ($this->request->getPost('stop_t')['hour'] * 60 * 60) + ($this->request->getPost('stop_t')['min'] * 60);

            $stmtIn = $sensei->database->prepare('insert into schedules(name,mon_day,tue_day,wed_day,thu_day,fri_day,sat_day,sun_day,start_time,stop_time,start_timestamp,stop_timestamp,description) ' .
                'values(:name,:mon_day,:tue_day,:wed_day,:thu_day,:fri_day,:sat_day,:sun_day,:start_time,:stop_time,:start_timestamp,:stop_timestamp,:description)');
            $stmtIn->bindValue(':name', $this->request->getPost('name'));
            $stmtIn->bindValue(':mon_day', $this->request->getPost('mon_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':tue_day', $this->request->getPost('tue_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':wed_day', $this->request->getPost('wed_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':thu_day', $this->request->getPost('thu_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':fri_day', $this->request->getPost('fri_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':sat_day', $this->request->getPost('sat_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':sun_day', $this->request->getPost('sun_day') == 'true' ? 1 : 0);
            $stmtIn->bindValue(':start_time', $start_time);
            $stmtIn->bindValue(':stop_time', $stop_time);
            $stmtIn->bindValue(':start_timestamp', $start_timestamp);
            $stmtIn->bindValue(':stop_timestamp', $stop_timestamp);
            $description = [];
            //mon{07:00-23:59}, tue{07:00-23:59},
            if ($this->request->getPost('mon_day') == 'true') {
                $description[] = 'mon{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('tue_day') == 'true') {
                $description[] = 'tue{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('wed_day') == 'true') {
                $description[] = 'wed{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('thu_day') == 'true') {
                $description[] = 'thu{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('fri_day') == 'true') {
                $description[] = 'fri{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('sat_day') == 'true') {
                $description[] = 'sat{' . $start_time . '-' . $stop_time . '}';
            }

            if ($this->request->getPost('sun_day') == 'true') {
                $description[] = 'sun{' . $start_time . '-' . $stop_time . '}';
            }

            $stmtIn->bindValue(':description', implode(',', $description));

            if ($stmtIn->execute()) {
                $schedulesId = $sensei->database->querySingle('select seq from sqlite_sequence where name="schedules"', false);
                return ['result' => 'OK', 'id' => $schedulesId];
            } else {
                return ['result' => 'ERR'];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['result' => 'ERR'];
        }
    }

    public function policydetailsAction()
    {
        $response = [];
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policyId', null, '');
            if ($policyId != '') {
                $response = $sensei->database->querySingle('SELECT * FROM policies WHERE id=' . $policyId, true);
                $stmt = $sensei->database->prepare('select s.* from policies_schedules p,schedules s
                                                 where p.schedule_id=s.id and p.policy_id = :policy_id
                                                    ORDER BY s.name');
                $stmt->bindValue(':policy_id', $policyId);
                $results = $stmt->execute();
                $schedules = [];
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $schedules[] = $row['description'];
                }
                $response['schedule'] = implode(', ', $schedules);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function findAction()
    {
        $response = ['exists' => false];
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policyId', null, '');
            $sid = $this->request->getPost('sid', null, '');
            $tmp = explode('.', $sid);
            if (count($tmp) < 2) {
                return $response;
            }

            $uuid = $tmp[1];
            $category = $tmp[0];
            $isCustomApp = substr($uuid, 0, 7) == 'custom-';

            if ($category == 'appcategories') {
                $stmt = $sensei->database->prepare('SELECT * FROM application_categories WHERE uuid=:uuid');
                $stmt->bindValue(':uuid', $uuid);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if (isset($row['name']) && !empty($row['name'])) {
                    $response['rule'] = $row;
                    $response['list'] = ['list' => 'application_categories', 'id' => $row['id']];
                    $response['exists'] = true;
                    return $response;
                }
            }
            // applications
            if ($category == 'apps') {
                if ($isCustomApp) {
                    $stmt = $sensei->database->prepare('select c.id,a.name,p.action,p.uuid from policy_custom_app_categories p,custom_applications a,application_categories c where p.custom_application_id=a.id and a.application_category_id=c.id and p.uuid=:uuid and p.policy_id=:policyId');
                    $stmt->bindValue(':uuid', $uuid);
                    $stmt->bindValue(':policyId', $policyId);
                    $results = $stmt->execute();
                    $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                    if (isset($row['action']) && !empty($row['action'])) {
                        $response['rule'] = $row;
                        $response['list'] = ['list' => 'application_categories', 'id' => $row['id']];
                        $response['exists'] = true;
                        return $response;
                    }
                } else {
                    $stmt = $sensei->database->prepare('select c.id,a.name,p.action,p.uuid from policy_app_categories p,applications a,application_categories c where p.application_id=a.id and a.application_category_id=c.id and p.uuid=:uuid and p.policy_id=:policyId');
                    $stmt->bindValue(':uuid', $uuid);
                    $stmt->bindValue(':policyId', $policyId);
                    $results = $stmt->execute();
                    $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                    if (isset($row['action']) && !empty($row['action'])) {
                        $response['rule'] = $row;
                        $response['list'] = ['list' => 'application_categories', 'id' => $row['id']];
                        $response['exists'] = true;
                        return $response;
                    }
                }
            }
            // web categories or Security Categories
            if ($category == 'webcategories' || $category == 'seccategories') {
                $stmt = $sensei->database->prepare('select p.id,p.action,w.name from policy_web_categories p, web_categories w where p.web_categories_id=w.id and p.uuid=:uuid');
                $stmt->bindValue(':uuid', $uuid);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if (isset($row['action']) && !empty($row['action'])) {
                    $response['rule'] = $row;
                    $response['list'] = ['list' => 'policy_web_categories', 'id' => $row['id']];
                    $response['exists'] = true;
                    return $response;
                }
            }
            // custom categories
            if ($category == 'customwebcategories' || $category == 'exceptionscategories') {
                $stmt = $sensei->database->prepare('select name,uuid,action,policy_id,c.id from policy_custom_web_categories p, custom_web_categories c where p.custom_web_categories_id=c.id and c.uuid=:uuid and p.policy_id=:policyId');
                $stmt->bindValue(':uuid', $uuid);
                $stmt->bindValue(':policyId', $policyId);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if (isset($row['action']) && !empty($row['action'])) {
                    $response['rule'] = $row;
                    $response['list'] = ['list' => $category == 'customwebcategories' ? 'policy_custom_web_categories' : 'global_list', 'id' => $row['id']];
                    $response['exists'] = true;
                    return $response;
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function changeAction()
    {
        $response = [
            'exists' => false,
            'changed' => false,
        ];
        try {
            $sensei = new Sensei();
            $ruleType = $this->request->getPost('type');
            $ruleBy = $this->request->getPost('by');
            $name = $this->request->getPost('name');
            $list = $this->request->getPost('list');
            $site = $this->request->getPost('site');
            $isSend = $this->request->getPost('send', null, 'false');
            $policyId = $this->request->getPost('policyId', null, '');
            $uuid = explode('.', $name)[1];
            $category = explode('.', $name)[0];
            $isCustomApp = substr($uuid, 0, 7) == 'custom-';

            // application categories
            if ($category == 'appcategories') {
                $stmt = $sensei->database->prepare('update policy_app_categories set action=:action where policy_id=:policyId and application_id in (select a.id from applications a,application_categories c where a.application_category_id=c.id and c.uuid=:uuid)');
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':uuid', $uuid);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute()) {
                    $response['changed'] = true;
                    $response['exists'] = true;
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    $response['cloud_status'] = $cloud_result;
                    return $response;
                }
                return $response;
            }

            // applications
            if ($category == 'apps') {
                if ($isCustomApp) {
                    $stmt = $sensei->database->prepare('update policy_custom_app_categories set action=:action where uuid=:uuid and policy_id=:policyId');
                    $stmt->bindValue(':action', $ruleType);
                    $stmt->bindValue(':uuid', $uuid);
                    $stmt->bindValue(':policyId', $policyId);
                    if ($stmt->execute()) {
                        $response['changed'] = true;
                        $response['exists'] = true;
                        $cloud_result = $sensei->sendDataCloud('update', $policyId);
                        $response['cloud_status'] = $cloud_result;
                        return $response;
                    } else {
                        $stmt = $sensei->database->prepare('update policy_app_categories set action=:action where uuid=:uuid and policy_id=:policyId');
                        $stmt->bindValue(':action', $ruleType);
                        $stmt->bindValue(':uuid', $uuid);
                        $stmt->bindValue(':policyId', $policyId);
                        if ($stmt->execute()) {
                            $response['changed'] = true;
                            $response['exists'] = true;
                            $cloud_result = $sensei->sendDataCloud('update', $policyId);
                            $response['cloud_status'] = $cloud_result;
                            return $response;
                        }
                    }
                }
                return $response;
            }

            // web categories
            if ($category == 'webcategories' || $category == 'seccategories') {
                $stmt = $sensei->database->prepare('update policy_web_categories set action=:action where policy_id=:policyId and uuid=:uuid');
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':uuid', $uuid);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute()) {
                    $response['changed'] = true;
                    $response['exists'] = true;
                    $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => 'Whitelisted', 'site' => $site]], $isSend);
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    $response['cloud_status'] = $cloud_result;
                    return $response;
                }
            }

            // custom categories
            if ($category == 'customwebcategories') {
                $stmt = $sensei->database->prepare('update custom_web_categories set action=:action where uuid=:uuid');
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':uuid', $uuid);
                if ($stmt->execute()) {
                    $response['changed'] = true;
                    $response['exists'] = true;
                    $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => 'Whitelisted', 'site' => $site]], $isSend);
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    $response['cloud_status'] = $cloud_result;
                    return $response;
                }
            }
            if ($category == 'exceptionscategories' && $ruleType == 'accept') {
                $url_host = $sensei->get_domain($site);
                $stmt = $sensei->database->prepare("delete from global_sites where action='reject' and (site=:host or site=:site)");
                //$stmt->bindValue(':site', $site);
                $stmt->bindValue(':host', $url_host);
                $stmt->bindValue(':site', $site);
                if ($stmt->execute()) {
                    $response['changed'] = true;
                    $response['exists'] = true;
                    $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => 'Whitelisted', 'site' => $site]], $isSend);
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    $response['cloud_status'] = $cloud_result;
                    $sensei->logger("SQL: Delete global sites in exception list " . $url_host);
                    return $response;
                } else {
                    $sensei->logger("SQL Error Delete global sites exception: " . $sensei->database->lastErrorMsg());
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function AddRuleAction()
    {
        $response = [
            'exists' => false,
            'changed' => false,
        ];
        try {
            $sensei = new Sensei();
            $ruleType = $this->request->getPost('type');
            $ruleBy = $this->request->getPost('by');
            $name = $this->request->getPost('name');
            $policyId = $this->request->getPost('policyId', null, 0);
            $isSend = $this->request->getPost('send', null, 'false');

            if ($ruleBy == 'apps') {
                $stmt = $sensei->database->prepare('update policy_app_categories set action=:action where policy_id=:policyId and application_id in (select id from applications where name=:name)');
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute() && $sensei->database->changes() > 0) {
                    $response = ['exists' => true, 'changed' => true];
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    $response['cloud_status'] = $cloud_result;
                    return $response;
                }

                $stmt = $sensei->database->prepare('update policy_custom_app_categories set action=:action where policy_id=:policyId and custom_application_id in (select id from custom_applications where name=:name)');
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute() && $sensei->database->changes() > 0) {
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    $response = ['exists' => true, 'changed' => true, 'cloud_result' => $cloud_result];
                    return $response;
                }

                return $response;
            }

            if ($ruleBy == 'appcategories') {
                $stmt = $sensei->database->prepare('update policy_app_categories set action=:action where policy_id=:policyId and application_id in (select a.id from applications a,application_categories c where a.application_category_id=c.id and c.name=:name)');
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute() && $sensei->database->changes() > 0) {
                    $response = ['exists' => true, 'changed' => true];
                }

                $stmt = $sensei->database->prepare('update policy_custom_app_categories set action=:action where policy_id=:policyId and custom_application_id in (select a.id from custom_applications a,application_categories c where a.application_category_id=c.id and c.name=:name)');
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute() && $sensei->database->changes() > 0) {
                    $response = ['exists' => true, 'changed' => true];
                }
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
                $response['cloud_status'] = $cloud_result;
                return $response;
            }

            if ($ruleBy == 'webcategories') {
                $stmt = $sensei->database->prepare('update policy_web_categories set action=:action where policy_id=:policyId and web_categories_id in (select id from web_categories where name=:name)');
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':action', $ruleType);
                $stmt->bindValue(':policyId', $policyId);
                if ($stmt->execute() && $sensei->database->changes() > 0) {
                    $response = ['exists' => true, 'changed' => true];
                }

                if ($sensei->database->changes() == 0) {
                    $stmt = $sensei->database->prepare('update custom_web_categories set action=:action where name=:name and id in (select custom_web_categories_id from policy_custom_web_categories where policy_id=:policyId)');
                    $stmt->bindValue(':name', $name);
                    $stmt->bindValue(':action', $ruleType);
                    $stmt->bindValue(':policyId', $policyId);
                    if ($stmt->execute() && $sensei->database->changes() > 0) {
                        $response = ['exists' => true, 'changed' => true];
                    }
                }
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
                $response['cloud_status'] = $cloud_result;
                return $response;
            }
            if ($ruleBy == 'host') {
                if ($ruleType == 'reject') {
                    $row = $sensei->database->querySingle("select * from custom_web_categories where name='Blacklisted' and id in (select custom_web_categories_id from policy_custom_web_categories where policy_id=$policyId)", true);
                    if (empty($row['name'])) {
                        // add one custom web category
                        $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
                        $stmtIn->bindValue(':name', 'Blacklisted');
                        $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                        $stmtIn->bindValue(':action', 'reject');
                        $stmtIn->execute();
                        $sensei->logger('inserted policy custom web applications');
                        $customWebID = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);

                        //add custom web category to policies
                        $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id)
                                                  VALUES(:policy_id,:custom_web_categories_id)');
                        $stmtIn->bindValue(':policy_id', 0);
                        $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
                        $stmtIn->execute();
                        $row['id'] = $customWebID;
                    }
                    $count = $sensei->database->querySingle(sprintf("select count(*) from custom_web_category_sites where site='%s' and id=%d", $name, $row['id']), false);
                    if ($count == 0) {
                        $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(custom_web_categories_id,site,uuid)
                                                  VALUES(:custom_web_categories_id,:site,:uuid)');
                        $stmtIn->bindValue(':custom_web_categories_id', $row['id']);
                        $stmtIn->bindValue(':site', $name);
                        $stmtIn->bindValue(':uuid', $sensei->generateUUID());
                        $stmtIn->execute();
                        $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => 'Blacklisted', 'site' => $name]], $isSend);
                    }
                    $response = ['exists' => true, 'changed' => true];
                }
            }
            $cloud_result = $sensei->sendDataCloud('update', $policyId);
            $response['cloud_status'] = $cloud_result;
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function generalAction()
    {
        $response = ['policies' => [], 'result' => 'OK'];
        try {
            $sensei = new Sensei();
            $policyId = $this->request->getPost('policyId', null, '');
            $rules = $this->request->getPost('rules');
            foreach ($rules as $rule) {
                $stmt = $sensei->database->prepare('update policies set ' . $rule['id'] . '=:action where  id=:id');
                $stmt->bindValue(':action', $rule['action'] == 'reject' ? 1 : 0);
                $stmt->bindValue(':id', $policyId);
                $stmt->execute();
            }
            $sensei->saveChanges();
            $policies = $sensei->database->query('SELECT * FROM policies where delete_status=0 order by name');
            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                $response['policies'][] = $row;
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function addToWhitelistAction()
    {
        $response = ['exists' => false, 'status' => 'accept'];
        try {
            $sensei = new Sensei();
            $hostname = $this->request->getPost('hostname');
            $isSend = $this->request->getPost('send', null, 'false');
            $policyId = $this->request->getPost('policyId', null, '');
            $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => 'Whitelisted', 'site' => $hostname]], $isSend);
            $sql = "select p.custom_web_categories_id as id,c.action from  policy_custom_web_categories p,custom_web_categories c where p.custom_web_categories_id=c.id and c.name='Whitelisted' and p.policy_id=$policyId";
            $row = $sensei->database->querySingle($sql, true);
            if (!isset($row['action'])) {
                return $response;
            }

            if ($row['action'] == 'reject') {
                $response['status'] = 'reject';
                return $response;
            }

            $sql = "select count(*) from custom_web_category_sites where custom_web_categories_id='{$row['id']}' and site='$hostname'";
            $row_count = $sensei->database->querySingle($sql, false);
            $sensei->logger("Custom Web Category : $hostname " . intval($row_count) > 0 ? ' Exists' : 'Not Exists');
            if (intval($row_count) > 0) {
                $response['exists'] = true;
                return $response;
            }

            $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(custom_web_categories_id,site,uuid) VALUES(:custom_web_categories_id,:site,:uuid)');
            $stmtIn->bindValue(':custom_web_categories_id', $row['id']);
            $stmtIn->bindValue(':site', $hostname);
            $stmtIn->bindValue(':uuid', $sensei->generateUUID());
            if (!$stmtIn->execute()) {
                $sensei->logger("SQL Error : " . $sensei->database->lastErrorMsg());
            }
            $response['added'] = true;
            $cloud_result = $sensei->sendDataCloud('update', $policyId);
            $response['cloud_status'] = $cloud_result;

            $url_host = $sensei->get_domain($hostname);
            $stmt = $sensei->database->prepare("delete from global_sites where action='reject' and (site=:host or site=:site)");
            $stmt->bindValue(':host', $url_host);
            $stmt->bindValue(':site', $hostname);
            if (!$stmt->execute()) {
                $sensei->logger("SQL Error Delete global sites: " . $sensei->database->lastErrorMsg());
            } else {
                $sensei->logger("SQL: Delete global sites for " . $url_host);
            }

            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return $response;
        }
    }

    public function statAction()
    {
        $response = [];
        try {
            $sensei = new Sensei();
            $policies = $sensei->database->query('select count(*) as total,status from policies where delete_status=0 group by status');
            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                array_push($response, $row);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => true];
        }
    }

    public function reorderAction()
    {
        try {
            $sensei = new Sensei();
            $policies = $this->request->getPost('policies', null, '');
            if (empty($policies) || !is_array($policies)) {
                $sensei->logger("Empty field for reorder");
                return ['error' => true];
            }
            foreach ($policies as $index => $policy) {
                $stmtIn = $sensei->database->prepare('update policies set sort_number=:index where id=:id');
                $stmtIn->bindValue(':index', $index);
                $stmtIn->bindValue(':id', $policy['id']);
                if (!$stmtIn->execute()) {
                    $sensei->logger("SQL Error on Reorder Action: " . $sensei->database->lastErrorMsg());
                    return ['error' => true];
                }
            }
            return ['error' => false];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => true];
        }
    }

    public function importListAction()
    {
        $response = ['error' => false, 'message' => ''];
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $tmp_filename = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $_FILES['file']['name'];
            if (move_uploaded_file($_FILES['file']['tmp_name'], $tmp_filename)) {
                $content = file_get_contents($tmp_filename);
                $lines = explode(PHP_EOL, $content);

                if (count($lines) == 0 || strlen($content) == 0) {
                    return ['error' => true, 'message' => 'File is empty'];
                }

                if (count($lines) > 100) {
                    return ['error' => true, 'message' => 'Max number of entries cannot exceed 100'];
                }

                foreach ($lines as $key => $line) {
                    $lines[$key] = trim($line);
                    if (empty($lines[$key])) {
                        unset($lines[$key]);
                    } else {
                        if (!filter_var($lines[$key], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !substr_count($lines[$key], '.')) {
                            return ['error' => true, 'message' => 'Invalid domain name is ' . $line];
                        }
                    }
                }

                $uuid = $this->request->getPost('uuid');
                $sendcategory = $this->request->getPost('sendcategory', null, '');
                $categoryId = $sensei->database->querySingle('select id,name,action from custom_web_categories where uuid="' . $uuid . '"', true);
                if (!empty($categoryId['id'])) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(custom_web_categories_id,site,uuid) VALUES(:custom_web_categories_id,:site,:uuid)');
                    $err_list = [];
                    foreach ($lines as $line) {
                        $sensei->logger($line);
                        $total = $sensei->database->querySingle(sprintf('select count(*) as total from custom_web_category_sites where custom_web_categories_id="%d" and site="%s"', $categoryId['id'], $line));
                        if ($total > 0) {
                            $err_list[] = 'Domain is already exists->' . $line;
                            continue;
                        }
                        $total = $sensei->database->querySingle(sprintf('select count(*) as total from global_sites where site="%s"', $line));
                        if ($total > 0) {
                            $err_list[] = 'Domain is already exists in globals->' . $line;
                            continue;
                        }
                        $uuid_site = $sensei->generateUUID();
                        $stmtIn->bindValue(':custom_web_categories_id', $categoryId['id']);
                        $stmtIn->bindValue(':site', $line);
                        $stmtIn->bindValue(':uuid', $uuid_site);
                        if (!$stmtIn->execute()) {
                            $sensei->logger(__METHOD__ . ' -> SQL error:' . $sensei->database->lastErrorMsg());
                            $err_list[] = 'SQL Error->' . $line;
                        }
                        if ($sendcategory == 'true') {
                            $this->sendCategorizationData(['process' => 'NEW_SITE', 'data' => ['category' => $categoryId['name'], 'site' => $line]], $sendcategory);
                        }
                    }
                    $backend->configdRun('sensei policy reload');
                    $policyId = $this->request->getPost('policy', null, '');
                    $cloud_result = $sensei->sendDataCloud('update', $policyId);
                    return ['error' => false, 'message' => implode(',', $err_list), 'cloud_status' => $cloud_result];
                } else {
                    return ['error' => true, 'message' => 'Category not found'];
                }
            } else {
                return ['error' => true, 'message' => 'File could not upload'];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    public function exportListAction()
    {
        try {
            $uuid = $this->request->getPost('uuid', null, '');
            $sensei = new Sensei();
            $categoryId = $sensei->database->querySingle('select id,name,action from custom_web_categories where uuid="' . $uuid . '"', true);
            if (!empty($categoryId['id'])) {
                $rows = $sensei->database->query(sprintf('select site from custom_web_category_sites where custom_web_categories_id="%d"', $categoryId['id']));
                $lines = [];
                while ($row = $rows->fetchArray($mode = SQLITE3_ASSOC)) {
                    $lines[] = $row['site'];
                }
                $rows = $sensei->database->query(sprintf('select * from global_sites where action="%s"', $categoryId['action']));
                while ($row = $rows->fetchArray($mode = SQLITE3_ASSOC)) {
                    $lines[] = $row['site'];
                }
                $file_name = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $categoryId['id'] . '.txt';
                $size = file_put_contents($file_name, implode(PHP_EOL, $lines));
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=' . basename($file_name));
                readfile($file_name);
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return 'ERR';
        }
    }

    public function setGlobalAction()
    {
        try {
            $uuid = $this->request->getPost('uuid', null, '');
            $cuuid = $this->request->getPost('cuuid', null, '');
            $isGlobal = $this->request->getPost('isglobal', null, 0);
            $policyId = $this->request->getPost('policy', null, -10);
            $sensei = new Sensei();
            $err_list = [];
            if ($isGlobal == 1) {
                $sites = $sensei->database->querySingle(sprintf('select * from custom_web_category_sites where uuid="%s"', $uuid), true);
                $category = $sensei->database->querySingle(sprintf('select * from custom_web_categories where id=%d', $sites['custom_web_categories_id']), true);
                $sensei->logger(sprintf('Set Global Site: %s, Action: True, Uuid: %s, Category Name:%s', $sites['site'], $uuid, $category['name']));
                $total = $sensei->database->querySingle(sprintf('select count(*) as total from global_sites where site="%s"', $sites['site']));
                if ($total > 0) {
                    $sensei->logger('Domain is already exists->' . $sites['site']);
                } else {
                    $st = $sensei->database->prepare("insert into global_sites(site,uuid,action,site_type,policy_id) values(:site,:uuid,:action,:site_type,:policy_id)");
                    $st->bindValue(':site', $sites['site']);
                    $st->bindValue(':uuid', $sites['uuid']);
                    $st->bindValue(':site_type', $sites['category_type']);
                    $st->bindValue(':action', $category['name'] == 'Whitelisted' ? 'accept' : 'reject');
                    $st->bindValue(':policy_id', $policyId);
                    $result = $st->execute();
                }
                $sensei->logger('Global Site Delete from custom web sites');
                $st = $sensei->database->prepare("delete from custom_web_category_sites where uuid=:uuid");
                $st->bindValue(':uuid', $uuid);
                $result = $st->execute();
                $sensei->logger('Global Site from custom web sites deleted....');
            }
            if ($isGlobal == 0) {
                $sites = $sensei->database->querySingle(sprintf('select * from global_sites where uuid="%s"', $uuid), true);
                $category = $sensei->database->querySingle(sprintf('select * from custom_web_categories where uuid="%s"', $cuuid), true);
                $sensei->logger(sprintf('Set Global Site: %s, Action: False, Uuid: %s, Category Name:%s', $sites['site'], $uuid, $sites['action'] == 'accept' ? 'Whitelisted' : 'Blacklisted'));
                $st = $sensei->database->prepare("delete from global_sites where uuid=:uuid");
                $st->bindValue(':uuid', $uuid);
                $result = $st->execute();
                $sensei->logger('Global Site Deleted from global list....');

                $st = $sensei->database->prepare("insert into custom_web_category_sites(site,uuid,custom_web_categories_id) values(:site,:uuid,:custom_web_categories_id)");
                $st->bindValue(':site', $sites['site']);
                $st->bindValue(':uuid', $sites['uuid']);
                $st->bindValue(':custom_web_categories_id', $category['id']);
                $result = $st->execute();
                $sensei->logger('Global Site Delete from custom web sites');
            }
            $sensei->logger('update custom web applications sites for set global Original ' . $isGlobal . ' ' . $uuid);
            if (count($err_list) == 0) {
                $cloud_result = $sensei->sendDataCloud('update', $policyId);
            }

            return ['error' => count($err_list) > 0 ? implode(', ', $err_list) : '', 'cloud_status' => $cloud_result];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function thirtyPartyDomainAction()
    {
        try {
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }
}
