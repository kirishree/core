<?php

namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\Sensei;

class BackupController extends ApiControllerBase
{
    const root_dir = "/usr/local/bpsensei/";

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function indexAction()
    {
        //get backup folder & list
    }
    public function setPathAction()
    {
        try {
            if ($this->request->getMethod() == 'POST') {
                $path = $this->request->getPost('path', null, '');
                if ($path == '') {
                    return ['error' => 'Backup path shouldn\'t be empty'];
                }

                $sensei = new Sensei();
                if (file_exists($path)) {
                    if (!is_dir($path)) {
                        return ['error' => sprintf('% is file not folder', $path)];
                    }
                    if (!is_writable($path)) {
                        return ['error' => sprintf('%, folder is not writable', $path)];
                    }
                } else {
                    $ret = mkdir($path, 755, true);
                    if ($ret === false) {
                        return ['error' => sprintf('%, folder could not create', $path)];
                    }
                }
                $node = $sensei->getNodeByReference('general');
                $node->setNodes(['backupPath' => $path]);
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }
        }
    }

    private function deleteTemp($path)
    {
        try {
            array_map('unlink', glob("$path/*"));
            rmdir($path);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }
        }
    }
    public function takeBackupAction()
    {
        $sensei = new Sensei();
        $config_xml = '/conf/config.xml';
        $worker_map = '/usr/local/sensei/etc/workers.map';
        $eastpect_cfg = '/usr/local/sensei/etc/eastpect.cfg';
        $settingsdb_path = '/usr/local/sensei/userdefined/config/settings.db';
        $msg = 'it could not take backup';
        try {
            $path_backup_root = (string) $sensei->getNodeByReference('general.backupPath');
            $buffer = file_get_contents($config_xml);
            $xml = simplexml_load_string($buffer);
            $json = json_encode((array) $xml->OPNsense->Sensei);
            $time = time();
            $path = $path_backup_root . '/' . $time;
            mkdir($path, 755, true);
            $size = file_put_contents($path . '/config.xml', $json);
            if ($size == 0) {
                $this->deleteTemp($path);
                return ['error' => $msg . ' from config.xml'];
            }
            $ret = copy($worker_map, $path . '/workers.map');
            if ($ret === false) {
                $this->deleteTemp($path);
                return ['error' => $msg . ' from workers'];
            }

            $ret = copy($eastpect_cfg, $path . '/eastpect.cfg');
            if ($ret === false) {
                $this->deleteTemp($path);
                return ['error' => $msg . ' from Zenarmor engine configuration'];
            }
            $ret = copy($settingsdb_path, $path . '/settings.db');
            if ($ret === false) {
                $this->deleteTemp($path);
                return ['error' => $msg . ' from policies'];
            }
            exec("tar -cvf $path_backup_root/$time.tar $path/*", $output, $retval);
            if ($retval != 0) {
                return ['error' => $msg . ':tar error ' . implode(' ', $output)];
            }
            exec("gzip $path_backup_root/$time.tar", $output, $retval);
            if ($retval != 0) {
                return ['error' => $msg . ':gzip error ' . implode(' ', $output)];
            }
            return ['error' => 0];
        } catch (\Exception $e) {
            return ['error' => $msg . ': ' . $e->getMessage()];
            $sensei->logger('Error while take backup');
        }
    }
    public function backupAction()
    {
        try {
            $backend = new Backend();
            $pass = $this->request->getPost('pass', null, '');
            $result = $backend->configdRun("sensei backup " . $pass);
            return trim($result);
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return [];
        }
    }

    public function backupListAction()
    {
        try {
            $file_list = glob(self::root_dir . "/*.gz*");
            usort($file_list, function ($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            $file_list = array_reverse($file_list, true);
            $response = [];
            foreach ($file_list as $path) {
                $fname = basename($path);
                if (preg_match('/^sensei-backup-(.*)-(\d+)\.(.*)$/', $fname, $matches)) {
                    $tmp = ['enc' => substr($fname, -3) == 'enc' ? true : false, 'fname' => $fname, 'date' => date('Y-m-d H:i:s', $matches[2]), 'upload' => false];
                    $response[] = $tmp;
                }
                if (preg_match('/^(\d+)\.(.*)$/', $fname, $matches)) {
                    $tmp = ['enc' => substr($fname, -3) == 'enc' ? true : false, 'fname' => $fname, 'date' => date('Y-m-d H:i:s', $matches[1]), 'upload' => false];
                    $response[] = $tmp;
                }
            }
            return ['list' => $response];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['list' => []];
        }
    }
    public function backupDeleteAction()
    {
        try {
            $file_name = $this->request->getPost('file_name', null, '');
            $path = self::root_dir . $file_name;
            $tmpdir = self::root_dir . 'tmp/';
            if (file_exists($path)) {
                unlink($path);
                if (file_exists($tmpdir)) {
                    shell_exec("rm -rf $tmpdir");
                }

                return 'OK';
            }
            return 'Error';
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return 'error';
        }
    }

    public function downloadAction()
    {
        try {
            $file_name = $this->request->getPost('file_name', null, '');
            $path = self::root_dir . $file_name;
            if (file_exists($path)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=' . $file_name);
                readfile($path);
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            print '';
        }
    }

    public function uploadBackupAction()
    {
        try {
            $sensei = new Sensei();
            $path = ini_get('upload_tmp_dir') . DIRECTORY_SEPARATOR . $_FILES['file']['name'];
            $ret = move_uploaded_file($_FILES['file']['tmp_name'], $path);
            $ret_message = 'OK';
            if (!$ret) {
                $ret_message = 'File could not uploaded successfully -> ' . $_FILES["file"]["error"];
            }
            $sensei->logger('Backup file upload is ' . $path . ' Return:' . $ret_message);
            return $ret ? 'OK' : 'NOK';
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return 'error';
        }
    }

    private function checkArrforEmpty(&$node)
    {
        try {
            if (is_array($node)) {
                foreach ($node as $k => &$v) {
                    if (is_array($v)) {
                        if (count($v) == 0) {
                            $node[$k] = '';
                        } else {
                            $this->checkArrforEmpty($v);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }
        }
    }

    private function xmlRestore($file, $licenseExclude = 'false', $cloudExclude = 'false')
    {
        $sensei = new Sensei();
        try {
            $sensei->logger('XML restore with ' . $file);
            $buffer = file_get_contents($file);
            $xml = simplexml_load_string($buffer);
            $data = json_decode(json_encode((array) $xml->OPNsense->Sensei), true);
            $config = [];
            foreach ($data as $key => $val) {
                if ($key != '@attributes') {
                    $config[$key] = $val;
                }
            }
            $sensei->logger('preapared xml data');
            unset($config['general']['database']['Type']);
            if ($licenseExclude == 'true') {
                $sensei->logger('license will be not load');
                unset($config['general']['license']);
            }
            if ($cloudExclude == 'true') {
                $sensei->logger('Cloud Settings will be not load');
                unset($config['general']['CloudManagementEnable']);
                unset($config['general']['CloudManagementAdmin']);
                unset($config['general']['CloudManagementUUID']);
            }
            $this->checkArrforEmpty($config);
            $sensei->setNodes($config);
            $sensei->logger('loading new configuration');
            $sensei->saveChanges();
            return true;
        } catch (Exception $e) {
            $sensei->logger('Error XML restore ' . $e->getMessage());
            return false;
        }
    }

    private function checkDb()
    {
        $sensei = new Sensei();
        if (!$sensei->databaseStatus) {
        }
    }

    private function dbRestore($backend, $file, $option = 'all')
    {
        $sensei = new Sensei();
        $ts = time();
        $sensei->logger("DB restore: Timestamp is $ts.");
        try {
            $curr_database = $sensei->config->database;
            if ($option == 'all') {
                $sensei->database->close();
                // $ret_val = copy($file, $curr_database);
                $result = $backend->configdRun("sensei restore-db " . $file . " $ts");
                // exec("cp $file ".$sensei->config->database,$output,$ret_val);
                return [trim($result) == 'OK', $result, $ts];
            }
            if ($option == 'rule') {
                $sensei->database->close();
                $result = $backend->configdRun("sensei restore-db-rules " . $file . " $ts");
                return [strpos('Error', $result) === false ? true : false, $result, $ts];
            }
            /*
        if ($option == 'policies') {
        $sensei->database->close();
        $result = $backend->configdRun("sensei restore-db-policies " . $file." $ts");
        return strpos('Error', $result) === false ? true : false;
        }
         */
        } catch (Exception $e) {
            $sensei->logger('Error DB restore ' . $e->getMessage());
            return [false, '', $ts];
        }
    }

    public function restoreAction()
    {
        try {
            $backend = new Backend();
            $sensei = new Sensei();
            $diff_iflist = [];
            $sensei->logger('Restore Action starting.');
            $enc = $this->request->getPost('enc', null, 'false');
            $pass = $this->request->getPost('pass', null, '');
            $fname = $this->request->getPost('fname', null, '');
            $upload = $this->request->getPost('upload', null, 'false');
            $option = $this->request->getPost('option', null, 'all');
            $force = $this->request->getPost('force', null, 'false');
            $licenseExclude = $this->request->getPost('licenseExclude', null, 'true');
            $cloudExclude = $this->request->getPost('cloudExclude', null, 'true');

            if ($upload == 'false') {
                $path = self::root_dir . $fname;
            } else {
                $path = ini_get('upload_tmp_dir') . DIRECTORY_SEPARATOR . $fname;
            }

            if (!file_exists($path)) {
                $sensei->logger('Backup file not exists.Filename is ' . $path);
                return ['force' => false, 'error' => 'Backup file not exists.', 'diff_list' => $diff_iflist];
            }

            if (substr($path, -4) == '.enc' && empty($pass)) {
                $sensei->logger('You should enter a password.');
                return ['force' => false, 'error' => 'You should enter a password.', 'diff_list' => $diff_iflist];
            }
            $result = $backend->configdRun("sensei restore $path $licenseExclude $cloudExclude $enc $pass");
            $sensei->logger("sensei restore $path $licenseExclude $cloudExclude $enc");
            if (strpos($result, 'Error') !== false) {
                $sensei->logger($result);
                return ['force' => false, 'error' => $result, 'diff_list' => $diff_iflist];
            }

            $list = explode(PHP_EOL, $result);
            foreach ($list as $fbackup) {
                $file = basename($fbackup);
                if ($file == 'config.xml' && $option == 'all') {
                    if (!$this->xmlRestore($fbackup, $licenseExclude, $cloudExclude)) {
                        $sensei->logger('Configuration could not loaded');
                        return ['force' => false, 'error' => 'Configuration could not loaded', 'diff_list' => $diff_iflist];
                    }
                }
                if ($file == 'settings.db') {
                    $curr_version = preg_replace('/\R+/', '', $backend->configdRun('sensei engine-version'));
                    $curr_version = explode('.', $curr_version);
                    $curr_version = $curr_version[0] . (isset($curr_version[1]) ? '.' . $curr_version[1] : '');
                    $database = new \SQLite3($fbackup);
                    $backup_version = '0';
                    $rows = $database->query('select version from sensei_version order by id desc limit 1');
                    while ($row = $rows->fetchArray($mode = SQLITE3_ASSOC)) {
                        $backup_version = $row['version'];
                    }
                    $backup_version = explode(',', $backup_version);
                    $backup_version = $backup_version[0] . (isset($backup_version[1]) ? '.' . $backup_version[1] : '');
                    if ($force == 'false' && version_compare($backup_version, $curr_version) != 0) {
                        return ['force' => true, 'error' => sprintf("Major version mismatch. Backup version: %s and Current version: %s", $backup_version, $curr_version), 'diff_list' => $diff_iflist];
                    }
                    $tools = new ToolsController();
                    $iflist = $tools->interfacesAction(0);
                    $rows = $database->query('select lan_interface,wan_interface from interface_settings');
                    $backup_iflist = [];
                    while ($row = $rows->fetchArray($mode = SQLITE3_ASSOC)) {
                        if (!empty($row['lan_interface'])) {
                            $backup_iflist[] = $row['lan_interface'];
                        }
                        if (!empty($row['wan_interface'])) {
                            $backup_iflist[] = $row['wan_interface'];
                        }
                    }
                    foreach ($backup_iflist as $b_i) {
                        $exists = false;
                        foreach ($iflist as $c_i) {
                            if ($c_i['interface'] == $b_i) {
                                $exists = true;
                            }
                        }
                        if (!$exists) {
                            $diff_iflist[] = $b_i;
                        }
                    }

                    $ret = $this->dbRestore($backend, $fbackup, $option);
                    if (!$ret[0]) {
                        $sensei->logger('Database could not loaded');
                        return ['force' => false, 'error' => 'Database could not loaded, ' . $ret[1], 'diff_list' => $diff_iflist];
                    }

                    $sensei = new Sensei();
                    if ($sensei->databaseStatus == false) {
                        $sensei->logger("Error DB restore: Database could'nt open. Copying last database.");
                        $msg = "Database could'nt open. Copying last database.";
                        $result = $backend->configdRun("sensei restore-db-copy " . $sensei->config->database . ".$ret[2] " . $sensei->config->database);
                        if (strpos('error', $result) !== false) {
                            $msg = "it could'nt copy last database. Please do it manuel. Database name is " . $sensei->config->database . "." . $ret[2];
                            $sensei->logger("it could'nt copy last database. Please do it manuel. Database name is " . $sensei->config->database . "." . $ret[2]);
                        } else {
                            return ['force' => false, 'error' => 'Database could not loaded, ' . $msg, 'diff_list' => $diff_iflist];
                        }
                    }
                }
            }

            if ($licenseExclude == 'false') {
                $sensei->logger('License file loading');
                $response['output'] = $backend->configdRun('sensei license-verify');
                $response['valid'] = strpos($response['output'], 'License OK') !== false;
                if ($response['valid']) {
                    $sensei->logger('License file valid');
                    rename('/tmp/sensei-license.data', $sensei->licenseData);
                    $licenseDetails = json_decode($backend->configdRun('sensei license-details'));
                    if ($licenseDetails->premium != true || ((int) $licenseDetails->expire_time + 1209600) < time()) {
                        if (file_exists($sensei->licenseData)) {
                            unlink($sensei->licenseData);
                        }
                    }

                    $backend->configdRun('sensei license');
                } else {
                    $sensei->logger('License file invalid!!!');
                }
            }
            return ['force' => false, 'error' => '', 'diff_list' => $diff_iflist];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['force' => false, 'error' => $e->getMessage(), 'diff_list' => $diff_iflist];
        }
    }

    public function loadRestoreAction()
    {
        try {

            $backend = new Backend();
            $backend->configdRun('template reload OPNsense/Sensei');
            $backend->configdRun('sensei worker reload');
            $backend->configdRun('sensei policy reload');
            //        $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
            $backend->configdRun('sensei service eastpect restart');
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }

    public function backupTempFolderAction()
    {
        try {
            $backend = new Backend();
            $output = $backend->configdRun('sensei backup-temp-folder');
            if ($output == 'error') {
                return ['error' => 'could not backup temp folder'];
            }

            return ['error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }
            return ['error' => $e->getMessage()];
        }
    }

    public function restoreTempFolderAction()
    {
        try {
            $backend = new Backend();
            $output = $backend->configdRun('sensei restore-temp-folder');
            if ($output == 'error') {
                return ['error' => 'could not restore temp folder'];
            }
            return ['error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }
    public function migrateSettingsAction()
    {
        try {
            $backend = new Backend();
            $senpai_status = $backend->configdRun('sensei service senpai status');
            if (strpos($senpai_status, 'is running') !== false) {
                $output = $backend->configdRun('sensei senpai stop');
            }

            //$output = $backend->configdRun('webgui restart');
            $output_migrate = $backend->configdRun('sensei migrate-settings');

            if (strpos($senpai_status, 'is running') !== false) {
                $output = $backend->configdRun('sensei senpai start');
            }

            if (strpos($output_migrate, "ERROR") !== false) {
                return ['error' => $output_migrate];
            }
            return ['error' => ''];
        } catch (\Exception $e) {
            if (file_exists(self::log_file)) {
                file_put_contents(self::log_file, __METHOD__ . ' ::Exception:: ' . $e->getMessage(), FILE_APPEND);
            }

            return ['error' => $e->getMessage()];
        }
    }
}
