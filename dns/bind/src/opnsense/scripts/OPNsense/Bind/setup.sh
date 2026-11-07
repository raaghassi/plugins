#!/bin/sh

for DIR in /var/run/named /var/dump /var/stats /var/log/named /usr/local/etc/namedb/primary /usr/local/etc/namedb/dynamic /var/db/bind/dynzone; do
	mkdir -p ${DIR}
	chown -R bind:bind ${DIR}
	chmod 755 ${DIR}
done

# dynamic zones: every zone file must exist before named loads the configuration
if [ -f /usr/local/etc/namedb/dynzones.json ]; then
	/usr/local/opnsense/scripts/OPNsense/Bind/dynzone.py bootstrap > /dev/null
fi
