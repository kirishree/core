<?php

namespace OPNsense\Sensei\Api;

use Exception;
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Sensei\Sensei;

class UpdateController extends ApiControllerBase
{

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function checkAction($section = 'auto')
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $this->sessionClose();
            $dbVersion = preg_replace('/\R+/', '', $backend->configdRun('sensei db-version'));
            if ($section != 'auto' or !file_exists($sensei->updatesJson)) {
                $backend->configdRun('sensei check-updates');
            }
            if (file_exists($sensei->updatesJson)) {
                $this->response->setHeader('Content-Type', 'application/json; charset=utf-8');
                $json = json_decode(file_get_contents($sensei->updatesJson), true);
                $json['dbversion'] = $dbVersion;
                $json['error'] = false;
                return $json;
            } else {
                return ['error' => true];
            }
        } catch (Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['error' => true];
        }
    }
 
    private function setEnginenotification($p_hash)
    {
        $response = [];
        try {
            $sensei = new Sensei();
            $sensei->logger('Notification starting...');
            if (file_exists($sensei->config['dirs']['notification'])) {
                $sensei->logger('Notification file found...');
                $content = file_get_contents($sensei->config['dirs']['notification']);
                $lines = explode(PHP_EOL, $content);
                $new_lines = [];
                foreach ($lines as $line) {
                    if (!empty($line)) {
                        $hash = hash('sha256',$line);
                        if ($hash == $p_hash){
                            $tmp = json_decode($line, true);
                            $tmp['dismiss'] = true;
                            $tmp = json_encode($tmp);
                            $new_lines[] = $tmp;
                        }else 
                            $new_lines[] = $line;
                    }
                }
                file_put_contents($sensei->config['dirs']['notification'],implode(PHP_EOL,$new_lines).PHP_EOL);
            }
                
            return ['error' => false];
        } catch (Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['error' => true];
        }
    }

    private function getEnginenotification()
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
                        $tmp['event']['timefmt'] = date('Y/m/d h:i:s', $tmp['event']['time']);
                        if (
                            $tmp['event']['prio'] != 'info' && $tmp['event']['prio'] != 'warn' &&
                            (!isset($tmp['dismiss']) || (isset($tmp['dismiss']) && $tmp['dismiss'] == false))
                        ) {
                            //$response[] = ['notice_name'=>'engine_notice','notice'=>'Priority: ' . $tmp['event']['prio'].' Date: '.$tmp['event']['time'].'</br>'.$tmp['event']['title'].'=>'.$tmp['event']['msg']];
                            $response[] = ['id'=> $hash,'status'=>0,'time'=>$tmp['event']['time'],'notice_name'=>'engine_notice','notice'=>'<strong style="color:red;">Engine exception -> Priority: ' . $tmp['event']['prio'].' Date: '.$tmp['event']['timefmt'].'</strong> '.$tmp['event']['title'].'=>'.$tmp['event']['msg']];
                        }
                        //$response[] = ['id'=> $hash,'status'=>0,'time'=>$tmp['event']['time'],'notice_name'=>'engine_notice','notice'=>'<strong style="color:red;">Priority: ' . $tmp['event']['prio'].' Date: '.$tmp['event']['timefmt'].'</strong> '.$tmp['event']['title'].'=>'.$tmp['event']['msg']];
               
                    }
                }
                array_multisort(array_map(function ($element) {
                    return $element['time'];
                }, $response), SORT_DESC, $response);
                return array_slice($response, -20);
            } else
                return [];
        } catch (\Exception $th) {
            $sensei->logger(__METHOD__ . ' Exception -> ' . $th->getMessage());
            return $response;
        }
    }

    private function getNotice($sensei)
    {
        $notices = [];
        try {
            $cloud = (string) $sensei->getNodeByReference('general.CloudManagementEnable');
            $stmt = $sensei->database->prepare('select * from user_notices where status=0 order by create_date');
            $results = $stmt->execute();
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                if ($cloud == "true" && $row['notice_name'] == 'Upgrade_to_portal')
                    continue;
                $notices[] = $row;
            }
            $engineNotification = $this->getEnginenotification();
            if (count($engineNotification)>0){
                $notices = array_merge($notices,$engineNotification);
            }
            $response = new \Phalcon\Http\Response();
            $response->setHeader("Content-Type", "application/json");
            $response->setContent(json_encode($notices));
            $response->send();
        } catch (Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            $response = new \Phalcon\Http\Response();
            $response->setHeader("Content-Type", "application/json");
            $response->setContent(json_encode($notices));
            $response->send();
        }
    }
    public function noticeAction($section = 'getnote', $id = 0)
    {
        try {
            $sensei = new Sensei();
            if ($section == 'getnote') {
                $this->getNotice($sensei);
                return true;
            }
            if ($section == 'delete') {
                $stmt = $sensei->database->prepare('update user_notices set status=1 where id=:id');
                $stmt->bindValue(':id', $id);
                $stmt->execute();
                if (strlen($id) == 64)
                    $this->setEnginenotification($id);
                $this->getNotice($sensei);
                return true;
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return false;
        }
    }

    private function compareVersion($list)
    {
        if ((int) $list[0] < 19)
            return false;
        if ((int) $list[0] > 19)
            return true;

        if ((int) $list[1] < 7)
            return false;
        if ((int) $list[1] > 7)
            return true;

        if ((int) $list[2] < 8)
            return false;
        if ((int) $list[2] > 8)
            return true;
        return true;
    }

    public function installDbAction()
    {
        try {
            $backend = new Backend();
            $keepData = $this->request->getPost('keep_data', null, 'false');
            $dbtype = $this->request->getPost('dbtype', null, 'ES');
            $engineStart = $this->request->getPost('engine_start', null, 'false');
            $output = trim(shell_exec('ps aux | grep pkg | grep -v grep | grep -c install'));
            if ($output != '0')
                return true;
            if ($dbtype == 'ES')
                //return $backend->configdRun('sensei reinstall elasticsearch ' . $keepData . ' ' . $engineStart . ' &');
                return trim(shell_exec('/usr/local/sbin/configctl sensei reinstall elasticsearch ' . $keepData . ' ' . $engineStart . ' &'));
            if ($dbtype == 'MN') {
                $check = trim(shell_exec('opnsense-version | awk \'{ print $2 }\''));
                $list = explode('.', $check);
                $lastDigit = '';
                if (isset($list[2])) {
                    for ($i = 0; $i < strlen($list[2]); $i++) {
                        if (is_int((int) $list[2][$i]))
                            $lastDigit .= $list[2][$i];
                        else
                            break;
                    }
                    $list[2] = (int) $lastDigit;
                } else
                    $list[2] = 0;
                if (!$this->compareVersion($list))
                    return ['error' => 'Warning: Please make sure you are running the latest OPNsense version'];
                // return $backend->configdRun('sensei reinstall mongodb ' . $keepData . ' ' . $engineStart . ' &');
                return trim(shell_exec('/usr/local/sbin/configctl sensei reinstall mongodb ' . $keepData . ' ' . $engineStart . ' &'));
            }
            if ($dbtype == 'SQ') {
                //$result = $backend->configdRun('sensei reinstall sqlite ' . $keepData . ' false &');
                return trim(shell_exec('/usr/local/sbin/configctl sensei reinstall sqlite ' . $keepData . ' false &'));
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return false;
        }
    }

    public function installAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $package = $this->request->getPost('package', null, '');
            $version = $this->request->getPost('version', null, '');
            $response = [];
            if ($package) {
                $response['msg_uuid'] = $backend->configdRun('sensei update-install ' . $package . ' ' . $version, true);
                if (file_exists($sensei->updatesJson)) {
                    unlink($sensei->updatesJson);
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return [];
        }
    }

    public function statusAction()
    {
        try {
            $backend = new Backend();
            $response = [];
            $response['outputs'] = $backend->configdRun('sensei update-status');
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return ['outputs' => ''];
        }
    }

    public function uninstallAction($check = false)
    {
        try {
            $backend = new Backend();
            $response = [];
            if (!$check) {
                $removeData = $this->request->getPost('removeData');
                $removeFolder = $this->request->getPost('removeFolder');
                $response['msg_uuid'] = $backend->configdRun('sensei uninstall ' . $removeData . ' ' . $removeFolder, true);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return [];
        }
    }

    public function changelogAction($version = null)
    {
        try {
            $sensei = new Sensei();
            $response = [];
            $updateServerUrl = $sensei->getUpdateServerUrl();
            if ($version) {
                $uri = $updateServerUrl . '/updates/engine/changelog/' . $version . '.htm';
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $uri);
                curl_setopt($curl, CURLOPT_TIMEOUT, 20);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $results = curl_exec($curl);
                if ($results !== false and curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 200 and curl_getinfo($curl, CURLINFO_HTTP_CODE) < 400) {
                    $response['exists'] = true;
                    $response['content'] = preg_replace('/\R+/', '', $results);
                } else {
                    $response['exists'] = false;
                    $response['content'] = 'not connected';
                }
                curl_close($curl);
            } else {
                $uri = $updateServerUrl . '/updates/engine/changelog/version_history.json';
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $uri);
                curl_setopt($curl, CURLOPT_TIMEOUT, 20);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                $results = curl_exec($curl);
                if ($results !== false and curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 200 and curl_getinfo($curl, CURLINFO_HTTP_CODE) < 400) {
                    $response['exists'] = true;
                    $data = json_decode($results, true);
                    $response['content'] = $data['versions'];
                } else {
                    $response['exists'] = false;
                    $response['content'] = [];
                }
                curl_close($curl);
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return [];
        }
    }

    public function dbversionAction()
    {
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $response = [];
            if (file_exists($sensei->dbServerConf))
                $uri = trim(file_get_contents($sensei->dbServerConf)) . 'version_history.json';
            else {
                $updateServerUrl = $sensei->getUpdateServerUrl();
                $uri = $updateServerUrl . '/updates/db/1.8/version_history.json';
            }
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $uri);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $results = curl_exec($curl);
            if ($results !== false and curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 200 and curl_getinfo($curl, CURLINFO_HTTP_CODE) < 400) {
                $response['exists'] = true;
                $data = json_decode($results, true);
                $response['versions'] =  array_slice(array_reverse($data['versions']), 0, 5);
            } else {
                $response['exists'] = false;
                $response['content'] = [];
            }
            curl_close($curl);
            $curr_version = preg_replace('/\R+/', '', $backend->configdRun('sensei db-version'));
            $response['curr_version'] = $curr_version;
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);

            return [];
        }
    }
}
