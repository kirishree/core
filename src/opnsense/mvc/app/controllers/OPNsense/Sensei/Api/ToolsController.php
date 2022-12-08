<?php

namespace OPNsense\Sensei\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\IDS\IDS;
use \OPNsense\Sensei\Sensei;

class ToolsController extends ApiControllerBase
{

    const log_file = '/usr/local/sensei/log/active/Senseigui.log';

    public function interfacesAction($mode = 0)
    {
        try {
            $backend = new Backend();
            $response = [];
            $AllInterface = [];
            $config = Config::getInstance()->object();
            if ($config->interfaces->count() > 0) {
                $unique = [];
                $opnsenseInfo = array();
                list($opnsenseInfo['product_name'], $opnsenseInfo['product_version']) =
                    explode(' ', trim(shell_exec('opnsense-version -nv')));
                $opnsenseInfo['product_version'] = str_replace(
                    array('-', '_'),
                    '',
                    substr($opnsenseInfo['product_version'], 0, 4)
                );
                $netmapVersion = 0;
                exec("grep NETMAP_API /usr/include/net/netmap.h | grep 'current API version'|awk '{ print $3 }'", $output, $return);
                if ($return == 0) {
                    $netmapVersion = trim($output[0]);
                }
                foreach ($config->interfaces->children() as $key => $node) {
                    $filterflag = false;
                    $parent_iface = '';
                    $warning = false;
                    $type = 'iface';
                    $desc = strtoupper(!empty((string) $node->descr) ? (string) $node->descr : $key);
                    $interface = (string) $node->if;
                    array_push($AllInterface, $interface);

                    if (in_array($interface, $unique)) {
                        $filterflag = true;
                    }

                    if ($desc == 'WAN' && $mode == 0) {
                        $filterflag = true;
                    }

                    if ($node->virtual == '1') {
                        $filterflag = true;
                    }

                    if (isset($node['wireless']) && floatval($opnsenseInfo['product_version']) < 19.1) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "vlan") !== false) {
                        $type = 'vlan';
                        $pos = strpos(strtolower($interface), '_vlan');
                        if ($pos > 0) {
                            $parent_iface = substr($interface, 0, $pos);
                        }
                        # $warning = true;
                    }

                    if (strpos(strtolower($interface), "gif") !== false) {
                        # $filterflag = true;
                        $warning = true;
                    }

                    if (strpos(strtolower($interface), "le") !== false) {
                        # $filterflag = true;
                        $warning = true;
                    }

                    /*
                    if (strpos(strtolower($interface), "wg") !== false) {
                        $filterflag = true;
                    }
                    */


                    if (strpos(strtolower($interface), "lagg") !== false && strpos(strtolower($interface), "vlan") !== false) {
                        # $filterflag = true;
                        $warning = true;
                    }


                    /*
                    if (strpos(strtolower($interface), "vmx") !== false && strpos(strtolower($interface), "vlan") == false) {
                        $filterflag = true;
                    }
		     */

                    if (strpos(strtolower($interface), "bridge") !== false) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "ppp") !== false) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "ue") !== false) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "lo") !== false) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "enc") !== false) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "pf") !== false) {
                        $filterflag = true;
                    }

                    if (strpos(strtolower($interface), "lagg") !== false) {
                        # $filterflag = true;
                        # $warning = true;
                    }

                    /*
                    if (strpos(strtolower($interface), "zt") !== false) {
                        $filterflag = true;
                    }
                    */
                    /* 
                    if ((substr($interface, 0, 5) == 'vtnet') && (floatval($netmapVersion) < 13 or floatval($opnsenseInfo['product_version']) >= 20.7)) {
                        $filterflag = true;
                    }
		             */

                    if ($filterflag) {
                        continue;
                    }
                    $mtu = 0;
                    $cmd = '/sbin/ifconfig ' . $interface . '| grep -i mtu | head -1';
                    $mtu_output = exec($cmd, $tmp, $retval);
                    if (preg_match("/^(.*) metric (\d+) mtu (\d+)(.*)/i", $mtu_output, $mathes)) {
                        if (isset($mathes[3])) {
                            $mtu = $mathes[3];
                        }
                    }
                    array_push($unique, $interface);
                    array_push($response, ['interface' => $interface, 'description' => $desc . ' (' . $interface . ')', 'desc' => $desc, 'type' => $type, 'mtu' => $mtu, 'parent_iface' => $parent_iface, 'warning' => $warning]);
                }
            }
            exec('/sbin/ifconfig -lu 2>&1', $output, $ret);
            $cmd_wlan = 'sysctl -n net.wlan.devices';
            $ifs_wlan = array();
            if ($ret == '0') {
                $iflist = explode(' ', $output[1]);
                exec($cmd_wlan . ' 2>&1', $out_wlan, $ret_wlan);
                if (!$ret_wlan && !empty($out_wlan[0])) {
                    $ifs_wlan = explode(' ', $out_wlan[0]);
                }
                foreach ($iflist as $ifname) {
                    $warning = false;
                    $parent_iface = '';
                    if (!in_array($ifname, $AllInterface)) {
                        $filterflag = false;
                        $type = 'iface';

                        if (strpos(strtolower($ifname), "lo") !== false) {
                            $filterflag = true;
                        }

                        /*
                    if ($node->virtual == '1') {
                    $filterflag = true;
                    }
                     */
                        if (substr(strtolower($ifname), 0, 3) == "vir") {
                            $filterflag = true;
                        }

                        if (in_array($ifname, $ifs_wlan) && floatval($opnsenseInfo['product_version']) < 19.1) {
                            $filterflag = true;
                        }

                        if (strpos(strtolower($ifname), "vlan") !== false) {
                            $type = 'vlan';
                            $pos = strpos(strtolower($ifname), '_vlan');
                            if ($pos > 0) {
                                $parent_iface = substr($ifname, 0, $pos);
                            }

                            # $warning = true;
                        }

                        if (strpos(strtolower($ifname), "gif") !== false) {
                            # $filterflag = true;
                            $warning = true;
                        }

                        if (strpos(strtolower($ifname), "le") !== false) {
                            # $filterflag = true;
                            $warning = true;
                        }
                        /*
                        if (strpos(strtolower($ifname), "wg") !== false) {
                            $filterflag = true;
                        }
                        */
                        if (strpos(strtolower($ifname), "lo") !== false) {
                            $filterflag = true;
                        }

                        if (strpos(strtolower($ifname), "enc") !== false) {
                            $filterflag = true;
                        }

                        if (strpos(strtolower($ifname), "pf") !== false) {
                            $filterflag = true;
                        }

                        if (strpos(strtolower($ifname), "lagg") !== false && strpos(strtolower($ifname), "vlan") !== false) {
                            # $filterflag = true;
                            $warning = true;
                        }


                        if (strpos(strtolower($ifname), "bridge") !== false) {
                            $filterflag = true;
                        }
                        /*
                        if ((substr($ifname, 0, 5) == 'vtnet') && (floatval($netmapVersion) < 13 or floatval($opnsenseInfo['product_version']) < 19.1)) {
                            $filterflag = true;
                        }
                        */
                        if ($filterflag) {
                            continue;
                        }
                        $mtu = 0;
                        $cmd = '/sbin/ifconfig ' . $ifname . '| grep -i mtu | head -1';
                        $mtu_output = exec($cmd, $tmp, $retval);
                        if (strpos($mtu_output, 'mtu') !== false) {
                            $mtu_output = explode('mtu', $mtu_output);
                            if (isset($mtu_output[1])) {
                                $mtu = intval($mtu_output[1]);
                            }
                        }

                        array_push($response, ['interface' => $ifname, 'description' => 'Unassigned (' . $ifname . ')', 'desc' => 'Unassigned', 'type' => $type, 'mtu' => $mtu, 'parent_iface' => $parent_iface, 'warning' => $warning]);
                    }
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function proctectedInterfaceAction()
    {
        $interfaces = [];
        try {
            $sensei = new Sensei();
            $policies = $sensei->database->query('select * from interface_settings order by id');

            while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                if ($row['lan_interface'] != '' || $row['lan_desc'] != '')
                    $interfaces[] = ['interface' => $row['lan_interface'], 'description' => $row['lan_desc']];
                if ($row['wan_interface'] != '' || $row['wan_desc'] != '')
                    $interfaces[] = ['interface' => $row['wan_interface'], 'description' => $row['wan_desc']];
            }
            return ['error' => '', 'interfaces' => $interfaces];
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['error' => $e->getMessage(), 'interfaces' => []];
        }
    }

    public function configAction()
    {
        try {
            return (array) Config::getInstance()->object();
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function suricataInterfacesAction()
    {
        try {
            $idsMdl = new IDS();
            $response = [];
            //if ((string) $idsMdl->general->enabled == 1) {
            if ((string) $idsMdl->general->ips == 1) {
                foreach ($idsMdl->getNodeByReference('general')->getNodes()['interfaces'] as $key => $value) {
                    if ($value['selected'] == 1) {
                        array_push($response, $value['value']);
                    }
                }
            }
            return $response;
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return [];
        }
    }

    public function whoisAction()
    {
        try {
            $backend = new Backend();
            $sensei = new Sensei();
            $query = $this->request->getPost('query');
            $type = $this->request->getPost('type');
            $response = [];
            $sensei->logger("Query : $query , Type : $type");
            if ($type == 'domain') {
                $list = explode('.', $query);
                $response['outputs'] = $backend->configdRun('sensei whois ' . $query);
                while ((strpos(strtolower($response['outputs']), 'no match for domain') !== false || strpos(strtolower($response['outputs']), 'not found')) && count($list) > 1) {
                    $removed = array_shift($list);
                    $response['outputs'] = $backend->configdRun('sensei whois ' . implode('.', $list));
                    $sensei->logger("Query : " . implode('.', $list));
                }
            } else {
                $response['outputs'] = $backend->configdRun('sensei whois ' . $query);
            }

            if (strpos($response['outputs'], 'whois:') > 1) {
                $response['outputs'] = substr($response['outputs'], strpos($response['outputs'], 'whois:'));
            }

            return trim($response['outputs']);
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['outputs' => ''];
        }
    }

    public function hostnameAction()
    {
        try {
            $backend = new Backend();
            $query = $this->request->getPost('query');
            $query = str_replace(' ', '', $query);
            $sensei = new Sensei();
            $nameservers = ' ';
            try {
                $nameservers .= (string) $sensei->getNodeByReference('dnsEncrihmentConfig.servers');
            } catch (\Exception $e) {
            }
            $response = $backend->configdRun('sensei hostname ' . $query . $nameservers);
            if (trim($response) != '') {
                return trim(str_replace(['.', ':'], ['. ', ': '], $response));
            }

            return trim(str_replace(['.', ':'], ['. ', ': '], $query));
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return '';
        }
    }

    public function shunNetworksAction()
    {
        try {
            $sensei = new Sensei();
            if ($this->request->getMethod() == 'GET') {
                $response = [];
                $results = $sensei->database->query('SELECT type,network,desc,status FROM shun_networks order by network');
                $networks = ['vlans' => [], 'networks' => []];
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    if ($row['type'] == 1) {
                        $networks['networks'][] = $row;
                    }

                    if ($row['type'] == 2) {
                        $networks['vlans'][] = $row;
                    }
                }
                $response['vlans'] = $networks['vlans'];
                $response['networks'] = $networks['networks'];
                return $response;
            }
        } catch (\Exception $e) {
            if (file_exists(self::log_file))
                file_put_contents(self::log_file, __METHOD__ . '::Exception::' . $e->getMessage(), FILE_APPEND);
            return ['vlans' => '', 'networks' => ''];
        }
    }

    public function vlansAction()
    {
        return [];
    }
}
