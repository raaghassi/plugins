#!/usr/bin/env python3

# send primary zone file content to stdout

import sys
import os.path
import glob
import subprocess
import ujson

zone_name = sys.argv[1]
result = dict()
zone_config = []
zone_files_root = '/usr/local/etc/namedb/primary/'
zone_file = zone_files_root + zone_name + '.db'
dynamic_zone_file = '/usr/local/etc/namedb/dynamic/' + zone_name + '.db'


def load_db_file(zone_file):
    """ load db-file
    """
    for line in open(zone_file, 'r').read().split('\n'):
        zone_config.append(line.rstrip())


if os.path.isfile(dynamic_zone_file):
    # dynamic zone: named owns the file, show the canonical content including the journal
    result['path'] = dynamic_zone_file
    result['time'] = os.path.getmtime(dynamic_zone_file)
    dump = subprocess.run(
        ['/usr/local/bin/named-checkzone', '-q', '-D', '-j', zone_name, dynamic_zone_file],
        capture_output=True, text=True
    )
    for line in dump.stdout.split('\n'):
        zone_config.append(line.rstrip())
    result['zone_content'] = zone_config
elif os.path.isfile(zone_file):
    result['path'] = zone_file
    result['time'] = os.path.getmtime(zone_file)
    load_db_file(zone_file)
    result['zone_content'] = zone_config
print(ujson.dumps(result))
