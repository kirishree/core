<?php

/**
 * Created by PhpStorm.
 * User: ureyni
 * Date: 09.04.2019
 * Time: 02:47
 */

namespace OPNsense\Sensei\Api;

use Phalcon\Logger;
use Phalcon\Mvc\Controller;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\Sensei;

class UserenricherController extends Controller
{
    private $sensei = null;
    private $s = 0;
    private $logtime = [];
    private $dberrorMsg = [];
    private $db = null;
    private $log = null;
    const rootpath = '/usr/local/sensei';
    const user_dbfile = self::rootpath . '/userdefined/db/Usercache/userauth_cache.db';
    const pid_dbfile = self::rootpath . '/userdefined/db/Usercache/userauth_cache.pid';
    const userenrich_py = '/usr/local/opnsense/scripts/OPNsense/Sensei/userenrich.py';
    const sql_timeout = 10;

    public function userdb()
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        try {

            if (!file_exists(self::user_dbfile)) {
                if (!file_exists(dirname(self::user_dbfile))) {
                    mkdir(dirname(self::user_dbfile), 0755, true);
                }

                $this->db = new \SQLite3(self::user_dbfile);
                $this->db->busyTimeout(5000);
                $this->db->exec('PRAGMA journal_mode = wal;');

                $sqls = [
                    'CREATE TABLE IF NOT EXISTS "users_cache" (policyid int,sessionid varchar,authenticated_via varchar,username varchar,groups varchar,ip_address varchar,mac_address varchar,hostname int default(0), created number,deleted integer default (0),primary key (policyid, sessionid))',
                    'CREATE INDEX IF NOT EXISTS users_cache_ip ON users_cache (ip_address)',
                    'CREATE INDEX IF NOT EXISTS users_cache_policy ON users_cache (policyid)',
                    'CREATE INDEX IF NOT EXISTS users_cache_session ON users_cache (sessionid)',
                    'CREATE INDEX IF NOT EXISTS users_cache_created ON users_cache (created)',
                    'CREATE INDEX IF NOT EXISTS users_cache_hostname ON users_cache (hostname)',
                ];
                foreach ($sqls as $sql) {
                    $op = $this->db->prepare($sql);
                    $op->execute();
                }
            } else {
                try {
                    $this->db = new \SQLite3(self::user_dbfile);
                    $this->db->busyTimeout(10000);
                    $this->db->exec('PRAGMA journal_mode = wal;');
                } catch (\Exception $e) {
                    $this->sensei->logger(__METHOD__ . '::Exception::' . $e->getMessage());
                }
            }
            return true;
        } catch (\Exception $e) {
            $sensei->logger(sprintf('Userenrich Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }

    private function Response($status = true, $error_code = 0, $message = '')
    {
        if ($status == false) {
            $this->log .= ' Failed : ' . $message;
            $this->sensei->logger($this->log);
        }
        $this->sensei->logger("Response : $status , $error_code , $message", 7 /*Logger::DEBUG*/);
        return $this->response->setJsonContent(array(
            'response' => [
                'status' => $status,
                'error_code' => $error_code,
                'message' => $message,
            ],
        ), JSON_UNESCAPED_UNICODE)->send();
    }

    private function checkAuth($token)
    {
        $token = explode(' ', $token);
        $token = end($token);
        if (strlen($token) < 12) {
            return false;
        }

        $tokenlist = [];
        if (file_exists(Sensei::restTokenFile)) {
            $tokenlist = json_decode(file_get_contents(Sensei::restTokenFile));
        }

        foreach ($tokenlist as $item) {
            if ($item->token == $token && $item->status == 'true') {
                return true;
            }
        }
        return false;
    }

    private function checkGroups($rest_data)
    {
        if (isset($rest_data->groups)) {
            if (empty($rest_data->groups)) {
                return '';
            }

            if (is_array($rest_data->groups)) {
                return implode(',', $rest_data->groups);
            }

            if (is_string($rest_data->groups)) {
                return trim($rest_data->groups);
                /*
            $rest_data->groups = trim($rest_data->groups);
            if (strpos($rest_data->groups,',') !== false) {
            return $rest_data->groups;
            }
            if (strpos($rest_data->groups,' ') !== false) {
            return str_replace(' ',',',$rest_data->groups);
            }
             */
            }
            return '';
        } else {
            return '';
        }
    }

    private function saveLogin($rest_data)
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        try {

            $ct = 0;
            do {
                try {
                    $stmt = $this->db->prepare('INSERT INTO users_cache(username,sessionid,ip_address,groups,hostname,created) values(:username,:sessionid,:ip_address,:groups,:hostname,:created)');
                    if ($stmt == false) {
                        usleep(rand(1000, 1000000));
                        $ct++;
                    }
                } catch (\Exception $e) {
                    $this->sensei->logger(__METHOD__ . ": Exception : " . $e->getMessage());
                }
            } while ($stmt == false && $ct < 5);

            $groups = $this->checkGroups($rest_data);
            $stmt->bindValue(':username', $rest_data->username);
            $stmt->bindValue(':sessionid', $rest_data->logonid);
            $stmt->bindValue(':groups', $groups);
            $stmt->bindValue(':ip_address', $rest_data->ip);
            $stmt->bindValue(':hostname', strpos($rest_data->username, '$') !== false ? 1 : 0);
            $stmt->bindValue(':created', time());
            if (!$stmt->execute()->finalize()) {
                $this->sensei->logger(__METHOD__ . ": Execute Error : " . $this->db->lastErrorMsg());
                $this->dberrorMsg[] = $this->db->lastErrorMsg();
                unset($stmt);
                return false;
            } else {
                unset($stmt);
            }

            return true;
        } catch (\Exception $e) {
            if (file_exists(self::user_dbfile)) {
                unlink(self::user_dbfile);
            }

            $this->userdb();
            $sensei->logger(sprintf('Userenrich Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }

    private function deleteLogin($rest_data)
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        try {

            do {
                $stmt = $this->db->prepare('update users_cache set deleted=:deleted where sessionid=:sessionid');
                if ($stmt == false) {
                    usleep(rand(1000, 1000000));
                }
            } while ($stmt == false);
            $stmt->bindValue(':deleted', time());
            $stmt->bindValue(':sessionid', $rest_data->logonid);
            if (!$stmt->execute()) {
                $this->dberrorMsg[] = $this->db->lastErrorMsg();
                return false;
            } else {
                return true;
            }
        } catch (\Exception $e) {
            if (file_exists(self::user_dbfile)) {
                unlink(self::user_dbfile);
            }

            $this->userdb();
            $sensei->logger(sprintf('Userenrich Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }

    private function getLogonId($rest_data)
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        try {

            do {
                $stmt = $this->db->prepare('SELECT ip_address FROM users_cache WHERE sessionid=:sessionid order by created desc limit 1');
                if ($stmt == false) {
                    usleep(rand(1000, 1000000));
                }
            } while ($stmt == false);
            $stmt->bindValue(':sessionid', $rest_data->logonid);
            if (!$results = $stmt->execute()) {
                return false;
            }

            $row = $results->fetchArray($mode = SQLITE3_ASSOC);
            if (!empty($row['ip_address'])) {
                return $row['ip_address'];
            }

            return false;
        } catch (\Exception $e) {
            if (file_exists(self::user_dbfile)) {
                unlink(self::user_dbfile);
            }

            $this->userdb();
            $sensei->logger(sprintf('Userenrich Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return false;
        }
    }

    private function exists($rest_data, $groups)
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        try {
            $time = time();
            $stmt = $this->db->prepare('SELECT count(*)  as total FROM users_cache WHERE username=:username and ip_address=:ip_address and deleted=0 and created>:curr_time');
            $stmt->bindValue(':username', $rest_data->username);
            $stmt->bindValue(':ip_address', $rest_data->ip);
            $stmt->bindValue(':curr_time', ($time - 2592000));
            if (!$results = $stmt->execute()) {
                return 0;
            }

            $row = $results->fetchArray($mode = SQLITE3_ASSOC);
            //$sensei->logger(sprintf(__METHOD__ . ' : Total => %d', $row['total']));
            return $row['total'];
        } catch (\Exception $e) {
            $sensei->logger(sprintf(__METHOD__ . ' : Userenrich Exception: %s => %s', __METHOD__ . '::Exception::', $e->getMessage()));
            return 0;
        }
    }

    private function resultLog($response)
    {
        $happened = ['success' => 0, 'error' => 0, 'message' => []];
        foreach ($response as $key => $item) {
            if (isset($item['error']) && $item['error'] == true) {
                $happened['error']++;
                $happened['message'][] = json_encode($item);
            } else {
                $happened['success']++;
            }
        }

        $this->log .= " Result : " . $happened['success'] . '/' . ($happened['error'] + $happened['success']) . ($happened['error'] > 0 ? ' Failed:' . implode(',', $happened['message']) : '');
        $this->sensei->logger($this->log);
        return $happened['error'];
    }

    public function indexAction()
    {
        $config = Config::getInstance()->object();
        if (isset($config->system->timezone)) {
            date_default_timezone_set($config->system->timezone);
        }

        $this->userdb();
        $this->sensei = new Sensei();
        $clientIp = $this->request->getClientAddress();

        $classname = get_class($this);
        $this->sensei->logger($classname . '->' . __METHOD__ . ' Starting..', 7 /*Logger::DEBUG*/);
        $ip = null;
        $headers = $this->request->getHeaders();
        $this->sensei->logger(implode(',', $headers), 7 /*Logger::DEBUG*/);

        $this->log = "Userenrich Data From $clientIp " . file_get_contents('php://input');

        if ($clientIp != '127.0.0.1') {
            if (empty($headers['Authorization'])) {
                return $this->Response(false, 101, 'Authorization header could not set');
            }

            if (!$this->checkAuth($headers['Authorization'])) {
                return $this->Response(false, 102, 'Token is not valid');
            }
        }

        $rest_data = $this->request->getJsonRawBody();
        if (empty($rest_data->action)) {
            return $this->Response(false, 3, 'Action(Login,Logout) parameter could not set');
        }

        $rest_data->action = strtolower($rest_data->action);
        if (empty($rest_data->username) && $rest_data->action == 'login') {
            return $this->Response(false, 1, 'username parameter could not set');
        }

        if ($rest_data->action != 'login' && $rest_data->action != 'logout') {
            return $this->Response(false, 4, 'Action must be login or logout');
        }

        if (empty($rest_data->ip) && $rest_data->action == 'login') {
            return $this->Response(false, 2, 'IP parameter could not set');
        }

        if ($rest_data->action == 'login') {
            if (
                !filter_var($rest_data->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                !filter_var($rest_data->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ) {
                return $this->Response(false, 21, 'IP is not validate');
            }
            $ip = $rest_data->ip;
        }

        if (empty($rest_data->logonid)) {
            return $this->Response(false, 5, 'logonid parameter could not set');
        }

        /*
        if (empty($rest_data->token))
        return $this->Response(false, 5, 'Token parameter could not set');
         */
        $this->sensei->logger('Finish Checking.', 7 /*Logger::DEBUG*/);
        $commands = [];
        //$commands[] = 'pass ' . (string)$this->sensei->getNodeByReference('enrich.tcpServicePsk');

        $this->sensei->logger('Save to db.', 7 /*Logger::DEBUG*/);
        $groups = $this->checkGroups($rest_data);
        if ($this->exists($rest_data, $groups) > 0 && $rest_data->action == 'login') {
            return $this->Response(true, 0, '');
        }

        if ($rest_data->action == 'login') {
            $this->saveLogin($rest_data);
            $this->sensei->logger('Prepare login commands.', 7 /*Logger::DEBUG*/);
            $commands[] = 'ipmap ' . $rest_data->username . ' ' . $ip . ' ' . $groups;
        }

        if ($rest_data->action == 'logout') {
            if (($ip = $this->getLogonId($rest_data)) == false) {
                return $this->Response(false, 51, 'logonid not found');
            }

            $this->deleteLogin($rest_data);
            $commands[] = 'ipunmap ' . $ip;
            $response = $this->sensei->runCLI($commands);
            $ret = $this->resultLog($response);
            return $this->Response($ret == 0 ? true : false, $ret == 0 ? 0 : 999, $ret == 0 ? '' : ' Engine return error');
        }
        # $commands[] = 'q';
        $this->sensei->logger('Starting CLI...' . implode(',', $commands), 7 /*Logger::DEBUG*/);
        $this->sensei->stream_timeout = 1;

        $response = $this->sensei->runCLI($commands);
        $this->sensei->logger('Responses: ' . json_encode($response), 7 /*Logger::DEBUG*/);
        //$response = $this->postCommand($commands);
        $this->sensei->logger($classname . '->' . __METHOD__ . ' End..', 7 /*Logger::DEBUG*/);
        $ret = $this->resultLog($response);
        return $this->Response($ret == 0 ? true : false, $ret == 0 ? 0 : 999, $ret == 0 ? '' : ' Engine return error');
    }
}
