#!/bin/bash
# Included by openNDS' stock binauth_log.sh. Keep the stock BinAuth wrapper so
# auth_restore and normal openNDS accounting continue to work.

custombinauth_title="TaraSec access policy"
custombinauth_description="Access-table authorization and per-client quota overrides"

POLICY=/usr/lib/opennds/access_policy.pl

# auth_client exposes clientip. For later callbacks the IP may not be present;
# those callbacks are accounting/deauth notifications and need no policy change.
if [ "${action:-}" = "auth_client" ] && [ -n "${clientip:-}" ] && [ -x "$POLICY" ]; then
    IFS=$'\t' read -r ts_access ts_session ts_up_rate ts_down_rate ts_up_quota ts_down_quota < <("$POLICY" "$clientip")

    if [ "${ts_access:-0}" != "1" ]; then
        # Non-zero exitlevel rejects the authentication request.
        exitlevel=1
    else
        # Values of zero mean use openNDS defaults/no limit. openNDS expects
        # session timeout in minutes, rates in kbit/s, quotas in kB.
        sessiontimeout="${ts_session:-0}"
        upload_rate="${ts_up_rate:-0}"
        download_rate="${ts_down_rate:-0}"
        upload_quota="${ts_up_quota:-0}"
        download_quota="${ts_down_quota:-0}"
        exitlevel=0
    fi
fi
