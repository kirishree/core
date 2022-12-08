#!/usr/local/bin/php
<?php

require_once 'script/load_phalcon.php';

use Phalcon\Config\Adapter\Ini as ConfigIni;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Sensei\Api\CronController;
use \OPNsense\Sensei\Sensei;

$version = "1.11";
# error_reporting(E_ERROR);

function migrateDatabase($sensei)
{
    echo "Report Mail Configuration Checking ...";
    try {
        $dbtype = (string) $sensei->getNodeByReference('general.databaseType');
        if (!empty($dbtype)) {
            $sensei->setNodes(['general' => ["database" => [
                'Type' => $dbtype,
            ]]]);
        }
    } catch (Exception $th) {
    }

    $password = (string) $sensei->getNodeByReference('reports.generate.mail.password');
    if (!empty($password)) {
        if (substr($password, 0, 4) != 'b64:') {
            $sensei->getNodeByReference('reports.generate.mail')->setNodes(['password' => 'b64:' . base64_encode(html_entity_decode($password))]);
        }
    }

    $password = (string) $sensei->getNodeByReference('general.database.Pass');
    if (!empty($password)) {
        if (substr($password, 0, 4) != 'b64:') {
            $sensei->getNodeByReference('general')->setNodes(['Pass' => 'b64:' . base64_encode(html_entity_decode($password))]);
        }
    }

    $password = (string) $sensei->getNodeByReference('streamReportDataExternal.Pass');
    if (!empty($password)) {
        if (substr($password, 0, 4) != 'b64:') {
            $sensei->getNodeByReference('streamReportDataExternal')->setNodes(['Pass' => 'b64:' . base64_encode(html_entity_decode($password))]);
        }
    }
    echo "done\n";
    echo "Web category migration ...";
    $nodes = $sensei->getNodeByReference('rules.webcategories')->getNodes();
    foreach ($nodes as $uuid => $node) {
        if ($node['action'] == 'reject') {
            $stmtIn = $sensei->database->prepare('UPDATE policy_web_categories set action=:action,uuid=:uuid where policy_id=0 and web_categories_id in (select id from web_categories where name=:name)');
            $stmtIn->bindValue(':name', $node['name']);
            $stmtIn->bindValue(':uuid', $uuid);
            $stmtIn->bindValue(':action', $node['action']);
            $stmtIn->execute();
        }
    }
    echo "done\n";
    echo "Custom web category migration ...";
    $nodes = $sensei->getNodeByReference('rules.customwebcategories')->getNodes();
    $nodes_sites = $sensei->getNodeByReference('rules.customwebrules')->getNodes();
    foreach ($nodes as $uuid => $rule) {
        $stmtIn = $sensei->database->prepare('select c.id,c.name from policy_custom_web_categories p,custom_web_categories c where p.custom_web_categories_id=c.id and policy_id=0 and c.name=:name');
        $stmtIn->bindValue(':name', $rule['name']);
        $results = $stmtIn->execute();
        $cat_row = $results->fetchArray($mode = SQLITE3_ASSOC);
        if ($cat_row == false) {
            $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
            $stmtIn->bindValue(':name', $rule['name']);
            $stmtIn->bindValue(':uuid', $uuid);
            $stmtIn->bindValue(':action', $rule['action']);
            $stmtIn->execute();
            $cat_row = [];
            $cat_row['id'] = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);
            $stmt = $sensei->database->prepare('INSERT INTO policy_custom_web_categories (policy_id,custom_web_categories_id) VALUES(0,:custom_web_categories_id)');
            $stmt->bindValue(':custom_web_categories_id', $cat_row['id']);
            $stmt->execute();
        }
        foreach ($nodes_sites as $s_uuid => $s_node) {
            if ($s_node['catid'] == $uuid) {
                $stmt = $sensei->database->prepare('select count(*) as total from custom_web_category_sites where custom_web_categories_id=:custom_web_categories_id and site=:site');
                $stmt->bindValue(':custom_web_categories_id', $cat_row['id']);
                $stmt->bindValue(':site', $s_node['site']);
                $results = $stmt->execute();
                $row = $results->fetchArray($mode = SQLITE3_ASSOC);
                if ($row['total'] === 0) {
                    $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(custom_web_categories_id,site,uuid) VALUES(:custom_web_categories_id,:site,:uuid)');
                    $stmtIn->bindValue(':custom_web_categories_id', $cat_row['id']);
                    $stmtIn->bindValue(':site', $s_node['site']);
                    $stmtIn->bindValue(':uuid', $s_uuid);
                    $stmtIn->execute();
                }
            }
        }
    }
    echo "done\n";
    echo "Appications category migration ...";
    $appCatNodes = $sensei->getNodeByReference('rules.apps')->getNodes();
    foreach ($appCatNodes as $uuid => $node) {
        if ($node['action'] == 'reject' && $node['web20'] == 'no') {
            $stmtIn = $sensei->database->prepare('UPDATE policy_app_categories set action=:action,uuid=:uuid where policy_id=0 and application_id in (select id from applications where name=:name)');
            $stmtIn->bindValue(':name', $node['name']);
            $stmtIn->bindValue(':uuid', $uuid);
            $stmtIn->bindValue(':action', $node['action']);
            $stmtIn->execute();
        }
    }

    $node = (string) $sensei->getNodeByReference('rules.webcategoriesType');
    if ($node != '') {
        $stmtIn = $sensei->database->prepare('UPDATE policies set webcategory_type=:webcategory_type where id=0');
        $stmtIn->bindValue(':webcategory_type', $node);
        $stmtIn->execute();
    }
    $sensei->saveChanges();
    $doc = new DOMDocument;
    $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xpath = new DOMXPath($doc);
    while (($rules = $xpath->query("/opnsense/OPNsense/Eastpect"))->count() != 0) {
        foreach ($rules as $rule) {
            $rule->parentNode->removeChild($rule);
        }
    }
    while (($rules = $xpath->query("/opnsense/OPNsense/Sensei/rules"))->count() != 0) {
        foreach ($rules as $rule) {
            $rule->parentNode->removeChild($rule);
        }
    }

    $doc->save('/conf/config.xml');

    // $sensei->saveChanges();
    echo "done\n";
}

function manageOnBoot($sensei, $backend, $service, $option)
{
    if (!in_array($option, ['enable', 'disable'])) {
        exit("You can only \"enable\" or \"disable\" this option!\n");
    } else {
        echo "Saving changes...\n";
        $sensei->getNodeByReference('onboot')->setNodes([
            $service => $option == 'enable' ? 'YES' : 'NO',
        ]);
        $sensei->saveChanges();
        echo "Re-generating configuration files...\n";
        $backend->configdRun('template reload OPNsense/Sensei');
        echo "You have " . $option . " auto-start of Zenarmor packet engine.\n";
    }
}

function manageCrons($cronController, $option)
{
    if (!in_array($option, ['configure', 'remove'])) {
        exit("You can only configure or remove cron jobs!\n");
    } else {
        if ($option == 'configure') {
            $response = $cronController->configureAction();
            echo "Cron jobs created: " . $response['created'] . "\n";
            echo "Cron jobs edited: " . $response['changed'] . "\n";
        } else {
            $response = $cronController->removeAllAction();
        }
        echo "Cron jobs deleted: " . $response['deleted'] . PHP_EOL;
    }
}

