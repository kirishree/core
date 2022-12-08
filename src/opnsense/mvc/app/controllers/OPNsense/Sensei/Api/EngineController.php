<?php

namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\Sensei;

class EngineController extends ApiControllerBase
{

    const checkFailfilename = '/tmp/bypass_fails';
    const checkFailfilename_extra = '/tmp/bypass_fails_extra';
    const checkFailfilename_extra_exp = '/tmp/bypass_fails_extra_exp';
    const checklicensefilename = '/tmp/license_warning';
    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function trialKeyAction()
    {
        $email = $this->request->getPost('email', null, '');
        $partner_id = $this->request->getPost('partner_id', null, '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => false, 'message' => 'Invalid email address'];
        }

        $data = json_encode(["email" => $email, "referral" => $partner_id]);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, Sensei::freeTrialEndpoint);
        curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt(
            $curl,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data),
                'Authorization: Bearer qUErid3Uskom64yWwNwi'
            )
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_TIMEOUT, 40);
        if ($data) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        }
        $results = curl_exec($curl);
        if ($results === false) {
            return ['status' => false, 'message' => 'Gateway Timeout!'];
        } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) > 200) {
            $response = json_decode($results, true);
            return ['status' => false, 'message' => $response['error']];
        }
        curl_close($curl);
        return ['status' => true, 'message' => ''];
    }

    public function notificationAction()
    {
        $response = [];
        try {
            $sensei = new Sensei();
            $sensei->logger('Notification starting...');
            if (file_exists($sensei->config['dirs']['notification'])) {
                $sensei->logger('Notification file found...');
                $content = file_get_contents($sensei->config['dirs']['notification']);
                $lines = explode(PHP_EOL, $content);
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $hash = hash('sha256',$line);
                        $tmp = json_decode($line, true);
                        if (
                            $tmp['event']['prio'] != 'info' && $tmp['event']['prio'] != 'warn' &&
                            (!isset($tmp['dismiss']) || (isset($tmp['dismiss']) && $tmp['dismiss'] == 'false'))
                        ) {
                            $tmp['event']['timefmt'] = date('Y/m/d h:i:s', $tmp['event']['time']);
                            //$response[] = ['notice_name'=>'engine_notice','notice'=>'Priority: ' . $tmp['event']['prio'].' Date: '.$tmp['event']['time'].'</br>'.$tmp['event']['title'].'=>'.$tmp['event']['msg']];
                            $response[] = ['notice_name'=>'engine_notice','id'=> $hash,'notice'=>'Priority: ' . $tmp['event']['prio'].' Date: '.$tmp['event']['timefmt'].'</br>'.$tmp['event']['title'].'=>'.$tmp['event']['msg']];
                        }
                    }
                }
                array_multisort(array_map(function ($element) {
                    return $element['event']['time'];
                }, $response), SORT_DESC, $response);
                return array_slice($response, -10);
            } else
                return [];
        } catch (\Exception $th) {
            $sensei->logger(__METHOD__ . ' Exception -> ' . $th->getMessage());
            return $response;
        }
    }

    public function statusAction()
    {
        try {
            $backend = new Backend();
            $sensei = new Sensei();
            $sensei->logger('status position starting...');
            $onBoots = $sensei->getNodeByReference('onboot')->getNodes();
            $eastpact_status = $backend->configdRun('sensei service eastpect status');
            $senpai_status = $backend->configdRun('sensei service senpai status');
            $db_path = $backend->configdRun('sensei datastore-path ' . $sensei->reportDatabase);
            $remote = (string) $sensei->getNodeByReference('general.database.Remote');
            if (strpos('ERROR', $db_path) !== false) {
                $db_path = '0';
            }
            $bypass = ['enabled' => 0, 'disabled' => 0];
            if (strpos($eastpact_status, 'is running') !== false) {
                $bypass_str = trim(substr($eastpact_status, strpos($eastpact_status, 'bypass=') + 7));
                $bypass_arr = explode(':', $bypass_str);
                if (count($bypass_arr) > 1) {
                    $bypass = ['enabled' => $bypass_arr[0], 'disabled' => $bypass_arr[1]];
                }
            }
            if ($remote == 'true') {
                try {
                    $arrContextOptions = array(
                        "ssl" => array(
                            "verify_peer" => false,
                            "verify_peer_name" => false,
                        ),
                    );
                    $dbuser = (string) $sensei->getNodeByReference('general.database.User');
                    $dbpass = (string) $sensei->getNodeByReference('general.database.Pass');
                    if (substr($dbpass, 0, 4) == 'b64:') {
                        $dbpass = base64_decode(substr($dbpass, 4));
                    }

                    if (!empty($dbuser) && !empty($dbpass)) {
                        $auth = base64_encode("$dbuser:$dbpass");
                        $arrContextOptions["http"] = [
                            "header" => "Authorization: Basic $auth",
                        ];
                    }
                    $context = stream_context_create($arrContextOptions);
                    $tmp = file_get_contents((string) $sensei->getNodeByReference('general.database.Host') . ':' . (string) $sensei->getNodeByReference('general.database.Port'), false, $context);
                    $databaseStatus = true;
                } catch (\Exception $e) {
                    $databaseStatus = false;
                    $sensei->logger(__METHOD__ . '->' . $e->getMessage());
                }
            } else {
                if ($sensei->reportDatabase == 'SQ') {
                    $databaseStatus = true;
                } else {
                    $databaseStatus = strpos(
                        $backend->configdRun('sensei service ' . $sensei::reportDatabases[$sensei->reportDatabase]['service'] . ' status'),
                        'is running'
                    ) !== false;
                }
            }

            return [
                'eastpect' => [
                    'status' => strpos($eastpact_status, 'is running') !== false,
                    'bypass' => $bypass,
                    'onboot' => $onBoots['eastpect'] == 'YES',
                    'engine' => [
                        'version' => preg_replace('/\R+/', '', $backend->configdRun('sensei engine-version')),
                        'lastUpdate' => preg_replace('/\R+/', '', $backend->configdRun('sensei engine-date')),
                    ],
                    'db' => [
                        'version' => preg_replace('/\R+/', '', $backend->configdRun('sensei db-version')),
                        'lastUpdate' => preg_replace('/\R+/', '', $backend->configdRun('sensei db-date')),
                    ],
                    'ui' => [
                        'version' => preg_replace('/\R+/', '', $backend->configdRun('sensei ui-version')),
                    ],
                ],
                'database' => [
                    'status' => $databaseStatus,
                    'onboot' => $sensei->reportDatabase == 'SQ' ? true : $onBoots[$sensei::reportDatabases[$sensei->reportDatabase]['service']] == 'YES',
                    'disksize' => intval(preg_replace(
                        '/\R+/',
                        '',
                        $db_path != '0' ? $backend->configdRun('sensei database-disk-size ' . $db_path) : 0
                    )),
                    'info' => $sensei::reportDatabases[$sensei->reportDatabase],
                    'remote' => $remote,
                ],
                'senpai' => [
                    'register' => file_exists($sensei->cloudToken),
                    'status' => strpos($senpai_status, 'is running') !== false,
                    'onboot' => $onBoots['senpai'] == 'YES',
                ],
            ];
        } catch (\Exception $th) {
            $sensei->logger(__METHOD__ . ' Exception -> ' . $th->getMessage());
            return '';
        }
    }

    public function mongodbrepairAction()
    {
        $backend = new Backend();
        $sensei = new Sensei();
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $backend->configdRun('sensei mongodb-repair');
        $dbtype = (string) $sensei->getNodeByReference('general.database.Type');
        $dbname = $sensei::reportDatabases[$dbtype];
        $sensei->logger('Create ' . $dbname['name'] . ' indexes');
        $result = $backend->configdRun('sensei erase-reporting-data 0 ' . $dbtype);
        $sensei->logger('Created ' . $dbname['name'] . ' indexes : ' . $result);
        return true;
    }

    public function statsAction()
    {
        $sensei = new Sensei();
        $backend = new Backend();
        $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return $backend->configdRun('sensei stats-read');
    }

    public function bypassAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $status = $this->request->getPost('status', null, 'false');

            $result = $backend->configdRun('sensei bypass ' . ($status == 'true' ? 'on' : 'off'));
            $sensei->logger('sensei bypass ' . ($status == 'true' ? 'on' : 'off' . '-> ' . $result));
            # 1 : disable , 2 : not exists hwcard , 3 : not exits bpctl_util command, 4: command give error
            if ($result == 2 || $result == 1) {
                $commands = ['set bypass ' . $status];
                $node = $sensei->getNodeByReference('bypass');
                $node->setNodes([
                    'enable' => $status,
                    'mode' => $status,
                ]);
                $sensei->saveChanges();
                $backend->configdRun('template reload OPNsense/Sensei');
                return $sensei->runCLI($commands);
            } else if ($result == 0) {
                return ['error' => false];
            } else {
                $message = '';
                if ($result == 3) {
                    $message = 'Silicom bypass utility not installed';
                }

                if ($result == 4) {
                    $message = 'Silicom bypass utility reported error';
                }

                return ['error' => true, 'message' => $message];
            }
            return ['error' => false];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    public function reloadApplicationAction()
    {
        $backend = new Backend();
        $response = $backend->configdRun('sensei application reload');
        $sensei = new Sensei();
        $sensei->logger('Application Reload Result ' . PHP_EOL . $response);
        return true;
    }

    public function cliAction()
    {
        try {
            $sensei = new Sensei();
            $pass = '';
            $this->sessionClose();
            $commands = $this->request->getPost('commands');
            return $sensei->runCLI($commands, $pass);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => true, 'message' => $e->getMessage()];
        }
    }

    public function cloudNodesStatusAction($mode)
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            if ($mode == 'write' or $mode == 'recheck') {
                if (file_exists($sensei->nodesStatusJson)) {
                    unlink($sensei->nodesStatusJson);
                }
            }
            $json = $backend->configdRun('sensei nodes-status ' . $mode);
            $nodes = json_decode($json);
            $nodesArr = array_merge($nodes->availables ?? [], $nodes->unavailables ?? []);
            $tmp = [];
            $enableNodes = 0;
            $changed = false;
            foreach ($nodesArr as $key => $node) {
                //less two nodes should be enabled
                if ($node->type == 4 && $node->enabled == true && $node->available == true) {
                    $enableNodes++;
                }
                if ($node->type == 4 && $node->enabled == true && $node->available == false) {
                    $node->enabled = false;
                    $changed = true;
                }

                $tmp[$node->name]['name'] = $node->name;
                $tmp[$node->name]['inet' . $node->type] = $node;
                $tmp[$node->name]['enabled'] = (isset($tmp[$node->name]['inet4']) ? $tmp[$node->name]['inet4']->enabled : $node->enabled);
            }
            $tmp = array_values($tmp);
            for ($index = 0; $index < count($tmp) && $enableNodes < 2; $index++) {

                //node was enabled but now node is not available.
                if (isset($tmp[$index]['inet4']) && $tmp[$index]['inet4']->available == false && $tmp[$index]['inet4']->enabled == true) {
                    $tmp[$index]['enabled'] = false;
                    $tmp[$index]['inet4']->enabled = false;
                }

                //node is available , not enabled , enableNodes counter less two
                if ($enableNodes < 2 && $tmp[$index]['enabled'] == false && isset($tmp[$index]['inet4']) && $tmp[$index]['inet4']->available == true) {
                    $tmp[$index]['enabled'] = true;
                    $changed = true;
                    $enableNodes++;
                }
            }
            $tmp = ["successful" => true, "changed" => $changed, "availables" => $tmp, "unavailables" => []];

            return json_encode($tmp);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return '';
        }
    }

    public function initAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $response = [];
            $dbtype = $this->request->get('dbtype', null, 'ES');
            $response['output'] = $backend->configdRun('sensei init ' . $dbtype);
            $response['successful'] = !is_null($response['output']) and preg_match(
                '/Execute error|Action not found|\*\*\*ERROR/i',
                $response['output']
            ) == 0;
            if (!$response['successful']) {
                shell_exec('/usr/local/etc/rc.d/configd restart');
            } else {
                if (!file_exists($sensei->configDoneFile)) {
                    touch($sensei->configDoneFile);
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['output' => $e->getMessage(), 'successful' => false];
        }
    }

    public function restartconfigdAction()
    {
        trim(shell_exec('/usr/sbin/service configd restart'));
    }

    public function checkFailAction()
    {
        try {
            $tmp = [];
            $response_value = [];
            $desc_start = "<p><strong style='font-family: AmpleSoftPro-Bold;'>zenarmor</strong> has detected a problem during operation and has shut down <strong style='font-family: AmpleSoftPro-Bold;'>zenarmor</strong> services in order to prevent a network outage.</p>";
            $desc_ext = "<p>If you think this is something we should have a look, <a data-dismiss=\"modal\" data-toggle=\"modal\" data-target=\"#contact-bug-team\">just click here</a> to let us know about the details and we will investigate this further.</p>" .
                "<p>You can re-enable the services from Status page.</p>";

            if (file_exists($this::checkFailfilename)) {
                $context = file_get_contents($this::checkFailfilename);
                if (strlen($context) == 0) {
                    return ['status' => 0];
                }
                $context_split = explode(PHP_EOL, trim($context));
                $message = [];
                $last_index = count($context_split) - 1;
                $counter = 0;
                for ($index = $last_index; $counter < 3 && $counter <= $last_index; $counter++) {
                    $line_arr = explode(',', $context_split[$last_index - $counter]);
                    $description = $desc_start;
                    switch ($line_arr[1]) {
                        case 'swap':
                            $list = explode('--', $line_arr[2]);
                            $swap_percent = $list[0];
                            $swap_usage = Sensei::formatBytes((int) trim($list[1]) * 1024);
                            $swap_total = Sensei::formatBytes((int) trim($list[2]) * 1024);
                            $description .= "<p>It is because we detected high SWAP usage <span class=\"text-danger\">$swap_percent % (<strong> $swap_usage / $swap_total </strong>)</span></p>";
                            break;
                        case 'disk':
                            $description .= "<p>It is because we detected high Disk<span class=\"text-danger\">(<strong>$line_arr[2]% usage</strong>)</span></p>";
                            break;
                        case 'cpumem':
                            $cpu_util = explode('%', explode('CPU: ', $line_arr[2])[1])[0] . '%';
                            $mem_util = explode('%', explode('MEM: ', $line_arr[2])[1])[0] . '%';
                            $description .= "<p>It is because we detected high CPU/Memory <span class=\"text-danger\">(<strong>$cpu_util/$mem_util</strong> utilization)</span> </p>";
                            break;
                        case 'stalled':
                            $description .= "<p><strong style='font-family: AmpleSoftPro-Bold;'>zenarmor</strong> has detected stalled and has shut down <strong style='font-family: AmpleSoftPro-Bold;'>zenarmor</strong> services in order to prevent a network outage.</p>";
                            break;
                        case 'core':
                            $description .= "<p><strong style='font-family: AmpleSoftPro-Bold;'>zenarmor</strong> has detected core dump and has shut down <strong style='font-family: AmpleSoftPro-Bold;'>zenarmor</strong> services in order to prevent a network outage.</p>";
                            break;
                        case 'Interface':
                            $description .= "<p>It is highly probable that this is due to a netmap issue where netmap might not be inter-operable with your current ethernet adapter.</p>";
                            break;
                        case 'DB':
                            $response = new \Phalcon\Http\Response();
                            $response->setHeader("Content-Type", "application/json");
                            $response->setContent(json_encode(['status' => 12]));
                            $response->send();
                            return true;
                            break;
                        default:
                            $description .= "<p>{$line_arr[2]}</p>";
                            break;
                    }
                    $description .= $desc_ext;
                    if (!isset($tmp[hash('MD5', $description)])) {
                        $tmp[hash('MD5', $description)] = 1;
                        $message[] = [
                            'time' => date(DATE_ISO8601, (int) ($line_arr[0] ?? time())),
                            'reason' => $line_arr[1] ?? '',
                            'extra' => $description,
                        ];
                    }
                }
                $response_value['message_extra'] = '';
                if (file_exists($this::checkFailfilename_extra_exp)) {
                    $content = trim(file_get_contents($this::checkFailfilename_extra_exp));
                    $response_value['message_extra'] = array_map('trim', explode(PHP_EOL, $content));
                }
                if (file_exists($this::checkFailfilename_extra)) {
                    $content = trim(file_get_contents($this::checkFailfilename_extra));
                    $response_value['message_extra2'] = array_map('trim', explode(PHP_EOL, $content));
                }
                $response_value['messages'] = $message;
                $response_value['status'] = 1;
            } else {
                $response_value['status'] = 0;
            }
            $configd_status = trim(shell_exec('/usr/sbin/service configd status'));
            if (strpos($configd_status, 'is not running') !== false) {
                $response_value['status'] = 101;
            }
            $response = new \Phalcon\Http\Response();
            $response->setHeader("Content-Type", "application/json");
            $response->setContent(json_encode($response_value));
            $response->send();
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return '';
        }
    }

    /*
     * Delete checkfilename file.
     */

    public function checkDelAction()
    {
        try {
            if (file_exists($this::checkFailfilename)) {
                $ret = rename($this::checkFailfilename, $this::checkFailfilename . '_' . time());
                if (file_exists($this::checkFailfilename_extra)) {
                    $ret = rename($this::checkFailfilename_extra, $this::checkFailfilename_extra . '_' . time());
                }

                if (file_exists($this::checkFailfilename_extra_exp)) {
                    $ret = rename($this::checkFailfilename_extra_exp, $this::checkFailfilename_extra_exp . '_' . time());
                }

                return ['return' => $ret];
            }
            return ['return' => true];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['return' => false];
        }
    }

    public function activationAction()
    {
        try {
            $response = [];
            if ($this->request->getMethod() == 'POST') {
                $activationKey = $this->request->getPost('activationKey');
                $activationForce = $this->request->getPost('activationForce', null, '0');
                $host_uuid = '';
                if (file_exists(Sensei::serialPath)) {
                    exec('/usr/local/sensei/bin/eastpect -s', $output, $return);
                    if ($return == 0) {
                        $host_uuid = trim($output[0]);
                    }
                } else {
                    exec('/usr/local/sensei/bin/eastpect -g', $output, $return);
                    if ($return == 0) {
                        $host_uuid = explode(' ', trim($output[0]));
                    }

                    $host_uuid = isset($host_uuid[3]) ? $host_uuid[3] : '';
                }

                if (empty($host_uuid)) {
                    $response['status'] = false;
                    $response['message'] = "could not get uuid";
                    return $response;
                }
                $opnsense_version = trim(shell_exec('opnsense-version | awk \'{ print $2 }\''));
                $data = json_encode([
                    'activation_key' => $activationKey, 'hwfingerprint' => $host_uuid, 'api_key' => $activationKey,
                    'platform' => 'OPNsense', 'platform_version' => $opnsense_version,
                    'force_activation' => ($activationForce == 'true' ? '1' : '0'),
                ]);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, Sensei::licenseServer . '/api/v1/license/generate');
                curl_setopt($curl, CURLOPT_PORT, 443);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt(
                    $curl,
                    CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data),
                    )
                );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
                curl_setopt($curl, CURLOPT_TIMEOUT, 40);
                if ($data) {
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                }
                $results = curl_exec($curl);
                if ($results === false) {
                    $this->response->setStatusCode(504, 'Gateway Timeout');
                    $results = 'Gateway Timeout!';
                } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
                    $this->response->setStatusCode(503, 'License service Unavailable');
                } else {
                    $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
                }
                curl_close($curl);
                //$results = json_decode($results);
                return $results;
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function licenseAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $response = [];
            if ($this->request->getMethod() == 'GET') {
                if (file_exists($sensei->licenseData)) {
                    $response = $backend->configdRun('sensei license-details');
                    $response = (array) json_decode($response);
                    if (isset($response['plan'])) {
                        $response['plan'] = $sensei::license_list[$response['plan']];
                    }
                    $config = Config::getInstance()->object();
                    if (isset($config->system->timezone)) {
                        date_default_timezone_set($config->system->timezone);
                    }

                    $licenseDetails = $sensei->getNodeByReference('general.license')->getNodes();
                    $response['expire_time'] = date('c', $response['expire_time']); // $licenseDetails['endDate'] ?? '';
                    $timezone_object = date_default_timezone_get();
                    date_default_timezone_set('America/Los_Angeles');
                    $end_ts = strtotime($licenseDetails['startDate']);
                    date_default_timezone_set($timezone_object);
                    $response['start_time'] = date('c', $end_ts);
                    $response['exists'] = true;
                    $response['licenseKey'] = $licenseDetails['key'] ?? '';
                    $response['license'] = base64_encode(file_get_contents($sensei->licenseData));
                    #$response['size'] = $sensei::flavorSizes[($response['size'] ?? 15) > 2000 ? 2000 : ($response['size'] ?? 15)] . ' (' . ($response['size'] ?? 15) . ' devices)';
                } else {
                    $response['exists'] = false;
                }
            } elseif ($this->request->getMethod() == 'POST') {
                $licenseData = $this->request->getPost('license');
                $licenseKey = str_replace(' ', '', $this->request->getPost('licenseKey'));
                $licenseStartDate = $this->request->getPost('licenseStartDate');
                $licenseEndDate = $this->request->getPost('licenseEndDate');
                $licenseData = base64_decode($licenseData);
                file_put_contents('/tmp/sensei-license.data', $licenseData);
                $response['output'] = $backend->configdRun('sensei license-verify');
                $response['valid'] = strpos($response['output'], 'License OK') !== false;
                $response['plan'] = '';
                if ($response['valid']) {
                    rename('/tmp/sensei-license.data', $sensei->licenseData);
                    $backend->configdRun('sensei license');
                    $licenseDetails = json_decode($backend->configdRun('sensei license-details'));
                    if (isset($licenseDetails->size) && $licenseDetails->size > 0) {
                        $sensei->getNodeByReference('general')->setNodes([
                            #'flavor' => Sensei::flavorSizes2[$licenseDetails->size]
                            'flavor' => $licenseDetails->size,
                        ]);
                        $sensei->getNodeByReference('general.license')->setNodes([
                            'plan' => $licenseDetails->plan,
                            'key' => $licenseDetails->activation_key,
                            'startDate' => $licenseStartDate,
                            'endDate' => $licenseEndDate,
                            'Size' => $licenseDetails->size,
                        ]);
                        $response['plan'] = isset($sensei::license_list[$licenseDetails->plan]) ? $sensei::license_list[$licenseDetails->plan] : '';
                        if (!empty($response['plan']))
                            $response['plan'] .= ' - ' . $licenseDetails->size;
                        $sensei->getNodeByReference('general.support')->setNodes([
                            'plan' => 'Basic',
                            'key' => '',
                            'startDate' => '',
                            'endDate' => '',
                        ]);
                        $sensei->getNodeByReference('dnsEncrihmentConfig')->setNodes([
                            'reverse' => 'true',
                        ]);

                        if (file_exists($sensei->config->files->support)) {
                            unlink($sensei->config->files->support);
                        }

                        $sensei->saveChanges();
                    } else {
                        $response['valid'] = false;
                        $response['output'] = sprintf('License plan size did not defined.(%s)', $licenseDetails->size);
                    }
                }
            } elseif ($this->request->getMethod() == 'DELETE') {
                $sensei->logger('License deleting via Controller.');
                if (file_exists($sensei->licenseData)) {
                    $response['exists'] = true;
                    unlink($sensei->licenseData);
                    if (file_exists($sensei->config->files->support)) {
                        unlink($sensei->config->files->support);
                    }

                    $sensei->logger('Change web categories.');
                    # change web categories
                    $webcategoriesType = 'permissive';
                    $stmt = $sensei->database->prepare('select w.uuid,c.name,w.action,w.policy_id,p.webcategory_type,c.is_security_category from policy_web_categories w,web_categories c,policies p
                                                    where p.id=w.policy_id and w.web_categories_id = c.id and w.policy_id =:policy_id order by c.name');
                    $stmt->bindValue(':policy_id', 0);
                    $results = $stmt->execute();
                    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                        if ($row['action'] == 'reject') {
                            $webcategoriesType = 'moderate';
                        }

                        if (($row['is_security_category'] == 0 && $row['action'] == 'reject') || ($row['is_security_category'] == 1 && in_array($row['name'], $sensei::security_premium))) {
                            $stUpt = $sensei->database->prepare('update policy_web_categories set action=:action where uuid=:uuid');
                            $stUpt->bindValue(':action', 'accept');
                            $stUpt->bindValue(':uuid', $row['uuid']);
                            $stUpt->execute();
                        }
                    }
                    if ($webcategoriesType == 'moderate') {
                        $stmtIn = $sensei->database->prepare('Update policies set webcategory_type=:webcategorytype where id=:id');
                        $stmtIn->bindValue(':webcategorytype', $webcategoriesType);
                        $stmtIn->bindValue(':id', 0);
                        $stmtIn->execute();
                        $results = $stmt->execute();
                        while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                            if (in_array($row['name'], $sensei::webcategory_list['moderate'])) {
                                $stUpt = $sensei->database->prepare('update policy_web_categories set action=:action where uuid=:uuid');
                                $stUpt->bindValue(':action', 'reject');
                                $stUpt->bindValue(':uuid', $row['uuid']);
                                $stUpt->execute();
                            }
                        }
                    }

                    $sensei->logger('Deleting Shun settings.');
                    $node = $sensei->getNodeByReference('shun');
                    $node->setNodes([
                        'networks' => '',
                        'vlans' => '',
                    ]);
                    $sensei->getNodeByReference('general.license')->setNodes([
                        'plan' => 'Free',
                        'key' => '',
                        'startDate' => '',
                        'endDate' => '',
                    ]);
                    $sensei->getNodeByReference('general.support')->setNodes([
                        'plan' => '',
                        'key' => '',
                        'startDate' => '',
                        'endDate' => '',
                    ]);
                    $sensei->getNodeByReference('dnsEncrihmentConfig')->setNodes([
                        'reverse' => 'false',
                    ]);

                    $sensei->saveChanges();

                    $sensei->logger('Update policies table.');
                    $stmt = $sensei->database->prepare('update policies set status=0 where  id>0');
                    $stmt->execute();

                    $backend->configdRun('template reload OPNsense/Sensei');
                    $backend->configdRun('sensei worker reload');
                    $backend->configdRun('sensei policy reload');
                    // $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
                    $backend->configdRun('sensei license');
                    // $backend->configdRun('sensei service eastpect restart');
                    $response['successful'] = true;
                } else {
                    $response['exists'] = false;
                }
                $sensei->logger('License deleted vi Controller');
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function supportActivationAction()
    {
        try {
            $response = ['successful' => false, 'message' => 'error occur!'];
            $sensei = new Sensei();

            if ($this->request->getMethod() == 'GET') {
                if (file_exists($sensei->config->files->support)) {
                    $supportDetails = $sensei->getNodeByReference('general.support')->getNodes();
                    $response['start_time'] = $supportDetails['startDate'] ?? '';
                    $response['expires_time'] = $supportDetails['endDate'] ?? '';
                    $response['exists'] = true;
                    $response['supportKey'] = $supportDetails['key'] ?? '';
                    $response['plan'] = ucfirst($supportDetails['plan'] ?? '');
                } elseif (file_exists($sensei->licenseData)) {
                    $backend = new Backend();
                    $license = $backend->configdRun('sensei license-details');
                    $license = (array) json_decode($license);
                    if ($license['premium']) {
                        $supportDetails = $sensei->getNodeByReference('general.license')->getNodes();
                        $response['start_time'] = $supportDetails['startDate'] ?? '';
                        $response['expires_time'] = $supportDetails['endDate'] ?? '';
                        $response['exists'] = true;
                        $response['supportKey'] = $supportDetails['key'] ?? '';
                        $response['plan'] = ($license['plan'] == 'opnsense_home' || $license['plan'] == 'opnsense_soho') ? 'Forum' : 'Basic';
                    } else {
                        $response['exists'] = false;
                    }
                }
                return $response;
            }
            if ($this->request->getMethod() == 'POST') {
                $supportKey = $this->request->getPost('supportKey');
                $licenseDetails = $sensei->getNodeByReference('general.license')->getNodes();
                $data = json_encode(["apiv2" => ['api_key' => md5(time()), 'licensekey' => $licenseDetails['key'], 'supportkey' => $supportKey]]);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, Sensei::licenseServer . '/api/v2/supportkey/assign');
                curl_setopt($curl, CURLOPT_PORT, 443);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt(
                    $curl,
                    CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($data),
                    )
                );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_TIMEOUT, 20);
                if ($data) {
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
                }
                $results = curl_exec($curl);
                if ($results === false) {
                    $this->response->setStatusCode(504, 'Gateway Timeout');
                } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 500) {
                    $this->response->setStatusCode(503, 'Support service Unavailable');
                } else {
                    $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
                    $response = json_decode($results, true);
                    if ($response['successful']) {
                        $sensei->getNodeByReference('general.support')->setNodes([
                            'key' => $supportKey,
                            'plan' => $response["plan"],
                            'startDate' => $response["created_at"],
                            'endDate' => $response["expires_at"],
                        ]);
                        $sensei->saveChanges();
                        $response['key'] = $supportKey;
                        file_put_contents($sensei->config->files->support, json_encode($response));
                    }
                }
                curl_close($curl);
                return json_decode($results, true);
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['successful' => false, 'message' => $e->getMessage()];
        }
    }

    public function startPaymentSessionAction()
    {
        try {
            file_put_contents(self::log_file, __METHOD__ . ' Starting ', FILE_APPEND);
            $host_uuid = '';
            exec('/usr/local/sensei/bin/eastpect -s', $output, $return);
            if ($return == 0) {
                $host_uuid = trim($output[0]);
            }

            $data = json_encode([
                "plan" => '2019_' . $this->request->getPost('plan', null, '') . '_' . $this->request->getPost('duration', null, ''),
                "path" => $this->request->getPost('path', null, ''),
                "discount" => $this->request->getPost('discount', null, ''),
                "host_uuid" => $host_uuid,
            ]);
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, Sensei::licenseServer . ':8443/settingsPayment.php');
            curl_setopt($curl, CURLOPT_PORT, 8443);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt(
                $curl,
                CURLOPT_HTTPHEADER,
                array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data),
                )
            );
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            if ($data) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            file_put_contents(self::log_file, __METHOD__ . ' Send Data ' . var_export($data, true), FILE_APPEND);
            $results = curl_exec($curl);
            if ($results === false) {
                $this->response->setStatusCode(504, 'Gateway Timeout');
            } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 500) {
                $this->response->setStatusCode(503, 'Support service Unavailable');
            } else {
                $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
            curl_close($curl);
            file_put_contents(self::log_file, __METHOD__ . ' Result ' . json_decode($results, true), FILE_APPEND);
            return json_decode($results, true);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function endPaymentSessionAction()
    {
        try {
            file_put_contents(self::log_file, __METHOD__ . ' Starting ', FILE_APPEND);
            $data = json_encode(["license" => $this->request->getPost('license', null, '')]);
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, Sensei::licenseServer . ':8443/successPayment.php');
            curl_setopt($curl, CURLOPT_PORT, 8443);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt(
                $curl,
                CURLOPT_HTTPHEADER,
                array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($data),
                )
            );
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            if ($data) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            file_put_contents(self::log_file, __METHOD__ . ' Send Data ' . var_export($data, true), FILE_APPEND);
            $results = curl_exec($curl);
            if ($results === false) {
                $this->response->setStatusCode(504, 'Gateway Timeout');
            } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 500) {
                $this->response->setStatusCode(503, 'Support service Unavailable');
            } else {
                $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
            curl_close($curl);
            file_put_contents(self::log_file, __METHOD__ . ' Result ' . json_decode($results, true), FILE_APPEND);
            return json_decode($results, true);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function getFlavorAction()
    {
        try {
            $sizes = ['size_list' => []];
            $backend = new Backend();
            $license = $backend->configdRun('sensei license-details');
            $license = (array) json_decode($license);
            $keys = array_keys(Sensei::flavorSizes);

            if ($license['premium']) {
                if (!isset(Sensei::flavorSizes[$license['size']])) {
                    $max = 0;
                    foreach (Sensei::flavorSizes as $key => $value) {
                        if ($license['size'] > $key) {
                            $max = $key;
                        }
                    }
                    $sizes['size_list'][] = [
                        'size' => Sensei::flavorSizes2[$max],
                        'label' => Sensei::flavorSizes[$max],
                        'concurrent_users' => $license['size'],
                    ];
                } else {
                    $sizes['size_list'][] = [
                        'size' => Sensei::flavorSizes2[$license['size']],
                        'label' => Sensei::flavorSizes[$license['size']],
                        'concurrent_users' => $license['size'],
                    ];
                }
            }
            for ($index = 0; $index < 3; $index++) {
                $sizes['size_list'][] = [
                    'size' => Sensei::flavorSizes2[$keys[$index]],
                    'label' => Sensei::flavorSizes[$keys[$index]],
                    'concurrent_users' => $keys[$index],
                ];
            }

            $hardwarelevel = $this->request->get('hardwarelevel');
            if ($hardwarelevel == 'ES' || $hardwarelevel == 1) {
                for ($index = 3; $index < count(Sensei::flavorSizes); $index++) {
                    $sizes['size_list'][] = [
                        'size' => Sensei::flavorSizes2[$keys[$index]],
                        'label' => Sensei::flavorSizes[$keys[$index]],
                        'concurrent_users' => $keys[$index],
                    ];
                }
            }

            if (count($sizes['size_list']) > count(Sensei::flavorSizes)) {
                array_shift($sizes['size_list']);
            }

            $sizes['database_list'] = Sensei::reportDatabases;
            return $sizes;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }
}
