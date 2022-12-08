<?php


namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);
use OPNsense\Auth\AuthenticationFactory;
use \OPNsense\CaptivePortal\Api\AccessController;
use OPNsense\CaptivePortal\CaptivePortal;
use OPNsense\Core\Backend;


class CaptivesenseiController extends  AccessController
{
    /**
     * determine clients ip address
     */

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    private function getClientIp()
    {
        // determine orginal sender of this request
        $trusted_proxy = array(); // optional, not implemented
        if (
            $this->request->getHeader('X-Forwarded-For') != "" &&
            (explode('.', $this->request->getClientAddress())[0] == '127' ||
                in_array($this->request->getClientAddress(), $trusted_proxy))
        ) {
            // use X-Forwarded-For header to determine real client
            return $this->request->getHeader('X-Forwarded-For');
        } else {
            // client accesses the Api directly
            return $this->request->getClientAddress();
        }
    }


    public function indexAction()
    {
        return ["message" => "Your Welcome"];
    }

    public function SenseiCaptiveLogonAction($zoneid = 0)
    {
        $clientIp = $this->getClientIp();
        return ['clientIp' => $clientIp];
    }


    public function FakelogonAction($zoneid = 0)
    {
        try {
            $clientIp = $this->getClientIp();
            if ($this->request->isOptions()) {
                // return empty result on CORS preflight
                return array();
            } elseif ($this->request->isPost()) {
                // close session for long running action
                $this->sessionClose();
                // init variables for authserver object and name
                $authServer = null;
                $authServerName = "";

                // get username from post
                $userName = $this->request->getPost("user", "striptags", null);

                // search zone info, to retrieve list of authenticators
                $mdlCP = new CaptivePortal();
                $cpZone = $mdlCP->getByZoneID($zoneid);
                $this->getLogger("captiveportal")->info("Zone : $zoneid");
                if ($cpZone != null) {
                    if (trim((string) $cpZone->authservers) != "") {
                        // authenticate user
                        $isAuthenticated = false;
                        $authFactory = new AuthenticationFactory();
                        foreach (explode(',', (string) $cpZone->authservers) as $authServerName) {
                            $authServer = $authFactory->get(trim($authServerName));
                            if ($authServer != null) {
                                // try this auth method
                                $isAuthenticated = $authServer->authenticate(
                                    $userName,
                                    $this->request->getPost("password", "string")
                                );

                                // check group when group enforcement is set
                                if ($isAuthenticated && (string) $cpZone->authEnforceGroup != "") {
                                    $isAuthenticated = $authServer->groupAllowed($userName, $cpZone->authEnforceGroup);
                                }
                                if ($isAuthenticated) {
                                    // stop trying, when authenticated
                                    break;
                                }
                            }
                        }
                    } else {
                        // no authentication needed, set username to "anonymous@ip"
                        $userName = "anonymous@" . $clientIp;
                        $isAuthenticated = true;
                    }

                    if ($isAuthenticated) {
                        $this->getLogger("captiveportal")->info("AUTH " . $userName .  " (" . $clientIp . ") zone " . $zoneid);
                        // when authenticated, we have $authServer available to request additional data if needed
                        /*
                     * {"zoneid":"0",
                     * "authenticated_via":"Local Database",->$authServerName
                     * "userName":"test002", $username
                     * "ipAddress":"192.168.122.1", -> getClientIp()
                     * "macAddress":"52:54:00:59:bf:4e", shell('/usr/sbin/arp -an | grep 50.238.98.129 | awk '{print $4 }')
                     * "startTime":1567809210.353249, ->time.time()
                     * "sessionId":"6rp1BmnyhSAUPQGyRqhHjg==",base64.b64encode(os.urandom(16)).decode()
                     * "clientState":"AUTHORIZED"}
                     */
                        $macAddress = trim(shell_exec('/usr/sbin/arp -an | grep "(' . $clientIp . ')" | awk \'{print $4 }\''));
                        return [
                            "zoneid" => $zoneid, "authenticated_via" => $authServerName, "userName" => $userName,
                            "ipAddress" => $clientIp, "macAddress" => $macAddress, "startTime" => time(),
                            "sessionId" => base64_encode(uniqid(time())), "clientState" => "AUTHORIZED"
                        ];
                    } else {
                        $this->getLogger("captiveportal")->info("DENY " . $userName .  " (" . $clientIp . ") zone " . $zoneid);
                        return array("clientState" => 'NOT_AUTHORIZED', "ipAddress" => $clientIp);
                    }
                }
            }

            return array("clientState" => 'UNKNOWN', "ipAddress" => $clientIp);
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            return array("clientState" => 'UNKNOWN', "ipAddress" => '');
        }
    }
}