function setSysctl($mode)
{
    try {
        # Zenarmor work with netmap genetik/native driver
        $doc = new \DOMDocument;
        $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $xpath = new \DOMXPath($doc);
        $items = $xpath->query("/opnsense/sysctl/item");
        $tunable = 'dev.netmap.admode';
        $value = '0';
        if ($mode == 'routedG') {
            $value = '2';
        }

        $descr = 'Automatically added by Sensei: Netmap Generic/Native Driver';
        foreach ($items as $item) {
            foreach ($item->childNodes as $child) {
                if ($child->nodeName == 'tunable' && $child->nodeValue == $tunable) {
                    $item->parentNode->removeChild($item);
                }
            }
        }
        if ($value != '0') {
            $itemNode = $doc->createElement('item');
            $tunableEl = $doc->createElement('tunable', $tunable);
            $valueEl = $doc->createElement('value', $value);
            $descrEl = $doc->createElement('descr', $descr);
            $itemNode->appendChild($tunableEl);
            $itemNode->appendChild($valueEl);
            $itemNode->appendChild($descrEl);
            $xpath->query("/opnsense/sysctl")[0]->appendChild($itemNode);
        }
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $doc->save('/conf/config.xml');
        #load set sysctl new params
        exec("/sbin/sysctl -i $tunable=$value ", $output, $success);
        if ($success == 0) {
            print("Changed dev.netmap.admode with $value");
        } else {
            print("did not change dev.netmap.admode with $value");
        }
    } catch (\Exception $e) {
        print($e->getMessage());
    }
}

# Zenarmor and Suricata work together
function setBufSysctl()
{
    $memory = 0;
    $output = 0;
    exec("sysctl -a | grep dev.netmap.buf_num | awk '{ print $2 }'", $output, $ret_val);
    if ($ret_val == 0) {
        $output = preg_replace('/\R+/', '', $output[0]);
    }
    exec("sysctl -a |grep hw.realmem | awk '{ print $2 }'", $memory, $ret_val);
    if ($ret_val == 0) {
        $memory = preg_replace('/\R+/', '', $memory[0]);
    }
    echo "dev.netmap.buf_num: $output" . PHP_EOL;
    $value = '163840';

    if ($memory < 8000000000)
        return true;

    if ($memory > 8000000000 && $memory < 16000000000 && $output >= 500000)
        return true;

    if ($output == 1000000 && $memory > 16000000000)
        return true;

    if ($memory > 8000000000)
        $value = '500000';
    if ($memory > 16000000000)
        $value = '1000000';

    $doc = new DOMDocument;
    $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xpath = new DOMXPath($doc);
    $items = $xpath->query("/opnsense/sysctl/item");
    $tunable = 'dev.netmap.buf_num';
    //$value = '163840';
    $descr = 'Automatically added by Zenarmor: Max NETMAP buffers';
    $find = false;
    foreach ($items as $item) {
        foreach ($item->childNodes as $child) {
            if ($child->nodeName == 'tunable' && $child->nodeValue == $tunable) {
                $item->parentNode->removeChild($item);
            }
        }
    }
    $itemNode = $doc->createElement('item');
    $tunableEl = $doc->createElement('tunable', $tunable);
    $valueEl = $doc->createElement('value', $value);
    $descrEl = $doc->createElement('descr', $descr);
    $itemNode->appendChild($tunableEl);
    $itemNode->appendChild($valueEl);
    $itemNode->appendChild($descrEl);
    $xpath->query("/opnsense/sysctl")[0]->appendChild($itemNode);
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    $doc->save('/conf/config.xml');
    #load set sysctl new params
    exec("/sbin/sysctl -i $tunable=$value ", $output, $success);
    if ($success == 0) {
        print "Setting dev.netmap.buf_num..." . PHP_EOL;
    } else {
        print "doesn't set sysctl config. please check $tunable configuration. must be $value." . PHP_EOL;
    }
}

function resetConfiguration($sensei, $backend)
{
    foreach ($sensei->getNodeByReference('interfaces')->getNodes() as $key => $interface) {
        $sensei->getNodeByReference('interfaces')->del($key);
        echo $interface['name'] . " interface removed from protected interfaces.\n";
    }
    $backend->configdRun('sensei service sensei stop');
    system('sqlite3 /usr/local/sensei/userdefined/config/settings.db < /usr/local/sensei/scripts/database/templates/resetfactory.sql', $return_var);
    $sensei->setNodes([
        'general' => [
            'coreFileEnable' => 'false',
            'flavor' => 'small',
            'healthCheck' => 'true',
            'CloudManagementEnable' => 'false',
            'CloudManagementAdmin' => '',
            'CloudManagementUUID' => '',
            'swapRate' => 60,
            'database' => [
                'Port' => 9200,
                'Host' => 'http://127.0.0.1',
                'Version' => '56800',
                'Remote' => 'false',
                'User' => '',
                'Pass' => '',
                'Prefix' => '',
                'ClusterUUID' => '',
                'retireAfter' => '7',
            ],
        ],
        'shun' => [
            'networks' => '',
            'vlans' => '',
        ],
        'netflow' => [
            'enabled' => 'false',
            'version' => '9',
            'collectorip' => '127.0.0.1',
            'collectorport' => '9996',
        ],
        'updater' => [
            'enabled' => 'true',
            'autocheck' => 'true',
            'lastupdate' => '',
        ],
        'onboot' => [
            'eastpect' => 'YES',
            'elasticsearch' => 'NO',
            'mongod' => 'NO',
        ],
        'reports' => [
            'refresh' => '60000',
            'interval' => '3600000',
            'custominterval' => [
                'start' => '',
                'end' => '',
            ],
            'sum' => 'sessions',
            'generate' => [
                'enabled' => 'false',
                'sum' => 'volume',
                'mail' => [
                    'server' => '127.0.0.1',
                    'port' => '25',
                    'secured' => 'false',
                    'username' => '',
                    'password' => '',
                    'to' => '',
                ],
            ],
        ],
        'dns' => [
            'localDomain' => 'intra.example.com',
        ],
        'tls' => [
            'enabled' => 'false',
            'certname' => '',
            'passtopsites' => 'false',
        ],
        'enrich' => [
            'tcpServiceEnable' => 'true',
            'tcpServiceIP' => '127.0.0.1',
            'cloudWebcatEnrich' => 'true',
        ],
        'streamReportDataExternal' => [
            'enabled' => 'false',
            'uri' => '',
            'server' => '',
            'port' => '9200',
            'esVersion' => '',
            'User' => '',
            'Pass' => '',
            'ClusterUUID' => '',
        ],
    ]);
    $sensei->saveChanges();
    $sensei->logger('Update policies table.');
    $stmt = $sensei->database->prepare('update policies set status=0 where  id>0');
    $stmt->execute();

    $backend->configdRun('template reload OPNsense/Sensei');
    $backend->configdRun('sensei worker reload');
    $backend->configdRun('sensei policy reload');
    $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
    $backend->configdRun('sensei service eastpect restart');

    echo "Re-generating configuration files...\n";
    $backend->configdRun('sensei service elasticsearch stop');
    $backend->configdRun('sensei service mongod stop');
    $backend->configdRun('sensei delete-data-folder ES');
    $backend->configdRun('sensei delete-data-folder MN');
    $backend->configdRun('template reload OPNsense/Sensei');
    $backend->configdRun('sensei worker reload');
    $backend->configdRun('sensei policy reload');
    $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
    $backend->configdRun('sensei license');
    $backend->configdRun('sensei service eastpect restart');
    if (file_exists($sensei->configDoneFile)) {
        unlink($sensei->configDoneFile);
    }
    echo "All configuration has been reset.\n";
}

function reloadEngine($sensei)
{
    $sensei->runCLI(['reload db', 'reload rules']);
}

function saveLoad($sensei, $backend)
{
    $sensei->saveChanges();
    $backend->configdRun('template reload OPNsense/Sensei');
}

