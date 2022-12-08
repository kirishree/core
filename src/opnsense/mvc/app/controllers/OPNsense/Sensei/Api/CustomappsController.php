<?php

namespace OPNsense\Sensei\Api;

# error_reporting(E_ERROR);

use Exception;
use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Sensei\Sensei;

class CustomappsController extends ApiControllerBase
{
    public function getApplicationsAction()
    {
        try {
            $sensei = new Sensei();
            $stmt = $sensei->database->prepare('select * from application_categories order by name');
            $results = $stmt->execute();
            $applictions = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $applictions[] = $row;
            }
            return $applictions;
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception :' . $e->getMessage());
            return [];
        }
    }

    public function getcustomAppsAction()
    {
        try {
            $sensei = new Sensei();
            $stmt = $sensei->database->prepare('select * from custom_applications order by name');
            $results = $stmt->execute();
            $applictions = [];
            while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                $applictions[] = $row;
            }
            return $applictions;
        } catch (Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception :' . $e->getMessage());
            return [];
        }
    }
    public function delCustomAppAction()
    {
        try {
            $sensei = new Sensei();
            $response = ['successful' => false];
            $cid = $this->request->getPost('id', null, -1);
            if ($cid == -1) {
                return $response;
            }
            $stmt = $sensei->database->prepare('delete from custom_applications where id=:id');
            $stmt->bindValue(':id', $cid);
            if (!$stmt->execute()) {
                $sensei->logger(__METHOD__ . " SQL Error : " . $sensei->database->lastErrorMsg());
                $response['message'] = 'Custom application could not deleted';
                return $response;
            }
            $stmt = $sensei->database->prepare('delete from policy_custom_app_categories where custom_application_id=:custom_application_id');
            $stmt->bindValue(':custom_application_id', $cid);
            if (!$stmt->execute()) {
                $sensei->logger(__METHOD__ . " SQL Error : " . $sensei->database->lastErrorMsg());
                $response['message'] = 'Custom application could not deleted';
                return $response;
            } else {
                $response = ['successful' => true];
                return $response;
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception :' . $e->getMessage());
            $response['message'] = 'Error Occurr Process could not finished';
            return $response;
        }
    }
    public function searchCustomAppAction()
    {
        try {
            $sensei = new Sensei();
            $response = ['successful' => true, 'applications' => []];
            $search = $this->request->getPost('search', null, '');
            $applictions = [];
            if ($search != '') {
                $stmt = $sensei->database->prepare('select a.*,c.name as cname from custom_applications a,application_categories c where a.application_category_id=c.id and (a.name like :aname or c.name like :cname) order by a.name');
                $stmt->bindValue(':aname', '%' . $search . '%');
                $stmt->bindValue(':cname', '%' . $search . '%');
                $results = $stmt->execute();
                while ($row = $results->fetchArray($mode = SQLITE3_ASSOC)) {
                    $applictions[] = $row;
                }
                $response['applications'] = $applictions;
                return $response;
            } else
                return $response;
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception :' . $e->getMessage());
            $response['successful'] = false;
            $response['message'] = 'Error Occurr Process could not finished';
            return $response;
        }
    }

    public function uptCustomAppAction()
    {
        try {
            $sensei = new Sensei();
            $cid = $this->request->getPost('id', null, -1);
            $name = $this->request->getPost('name', null, '');
            $protocol = $this->request->getPost('protocol', null, 'TCP');
            $port = $this->request->getPost('port', null, '');
            $hostnames = $this->request->getPost('hostnames', null, '');
            $ip_addrs = $this->request->getPost('ip_addrs', null, '');
            $category = $this->request->getPost('category', null, 0);
            $category_name = $this->request->getPost('category_name', null, '');
            $send = $this->request->getPost('send', null, false);
            $desc = $this->request->getPost('description', null, '');
            $policyId = $this->request->getPost('policyId', null, '');
            $protocol = empty($protocol) ? 'TCP':$protocol;
            $response = ['successful' => false];
            if (empty($name)) {
                $response['message'] = 'Name must be fill';
                return $response;
            }
            if ($category == 0) {
                $response['message'] = 'Application Category must be fill';
                return $response;
            }
            $nameCnt = $sensei->database->querySingle(sprintf("select count(*) from applications where name='%s' and id<>%d", $name, $cid), false);
            if ($nameCnt != 0) {
                $response['message'] = $name . ' is exits in Zenarmor Appliaction Database. Name must be uniq.';
                return $response;
            }
            $stmt = $sensei->database->prepare('update custom_applications set name=:name,description=:description,hostnames=:hostnames,
                                                ip_addrs=:ip_addrs,application_category_id=:application_category_id,protocol=:protocol, port=:port 
                                                where id=:id');
            $stmt->bindValue(':id', $cid);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $desc);
            $stmt->bindValue(':hostnames', $hostnames);
            $stmt->bindValue(':ip_addrs', $ip_addrs);
            $stmt->bindValue(':application_category_id', $category);
            $stmt->bindValue(':protocol', $protocol);
            $stmt->bindValue(':port', $port);
            if (!$stmt->execute()) {
                $sensei->logger(__METHOD__ . " SQL Error : " . $sensei->database->lastErrorMsg());
                $response['message'] = 'Custom application could not saved';
                return $response;
            } else {
                if ($send == 'true') {
                    $data = ["name" => $name, "description" => $desc, "hostnames" => str_replace(PHP_EOL, ',', $hostnames), "ip_addrs" => str_replace(PHP_EOL, ',', $ip_addrs), "category" => $category, "category_name" => $category_name, "protocol" => $protocol];
                    $sensei->sendJson($data, 'https://health.sunnyvalley.io/client_custom_application.php');
                }
                $response = ['successful' => true];
                return $response;
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception :' . $e->getMessage());
            $response['message'] = 'Error Occurr Process could not finished';
            return $response;
        }
    }

    public function setCustomAppAction()
    {
        # ids must be start 100.000 number.
        try {
            $sensei = new Sensei();
            $cid = $this->request->getPost('id', null, -1);
            $name = $this->request->getPost('name', null, '');
            $protocol = $this->request->getPost('protocol', null, 'TCP');
            $port = $this->request->getPost('port', null, '');
            $hostnames = $this->request->getPost('hostnames', null, '');
            $ip_addrs = $this->request->getPost('ip_addrs', null, '');
            $category = $this->request->getPost('category', null, 0);
            $category_name = $this->request->getPost('category_name', null, '');
            $send = $this->request->getPost('send', null, false);
            $desc = $this->request->getPost('description', null, '');
            $policyId = $this->request->getPost('policyId', null, '');
            $protocol = empty($protocol) ? 'TCP':$protocol;
            $response = ['successful' => false];
            if (empty($name)) {
                $response['message'] = 'Name must be fill';
                return $response;
            }
            if ($category == 0) {
                $response['message'] = 'Application Category must be fill';
                return $response;
            }
            $nameCnt = $sensei->database->querySingle(sprintf("select count(*) from applications where name='%s'", $name), false);
            if ($nameCnt != 0) {
                $response['message'] = $name . ' is exits in Zenarmor Appliaction Database. Name must be uniq.';
                return $response;
            }
            $nameCnt = $sensei->database->querySingle(sprintf("select count(*) from custom_applications where name='%s'", $name), false);
            if ($nameCnt != 0) {
                $response['message'] = $name . ' is exits in Zenarmor Appliaction Database. Name must be uniq.';
                return $response;
            }
            $maxId = $sensei->database->querySingle("select max(id) from custom_applications", false);
            $maxId = intval($maxId);
            if (empty($maxId) || $maxId == 0) {
                $maxId = 100000;
            } else {
                $maxId++;
            }

            $stmt = $sensei->database->prepare('insert into custom_applications(id,name,description,hostnames,ip_addrs,application_category_id,protocol,port) 
                                                values(:id,:name,:description,:hostnames,:ip_addrs,:application_category_id,:protocol,:port)');
            $stmt->bindValue(':id', $maxId);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $desc);
            $stmt->bindValue(':hostnames', $hostnames);
            $stmt->bindValue(':ip_addrs', $ip_addrs);
            $stmt->bindValue(':application_category_id', $category);
            $stmt->bindValue(':protocol', $protocol);
            $stmt->bindValue(':port', $port);
            if (!$stmt->execute()) {
                $sensei->logger(__METHOD__ . " SQL Error : " . $sensei->database->lastErrorMsg());
                $response['message'] = 'Custom application could not saved';
                return $response;
            } else {

                $stmt = $sensei->database->prepare('delete from custom_applications_host_ip where custom_application_id=:custom_application_id');
                $stmt->bindValue(':custom_application_id', $maxId);
                if (!$stmt->execute()) {
                    $sensei->logger(__METHOD__ . " SQL Error : " . $sensei->database->lastErrorMsg());
                    $response['message'] = 'Custom application could not save to Host and ip..';
                    return $response;
                }
                $hosts = explode(PHP_EOL, $hostnames);
                $ips = explode(PHP_EOL, $ip_addrs);
                $cn = count($hosts) > count($ips) ? count($hosts) : count($ips);
                $sensei->logger(__METHOD__ . " Host and ip will be saved $cn");
                for ($i = 0; $i < $cn; $i++) {
                    $stmt = $sensei->database->prepare('insert into custom_applications_host_ip(custom_application_id,host,ip) values(:custom_application_id,:host,:ip)');
                    $stmt->bindValue(':custom_application_id', $maxId);
                    $stmt->bindValue(':host', empty($hosts[$i]) ? 'SHA1:'.sha1($ips[$i]).random_int(0,1000) : $hosts[$i]);
                    $stmt->bindValue(':ip', empty($ips[$i]) ? 'SHA1:'.sha1($hosts[$i]).random_int(0,1000) : $ips[$i]);
                    try {
                        if (!$stmt->execute()) {
                            $sensei->logger(__METHOD__ . " SQL Error-> : " . $sensei->database->lastErrorMsg());
                            if (strpos($sensei->database->lastErrorMsg(), 'UNIQUE') !== false) {
                                $prm = empty($hosts[$i]) ? $ips[$i] : $hosts[$i];
                                if ($sensei->database->lastErrorMsg() == 'UNIQUE constraint failed: custom_applications_host_ip.host')
                                    $prm = $hosts[$i];
                                if ($sensei->database->lastErrorMsg() == 'UNIQUE constraint failed: custom_applications_host_ip.ip')
                                    $prm = $ips[$i];
                                $response['message'] = sprintf('This %s is duplicate. Please remove dublicate values and try again.',$prm);
                            } else
                                $response['message'] = 'Custom application could not save to Host and ip.';

                            $stmt = $sensei->database->prepare('delete from custom_applications_host_ip where custom_application_id=:custom_application_id');
                            $stmt->bindValue(':custom_application_id', $maxId);
                            $stmt->execute();
                            $sensei->logger(__METHOD__ . " Delete custom applications host and ip");
                            $stmt = $sensei->database->prepare('delete from custom_applications where id=:custom_application_id');
                            $stmt->bindValue(':custom_application_id', $maxId);
                            $stmt->execute();
                            $sensei->logger(__METHOD__ . " Deleted custom applications");
                            return $response;
                        }
                    } catch (\Exception $th) {
                        $sensei->logger(__METHOD__ . " SQL Error : " . $th->getMessage());
                        if (strpos($th->getMessage(), 'UNIQUE constraint failed: custom_applications_host_ip.host') !== false) {
                            $response['message'] = 'Hostname must be uniq ->' . $hosts[$i];
                        }
                        if (strpos($th->getMessage(), 'UNIQUE constraint failed: custom_applications_host_ip.ip') !== false) {
                            $response['message'] = 'IP address must be uniq -> ' . $ips[$i];
                        }
                        $stmt = $sensei->database->prepare('delete from custom_applications_host_ip where custom_application_id=:custom_application_id');
                        $stmt->bindValue(':custom_application_id', $maxId);
                        $stmt->execute();
                        $sensei->logger(__METHOD__ . " Delete custom applications host and ip");
                        $stmt = $sensei->database->prepare('delete from custom_applications where id=:custom_application_id');
                        $stmt->bindValue(':custom_application_id', $maxId);
                        $stmt->execute();
                        $sensei->logger(__METHOD__ . " Delete custom applications");
                        return $response;
                    }
                }

                $stmt = $sensei->database->prepare('delete from policy_custom_app_categories where custom_application_id=:custom_application_id');
                $stmt->bindValue(':custom_application_id', $maxId);
                if (!$stmt->execute()) {
                    $sensei->logger(__METHOD__ . " SQL Error : " . $sensei->database->lastErrorMsg());
                    $response['message'] = 'Custom application could not save to Policy..';
                    return $response;
                }

                $policies = $sensei->database->query('SELECT * FROM policies');
                $error = false;
                while ($row = $policies->fetchArray($mode = SQLITE3_ASSOC)) {
                    $acceptCustomCount = $sensei->database->querySingle('select count(*) as total from policy_custom_app_categories p,custom_applications a where p.policy_id=' . $row['id'] . ' and p.custom_application_id=a.id and a.application_category_id=' . $category . " and action='accept'", false);
                    $acceptCount = $sensei->database->querySingle('select count(*) as total from policy_app_categories p,applications a where policy_id=' . $row['id'] . ' and application_id=a.id and a.application_category_id=' . $category . " and action='accept'", false);

                    $stmtIn = $sensei->database->prepare('INSERT INTO policy_custom_app_categories (policy_id, custom_application_id, uuid,action ,writetofile) VALUES' .
                        '(:policy_id, :custom_application_id, :uuid, :action ,:writetofile)');
                    $stmtIn->bindValue(':policy_id', $row['id']);
                    $stmtIn->bindValue(':custom_application_id', $maxId);
                    $stmtIn->bindValue(':uuid', 'custom-' . $sensei->generateUUID());
                    $stmtIn->bindValue(':action', (($acceptCustomCount + $acceptCount) == 0 ? 'reject' : 'accept'));
                    $stmtIn->bindValue(':writetofile', 'on');
                    if (!$stmtIn->execute()) {
                        $sensei->logger(__METHOD__ . " SQL Error :->in " . $sensei->database->lastErrorMsg());
                        $response['message'] = 'Custom application could not saved to Policy.';
                        $error = true;
                        return $response;
                    }
                }
                if ($error == false) {
                    if ($send == 'true') {
                        $data = ["name" => $name, "description" => $desc, "hostnames" => str_replace(PHP_EOL, ',', $hostnames), "ip_addrs" => str_replace(PHP_EOL, ',', $ip_addrs), "category" => $category, "category_name" => $category_name, "protocol" => $protocol];
                        $sensei->sendJson($data, 'https://health.sunnyvalley.io/client_custom_application.php');
                    }
                    $response['successful'] = true;
                    return $response;
                }
            }
        } catch (\Exception $e) {
            $sensei->logger(__METHOD__ . ' Exception :' . $e->getMessage());
            $response['message'] = 'Error Occurr Process could not finished';
            return $response;
        }
    }
}
