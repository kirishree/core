<?php

namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);

use Phalcon\Mvc\Controller;
use \OPNsense\Core\Backend;
use \OPNsense\Sensei\Sensei;

require_once "auth.inc";

class HasyncController extends Controller
{

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';
    /**
     * do a basic authentication, uses $_SERVER['HTTP_AUTHORIZATION'] to validate user.
     * @param string $http_auth_header content of the Authorization HTTP header
     * @return bool
     */
    private function http_basic_auth($http_auth_header)
    {
        try {
            //code...
            $tags = explode(" ", $http_auth_header);
            if (count($tags) >= 2) {
                $userinfo = explode(":", base64_decode($tags[1]));

                if (function_exists('authenticate_user'))
                    $username = authenticate_user($userinfo[0], $userinfo[1]);
                else
                    $username = $this->authenticate_user($userinfo[0], $userinfo[1]);

                if ($username !== false) {
                    $aclObj = new \OPNsense\Core\ACL();
                    return $aclObj->isPageAccessible($username, '/xmlrpc.php');
                }
            }

            // not authenticated
            return false;
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return false;
        }
    }

    public function authenticate_user($username, $password)
    {
        $authFactory = new \OPNsense\Auth\AuthenticationFactory();

        foreach (['Local Database', 'Local API'] as $authName) {
            $authenticator = $authFactory->get($authName);
            if ($authenticator != null && $authenticator->authenticate($username, $password)) {
                $authResult = $authenticator->getLastAuthProperties();
                if (array_key_exists('username', $authResult)) {
                    $username = $authResult['username'];
                }
                return $username;
            }
        }
        file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: Unable to retrieve authenticator for ' . $username, FILE_APPEND);
        return false;
    }