function licenseDelete($sensei, $backend)
{
    $sensei->logger('License deleting.');
    if (file_exists($sensei->licenseData)) {
        $response['exists'] = true;
        unlink($sensei->licenseData);
        if (file_exists($sensei->config->files->support)) {
            unlink($sensei->config->files->support);
        }

        $sensei->logger('Deleting Shun settings.');
        $node = $sensei->getNodeByReference('shun');
        $node->setNodes([
            'networks' => '',
            'vlans' => '',
        ]);
        $sensei->getNodeByReference('general.license')->setNodes([
            'plan' => 'Freemium',
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
        # web categories change for freemium version
        $webcategoriesType = 'permissive';
        $stmt = $sensei->database->prepare('select w.uuid,c.name,w.action,w.policy_id,p.webcategory_type,c.is_security_category from policy_web_categories w,web_categories c,policies p
                                            where p.id=w.policy_id and w.web_categories_id = c.id  and w.policy_id =:policy_id order by c.name');
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

        $sensei->saveChanges();

        $sensei->logger('Update policies table.');
        $stmt = $sensei->database->prepare('update policies set status=0 where  id>0');
        $stmt->execute();

        $backend->configdRun('template reload OPNsense/Sensei');
        $backend->configdRun('sensei worker reload');
        $backend->configdRun('sensei policy reload');
        $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
        $backend->configdRun('sensei license');
        $backend->configdRun('sensei service eastpect restart');
        $response['successful'] = true;
    } else {
        $response['exists'] = false;
    }
    $sensei->logger('License deleted.');
}

function migrateWebcat($sensei, $backend)
{
    # web categories change for freemium version
    $license = $backend->configdRun('sensei license-details');
    $license = json_decode($license);
    $webcategoriesType = 'permissive';
    if ($license->premium) {
        $stmt = $sensei->database->prepare('select webcategory_type,id from policies');
        $results = $stmt->execute();
        while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
            $cat_row = $sensei->database->querySingle("select action,count(*) as total from policy_web_categories where policy_id=" . $row['id'] . " group by action order by action desc", true);
            if ($row['webcategory_type'] == 'permissive' && $cat_row['action'] == 'reject') {
                $stmtIn = $sensei->database->prepare("Update policies set webcategory_type='custom' where id=" . $row['id']);
                $stmtIn->execute();
            }
        }
    } else {
        $row = $sensei->database->querySingle("select action,count(*) as total from policy_web_categories where policy_id=0 and web_categories_id in (select id from web_categories c where is_security_category=0) group by action order by action desc", true);
        if ($row['action'] == 'reject') {
            $webcategoriesType = 'moderate';
        }
        $policy = $sensei->database->querySingle("select webcategory_type from policies where id=0");
        if ($policy != $webcategoriesType) {
            if ($policy == 'high') {
                $webcategoriesType = 'high';
            }

            $stmtIn = $sensei->database->prepare("Update policies set webcategory_type='$webcategoriesType' where id=0");
            $stmtIn->execute();
        }

        switch ($webcategoriesType) {
            case 'permissive':
                $stmtIn = $sensei->database->prepare("Update policy_web_categories set action='accept' where policy_id=0 and web_categories_id in (select id from web_categories c where is_security_category=0)");
                $stmtIn->execute();
                break;
            case 'moderate':
                $stmtIn = $sensei->database->prepare("Update policy_web_categories set action='accept' where policy_id=0 and web_categories_id in (select id from web_categories c where is_security_category=0)");
                $stmtIn->execute();
                $stmtIn = $sensei->database->prepare("Update policy_web_categories set action='reject' where policy_id=0 and web_categories_id in (select id from web_categories c where c.name in ('" . implode("','", Sensei::webcategory_list['moderate']) . "'))");
                $stmtIn->execute();
                break;
            case 'high':
                $stmtIn = $sensei->database->prepare("Update policy_web_categories set action='accept' where policy_id=0 and web_categories_id in (select id from web_categories c where is_security_category=0)");
                $stmtIn->execute();
                $stmtIn = $sensei->database->prepare("Update policy_web_categories set action='reject' where policy_id=0 and web_categories_id in (select id from web_categories c where c.name in ('" . implode("','", Sensei::webcategory_list['high']) . "'))");
                $stmtIn->execute();
                break;

            default:
                break;
        }
    }
    $backend->configdRun('template reload OPNsense/Sensei');
    $backend->configdRun('sensei worker reload');
    $backend->configdRun('sensei policy reload');
    exec("/usr/local/sensei/scripts/service.sh status | /usr/bin/grep -c 'is running'", $output, $return_var);
    if ($return_var == 0) {
        $output = intval($output[0]);
        if ($output > 0) {
            $sensei->runCLI(['reload shun networks none', 'reload shun vlans none', 'reload db', 'reload rules']);
        }
    }

    return true;
}

function config2db($sensei)
{
    $policyID = 0;
    $stmt = $sensei->database->prepare('SELECT * FROM web_categories');
    $results = $stmt->execute();

    //get all web categories
    $nodes = $sensei->getNodeByReference('rules.webcategories')->getNodes();
    $webcategories = [];
    foreach ($nodes as $uuid => $node) {
        $webcategories[$node['name']] = ['uuid' => $uuid, 'action' => $node['action']];
    }
    $nodes = $sensei->getNodeByReference('rules.customwebrules')->getNodes();
    $customWebcategories = [];
    foreach ($nodes as $uuid => $rule) {
        if (!is_array($customWebcategories[$rule['catid']])) {
            $customWebcategories[$rule['catid']] = [];
        }

        $customWebcategories[$rule['catid']][] = ['site' => $rule['site'], 'uuid' => $uuid];
    }

    if (count($webcategories) > 0) {
        while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
            if (isset($webcategories[$row['name']])) {
                $stmtIn = $sensei->database->prepare('INSERT INTO policy_web_categories (policy_id, web_categories_id, uuid, action) VALUES' .
                    '(:policy_id, :web_categories_id, :uuid, :action)');
                $stmtIn->bindValue(':policy_id', $policyID);
                $stmtIn->bindValue(':web_categories_id', $row['id']);
                $stmtIn->bindValue(':uuid', $webcategories[$row['name']]['uuid']);
                $stmtIn->bindValue(':action', $webcategories[$row['name']]['action']);
                $stmtIn->execute();
            }
        }
    }

    $sensei->logger('inserted policy web categories');
    $stmt = $sensei->database->prepare('SELECT * FROM applications');
    $results = $stmt->execute();

    //get all app categories
    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
        $stmtIn = $sensei->database->prepare('INSERT INTO policy_app_categories (policy_id, application_id, uuid,action ,writetofile) VALUES' .
            '(:policy_id, :application_id, :uuid, :action ,:writetofile)');
        $stmtIn->bindValue(':policy_id', $policyID);
        $stmtIn->bindValue(':application_id', $row['id']);
        $stmtIn->bindValue(':uuid', $sensei->generateUUID());
        $stmtIn->bindValue(':action', 'accept');
        $stmtIn->bindValue(':writetofile', 'on');
        $stmtIn->execute();
    }

    $sensei->logger('inserted policy web applications');
    // add one custom web category
    $nodes = $sensei->getNodeByReference('rules.customwebcategories')->getNodes();
    foreach ($nodes as $uuid => $rule) {
        $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_categories (name,uuid,action) VALUES(:name, :uuid,:action)');
        $stmtIn->bindValue(':name', $rule['name']);
        $stmtIn->bindValue(':uuid', $uuid);
        $stmtIn->bindValue(':action', $rule['name']);
        $stmtIn->execute();
        $sensei->logger('inserted policy custom web applications');
        $customWebID = $sensei->database->querySingle('select seq from sqlite_sequence where name="custom_web_categories"', false);

        //add custom web category to policies
        $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_web_categories(policy_id,custom_web_categories_id)
                                                  VALUES(:policy_id,:custom_web_categories_id)');
        $stmtIn->bindValue(':policy_id', $policyID);
        $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
        $stmtIn->execute();
        $sensei->logger('inserted policy custom web applications 2');
        foreach ($customWebcategories[$uuid] as $site) {
            $stmtIn = $sensei->database->prepare('INSERT INTO custom_web_category_sites(custom_web_categories_id,site,uuid)
                                    VALUES(:custom_web_categories_id,:site,:uuid)');
            $stmtIn->bindValue(':custom_web_categories_id', $customWebID);
            $stmtIn->bindValue(':site', $site['site']);
            $stmtIn->bindValue(':uuid', $site['uuid']);
            $stmtIn->execute();
        }
    }
}

function setLicense($prm)
{
    $serialPath = '/usr/local/sensei/etc/serial';
    $licenseServer = 'https://license.sunnyvalley.io';
    try {
        $activationKey = $prm[2];
        $activationForce = "false";
        if (count($prm) > 3) {
            $activationForce = $prm[3];
        }

        $host_uuid = '';
        if (file_exists($serialPath)) {
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
        curl_setopt($curl, CURLOPT_URL, $licenseServer . '/api/v1/license/generate');
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
            print('Gateway Timeout!');
            exit(1);
        } elseif (curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) {
            print('License service Unavailable');
            exit(1);
        } else {
            print('license donwloaded...' . PHP_EOL);
        }
        file_put_contents('/tmp/new_license.data', $results);
        curl_close($curl);
    } catch (\Exception $e) {
        print('::Exception:: ' . $e->getMessage());
        exit(2);
    }
}


function reloadTemplate($backend, $isRestart)
{
    print("loading license..." . PHP_EOL);
    $backend->configdRun('template reload OPNsense/Sensei');
    print("Template reloaded.." . PHP_EOL);
    $backend->configdRun('sensei worker reload');
    $backend->configdRun('sensei policy reload');
    print("Policies reloaded.." . PHP_EOL);
    if ($isRestart == '')
        $backend->configdRun('sensei service eastpect restart');
    print("Engine restarted.." . PHP_EOL);
    print("done..." . PHP_EOL);
}

function setLicenseSize($sensei, $backend)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $licenseDetails = json_decode($backend->configdRun('sensei license-details'));
    if ($licenseDetails->premium != true) {
        return false;
    }
    if (((int) $licenseDetails->expire_time + 1209600) < time()) {
        print("License is expired..." . PHP_EOL);
        if (file_exists($sensei->licenseData)) {
            unlink($sensei->licenseData);
        }

        $backend->configdRun('sensei license');
        return false;
    }
    $license_size = (string) $sensei->getNodeByReference('general.license.Size');
    $license_flavor = (string) $sensei->getNodeByReference('general.flavor');
    print(sprintf("Current License Size: %s, Current flavor: %s, License size: %s ", $license_size, $license_flavor, $licenseDetails->size));
    if (isset($licenseDetails->size) && ($license_size != $license_flavor || $license_flavor != $licenseDetails->size)) {
        $sensei->getNodeByReference('general')->setNodes([
            'flavor' => $licenseDetails->size,
        ]);
        $sensei->getNodeByReference('general.license')->setNodes([
            'plan' => $licenseDetails->plan,
            'key' => $licenseDetails->activation_key,
            'startDate' => date('c'),
            'endDate' => date('c', $licenseDetails->expire_time),
            'Size' => $licenseDetails->size,
        ]);
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
        reloadTemplate($backend, '');
    }
}

