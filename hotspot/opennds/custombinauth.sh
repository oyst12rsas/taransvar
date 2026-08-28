#!/bin/bash
# Included by openNDS stock binauth_log.sh. Preserve the stock wrapper so
# auth_restore and normal openNDS accounting continue to work.
#
# IMPORTANT: do not delete TaraSec access on generic client_deauth events.
# openNDS can emit those during normal captive-portal/authentication lifecycle
# transitions. Explicit subscriber logout is handled only by
# tarasec-subscriber-logout from portal_status.php.

custombinauth_title="TaraSec access policy"
custombinauth_description="Access-table authorization and per-client quota overrides"
POLICY=/usr/lib/opennds/access_policy.pl

if [ "${action:-}" = "auth_client" ] && [ -n "${clientip:-}" ] && [ -x "$POLICY" ]; then
    IFS=$'\t' read -r ts_access ts_session ts_up_rate ts_down_rate ts_up_quota ts_down_quota < <("$POLICY" "$clientip")
    if [ "${ts_access:-0}" != "1" ]; then
        exitlevel=1
    else
        sessiontimeout="${ts_session:-0}"
        upload_rate="${ts_up_rate:-0}"
        download_rate="${ts_down_rate:-0}"
        upload_quota="${ts_up_quota:-0}"
        download_quota="${ts_down_quota:-0}"
        exitlevel=0
    fi
fi