    // set Zenarmor tag in conf/config.xml
    public function configXmlAction($sensei, $backend)
    {
        try {
            $sensei->saveChanges();
            $backend->configdRun('template reload OPNsense/Sensei');
            $backend->configdRun('sensei worker reload');
            $backend->configdRun('sensei policy reload');
            $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
            #  $backend->configdRun('sensei service eastpect restart');
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    // set Zenarmor settings.db
    public function configSettingsAction($sensei, $backend)
    {
        try {
            $backend->configdRun('template reload OPNsense/Sensei');
            $backend->configdRun('sensei worker reload');
            $backend->configdRun('sensei policy reload');
            $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
            # $backend->configdRun('sensei service eastpect restart');
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function indexAction()
    {
        # error_reporting(E_ERROR);
        $sensei = new Sensei();
        $sensei->logger('Starting hasync....');
        if (!$sensei->isPremium()) {
            $sensei->logger('Node has not zenarmor license');
            $response = ['error' => 'Node has not zenarmor license', 'success' => false];
            $this->response->setStatusCode(402, 'Node has not license');
            return $this->response->setJsonContent($response, JSON_UNESCAPED_UNICODE)->send();
        }
        try {
            if (
                !isset($_SERVER['HTTP_AUTHORIZATION']) || // check for an auth header
                !$this->http_basic_auth($_SERVER['HTTP_AUTHORIZATION']) || // user authentication failure (basic auth)
                $_SERVER['SERVER_ADDR'] == $_SERVER['REMOTE_ADDR'] // do not accept request from server's own address
            ) {
                // failed
                $sensei->logger('Hasync failed User or password is wrong');
                $response = ['error' => 'User or password is wrong', 'success' => false];
                $this->response->setStatusCode(401, 'Unauthorized');
                return $this->response->setJsonContent($response, JSON_UNESCAPED_UNICODE)->send();
            }
            $backend = new Backend();
            $rest_data = $this->request->getJsonRawBody();
            switch ($rest_data->type) {
                case 'sh':
                    $sensei->logger('hasync shell process');
                    exec($rest_data->command, $output, $ret_val);
                    if ($ret_val == 0) {
                        return $this->response->setJsonContent($output, JSON_UNESCAPED_UNICODE)->send();
                    } else {
                        return $this->response->setJsonContent(['result' => false], JSON_UNESCAPED_UNICODE)->send();
                    }

                    break;
                case 'configctl':
                    $sensei->logger('hasync configctl deamon process');
                    $parameters = json_decode(json_encode($rest_data->parameters), true);
                    $response = [];
                    foreach ($parameters as $param) {
                        $response[] = preg_replace('/\R+/', '', $backend->configdRun('sensei ' . $param));
                    }
                    return $this->response->setJsonContent($response, JSON_UNESCAPED_UNICODE)->send();
                    break;
                case 'getConfig':
                    $sensei->logger('hasync config info');
                    $parameters = json_decode(json_encode($rest_data->parameters), true);
                    $response = [];
                    foreach ($parameters as $param) {
                        $response[] = preg_replace('/\R+/', '', $backend->configdRun('sensei ' . $param));
                    }
                    return $this->response->setJsonContent($response, JSON_UNESCAPED_UNICODE)->send();
                    break;
                case 'hainfo':
                    $sensei->logger('hasync info send.....');
                    $dbservice = 'service ' . $sensei::reportDatabases[$sensei->reportDatabase]['service'] . ' status';
                    $parameters = ['engine-version', 'db-version', 'service eastpect status', $dbservice];
                    $response = [];
                    foreach ($parameters as $key => $param) {
                        $response[$param] = preg_replace('/\R+/', '', $backend->configdRun('sensei ' . $param));
                    }
                    $response['dbtype'] = $sensei->reportDatabase;
                    $response['engine-status'] = strpos($response['service eastpect status'], 'is running') !== false;
                    unset($response['service eastpect status']);
                    $response['db-status'] = strpos($response[$dbservice], 'is running') !== false;
                    unset($response[$dbservice]);
                    return $this->response->setJsonContent($response, JSON_UNESCAPED_UNICODE)->send();
                    break;
                case 'setConfig':
                    $arr = json_decode(json_encode($rest_data->data), true);
                    $sensei->logger('hasync config process->' . var_export($arr, true));
                    $sensei->setNodes($arr);
                    $sensei->saveChanges();
                    $this->configXmlAction($sensei, $backend);
                    if ($rest_data->landingPage != '') {
                        $landingPageDir = dirname($sensei->landingPage);
                        if (!file_exists($landingPageDir)) {
                            mkdir($landingPageDir);
                        }
                        file_put_contents($sensei->landingPage, base64_decode($rest_data->landingPage));
                    }
                    return $this->response->setJsonContent($response, JSON_UNESCAPED_UNICODE)->send();
                    break;
                case 'settings':
                    if (!isset($rest_data->data)) {
                        $sensei->logger('hasync settings error doesnt set data parameter->' . var_export($rest_data, true));
                        return $this->response->setJsonContent([], JSON_UNESCAPED_UNICODE)->send();
                    }
                    $arr = json_decode(json_encode($rest_data->data), true);
                    if (!is_array($arr)) {
                        $sensei->logger('hasync settings error data cant converto to array->' . var_export($arr, true));
                        return $this->response->setJsonContent([], JSON_UNESCAPED_UNICODE)->send();
                    }
                    $sensei->logger('hasync settings process for table ->' . $rest_data->table . ':' . var_export($arr, true));
                    $stmt = $sensei->database->prepare('DELETE FROM ' . $rest_data->table);
                    $stmt->execute();
                    $columns = array_keys($arr[0]);
                    $col_names = implode(',', $columns);
                    $col_params = implode(',', array_map(function ($s) {
                        return ':' . $s;
                    }, $columns));
                    $sensei->logger('hasync settings process for table column names->' . var_export($col_names, true));
                    $sensei->logger('hasync settings process for table column parameters->' . var_export($col_params, true));
                    $stmt = $sensei->database->prepare('INSERT INTO ' . $rest_data->table . ' (' . $col_names . ') values(' . $col_params . ')');
                    $err = [];
                    foreach ($arr as $val) {
                        $tmp = [];
                        foreach ($val as $k => $v) {
                            $stmt->bindValue(':' . $k, $v);
                        }
                        $sensei->logger('hasync settings process for table values ->' . var_export($tmp, true));
                        if (!$stmt->execute()) {
                            $err[] = $sensei->database->lastErrorMsg();
                        }
                    }
                    $sensei->logger('hasync insert table row length:' . count($arr));
                    $sensei->logger('hasync insert table row err length:' . var_export($err, true));

                    $this->response->setStatusCode(200, '');
                    return $this->response->setJsonContent($err, JSON_UNESCAPED_UNICODE)->send();
                    break;
                case 'load':
                    $this->configXmlAction($sensei, $backend);
                    $this->response->setStatusCode(200, '');
                    return $this->response->setJsonContent([], JSON_UNESCAPED_UNICODE)->send();
                    break;
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . 'HA -> sync exception ::Exception:: ' . $e->getMessage());
            return $this->response->setJsonContent(['result' => false], JSON_UNESCAPED_UNICODE)->send();
        }
    }
}