function licenseActivation($sensei, $backend, $isRestart)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    # take license data
    print("License activation..." . PHP_EOL);
    $fname = '/tmp/new_license.data';
    if (!file_exists($fname)) {
        return false;
    }

    $results = file_get_contents($fname);
    $results = json_decode($results);
    $licenseData = $results->license;
    $licenseStartDate = $results->start_date;
    $licenseEndDate = $results->end_date;
    $licenseData = base64_decode($licenseData);
    file_put_contents('/tmp/sensei-license.data', $licenseData);
    $response['output'] = $backend->configdRun('sensei license-verify');
    $response['valid'] = strpos($response['output'], 'License OK') !== false;
    if ($response['valid']) {
        rename('/tmp/sensei-license.data', $sensei->licenseData);
        $backend->configdRun('sensei license');
        $licenseDetails = json_decode($backend->configdRun('sensei license-details'));
        print('Expire Time: ' . date(DATE_RFC822, $licenseDetails->expire_time) . PHP_EOL);
        if (((int) $licenseDetails->expire_time + 1209600) < time()) {
            print("License is expired..." . PHP_EOL);
            if (file_exists($sensei->licenseData)) {
                unlink($sensei->licenseData);
            }

            $backend->configdRun('sensei license');
        } else if (isset($licenseDetails->size)) {
            $sensei->getNodeByReference('general')->setNodes([
                # 'flavor' => Sensei::flavorSizes2[$licenseDetails->size]
                'flavor' => $licenseDetails->size,
            ]);
            $sensei->getNodeByReference('general.license')->setNodes([
                'plan' => $licenseDetails->plan,
                'key' => $licenseDetails->activation_key,
                'startDate' => $licenseStartDate,
                'endDate' => $licenseEndDate,
                'size' => $licenseDetails->size,
            ]);
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
            reloadTemplate($backend, $isRestart);
        }
    }
}

function deleteSettings()
{
    $doc = new DOMDocument;
    $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xpath = new DOMXPath($doc);
    while (($rules = $xpath->query("/opnsense/OPNsense/Sensei"))->count() != 0) {
        foreach ($rules as $rule) {
            $rule->parentNode->removeChild($rule);
        }
    }
    $doc->save('/conf/config.xml');
}

function getDbType()
{
    $result = '';
    $doc = new DOMDocument;
    $doc->load('/conf/config.xml', LIBXML_COMPACT | LIBXML_PARSEHUGE);
    $xpath = new DOMXPath($doc);
    $rules = $xpath->query("/opnsense/OPNsense/Sensei/general/database/Type");
    if (isset($rules[0])) {
        $result = $rules[0]->nodeValue;
        // print "Database Type : " . $rules[0]->nodeValue;
    }
    return $result;
}

function isGlobal($sensei)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    print "Global ip,domains configurations..." . PHP_EOL;
    $stmt = $sensei->database->prepare("select distinct site,c.action from custom_web_categories c,custom_web_category_sites s where s.custom_web_categories_id = c.id and s.is_global=1 and c.id in (select custom_web_categories_id from policy_custom_web_categories p where p.policy_id in (select id from policies where delete_status=0)) order by c.action");
    $results = $stmt->execute();
    $sites = [];
    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
        $sites[$row['site']] = 0;
        try {
            $st = $sensei->database->prepare("insert into global_sites(site,uuid,action) values(:site,:uuid,:action)");
            $st->bindValue(':site', $row['site']);
            $st->bindValue(':uuid', $sensei->generateUUID());
            $st->bindValue(':action', $row['action']);
            $result = $st->execute();
        } catch (\Exception $e) {
        }
    }
    $keys = array_keys($sites);
    $stmt = $sensei->database->prepare("delete from custom_web_category_sites where is_global=1 and site in ('" . implode("','", $keys) . "')");
    $results = $stmt->execute();
}

