<?php

/**
 *    Copyright (C) 2018 Michael Muenz <m.muenz@gmail.com>
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Bind\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Bind\General;
use OPNsense\Bind\Dnsbl;
use OPNsense\Bind\Domain;

/**
 * Class ServiceController
 * @package OPNsense\Bind
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\Bind\General';
    protected static $internalServiceTemplate = 'OPNsense/Bind';
    protected static $internalServiceEnabled = 'enabled';
    protected static $internalServiceName = 'bind';

    public function dnsblAction()
    {
        $mdl = new Dnsbl();
        $backend = new Backend();
        $response = $backend->configdpRun('bind dnsbl', [(string)$mdl->type]);
        return ['response' => $response];
    }

    /**
     * Zones that switch to dynamic mode are captured from the running server before the template
     * reload replaces their static zone file. Configured records are applied once named is up again.
     * @return array status
     */
    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }

        $backend = new Backend();
        foreach ((new Domain())->domains->domain->iterateItems() as $domain) {
            if (
                (string)$domain->enabled == '1' &&
                (string)$domain->type == 'primary' &&
                (string)$domain->dynamic == '1'
            ) {
                $backend->configdpRun('bind dynzone.capture', [(string)$domain->domainname]);
            }
        }

        $result = parent::reconfigureAction();

        if ($result['status'] == 'ok') {
            $response = json_decode(trim($backend->configdRun('bind dynzone.reconcile')), true);
            if (empty($response['status']) || $response['status'] != 'ok') {
                $result['status'] = 'failed';
                $result['status_msg'] = !empty($response['message']) ?
                    $response['message'] : gettext('Applying the configured records to the dynamic zones failed.');
            }
        }

        return $result;
    }
}
