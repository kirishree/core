<?php

namespace OPNsense\Sensei\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Sensei\Sensei;
use \OPNsense\Core\Backend;

/**
 * Class CloudController
 * @package OPNsense\Sensei
 */
class CloudregisterController extends ApiControllerBase
{
    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    /**
     * before routing event
     * @param Dispatcher $dispatcher
     * @return void
     */

    public function beforeExecuteRoute($dispatcher)
    {
        // disable standard authentication in CaptivePortal Access API calls.
        // set CORS headers
        // file_put_contents(self::log_file, __METHOD__ .var_export($_SERVER,true), FILE_APPEND);
        $sensei = new Sensei();
        $this->response->setHeader("Access-Control-Allow-Origin", $sensei->cloudUri);
        $this->response->setHeader("Access-Control-Allow-Methods", "POST, GET, PUT, OPTIONS");
        $this->response->setHeader("Access-Control-Allow-Credentials", true);
        $this->response->setHeader("Access-Control-Max-Age", 1000);
        $this->response->setHeader("Access-Control-Allow-Headers", "*");
    }

    public function sendResponse()
    {
        $this->response->setStatusCode(401, "Unauthorized");
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setJsonContent(array(
            'status'  => 401,
            'message' => 'Authentication Failed',
        ));
        $this->response->send();
        return false;
    }

    /**
     * cloud register 
     * @return array
     * @throws \OPNsense\Base\ModelException
     */

    public function indexAction()
    {

        $data = $this->request->getJsonRawBody();
        file_put_contents(self::log_file, __METHOD__ . ' REQUEST : ' . var_export($data, true) . PHP_EOL, FILE_APPEND);
        $response = array("error" => true, "message" => "");

        if ($data == false) {
            $response['message'] = 'could not take rest message';
            return $response;
        }

        try {
            if ($data->error == 0) {

                $sensei = new Sensei();
                $backend = new Backend();
                if (!isset($data->jwt_secret)) {
                    $response['message'] = 'Jwt key not set';
                    return $response;
                }
                if (!isset($data->node_ca)) {
                    $response['message'] = 'Root certificate not set';
                    return $response;
                }
                if (!isset($data->node_crt)) {
                    $response['message'] = 'Public key not set';
                    return $response;
                }
                if (!isset($data->node_key)) {
                    $response['message'] = 'Private key not set';
                    return $response;
                }

                if (!file_exists(dirname($sensei->cloudNodeCa)))
                    mkdir(dirname($sensei->cloudNodeCa), 0755);

                $size = file_put_contents($sensei->cloudToken, $data->jwt_secret);
                if ($size == 0) {
                    $response['message'] = 'Jwt key could not save.';
                    return $response;
                }

                $size = file_put_contents($sensei->cloudNodeCa, base64_decode($data->node_ca));
                if ($size == 0) {
                    $response['message'] = 'Root certificate could not save.';
                    return $response;
                }
                $size = file_put_contents($sensei->cloudNodeCrt, base64_decode($data->node_crt));
                if ($size == 0) {
                    $response['message'] = 'Public key could not save.';
                    return $response;
                }
                $size = file_put_contents($sensei->cloudNodeKey, base64_decode($data->node_key));
                if ($size == 0) {
                    $response['message'] = 'Private key could not save.';
                    return $response;
                }
                if (!empty($data->email)) {
                    $sensei->getNodeByReference('general')->setNodes(['CloudManagementAdmin' => $data->email]);
                    exec('/usr/local/sensei/bin/eastpect -s', $output, $return);
                    $host_uuid = '';
                    if ($return == 0) {
                        $host_uuid = $output[0];
                    }
                    $sensei->logger('Take hostuuid : ' . $host_uuid);
                    $sensei->getNodeByReference('general')->setNodes(['CloudManagementUUID' => $host_uuid]);
                    $ret = $sensei->saveChanges();
                    $backend->configdRun('template reload OPNsense/Sensei');
                    $sensei->logger('Save Changes status:' . var_export($ret, true));
                }
                //update policies after register.
                $sqls = [
                    "delete from policies where is_centralized=1",
                    "update policies set cloud_id='',is_sync=0,is_cloud=0"
                ];
                foreach ($sqls as $sql) {
                    $stmt = $sensei->database->prepare($sql);
                    $stmt->execute();
                }

                $out = $backend->configdRun('sensei service senpai restart');
                file_put_contents(self::log_file, __METHOD__ . ' Return : ' . $out . PHP_EOL, FILE_APPEND);
            } else {
                file_put_contents(self::log_file, __METHOD__ . ' Error : ' . "$data->error - $data->message " . PHP_EOL, FILE_APPEND);
                $response['message'] = $data->error;
                return $response;
            }
            $response["error"] = false;
            file_put_contents(self::log_file, __METHOD__ . 'Successs' . PHP_EOL, FILE_APPEND);
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            $response['message'] = $e->getMessage();
            return $response;
        }
    }
}
