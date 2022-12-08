<?php

namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Sensei\Sensei;

class HaconfigController extends ApiControllerBase
{

    public function indexAction()
    {
        try {
            $parameter = $this->request->getPost('parameter', null, '');
            $response = ['error' => '', 'success' => 0];
            $data = json_encode([
                'type' => 'hainfo'
            ]);
            $sensei = new Sensei();
            $response = $sensei->haSendData($data);
            switch ($parameter) {
                case 'config':
                    return $response;
                    break;
                case 'ping':
                    $cmd = sprintf("/sbin/ping -c '%d' '%s'", 3, $ip);
                    exec($cmd, $output, $retval);
                    return ['error' => ($retval == 0 ? '' : 'unsuccess ping'), 'success' => ($retval == 0 ? true : false)];

                    break;
                case 'auth':
                    if ($response['success'] == true)
                        $response['data']['dbtype'] = $sensei::reportDatabases[$response['data']['dbtype']];
                    return $response;

                    break;

                default:
                    # code...
                    break;
            }
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' ::Exception:: ' . $e->getMessage());
            return ['error' => $e->getMessage(), 'success' => 0];
        }
    }

    public function hasyncPolicyAction()
    {
        try {
            $sensei = new Sensei();
            $table = $this->request->getPost('table', null, '');
            return  $sensei->hasyncPolicy($table);
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' ::Exception:: ' . $e->getMessage());
            return false;
        }
    }

    public function hasyncConfigAction()
    {
        try {
            $sensei = new Sensei();
            return  $sensei->haConfig();
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' ::Exception:: ' . $e->getMessage());
            return false;
        }
    }


    public function hasyncLoadAction()
    {
        try {
            $sensei = new Sensei();
            $data = json_encode([
                'type' => 'load'
            ]);
            $stmt = $sensei->database->prepare('UPDATE user_notices SET status=1 where status=0 and notice_name=:notice_name');
            $stmt->bindValue(':notice_name', 'ha_status_notice');
            $stmt->execute();
            return  $sensei->haSendData($data);
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' ::Exception:: ' . $e->getMessage());
            return false;
        }
    }

    public function checkHaAction()
    {
        try {
            $data = json_encode([
                'type' => 'hainfo'
            ]);
            $sensei = new Sensei();
            $backend = new Backend();
            $response = $sensei->haSendData($data);
            if ($response['success']) {
                $engine_version = preg_replace('/\R+/', '', $backend->configdRun('sensei engine-version'));
                $db_version = preg_replace('/\R+/', '', $backend->configdRun('sensei db-version'));
                if ($engine_version != $response['data']['engine-version']) {
                    return ['success' => false, 'error' => sprintf('Engine version mismatch Master FW: %s , Backup FW: %s', $engine_version, $response['data']['engine-version'])];
                }
                if ($db_version != $response['data']['db-version']) {
                    return ['success' => false, 'error' => sprintf('DB version mismatch Master FW: %s , Backup FW: %s', $db_version, $response['data']['db-version'])];
                }
                if ($response['data']['dbtype'] != $sensei->reportDatabase) {
                    return ['success' => false, 'error' => sprintf(
                        'Reporting DB mismatch Master FW: %s , Backup FW: %s',
                        $sensei::reportDatabases[$sensei->reportDatabase]['name'],
                        $sensei::reportDatabases[$response['data']['dbtype']]['name']
                    )];
                }
            }
            return $response;
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' ::Exception:: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
