<?php

namespace OPNsense\Sensei\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Sensei\Sensei;
use \OPNsense\Core\Backend;
use Phalcon\Config\Adapter\Ini as ConfigIni;

/**
 * Class CloudController
 * @package OPNsense\Sensei
 */
class CloudController extends ApiControllerBase
{
    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function statusAction()
    {
        $cloudManagementStatus = 'Offline';
        $registered = false;
        $cloudAgentStatus = false;
        $message = '';
        $host_uuid = '';
        exec('/usr/sbin/pkg info os-sensei-agent', $output, $return_val);
        if ((int)$return_val != 0) {
            return ['installed' => false];
        }
        try {
            $sensei = new Sensei();
            $backend = new Backend();
            $cloudAgentStatus = $backend->configdRun('sensei service senpai status');
            $cloudAgentStatus = strpos($cloudAgentStatus, 'is running') !== false;
            if (file_exists($sensei->cloudToken) && filesize($sensei->cloudToken) > 0) {
                $registered = true;
                if (file_exists($sensei->cloudConnectStatus))
                    $cloudManagementStatus = 'Online';
            } else
                $message = "Token dosen't found";
            $output = [];

            $config = new ConfigIni($sensei::eastpect_config);
            if ((!isset($config->senpai["node-uuid"]) || $config->senpai["node-uuid"] == '') && !file_exists($sensei->licenseData)
                && !file_exists($sensei::serialPath)
            ) {
                exec('/usr/local/sensei/bin/eastpect -g', $output, $return);
                $sensei->logger('Created new NODE UUID ' . implode(', ', $output));
            }
            $output = [];
            exec('/usr/local/sensei/bin/eastpect -s', $output, $return);
            if ((int)$return == 0) {
                $host_uuid = trim($output[0]);
            } else
                $sensei->logger('It could not take node uuid. Return code is ' . $return);

            return [
                'installed' => true,
                'CloudManagementEnable' => (string) $sensei->getNodeByReference('general.CloudManagementEnable'),
                'CloudManagementAdmin' => (string) $sensei->getNodeByReference('general.CloudManagementAdmin'),
                'cloudManagementStatus' => $registered, 'cloudAgentStatus' => $cloudAgentStatus, 'host_uuid' => $host_uuid, 'message' => $message, 'registired' => $registered,
                'cloudAgentHost' => $sensei->cloudUri,
                'centralManagement' => (string) $sensei->getNodeByReference('zenconsole.centralManagement')
            ];
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['registered' => $registered, 'host_uuid' => $host_uuid, 'message' => $e->getMessage()];
        }
    }

    private function setPolicy($sensei)
    {
        $sqls = [
            "delete from policies where is_centralized=1",
            "update policies set cloud_id='',is_sync=0,is_cloud=0"
        ];
        foreach ($sqls as $sql) {
            $stmt = $sensei->database->prepare($sql);
            $stmt->execute();
        }
    }