function setClusterUUID($sensei)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    try {
        $dbType = (string) $sensei->getNodeByReference('general.database.Type');
        $clusterUUID = (string) $sensei->getNodeByReference('general.database.ClusterUUID');
        if ($dbType == 'ES') {

            $remote = (string) $sensei->getNodeByReference('general.database.Remote');
            if ($remote == 'false') {
                $status = exec('service elasticsearch status', $output, $success);
                if ($success != 0 || strpos($output[0], 'elasticsearch is running') === false) {
                    return false;
                }
            }

            $dbuser = (string) $sensei->getNodeByReference('general.database.User');
            $dbpass = (string) $sensei->getNodeByReference('general.database.Pass');
            if (substr($dbpass, 0, 4) == 'b64:') {
                $dbpass = base64_decode(substr($dbpass, 4));
            }

            $dbPort = (string) $sensei->getNodeByReference('general.database.Port');
            $dburi = ((string) $sensei->getNodeByReference('general.database.Host')) . ($dbPort != '' ? ':' . $dbPort : '');
            $arrContextOptions = array(
                "ssl" => array(
                    "verify_peer" => false,
                    "verify_peer_name" => false,
                ),
                "http" => array(
                    "header" => "Content-type: application/json\r\n",
                ),
            );
            if (!empty($dbuser) && !empty($dbpass)) {
                $auth = base64_encode($dbuser . ":" . $dbpass);
                $arrContextOptions["http"]["header"] .= "Authorization: Basic $auth\r\n";
            }
            $context = stream_context_create($arrContextOptions);
            $dbinfo = file_get_contents($dburi, false, $context);
            if ($dbinfo !== false) {
                $es_obj = json_decode($dbinfo);
                $sensei->getNodeByReference('general.database')->setNodes([
                    'ClusterUUID' => $es_obj->cluster_uuid,
                ]);
                print "Report database set cluster uuid..." . PHP_EOL;
                $sensei->saveChanges();
                # set prefix for host uuid
                $hostuuid = trim(shell_exec('/usr/local/sensei/bin/eastpect -s'));
                $prefix = (string) $sensei->getNodeByReference('general.database.Prefix');
                $arrContextOptions['http']['method'] = 'POST';
                $arrContextOptions['http']['content'] = '{"hostuuid": "' . $hostuuid . '", "prefix": "' . $prefix . '"}';
                $context = stream_context_create($arrContextOptions);
                # file_get_contents($dburi . "/indexes/_doc/" . $hostuuid, false, $context);

            }
        }
    } catch (\Exception $e) {
        print($e->getMessage());
    }
}

function checkArrforEmpty(&$node)
{
    try {
        if (is_array($node)) {
            foreach ($node as $k => &$v) {
                if (is_array($v)) {
                    if (count($v) == 0) {
                        $node[$k] = '';
                    } else {
                        checkArrforEmpty($v);
                    }
                }
            }
        }
    } catch (\Exception $e) {
        print(__METHOD__ . ' ::Exception:: ' . $e->getMessage() . PHP_EOL);
    }
}

function xmlRestore($file, $option, $licenseExclude, $sensei)
{
    try {
        $sensei->logger('CLI-> XML restore with ' . $file);
        print('XML restore with ' . $file . PHP_EOL);
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
            $sensei->logger('CLI-> license will be not load');
            print('license will be not load' . PHP_EOL);
            unset($config['general']['license']);
        }
        checkArrforEmpty($config);
        $sensei->setNodes($config);
        $sensei->logger('CLI-> loading new configuration');
        print('loading new configuration' . PHP_EOL);
        $sensei->saveChanges();
        return true;
    } catch (Exception $e) {
        $sensei->logger('CLI-> Error XML restore ' . $e->getMessage());
        print('Error XML restore ' . $e->getMessage() . PHP_EOL);
        return false;
    }
}

function dbRestore($backend, $file, $option, $sensei)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $ts = time();
    $sensei->logger("CLI-> DB restore: Timestamp is $ts.");
    print("DB restore: Timestamp is $ts." . PHP_EOL);
    try {
        $curr_database = $sensei->config->database;
        if ($option == 'all') {
            $sensei->database->close();
            $result = $backend->configdRun("sensei restore-db " . $file . " $ts");
            return [trim($result) == 'OK', $result, $ts];
        }
        if ($option == 'rule') {
            $sensei->database->close();
            $result = $backend->configdRun("sensei restore-db-rules " . $file . " $ts");
            return [strpos('Error', $result) === false ? true : false, $result, $ts];
        }
    } catch (Exception $e) {
        $sensei->logger('CLI-> Error DB restore ' . $e->getMessage());
        print('Error DB restore ' . $e->getMessage() . PHP_EOL);
        return [false, '', $ts];
    }
}

function restore($argv, $sensei, $backend)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    try {
        $sensei->logger('CLI-> Restore Action starting.');
        print('Restore Action starting.' . PHP_EOL);
        $path = $argv[2]; // ('fname', null, '');
        $enc = $argv[3]; //('enc', null, 'false');
        $pass = $argv[4]; //('pass', null, '');
        $option = $argv[5]; //('option', null, 'all');
        $licenseExclude = $argv[6]; //('licenseExclude', null, 'true');

        if (!file_exists($path)) {
            $sensei->logger('CLI->Backup file not exists.Filename is ' . $path);
            print('Backup file not exists.Filename is ' . $path . PHP_EOL);
            return ['error' => 'Backup file not exists.'];
        }

        if (substr($path, -4) == '.enc' && empty($pass)) {
            $sensei->logger('CLI-> You should enter a password.');
            print('You should enter a password.' . PHP_EOL);
            return ['error' => 'You should enter a password.'];
        }
        $result = $backend->configdRun("sensei restore $path $licenseExclude $enc $pass");
        $sensei->logger("CLI-> Zenarmor restore $path $licenseExclude $enc");
        print("Zenarmor restore $path $licenseExclude $enc" . PHP_EOL);
        if (strpos($result, 'Error') !== false) {
            $sensei->logger($result);
            print($result . PHP_EOL);
            return ['error' => $result];
        }

        $list = explode(PHP_EOL, $result);
        foreach ($list as $fbackup) {
            $file = basename($fbackup);
            if ($file == 'config.xml' && $option == 'all') {
                if (!xmlRestore($fbackup, $option, $licenseExclude, $sensei)) {
                    $sensei->logger('CLI-> Configuration could not loaded');
                    print('Configuration could not loaded' . PHP_EOL);
                    return ['error' => 'Configuration could not loaded'];
                }
            }
            if ($file == 'settings.db') {
                $ret = dbRestore($backend, $fbackup, $option, $sensei);
                if (!$ret[0]) {
                    $sensei->logger('CLI-> Database could not loaded');
                    print('Database could not loaded' . PHP_EOL);
                    return ['error' => 'Database could not loaded, ' . $ret[1]];
                }

                $sensei = new Sensei();
                if ($sensei->databaseStatus == false) {
                    $sensei->logger("CLI-> Error DB restore: Database could'nt open. Copying last database.");
                    print("Error DB restore: Database could'nt open. Copying last database." . PHP_EOL);
                    $msg = "Database could'nt open. Copying last database.";
                    $result = $backend->configdRun("sensei restore-db-copy " . $sensei->config->database . ".$ret[2] " . $sensei->config->database);
                    if (strpos('error', $result) !== false) {
                        $msg = "it could'nt copy last database. Please do it manuel. Database name is " . $sensei->config->database . "." . $ret[2];
                        $sensei->logger("CLI-> it could'nt copy last database. Please do it manuel. Database name is " . $sensei->config->database . "." . $ret[2]);
                        print("it could'nt copy last database. Please do it manuel. Database name is " . $sensei->config->database . "." . $ret[2] . PHP_EOL);
                    } else {
                        return ['error' => 'Database could not loaded, ' . $msg];
                    }
                }
            }
        }

        if ($licenseExclude == 'false') {
            $sensei->logger('CLI-> License file loading');
            print('License file loading' . PHP_EOL);
            $response['output'] = $backend->configdRun('sensei license-verify');
            $response['valid'] = strpos($response['output'], 'License OK') !== false;
            if ($response['valid']) {
                $sensei->logger('CLI-> License file valid');
                print('License file valid' . PHP_EOL);
                rename('/tmp/sensei-license.data', $sensei->licenseData);
                $licenseDetails = json_decode($backend->configdRun('sensei license-details'));
                if ($licenseDetails->premium != true || ((int) $licenseDetails->expire_time + 1209600) < time()) {
                    if (file_exists($sensei->licenseData)) {
                        unlink($sensei->licenseData);
                    }
                }

                $backend->configdRun('sensei license');
            } else {
                $sensei->logger('CLI-> License file invalid!!!');
                print('License file invalid!!!' . PHP_EOL);
            }
        }
        return ['error' => ''];
    } catch (\Exception $e) {
        print($e->getMessage() . PHP_EOL);
    }
}

