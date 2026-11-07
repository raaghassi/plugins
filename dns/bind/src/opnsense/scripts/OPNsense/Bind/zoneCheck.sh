#!/bin/sh

# check the primary zone file validity

ZONENAME=${1}
ZONEPATH="/usr/local/etc/namedb/primary/${ZONENAME}.db"
JOURNAL=""
if [ -f "/usr/local/etc/namedb/dynamic/${ZONENAME}.db" ]; then
    # dynamic zone: named owns the file, include its journal in the check
    ZONEPATH="/usr/local/etc/namedb/dynamic/${ZONENAME}.db"
    JOURNAL="-j"
fi
if checkzone_errors=$(named-checkzone ${JOURNAL} ${ZONENAME} ${ZONEPATH} 2>&1); then
    echo "Zone check completed successfully"
    echo "$checkzone_errors"
else
    echo "$checkzone_errors"
fi

exit 0