    public function removeregisterAction()
    {
        $message = '';
        $sensei = new Sensei();
        $backend = new Backend();
        try {
            $backend->configdRun('sensei service senpai stop');
            if (file_exists($sensei->cloudToken)) {
                $content = file_get_contents($sensei->cloudToken);
                if (strlen($content) == 0) {
                    $sensei->logger('Token: Size is zero.');
                    unlink($sensei->cloudToken);
                    return ['successful' => false, 'message' => "Failure unregistering this device from Cloud Portal: Content of token does not exist! It could be that you've already unregistered this device before."];
                }
                $host_uuid = (string) $sensei->getNodeByReference('general.CloudManagementUUID');
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $sensei->cloudUri . '/api/v1/nodes/' . $host_uuid);
                $sensei->logger('Unregister URL: ' . $sensei->cloudUri . '/api/v1/nodes/' . $host_uuid);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
                $jwt = ["exp" => strtotime("+10 minute"), "user_id" => (string) $sensei->getNodeByReference('general.CloudManagementAdmin'), "node_uuid" => $host_uuid];
                $sensei->logger('JWT: ' . implode(',', $jwt));
                $token = $sensei->jwt_encode($jwt, $content);
                $sensei->logger('Token: ' . $token);
                curl_setopt(
                    $curl,
                    CURLOPT_HTTPHEADER,
                    array(
                        'Authorization: Bearer ' . $token
                    )
                );
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_TIMEOUT, 40);
                $response = curl_exec($curl);
                $ret_http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                $sensei->logger('Remove Register Return Code: ' . $ret_http_code . ' : ' . $response);
                if ($ret_http_code != 200 && $ret_http_code != 201) {
                    switch ($ret_http_code) {
                        case 304:
                            $message = 'Not Modified';
                            break;
                        case 400:
                            $message = 'Bad Request';
                            break;
                        case 403:
                            $message = 'Authentication Failed';
                            break;
                        case 404:
                            $message = 'Node Not Found';
                            break;
                        case 406:
                            $message = 'Node already registered by another user';
                            break;
                    }
                    $sensei->logger('Could not remove register from cloud->' . $ret_http_code);
                    // return ['successful' => false, 'message' => "Failure unregistering this device from Cloud Portal. It could be that you've already unregistered this device before."];

                }
                if (file_exists($sensei->cloudToken))
                    unlink($sensei->cloudToken);

                if (file_exists($sensei->cloudNodeCa))
                    unlink($sensei->cloudNodeCa);

                if (file_exists($sensei->cloudNodeCrt))
                    unlink($sensei->cloudNodeCrt);

                if (file_exists($sensei->cloudNodeKey))
                    unlink($sensei->cloudNodeKey);
                /*
                    $sqls = [
                        'delete from policy_custom_app_categories where policy_id in (select id from policies where is_cloud=1)',
                        'delete from policy_web_categories where policy_id in (select id from policies where is_cloud=1)',
                        'delete from policy_app_categories where policy_id in (select id from policies where is_cloud=1)',
                        'delete from policies_schedules where policy_id in (select id from policies where is_cloud=1)',
                        'delete from policies_networks where policy_id in (select id from policies where is_cloud=1)',
                        'delete from policies where is_cloud=1'
                    ];
                */
                $this->setPolicy($sensei);
                return ['successful' => true, 'message' => $message];
            } else {
                $this->setPolicy($sensei);
                return ['successful' => false, 'message' => "Failure unregistering this device from Cloud Portal: token does not exist! It could be that you've already unregistered this device before."];
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['successful' => false, 'message' => $e->getMessage()];
        }
    }

    private function getMyWanIp($uri)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $uri);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt(
            $curl,
            CURLOPT_HTTPHEADER,
            array(
                'Content-Type: application/json',
            )
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($curl, CURLOPT_TIMEOUT, 40);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
        $results = curl_exec($curl);
        if ($results === false) {
            return ['status' => false, 'message' => 'Gateway Timeout!'];
        } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) > 200) {
            $response = json_decode($results, true);
            return ['status' => false, 'message' => $response['error'], 'result' => $results];
        }
        curl_close($curl);
        $ip = json_decode($results, true);
        return ['status' => true, 'message' => '', 'ip' => $ip, 'result' => $results];
    }

    public function userAction()
    {
        try {
            $cloud_user_name = 'sensei-cloud-envoy';
            $cloud_user_priv = 'page-sensei-dashboard';
            $sensei = new Sensei();
            $mylocation = [];
            $ipfile = '/tmp/ip.txt';
            $ip = ["ip" => "127.0.0.1"];
            $mylocation = ["country" => "", "countryCode" => "", "regionName" => "", "city" => ""];
            if (file_exists($sensei->config->files->mylocation)) {
                $sensei->logger($sensei->config->files->mylocation . ' is exists');
                $mylocation = json_decode(file_get_contents($sensei->config->files->mylocation), true);
                $sensei->logger($sensei->config->files->mylocation . ': ' . var_export($mylocation, true));
            } else {
                if (file_exists($ipfile)) {
                    $sensei->logger($ipfile . ' is exists');
                    $ip = json_decode(file_get_contents($ipfile), true);
                    $sensei->logger($ipfile . ': ' . var_export($ip, true));
                } else {
                    $wanIp = $this->getMyWanIp('https://api.ipify.org/?format=json');
                    $sensei->logger('WAN IP: ' . var_export($wanIp, true));
                    if ($wanIp['status']) {
                        file_put_contents($ipfile, $wanIp['result']);
                        $ip = $wanIp['ip'];
                    }
                }
                $wanIp = $this->getMyWanIp('http://ip-api.com/json/' . $ip['ip']);
                $sensei->logger('http://ip-api.com/json/' . $ip['ip'] . ' -- WAN IP-2: ' . var_export($wanIp, true));
                file_put_contents($sensei->config->files->mylocation, $wanIp['result']);
                if ($wanIp['status']) {
                    $mylocation = $wanIp['ip'];
                }
            }
            $sensei->logger('MY LOCATIN: ' . var_export($mylocation, true));
            $opnsense_version = trim(shell_exec('opnsense-version'));
            $keyData = ['key' => md5(time()), 'secret' => sha1(random_int(0, 1000))];
            file_put_contents($auth_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'register_auth', base64_encode($keyData['key'] . ':' . $keyData['secret']));
            if ($keyData != null) {
                return [
                    'error' => '', 'key' => $keyData['key'], 'secret' => $keyData['secret'], 'username' => $cloud_user_name,
                    'hostname' => php_uname('n'),
                    'os_version' => $opnsense_version,
                    'country_code' => $mylocation['countryCode'],
                    'country_name' =>  $mylocation['country'],
                    'city' => (empty($mylocation['regionName']) ? '' : $mylocation['regionName'] . '-') . $mylocation['city'],
                    'lat' =>  $mylocation['lat'],
                    'lon' =>  $mylocation['lon'],
                ];
            }
            // }
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['error' => $e->getMessage(), 'key' => '', 'secret' => '', 'username' => $cloud_user_name, 'hostname' => ''];
        }
    }

    public function agentInstallAction()
    {
        $backend = new Backend();
        try {
            $backend->configdRun('sensei reinstall sensei-agent');
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['error' => $e->getMessage()];
        }
    }
}