function wanlist()
{
    $wan_list = array();
    try {
        $config = Config::getInstance()->object();
        foreach ($config->interfaces->children() as $key => $node) {
            $desc = strtoupper(!empty((string) $node->descr) ? (string) $node->descr : $key);
            $interface = (string) $node->if;
            if ($desc == 'WAN' && $mode == 0) {
                array_push($wan_list, $interface);
            }
        }
        print json_encode($wan_list);
    } catch (\Exception $e) {
        print json_encode($wan_list);
        # print("ERROR:",$e->getMessage().PHP_EOL);
    }
}

function checkOfLoading($sensei)
{
    try {
        $config = Config::getInstance()->object();
        if (!isset($config->system->disablechecksumoffloading)) {
            $notice_name = 'hardwareofloading';
            $stmt = $sensei->database->prepare("select count(*) as total from user_notices where status=0 and notice_name=:notice_name");
            $stmt->bindValue(':notice_name', $notice_name);
            $results = $stmt->execute();
            $row = $results->fetchArray($mode = SQLITE3_ASSOC);
            if (intval($row['total']) == 0) {
                $stmt = $sensei->database->prepare("insert into user_notices(notice_name,notice,create_date) values(:notice_name,:notice,datetime('now'))");
                $stmt->bindValue(':notice_name', $notice_name);
                $stmt->bindValue(':notice', '<h2>Please disable Interface Hardware Offloadings</h2><p>It seems that you\'ve enabled hardware offloadings for your ethernet adapter. Although this capability is meant to provide some performance gain, the functionality is incompatible with netmap, the packet capture interface zenarmor utilizes to grab packets off the wire. Please disable the following hardware offloadings and try again:
                    <li>Hardware Checksum Offloading (Both IPv4 and IPv6)</li>
                    <li>Hardware TCP Segmentation Offload (TSO)</li>
                    <li>Hardware Large Receive Offload (LRO)</li>
                    <li>Hardware VLAN Tagging & Filtering</li>
                    <a href="https://www.sunnyvalley.io/docs/guides/disabling-hardware-offloading">Please see this document to read more about why zenarmor requires this.</a></p>');
                $results = $stmt->execute();
            }
        }
    } catch (\Exception $e) {
    }
}


function loadTemplate($sensei, $backend)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $result = $backend->configdRun('template reload OPNsense/Sensei');
    if (is_null($result)) {
        $sensei->logger("CLI:saveDbConfigES->Result: " . var_export($result, true));
        print("Configd deamon may not be working." . PHP_EOL);
    }
}

function setCloudRegister($sensei, $uuid, $adminEmail)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->setNodes(['general' => ["CloudManagementUUID" => $uuid, "CloudManagementAdmin" => $adminEmail]]);
    $sensei->saveChanges();
}

function setSethealth($sensei, $healthCheck, $healthShare, $heartbeatMonitor)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->setNodes(['general' => ["healthShare" => $healthShare, "heartbeatMonit" => $heartbeatMonitor, "healthCheck" => $healthCheck]]);
    $sensei->saveChanges();
}


function saveDbConfig($sensei, $backend, $dbType, $retireDay, $deploymentSize)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    if ($dbType != "" && $retireDay != "") {
        $sensei->setNodes(['general' => ["database" => [
            'Type' => $dbType,
            'retireAfter' => $retireDay,
        ]]]);
    }
    $sensei->setNodes(['general' => ["flavor" => $deploymentSize]]);
    $sensei->saveChanges();
    loadTemplate($sensei, $backend);
}

function saveDbConfigES($sensei, $backend)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    //{"ApiEndpointAddress":"http://192.168.122.101:9200","ApiEndpointPass":"","ApiEndpointPort":9200,"ApiEndpointPrefix":"","ApiEndpointUser":"","ApiEndpointVersion":56800,"BulkBufferSize":100,"ClusterUuid":"SrHZJKpWSiqA6CTY0U0mpQ"}
    //{"DatabaseType":1,"DatabaseTypeStr":"Elasticsearch","DatabaseName":"","DatabasePath":"","DatabaseServiceName":"","IsRemote":true,"RetireTableAfter":2}
    $contentPath = "/tmp/config.json";
    if (!file_exists($contentPath)) {
        print "$contentPath File does not exist";
        return false;
    }
    $content = file_get_contents($contentPath);
    $lines = explode("\n", $content);
    $esParam1 = json_decode($lines[0]);
    $esParam2 = json_decode($lines[1]);
    $sensei->setNodes(['general' => ["database" => [
        'Type' => "ES",
        'Port' => $esParam1->ApiEndpointPort,
        'Host' => $esParam1->ApiEndpointAddress,
        'Version' => $esParam1->ApiEndpointVersion,
        'Remote' => ($esParam2->IsRemote ? "true" : "false"),
        'Prefix' => $esParam1->ApiEndpointPrefix,
        'User' => $esParam1->ApiEndpointUser,
        'Pass' => $esParam1->ApiEndpointPass,
        'ClusterUUID' => $esParam1->ClusterUuid,
        'retireAfter' => 7,
    ]]]);

    $sensei->saveChanges();
    loadTemplate($sensei, $backend);
    // unlink($contentPath);
}

function saveDbConfigSQ($sensei, $backend, $path)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    if (!file_exists($path)) {
        mkdir($path, 0755, true);
    }
    $sensei->setNodes(['general' => ["database" => [
        'Type' => "SQ",
        'retireAfter' => 2,
        'dbpath' => $path,
    ]]]);

    $sensei->saveChanges();
    loadTemplate($sensei, $backend);
}

function setBypass($sensei, $bstatus, $mstatus)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $node = $sensei->getNodeByReference('bypass');
    $node->setNodes([
        'enable' => $bstatus,
        'mode' => $mstatus,
    ]);
    $sensei->saveChanges();
}

function setDnsEnrichment($sensei, $servers, $realtimeDNSreverse)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->getNodeByReference('dnsEncrihmentConfig')->setNodes([
        'servers' => $servers,
        'reverse' => $realtimeDNSreverse
    ]);
    $sensei->saveChanges();
}

