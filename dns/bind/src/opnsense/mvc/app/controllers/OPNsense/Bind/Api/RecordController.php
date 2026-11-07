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
     * Records of a zone in dynamic mode: the configured records, plus the records the running zone
     * holds that were written by dynamic update clients. The latter carry no uuid and are read-only.
     * @param \OPNsense\Base\FieldTypes\ArrayField $domainNode zone
     * @return array grid data
     */
    private function searchDynamicZone($domainNode)
    {
        $domainUuid = $domainNode->getAttribute('uuid');
        $domainName = (string)$domainNode->domainname;
        $rows = [];
        foreach ($this->getModel()->records->record->iterateItems() as $record) {
            if ((string)$record->domain != $domainUuid) {
                continue;
            }
            $rows[] = [
                'uuid' => $record->getAttribute('uuid'),
                'enabled' => (string)$record->enabled,
                'domain' => $domainUuid,
                '%domain' => $domainName,
                'name' => (string)$record->name,
                'type' => (string)$record->type,
                'value' => (string)$record->value,
                'source' => 'config',
            ];
        }

        $live = json_decode(trim((new Backend())->configdpRun('bind dynzone.list', [$domainName])), true);
        foreach ($live['rows'] ?? [] as $row) {
            if ($row['source'] == 'dynamic') {
                $rows[] = [
                    'uuid' => '',
                    'enabled' => '1',
                    'domain' => $domainUuid,
                    '%domain' => $domainName,
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'value' => $row['value'],
                    'source' => 'dynamic',
                ];
            }
        }

        return $this->searchRecordsetBase($rows, ['enabled', 'domain', 'name', 'type', 'value', 'source'], 'name');
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
