# Scheduled maintenance notice

Deploy `html/script/maintenanceNotice.php` to `https://tarasec.org/script/maintenanceNotice.php`.
The app and Gatekeeper read the same public schedule over HTTPS, independently of the TaraSec database.

On the tarasec.org web host, set `/etc/tarasec/maintenance-notice.json` with the
start and end as Unix timestamps in seconds. For example, this schedules
maintenance on 16 November 2026 from 18:00 to 19:00 Europe/Oslo.

```sh
clear
start=$(TZ=Europe/Oslo date -d '2026-11-16 18:00' +%s)
end=$(TZ=Europe/Oslo date -d '2026-11-16 19:00' +%s)
printf '{"starts_at":%s,"ends_at":%s}\n' "$start" "$end"
```

Save the output in that file with permissions that let the web service read it
and restrict writes to operators. Use an atomic file replacement when updating
the schedule. The banner appears as soon as the schedule is fetched, switches
to in-progress wording at the start, and disappears at the end. Removing the
file cancels the notice on the next successful refresh. Times display in the
viewer’s local timezone.

Verify the public HTTPS response and open both clients before maintenance.
A successful response with no configured or current schedule is
`{"notice":null}`. Keep the public web endpoint reachable during maintenance so
users opening either client for the first time can fetch the warning.
