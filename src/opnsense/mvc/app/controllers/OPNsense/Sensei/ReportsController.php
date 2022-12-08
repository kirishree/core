<?php

namespace OPNsense\Sensei;

use OPNsense\Core\ACL;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\Sensei;

class ReportsController extends \OPNsense\Base\IndexController

{
    public function indexAction()
    {
        $opnsense_version = trim(shell_exec('opnsense-version | awk \'{ print $2 }\''));
        $logdir = '/usr/local/sensei/log/active';
        if (file_exists($logdir)) {
            ini_set('error_log', $logdir . '/Senseigui.log');
        }

        $sensei = new Sensei();
        $backend = new Backend();
        if (file_exists('/usr/local/opnsense/scripts/OPNsense/Sensei/.first_check')) {
            unlink('/usr/local/opnsense/scripts/OPNsense/Sensei/.first_check');
            # $backend->configdRun('sensei check-updates');
            system('/usr/local/sbin/configctl sensei check-updates>/dev/null 2>&1 &');
        }
        $config = Config::getInstance()->object();
        $this->view->opnsense_version = $opnsense_version;
        $this->view->language = $config->system->language;
        $this->view->theme = $config->theme;
        $this->view->wizardRequired = file_exists($sensei->configDoneFile) ? 'false' : 'true';
        #grants check
        $grant = 'page-sensei-dashboard';
        if ((new ACL())->hasPrivilege($_SESSION['Username'], 'page-all')) {
            $grant = 'page-all';
        }
        $this->view->grant = $grant;

        $licenseJson = $backend->configdRun('sensei license-details');
        $licenseArr = (array) json_decode($licenseJson);
        $support = 'false';
        $supportPlan = 'Basic';
        $this->view->premium_plan = 'Free';
        $this->view->premium = 'false';
        $this->view->redirect = 'reports';
        $this->view->license_plan = '';
        $this->view->license_extdata = '';
        $this->view->license_size = 0;
        $this->view->license_key = '';
        $this->view->partner_id = '';
        $this->view->partner_name = '';
        if (file_exists($sensei->config->files->partner)) {
            try {
                $arr = json_decode(file_get_contents($sensei->config->files->partner));
                if (isset($arr->id)) {
                    $this->view->partner_id = $arr->id;
                }
                if (isset($arr->name)) {
                    $this->view->partner_name = $arr->name;
                }
            } catch (\Exception $e) {
            }
        }
        if ($licenseArr['premium'] && ((int) $licenseArr['expire_time'] + 1209600) > time()) {
            $support = 'true';
            $this->view->premium = $licenseArr['premium'] ? 'true' : 'false';
            $this->view->license_extdata = $licenseArr['extdata'];
            $this->view->premium_plan = $sensei::license_list[$licenseArr['plan']];
            $this->view->license_plan = $licenseArr['plan'];
            $this->view->license_size = $licenseArr['premium'] ? intval($licenseArr['size']) : 0;
            $this->view->license_key = $licenseArr['activation_key'];
            if ($licenseArr['plan'] == 'opnsense_soho') {
                $supportPlan = 'Forum';
            }
        }
        if (file_exists($sensei->config->files->isoconfig)) {
            try {
                $isoconfig = file_get_contents($sensei->config->files->isoconfig);
                $isoconfig = json_decode($isoconfig, true);
                $sensei->logger('there is isoconfig: ' . var_export($isoconfig, true));
                $sensei->setNodes(['general' => ["database" => [
                    'Type' => $isoconfig['db'],
                ]]]);
                $sensei->saveChanges();
                if (!empty($isoconfig['device'])) {
                    $sensei->logger('isconfig device settings....');
                    $result = $backend->configdRun('sensei set-interface ' . $isoconfig['device']);
                    $sensei->logger('isconfig device settings....result ..' . $result);
                }
                $sensei->logger('isconfig database settings...for: ' . $isoconfig['db']);
                if ($isoconfig['db'] == 'ES' || $isoconfig['db'] == 'MN') {
                    $service_status = $backend->configdRun('sensei service ' . $sensei::reportDatabases[$isoconfig['db']]['service'] . ' status');
                    if (strpos($service_status, 'is running') === false) {
                        $sensei->logger('isconfig database ' . $sensei::reportDatabases[$isoconfig['db']]['service'] . ' status is ' . $service_status);
                        $backend->configdRun('sensei service ' . $sensei::reportDatabases[$isoconfig['db']]['service'] . ' restart');
                        sleep(5);
                    }
                }
                $result = $backend->configdRun('sensei datastore-retire ' . $isoconfig['db'], true);
                $sensei->logger('isconfig database settings...result: ' . $result);
                $sensei->logger('check cloud-nodes status...');
                $backend->configdRun('sensei nodes-status rewrite');
                unlink($sensei->config->files->isoconfig);
            } catch (\Exception $e) {
                $sensei->logger(__METHOD__ . ' Exception: ' . $e->getMessage());
            }
        }
        if (file_exists($sensei->config->files->support)) {
            try {
                $arr = json_decode(file_get_contents($sensei->config->files->support));
                if (isset($arr->expires_at)) {
                    if (strtotime($arr->expires_at) + 1209600 > time()) {
                        $support = 'true';
                    }
                }
                if (isset($arr->plan)) {
                    $supportPlan = ucfirst($arr->plan);
                }
            } catch (\Exception $e) {
            }
        }
        $this->view->support = $support;
        $this->view->dbtype = (string) $sensei->getNodeByReference('general.database.Type');
        $this->view->dbversion = (string) $sensei->getNodeByReference('general.database.Version');
        $this->view->supportPlan = $supportPlan;
        $this->response->setHeader("Content-Security-Policy", "default-src 'self' https://sunnyvalley.cloud; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://sunnyvalley.cloud; connect-src 'self' https://sunnyvalley.cloud; img-src *; style-src 'self' 'unsafe-inline' 'unsafe-eval';");
        $this->view->pick('OPNsense/Sensei/index');
    }
}