function setCloudThreatIntel($sensei, $domains, $isactive)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->getNodeByReference('enrich')->setNodes([
        'cloudWebcatEnrich' => $isactive
    ]);
    $sensei->getNodeByReference('dns')->setNodes([
        'localDomain' => $domains
    ]);
    $sensei->saveChanges();
}


function setBlockNotification($sensei, $status)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->getNodeByReference('enrich')->setNodes([
        'cloudResponseTimeout' => ($status == 'true' ? '1000' : '0')
    ]);
    $sensei->saveChanges();
}

function setrestapi($sensei, $backend, $status)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->getNodeByReference('agentrestapi')->setNodes([
        'enabled' => $status
    ]);
    $sensei->saveChanges();
    $backend->configdRun('template reload OPNsense/Sensei');
}

/*
{'heartbeatData': true, 'heartbeatMonit': false, 'healthShare': true, 'coreFileEnable': false,
'updateAutocheck': true, 'updateEnabled': true,
'cloudWebcatEnrich': false, 'dns': false, 'user': true,
'centralManagement': true, 'reportInfastructureError': false,
'localAddress': false, 'remoteAddress': false}
*/
function setPrivacy($sensei, $backend, $jsonParams)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__ . ' with params ' . var_export($jsonParams, true));
    $jsonParams = str_replace(["'", 'true', 'false'], ['"', '"true"', '"false"'], $jsonParams);
    $params = json_decode($jsonParams, true);
    $general = [];
    # ['heartbeatData' => $heartbeat, 'heartbeatMonit' => $heartbeatMonit, 'healthShare' => $healthShare, 'coreFileEnable' => $coreFileEnable]
    if (isset($params['heartbeat'])) {
        $general['heartbeatData'] = $params['heartbeat'];
    }
    if (isset($params['heartbeatMonit'])) {
        $general['heartbeatMonit'] = $params['heartbeatMonit'];
    }
    if (isset($params['healthShare'])) {
        $general['healthShare'] = $params['healthShare'];
    }
    if (isset($params['coreFileEnable'])) {
        $general['coreFileEnable'] = $params['coreFileEnable'];
    }
    if (count($general) > 0)
        $sensei->getNodeByReference('general')->setNodes($general);

    $updater = [];
    # ['autocheck' => $updateAutocheck, 'enabled' => $updateEnabled]    
    if (isset($params['updateAutocheck'])) {
        $updater['autocheck'] = $params['updateAutocheck'];
    }
    if (isset($params['updateEnabled'])) {
        $updater['enabled'] = $params['updateEnabled'];
    }

    if (count($updater) > 0)
        $sensei->getNodeByReference('updater')->setNodes($updater);

    $enrich = [];
    # ['cloudWebcatEnrich' => $cloudWebcatEnrich, 'dns' => $dns, 'user' => $user]
    if (isset($params['cloudWebcatEnrich'])) {
        $enrich['cloudWebcatEnrich'] = $params['cloudWebcatEnrich'];
    }
    if (isset($params['dns'])) {
        $enrich['dns'] = $params['dns'];
    }
    if (isset($params['user'])) {
        $enrich['user'] = $params['user'];
    }
    if (count($enrich) > 0)
        $sensei->getNodeByReference('enrich')->setNodes($enrich);

    $zenconsole = [];
    # ['centralManagement' => $centralManagement, 'reportInfastructureError' => $reportInfastructureError]
    if (isset($params['centralManagement'])) {
        $zenconsole['centralManagement'] = $params['centralManagement'];
    }
    if (isset($params['reportInfastructureError'])) {
        $zenconsole['reportInfastructureError'] = $params['reportInfastructureError'];
    }
    if (count($zenconsole) > 0)
        $sensei->getNodeByReference('zenconsole')->setNodes($zenconsole);

    $anonymize = [];
    # ['localAddress' => $localAddress, 'remoteAddress' => $remoteAddress]
    if (isset($params['localAddress'])) {
        $anonymize['localAddress'] = $params['localAddress'];
    }
    if (isset($params['remoteAddress'])) {
        $anonymize['remoteAddress'] = $params['remoteAddress'];
    }
    if (count($anonymize) > 0)
        $sensei->getNodeByReference('anonymize')->setNodes($anonymize);

    $sensei->saveChanges();
    // $backend->configdRun('template reload OPNsense/Sensei');
}


function setflavor($sensei)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $config = new ConfigIni($sensei::eastpect_config);
    if (isset($config->General['flavor']) && is_string($config->General['flavor'])) {
        foreach (Sensei::flavorSizes2 as $k => $v) {
            if ($v == $config->General['flavor']) {
                $sensei->setNodes(['general' => ["flavor" => $k]]);
                $sensei->saveChanges();
            }
        }
    }
}

function setretireafter($sensei)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    if (file_exists($sensei::eastpect_config)) {
        $config = new ConfigIni($sensei::eastpect_config);
        try {
            print('DB Type: ' . $config->Database["type"] . PHP_EOL);
            if ($config->Database["type"] == "MN") {
                if (isset($config->Database["retireAfter"])) {
                    print('Retire After: ' . $config->Database["retireAfter"] . PHP_EOL);
                }

                $sensei->setNodes(['general' => ["database" => [
                    'retireAfter' => isset($config->Database["retireAfter"]) && $config->Database["retireAfter"] != "" ? $config->Database["retireAfter"] : 2,
                ]]]);
            }
            if ($config->Database["type"] == "ES") {
                if (isset($config->ElasticSearch["retireAfter"])) {
                    print('Retire After: ' . $config->ElasticSearch["retireAfter"] . PHP_EOL);
                }

                $sensei->setNodes(['general' => ["database" => [
                    'retireAfter' => isset($config->ElasticSearch["retireAfter"]) && $config->ElasticSearch["retireAfter"] != "" ? $config->ElasticSearch["retireAfter"] : 7,
                ]]]);
            }
            $sensei->saveChanges();
        } catch (\Exception $e) {
        }
    }
}
function setRetireAfterfromCloud($sensei, $backend, $maxRetireDay)
{

    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $sensei->setNodes(['general' => ["database" => [
        'retireAfter' => $maxRetireDay
    ]]]);
    $sensei->saveChanges();
    $backend->configdRun('template reload OPNsense/Sensei');
}

function setPoliciesToCloud($sensei)
{
    $sensei->logger('RUNING CLI ' . __FUNCTION__);
    $stmt = $sensei->database->prepare("select id,name from policies where delete_status=0");
    $results = $stmt->execute();
    while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
        try {
            $cloud_result = $sensei->sendDataCloud('update', $row['id']);
            echo "Policy Name: " . $row['name'] . " send to cloud.";
            if ($cloud_result['error']) {
                echo "Error: " . $cloud_result['error'] . " Message: " . $cloud_result['message'];
            }

            echo PHP_EOL;
        } catch (\Exception $e) {
            echo "Policy Name: " . $row['name'] . " Error: " . $e->getMessage . PHP_EOL;
        }
    }
}

function setSwapRate($sensei)
{
    try {
        $swapRate = (string) $sensei->getNodeByReference('general.swapRate');
        echo "Current Swap Rate: " . $swapRate . PHP_EOL;
        if ($swapRate == '' || intval($swapRate) < 60) {
            $sensei->getNodeByReference('general')->setNodes([
                'swapRate' => 60
            ]);
            $sensei->saveChanges();
            echo "Swap Rate: Changed " . $swapRate . " to 60." . PHP_EOL;
        }
    } catch (\Exception $e) {
        echo "Swap rate could'nt change " . $e->getMessage . PHP_EOL;
    }
}

