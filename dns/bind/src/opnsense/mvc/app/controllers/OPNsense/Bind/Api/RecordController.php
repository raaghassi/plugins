<?php

/*
 * Copyright (C) 2019 Michael Muenz <m.muenz@gmail.com>
 * Copyright (C) 2019 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Bind\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Bind\Domain;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;

class RecordController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'record';
    protected static $internalModelClass = '\OPNsense\Bind\Record';

    /**
     * update parent domain serial
     * @param $uuid string
     * @throws \OPNsense\Base\ModelException
     * @throws \ReflectionException
     */
    private function setDomainSerial($uuid)
    {
        if ($this->request->isPost()) {
            $record  = $this->getModel()->getRecord($uuid);
            if ($record !== null) {
                (new Domain())->updateSerial((string)$record->domain)->serializeToConfig();
                Config::getInstance()->save();
            }
        }
        $this->refreshDynamicZones();
    }

    /**
     * Re-render the input of the dynamic zone tooling (dynzones.json only, its own template module),
     * so the live view and its conflict detection follow a record change before Apply.
     */
    private function refreshDynamicZones()
    {
        (new Backend())->configdRun('template reload OPNsense/Bind/Dynzones');
    }

    public function searchRecordAction()
    {
        $domain = $this->request->get('domain');
        $filter_funct = null;
        if (!empty($domain)) {
            $domainNode = (new Domain())->getNodeByReference('domains.domain.' . $domain);
            if (
                $domainNode !== null &&
                (string)$domainNode->type == 'primary' &&
                (string)$domainNode->dynamic == '1'
            ) {
                return $this->searchDynamicZone($domainNode);
            }
            $filter_funct = function ($record) use ($domain) {
                return $record->domain == $domain;
            };
        }

        $result = $this->searchBase(
            'records.record',
            array("enabled", "domain", "name", "type", "value"),
            null,
            $filter_funct
        );
        foreach ($result['rows'] as &$row) {
            $row['source'] = 'config';
        }
        return $result;
    }

    /**
     * @param string $uuid zone uuid
     * @return \OPNsense\Base\FieldTypes\ArrayField|null the zone when it exists and is in dynamic mode
     */
    private function getDynamicDomain($uuid)
    {
        $domainNode = empty($uuid) ? null : (new Domain())->getNodeByReference('domains.domain.' . $uuid);
        if (
            $domainNode !== null &&
            (string)$domainNode->type == 'primary' &&
            (string)$domainNode->dynamic == '1'
        ) {
            return $domainNode;
        }
        return null;
    }

    /**
     * Live view of a zone in dynamic mode, including the (name, type) conflicts between the running
     * zone and the configured records.
     * @param string $domainName zone name
     * @return array rows and conflicts as returned by the backend, empty when named cannot be reached
     */
    private function liveZone($domainName)
    {
        $live = json_decode(trim((new Backend())->configdpRun('bind dynzone list', [$domainName])), true);
        return is_array($live) ? $live : [];
    }

    /**
     * Records of a zone in dynamic mode: the configured records, plus the records the running zone
     * holds that were written by dynamic update clients or that await removal. These carry no uuid
     * and are read-only.
     * Rows whose name and type carry different data on the two sides are flagged as conflicts.
     * @param \OPNsense\Base\FieldTypes\ArrayField $domainNode zone
     * @return array grid data
     */
    private function searchDynamicZone($domainNode)
    {
        $domainUuid = $domainNode->getAttribute('uuid');
        $domainName = (string)$domainNode->domainname;
        $live = $this->liveZone($domainName);
        $conflictUuids = [];
        foreach ($live['conflicts'] ?? [] as $conflict) {
            foreach ($conflict['config'] as $item) {
                $conflictUuids[$item['uuid']] = true;
            }
        }

        $rows = [];
        foreach ($this->getModel()->records->record->iterateItems() as $record) {
            if ((string)$record->domain != $domainUuid) {
                continue;
            }
            $uuid = $record->getAttribute('uuid');
            $rows[] = [
                'uuid' => $uuid,
                'enabled' => (string)$record->enabled,
                'domain' => $domainUuid,
                '%domain' => $domainName,
                'name' => (string)$record->name,
                'type' => (string)$record->type,
                'value' => (string)$record->value,
                'source' => 'config',
                'conflict' => isset($conflictUuids[$uuid]) ? '1' : '0',
            ];
        }

        foreach ($live['rows'] ?? [] as $row) {
            /* dynamic: written by another update client; stale: applied earlier, removed at the next Apply */
            if ($row['source'] != 'config') {
                $rows[] = [
                    'uuid' => '',
                    'enabled' => '1',
                    'domain' => $domainUuid,
                    '%domain' => $domainName,
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'value' => $row['value'],
                    'source' => $row['source'],
                    'conflict' => $row['conflict'],
                ];
            }
        }

        return $this->searchRecordsetBase(
            $rows,
            ['enabled', 'domain', 'name', 'type', 'value', 'source', 'conflict'],
            'name'
        );
    }

    /**
     * Operator decision for a record conflict in a zone in dynamic mode: the configured records take the
     * data the running zone holds for this owner name and type. Written through the model like
     * add/set/del, so the change waits for Apply like any other edit.
     * POST: domain (zone uuid), name, type
     * @return array result
     */
    public function adoptRecordAction()
    {
        $result = ['result' => 'failed'];
        if (!$this->request->isPost()) {
            return $result;
        }
        $domainNode = $this->getDynamicDomain($this->request->getPost('domain'));
        if ($domainNode === null) {
            $result['message'] = gettext('Zone not found or not in dynamic mode.');
            return $result;
        }
        $name = (string)$this->request->getPost('name');
        $type = (string)$this->request->getPost('type');
        $conflict = null;
        foreach ($this->liveZone((string)$domainNode->domainname)['conflicts'] ?? [] as $item) {
            if ($item['name'] == $name && $item['type'] == $type) {
                $conflict = $item;
            }
        }
        if ($conflict === null) {
            $result['message'] = gettext('No conflict found for this record.');
            return $result;
        }

        Config::getInstance()->lock();
        $mdl = $this->getModel();
        $configured = [];
        foreach ($conflict['config'] as $item) {
            $configured[$item['value']] = $item['uuid'];
        }
        if (count($configured) == 1 && count($conflict['live']) == 1) {
            $node = $mdl->getRecord(reset($configured));
            if ($node !== null) {
                $node->value = $conflict['live'][0];
            }
        } else {
            foreach ($configured as $value => $uuid) {
                if (!in_array($value, $conflict['live'])) {
                    $mdl->records->record->del($uuid);
                }
            }
            foreach ($conflict['live'] as $value) {
                if (!isset($configured[$value])) {
                    $node = $mdl->records->record->Add();
                    $node->enabled = '1';
                    $node->domain = $domainNode->getAttribute('uuid');
                    $node->name = $name;
                    $node->type = $type;
                    $node->value = $value;
                }
            }
        }
        $result = $this->validate();
        if (empty($result['validations'])) {
            $this->save(false, true);
            $this->refreshDynamicZones();
            $result = ['result' => 'saved'];
        } else {
            $result['result'] = 'failed';
        }
        return $result;
    }

    /**
     * Operator decision for a record conflict in a zone in dynamic mode: the running zone takes the
     * configured data for this owner name and type. Nothing is written to the configuration.
     * POST: domain (zone uuid), name, type
     * @return array result
     */
    public function overwriteRecordAction()
    {
        $result = ['result' => 'failed'];
        if (!$this->request->isPost()) {
            return $result;
        }
        $domainNode = $this->getDynamicDomain($this->request->getPost('domain'));
        if ($domainNode === null) {
            $result['message'] = gettext('Zone not found or not in dynamic mode.');
            return $result;
        }
        $response = json_decode(trim((new Backend())->configdpRun('bind dynzone overwrite', [
            (string)$domainNode->domainname,
            (string)$this->request->getPost('name'),
            (string)$this->request->getPost('type'),
        ])), true);
        if (!empty($response['status']) && $response['status'] == 'ok') {
            $result = ['result' => 'saved', 'added' => $response['added'], 'deleted' => $response['deleted']];
        } else {
            $result['message'] = $response['message'] ?? gettext('The zone could not be updated.');
        }
        return $result;
    }

    public function getRecordAction($uuid = null)
    {
        $domain = $this->request->get('domain');
        $result = $this->getBase('record', 'records.record', $uuid);
        if ($uuid == null && !empty($result['record']['domain'])) {
            // set domain selection
            foreach ($result['record']['domain'] as $key => &$value) {
                if ($key == $domain) {
                    $value['selected'] = 1;
                } else {
                    $value['selected'] = 0;
                }
            }
        }
        return $result;
    }

    public function addRecordAction()
    {
        $result = $this->addBase('record', 'records.record');
        if (!empty($result['uuid'])) {
            $this->setDomainSerial($result['uuid']);
        }
        return $result;
    }

    public function delRecordAction($uuid)
    {
        $result =  $this->delBase('records.record', $uuid);
        if ($result['result'] == 'deleted') {
            $this->setDomainSerial($uuid);
        }
        return $result;
    }

    public function setRecordAction($uuid = null)
    {
        $result =  $this->setBase('record', 'records.record', $uuid);
        if ($result['result'] == 'saved') {
            $this->setDomainSerial($uuid);
        }
        return $result;
    }

    public function toggleRecordAction($uuid)
    {
        $result =  $this->toggleBase('records.record', $uuid);
        if (!empty($result['changed'])) {
            $this->setDomainSerial($uuid);
        }
        return $result;
    }
}
