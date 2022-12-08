<?php

namespace OPNsense\Sensei\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\Sensei\Sensei;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Cron\Cron;

class CronController extends ApiControllerBase
{
    private $cronJobs = [
        'periodicals' => [
            'command' => 'sensei periodicals',
            'description' => 'Zenarmor periodicals',
            'hours' => '*',
            'minutes' => '*'
        ]
    ];

    private $legacyCronJobs = ['Check Sensei packet engine health'];

    private $disableMailAlertParameter = '> /dev/null 2>&1';

    private function isEnabled($description)
    {
        $cronMdl = new Cron();
        foreach ($cronMdl->getNodeByReference('jobs.job')->getNodes() as $uuid => $node) {
            if ($node['description'] == $description and $node['enabled'] == '1') {
                return true;
            }
        }
        return false;
    }

    private function createJob($cronMdl, $key)
    {
        $cron = $cronMdl->jobs->job->Add();
        $cron->setNodes([
            'origin' => 'Zenarmor',
            'command' => $this->cronJobs[$key]['command'],
            'description' => $this->cronJobs[$key]['description'],
            'minutes' => $this->cronJobs[$key]['minutes'],
            'hours' => $this->cronJobs[$key]['hours'],
            'parameters' => $this->disableMailAlertParameter
        ]);
    }

    private function deleteJob($cronMdl, $key)
    {
        foreach ($cronMdl->getNodeByReference('jobs.job')->getNodes() as $uuid => $node) {
            if ($node['description'] == $this->cronJobs[$key]['description']) {
                $cronMdl->jobs->job->del($uuid);
            }
        }
    }

    private function editJob($cronMdl, $key)
    {
        $job = $this->cronJobs[$key];
        foreach ($cronMdl->getNodeByReference('jobs.job')->getNodes() as $uuid => $node) {
            if ($node['description'] == $job['description']) {
                if ($node['minutes'] != $job['minutes'] or $node['hours'] != $job['hours'] or !isset($job['parameters']) or $job['parameters'] != $this->disableMailAlertParameter) {
                    $cronMdl->getNodeByReference('jobs.job.' . $uuid)->setNodes([
                        'minutes' => $job['minutes'],
                        'hours' => $job['hours'],
                        'parameters' => $this->disableMailAlertParameter
                    ]);
                    return 1;
                }
            }
        }
        return 0;
    }

    private function removeLegacyJobs($cronMdl)
    {
        $deleted = 0;
        foreach ($cronMdl->getNodeByReference('jobs.job')->getNodes() as $uuid => $node) {
            if (in_array($node['description'], $this->legacyCronJobs)) {
                $cronMdl->jobs->job->del($uuid);
                $deleted += 1;
            }
        }
        return $deleted;
    }

    private function writeChanges($cronMdl)
    {
        $backend = new Backend();
        $cronMdl->serializeToConfig();
        Config::getInstance()->save();
        $backend->configdRun('template reload OPNsense/Cron');
        $backend->configdRun('cron restart');
    }

    public function initialize()
    {
        foreach ($this->cronJobs as $key => $value) {
            $this->cronJobs[$key]['enabled'] = $this->isEnabled($this->cronJobs[$key]['description']);
        }
    }

    public function jobsAction()
    {
        return $this->cronJobs;
    }

    public function configureAction()
    {
        $cronMdl = new Cron();
        $sensei = new Sensei();
        $response = [
            'deleted' => 0,
            'created' => 0,
            'changed' => 0
        ];
        $mustEnabled = [
            'periodicals' => true,
            'health' => (string) $sensei->getNodeByReference('general.healthCheck') == 'true',
            'update' => (string) $sensei->getNodeByReference('updater.autocheck') == 'true',
            'retire' => true,
            'reports' => (string) $sensei->getNodeByReference('reports.generate.enabled') == 'true'
        ];
        foreach ($this->cronJobs as $key => $value) {
            if ($mustEnabled[$key] != $value['enabled']) {
                if ($mustEnabled[$key]) {
                    $this->createJob($cronMdl, $key);
                    $response['created'] += 1;
                } else {
                    $this->deleteJob($cronMdl, $key);
                    $response['deleted'] += 1;
                }
            }
            $response['changed'] += $this->editJob($cronMdl, $key);
        }
        $response['deleted'] += $this->removeLegacyJobs($cronMdl);
        if (array_sum(array_values($response)) > 0) {
            $this->writeChanges($cronMdl);
        }
        return $response;
    }

    public function removeAllAction()
    {
        $cronMdl = new Cron();
        $response = [
            'deleted' => 0
        ];
        foreach ($cronMdl->getNodeByReference('jobs.job')->getNodes() as $uuid => $node) {
            if (stripos($node['description'], 'sensei') !== false or stripos($node['description'], 'eastpect') !== false) {
                $cron = $cronMdl->jobs->job->del($uuid);
                $response['deleted'] += 1;
            }
            if (stripos($node['description'], 'zenarmor') !== false or stripos($node['description'], 'eastpect') !== false) {
                $cron = $cronMdl->jobs->job->del($uuid);
                $response['deleted'] += 1;
            }
        }
        if ($response['deleted'] > 0) {
            $this->writeChanges($cronMdl);
        }
        return $response;
    }
}
