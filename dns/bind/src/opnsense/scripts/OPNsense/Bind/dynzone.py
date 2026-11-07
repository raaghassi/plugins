#!/usr/local/bin/python3
"""
    Copyright (C) 2026 raaghassi
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
       this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
       notice, this list of conditions and the following disclaimer in the
       documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

    --------------------------------------------------------------------------------------
    Primary zones in dynamic mode: named owns the zone file. This tool applies the
    records configured in the GUI with dynamic updates (RFC 2136) and reads the live
    zone with a zone transfer. Its input is the rendered dynzones.json.

    usage: dynzone.py bootstrap         create missing zone files, runs before named starts
           dynzone.py capture <zone>    copy a loaded static zone, journal included, before it turns dynamic
           dynzone.py reconcile         apply configured records and SOA parameters to the running zones
           dynzone.py list <zone>       print the live zone as JSON rows tagged "config" or "dynamic"
"""
import json
import os
import pwd
import subprocess
import sys
import syslog
import time

import dns.exception
import dns.name
import dns.query
import dns.rcode
import dns.rdata
import dns.rdataclass
import dns.rdatatype
import dns.tsigkeyring
import dns.update
import dns.xfr
import dns.zone

CONFIG_FILE = '/usr/local/etc/namedb/dynzones.json'
PRIMARY_DIR = '/usr/local/etc/namedb/primary'
DYNAMIC_DIR = '/usr/local/etc/namedb/dynamic'
STATE_DIR = '/var/db/bind/dynzone'
ZONE_OWNER = 'bind'
LOAD_TIMEOUT = 15
ENV = dict(os.environ, PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin')


def load_config():
    with open(CONFIG_FILE) as handle:
        return json.load(handle)


def zone_file(zone):
    return os.path.join(DYNAMIC_DIR, '%s.db' % zone)


def static_file(zone):
    return os.path.join(PRIMARY_DIR, '%s.db' % zone)


def state_file(zone):
    return os.path.join(STATE_DIR, '%s.json' % zone)


def absolute(name):
    """ zone and host names from the GUI may lack the trailing dot
    """
    return name if name.endswith('.') else name + '.'


def run(args):
    return subprocess.run(args, capture_output=True, text=True, env=ENV)


def zone_loaded(zone):
    return run(['rndc', 'zonestatus', zone]).returncode == 0


def wait_loaded(zone, timeout=LOAD_TIMEOUT):
    deadline = time.monotonic() + timeout
    while not zone_loaded(zone):
        if time.monotonic() >= deadline:
            return False
        time.sleep(1)
    return True


def chown_zone_file(path):
    try:
        owner = pwd.getpwnam(ZONE_OWNER)
        os.chown(path, owner.pw_uid, owner.pw_gid)
    except KeyError:
        pass
    os.chmod(path, 0o644)


def import_zone(zone, source, target, with_journal):
    """ dump a master file, optionally with its journal, in canonical form into target
    """
    args = ['named-checkzone', '-q', '-D', '-o', target]
    if with_journal:
        args.append('-j')
    result = run(args + [zone, source])
    if result.returncode != 0 or not os.path.isfile(target):
        if os.path.exists(target):
            os.unlink(target)
        return False
    chown_zone_file(target)
    return True


def write_initial_zone(zone, zone_cfg, target):
    """ first zone file for a zone that has no static file to import: SOA plus the configured records
    """
    soa = zone_cfg['soa']
    lines = [
        '$ORIGIN %s' % absolute(zone),
        '$TTL %d' % zone_cfg['ttl'],
        '@ IN SOA %s %s ( %d %d %d %d %d )' % (
            absolute(soa['mname']), absolute(soa['rname']), int(time.time()),
            soa['refresh'], soa['retry'], soa['expire'], soa['minimum']
        ),
    ]
    for record in zone_cfg['records']:
        lines.append('%s %s %s' % (record['name'] or '@', record['type'], record['value']))
    with open(target, 'w') as handle:
        handle.write('\n'.join(lines) + '\n')
    chown_zone_file(target)
    check = run(['named-checkzone', '-q', zone, target])
    return check.returncode == 0, check.stdout.strip()


def bootstrap(cfg):
    """ make sure every enabled dynamic zone has a zone file before named loads its configuration
    """
    os.makedirs(DYNAMIC_DIR, exist_ok=True)
    results = {}
    for zone, zone_cfg in cfg['zones'].items():
        target = zone_file(zone)
        if zone_cfg.get('enabled') != '1':
            results[zone] = 'disabled'
        elif os.path.exists(target):
            results[zone] = 'present'
        else:
            source = static_file(zone)
            if os.path.isfile(source) and os.path.getsize(source) > 0 and import_zone(zone, source, target, True):
                results[zone] = 'imported'
            elif os.path.isfile(source) and os.path.getsize(source) > 0 and import_zone(zone, source, target, False):
                results[zone] = 'imported without journal'
            else:
                valid, message = write_initial_zone(zone, zone_cfg, target)
                results[zone] = 'created' if valid else 'created with errors: %s' % message
            syslog.syslog(syslog.LOG_NOTICE, 'dynamic zone %s: %s (%s)' % (zone, results[zone], target))
    return results


def capture(zone):
    """ a zone that is about to switch to dynamic mode is still served from its static file; copy the
        served content, journal included, before the template reload replaces the static file.
    """
    target = zone_file(zone)
    if os.path.exists(target):
        return 'present'
    if not zone_loaded(zone):
        return 'not loaded'
    os.makedirs(DYNAMIC_DIR, exist_ok=True)
    sync = run(['rndc', 'sync', zone])
    if sync.returncode != 0:
        syslog.syslog(syslog.LOG_WARNING, 'dynamic zone %s: rndc sync failed: %s' % (zone, sync.stderr.strip()))
    source = static_file(zone)
    if import_zone(zone, source, target, True):
        result = 'captured'
    elif import_zone(zone, source, target, False):
        result = 'captured without journal'
    else:
        result = 'failed'
    syslog.syslog(syslog.LOG_NOTICE, 'dynamic zone %s: %s' % (zone, result))
    return result


def keyring_for(cfg):
    key = cfg.get('key') or {}
    if not key:
        raise RuntimeError('no RNDC key configured')
    keyring = dns.tsigkeyring.from_text({key['name']: (key['algorithm'], key['secret'])})
    return keyring, dns.name.from_text(key['name']), key['algorithm']


def transfer(cfg, zone):
    """ fetch the live zone from named with a TSIG signed AXFR over loopback
    """
    keyring, keyname, algorithm = keyring_for(cfg)
    zone_obj = dns.zone.Zone(dns.name.from_text(zone), relativize=False)
    query, _ = dns.xfr.make_query(zone_obj, serial=None, keyring=keyring, keyname=keyname, keyalgorithm=algorithm)
    dns.query.inbound_xfr(
        cfg['server']['address'], zone_obj, query, port=cfg['server']['port'], timeout=10, lifetime=60
    )
    return zone_obj


def rr_key(name, rdata):
    return (name.to_text(), dns.rdatatype.to_text(rdata.rdtype), rdata.to_text())


def live_records(zone_obj):
    result = {}
    for name, ttl, rdata in zone_obj.iterate_rdatas():
        if rdata.rdtype != dns.rdatatype.SOA:
            result[rr_key(name, rdata)] = (name, ttl, rdata)
    return result


def configured_records(zone_cfg, origin):
    """ the enabled GUI records of a zone in canonical form, plus the ones that do not parse
    """
    result = {}
    errors = []
    for record in zone_cfg['records']:
        try:
            name = dns.name.from_text(record['name'] or '@', origin)
            rdata = dns.rdata.from_text(
                dns.rdataclass.IN, record['type'], record['value'], origin=origin, relativize=False
            )
        except (dns.exception.DNSException, ValueError) as error:
            errors.append('%s %s %s: %s' % (record['name'] or '@', record['type'], record['value'], error))
            continue
        result[rr_key(name, rdata)] = (name, zone_cfg['ttl'], rdata)
    return result, errors


def read_state(zone):
    try:
        with open(state_file(zone)) as handle:
            return {tuple(item) for item in json.load(handle)}
    except (OSError, ValueError):
        return set()


def write_state(zone, keys):
    os.makedirs(STATE_DIR, exist_ok=True)
    with open(state_file(zone), 'w') as handle:
        json.dump(sorted(list(key) for key in keys), handle)


def cleanup_state(cfg):
    if os.path.isdir(STATE_DIR):
        for entry in os.listdir(STATE_DIR):
            if entry.endswith('.json') and entry[:-5] not in cfg['zones']:
                os.unlink(os.path.join(STATE_DIR, entry))


def wanted_soa(zone_cfg, origin, serial):
    soa = zone_cfg['soa']
    text = '%s %s %d %d %d %d %d' % (
        absolute(soa['mname']), absolute(soa['rname']), serial,
        soa['refresh'], soa['retry'], soa['expire'], soa['minimum']
    )
    return dns.rdata.from_text(dns.rdataclass.IN, dns.rdatatype.SOA, text, origin=origin, relativize=False)


def soa_differs(live, wanted):
    return (live.mname, live.rname, live.refresh, live.retry, live.expire, live.minimum) != \
        (wanted.mname, wanted.rname, wanted.refresh, wanted.retry, wanted.expire, wanted.minimum)


def reconcile_zone(cfg, zone, zone_cfg):
    """ send one dynamic update that adds missing configured records, removes records this tool applied
        earlier that are no longer configured, and replaces the SOA when its parameters changed.
        Records written by other RFC 2136 clients are never touched.
    """
    if not wait_loaded(zone):
        raise RuntimeError('zone is not loaded')
    zone_obj = transfer(cfg, zone)
    origin = zone_obj.origin
    live = live_records(zone_obj)
    wanted, errors = configured_records(zone_cfg, origin)
    applied = read_state(zone)
    adds = [wanted[key] for key in wanted if key not in live]
    dels = [live[key] for key in applied if key in live and key not in wanted]
    soa_rdataset = zone_obj.get_rdataset(origin, dns.rdatatype.SOA)
    live_soa = soa_rdataset[0]
    new_soa = wanted_soa(zone_cfg, origin, live_soa.serial + 1)
    soa_update = soa_differs(live_soa, new_soa) or soa_rdataset.ttl != zone_cfg['ttl']
    if adds or dels or soa_update:
        keyring, keyname, algorithm = keyring_for(cfg)
        update = dns.update.UpdateMessage(origin, keyring=keyring, keyname=keyname, keyalgorithm=algorithm)
        for name, ttl, rdata in dels:
            update.delete(name, rdata)
        for name, ttl, rdata in adds:
            update.add(name, ttl, rdata)
        if soa_update:
            update.replace(origin, zone_cfg['ttl'], new_soa)
        response = dns.query.tcp(update, cfg['server']['address'], port=cfg['server']['port'], timeout=10)
        if response.rcode() != dns.rcode.NOERROR:
            raise RuntimeError('update refused: %s' % dns.rcode.to_text(response.rcode()))
    write_state(zone, set(wanted.keys()))
    return {
        'status': 'ok' if not errors else 'warning',
        'added': len(adds),
        'deleted': len(dels),
        'soa_updated': soa_update,
        'errors': errors,
    }


def reconcile(cfg):
    results = {}
    failed = False
    for zone, zone_cfg in cfg['zones'].items():
        if zone_cfg.get('enabled') != '1':
            results[zone] = {'status': 'disabled'}
            continue
        try:
            results[zone] = reconcile_zone(cfg, zone, zone_cfg)
            if results[zone]['added'] or results[zone]['deleted'] or results[zone]['soa_updated']:
                syslog.syslog(syslog.LOG_NOTICE, 'dynamic zone %s: %s' % (zone, json.dumps(results[zone])))
        except (RuntimeError, OSError, dns.exception.DNSException) as error:
            results[zone] = {'status': 'failed', 'message': str(error)}
            syslog.syslog(syslog.LOG_ERR, 'dynamic zone %s: %s' % (zone, error))
            failed = True
    cleanup_state(cfg)
    return results, failed


def list_zone(cfg, zone):
    if zone not in cfg['zones']:
        raise RuntimeError('%s is not a dynamic zone' % zone)
    zone_obj = transfer(cfg, zone)
    origin = zone_obj.origin
    wanted, _ = configured_records(cfg['zones'][zone], origin)
    rows = []
    for name, ttl, rdata in zone_obj.iterate_rdatas():
        if rdata.rdtype == dns.rdatatype.SOA:
            continue
        rows.append({
            'name': name.relativize(origin).to_text(),
            'type': dns.rdatatype.to_text(rdata.rdtype),
            'ttl': ttl,
            'value': rdata.to_text(),
            'source': 'config' if rr_key(name, rdata) in wanted else 'dynamic',
        })
    return {'zone': zone, 'serial': zone_obj.get_rdataset(origin, dns.rdatatype.SOA)[0].serial, 'rows': rows}


def main():
    syslog.openlog('bind-dynzone', facility=syslog.LOG_DAEMON)
    command = sys.argv[1] if len(sys.argv) > 1 else ''
    output = {'status': 'ok'}
    try:
        if command == 'capture' and len(sys.argv) == 3:
            output['result'] = capture(sys.argv[2])
        elif command == 'bootstrap':
            output['zones'] = bootstrap(load_config())
        elif command == 'reconcile':
            output['zones'], failed = reconcile(load_config())
            if failed:
                output['status'] = 'failed'
                output['message'] = 'applying records failed for: %s' % ', '.join(
                    zone for zone, result in output['zones'].items() if result['status'] == 'failed'
                )
        elif command == 'list' and len(sys.argv) == 3:
            output.update(list_zone(load_config(), sys.argv[2]))
        else:
            print(__doc__.split('usage:', 1)[1].rstrip(), file=sys.stderr)
            sys.exit(2)
    except (OSError, ValueError, RuntimeError, dns.exception.DNSException) as error:
        output = {'status': 'failed', 'message': str(error)}
    print(json.dumps(output))
    sys.exit(0 if output['status'] != 'failed' else 1)


if __name__ == '__main__':
    main()