function Interface_Settings($sensei, $backend, $if)
{
    try {
        $cpuCount = intval(trim($backend->configdRun('sensei cpu-count'), "\n"));
        $stmt = $sensei->database->prepare('delete from interface_settings');
        if (!$results = $stmt->execute()) {
            return false;
        }

        $dberrorMsg = [];
        $index = 0;
        $mode = 'routed';
        $tags = 'netmap;routedmode';
        $stmt = $sensei->database->prepare('insert into interface_settings(mode,lan_interface,lan_desc,cpu_index,manage_port,create_date,tags)' .
            ' values(:mode,:lan_interface,:lan_desc,:cpu_index,:manage_port,datetime(\'now\'),:tags)');
        $stmt->bindValue(':mode', $mode);
        $stmt->bindValue(':lan_interface', $if);
        $stmt->bindValue(':lan_desc', "LAN($if)");
        $stmt->bindValue(':manage_port', (string) (4343 + $index));
        $stmt->bindValue(':cpu_index', $cpuCount > 1 ? (string) (($index % ($cpuCount - 1)) + 1) : '0');
        $stmt->bindValue(':tags', $tags);
        if (!$stmt->execute()) {
            $dberrorMsg[] = $sensei->database->lastErrorMsg();
        }
        if (count($dberrorMsg) > 0) {
            return false;
        }
        reloadTemplate($backend, 'false');
        return true;
    } catch (\Exception $e) {
        print(__METHOD__ . ' Exception: ' . $e->getMessage());
        return false;
    }
}

$logdir = '/usr/local/sensei/log/active';
if (file_exists($logdir)) {
    ini_set('error_log', $logdir . '/Senseigui.log');
}

ini_set('display_errors', "no");

if (isset($argv) and is_array($argv) and count($argv) > 1) {
    $dbType = getDbType();
    $sensei = new Sensei();
    if (!empty($dbType)) {
        $sensei->getNodeByReference('general.database')->setNodes([
            'Type' => $dbType,
        ]);
        $sensei->saveChanges();
    }
    $backend = new Backend();
    $cronController = new CronController();
    switch ($argv[1]) {
        case 'migrate':
            migrateDatabase($sensei);
            break;
        case 'migratewebcat':
            migrateWebcat($sensei, $backend);
            break;
        case 'onboot':
            manageOnBoot($sensei, $backend, $argv[2], $argv[3]);
            break;
        case 'crons':
            manageCrons($cronController, $argv[2]);
            break;
        case 'reset':
            resetConfiguration($sensei, $backend);
            break;
        case 'reload':
            reloadEngine($sensei);
            break;
        case 'licensedel':
            licenseDelete($sensei, $backend);
            break;
        case 'config2db':
            config2db($sensei, $backend);
            break;
        case 'licenseActivation':
            licenseActivation($sensei, $backend, isset($argv[2]) ? $argv[2] : '');
            break;
        case 'deletesettings':
            deleteSettings();
            break;
        case 'sysctl':
            setSysctl($argv[2]);
            break;
        case 'bufsysctl':
            setBufSysctl();
            break;
        case 'setClusterUUID':
            setClusterUUID($sensei);
            break;
        case 'isGlobal':
            isGlobal($sensei);
            break;
        case 'setretireafter':
            setretireafter($sensei);
            break;
        case 'setRetireAfterfromCloud':
            setRetireAfterfromCloud($sensei, $backend, $argv[2]);

        case 'saveload':
            saveLoad($sensei, $backend);
            break;
        case 'wanlist':
            wanlist();
            break;
        case 'setflavor':
            setflavor($sensei);
            break;
        case 'saveDbConfigES':
            saveDbConfigES($sensei, $backend);
            break;
        case 'saveDbConfigSQ':
            saveDbConfigSQ($sensei, $backend, $argv[2]);
            break;
        case 'saveDbConfig':
            saveDbConfig($sensei, $backend, $argv[2], $argv[3], $argv[4]);
            break;
        case 'setbypass':
            setBypass($sensei, $argv[2], $argv[3]);
            saveLoad($sensei, $backend);
            break;
        case 'setdnsenrichment':
            setDnsEnrichment($sensei, $argv[2], $argv[3]);
            break;
        case 'setrestapi':
            if (count($argv) < 3 || ($argv[2] != 'true' && $argv[2] != 'false')) {
                print('parameter must be true or false.' . PHP_EOL);
                print($argv[0] . ' setrestapi true|false' . PHP_EOL);
                exit(1);
            } else
                setrestapi($sensei, $backend, $argv[2]);
            break;
        case 'setprivacy':
            if (count($argv) < 3) {
                print($argv[0] . ' setprivacy [json string]' . PHP_EOL);
                exit(1);
            } else
                setPrivacy($sensei, $backend, $argv[2]);
            break;
        case 'setswap':
            setSwapRate($sensei);
            break;

        case 'setcloudthreatintel':
            if (!isset($argv[2]) || !isset($argv[3])) {
                print("missing parameter. setcloudthreatintel [domains] [isactive]" . PHP_EOL);
                exit(1);
            }
            if ($argv[3] != 'true' && $argv[3] != 'false') {
                print("wron gparameter value. [isactive] must be true or false" . PHP_EOL);
                exit(1);
            }
            setCloudThreatIntel($sensei, $argv[2], $argv[3]);
            break;


        case 'setCloudRegister':
            if (!isset($argv[2]) || !isset($argv[3])) {
                print("missing parameter. setCloudRegister [cloud_uuid] [admin email]" . PHP_EOL);
                exit(1);
            }
            setCloudRegister($sensei, $argv[2], $argv[3]);
            break;

        case 'setblocknotification':
            if (!isset($argv[2]) || array_search($argv[2], ['true', 'false']) === false) {
                print("missing parameter. setblocknotification [true|false]" . PHP_EOL);
                exit(1);
            }
            setBlockNotification($sensei, $argv[2]);
            break;
        case 'setlicensesize':
            setlicensesize($sensei, $backend);
            break;
        case 'sethealth':
            if (!isset($argv[2]) || !isset($argv[3])) {
                print("missing parameter. sethealth [healtcheck true/false] [healtShare true/false] [heartbeatMonitor true/false]" . PHP_EOL);
                exit(1);
            }
            setSethealth($sensei, $argv[2], $argv[3], $argv[4]);
            break;
        case 'setlicense':
            if (count($argv) != 4) {
                print("Usage: " . __FILE__ . " setlicense [license key] [activation force (false,true)]" . PHP_EOL);
                exit(1);
            }
            setlicense($argv);
            licenseActivation($sensei, $backend, isset($argv[2]) ? $argv[2] : '');
            break;
        case 'restore':
            if (count($argv) != 7) {
                print("Usage: " . __FILE__ . " [restore] [path (/tmp/backup001.enc)] [enc (false,true)] [pass 'a*1',''] [option 'all','rule'] [license Exclude true,false]" . PHP_EOL);
                exit(1);
            }
            restore($argv, $sensei, $backend);
            break;
        case 'setpoliciestocloud':
            setPoliciesToCloud($sensei);
            break;
        case 'settimestamp':
            $r = random_int(0, 9);
            $timer = [$r];
            for ($i = 10; $i < 59; $i = $i + 10) {
                $timer[] = $r + $i;
            }
            $sensei->getNodeByReference('general')->setNodes(['healthTimer' => implode(',', $timer) . ' * * * *', 'installTimestamp' => (string) time(), 'heartbeatTimer' => random_int(0, 59) . ' 1,9,18 * * *']);
            $sensei->saveChanges();
            break;
        case 'setinterface':
            Interface_Settings($sensei, $backend, $argv[2]);
            break;
        case 'checkOfLoading':
            checkOfLoading($sensei);
            break;
    }
}
