#!/bin/bash
# TaraSec captive portal ThemeSpec for openNDS 10.x.
# Self-contained for captive portal mini-browsers.

title="TaraSec Hotspot"
download_data_files() { :; }
download_image_files() { :; }

generate_splash_sequence() {
    if access_allowed; then
        authenticated_status_page
    else
        denied_page
    fi
}

header() {
    echo "<!doctype html>
<html lang=\"en\"><head>
<meta http-equiv=\"Cache-Control\" content=\"no-cache, no-store, must-revalidate\">
<meta http-equiv=\"Pragma\" content=\"no-cache\"><meta http-equiv=\"Expires\" content=\"0\">
<meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">
<title>TaraSec Hotspot</title>
<style>*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#eef3f8;color:#172233;line-height:1.45}.top{background:#17212d;color:#fff;padding:15px 20px;font-weight:700;font-size:20px}.top span{font-weight:400;color:#b8c5d3;font-size:13px;margin-left:8px}.hero{background:linear-gradient(135deg,#1265ad,#268bc7);color:#fff;padding:28px 18px}.wrap{max-width:760px;margin:auto}.hero h1{font-size:30px;margin:0 0 8px}.hero p{margin:0;opacity:.95}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.status{background:#17212d;border-radius:10px;padding:14px}.dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#35c96f;margin-right:7px}.card{background:#fff;margin:18px auto;padding:22px;border-radius:12px;box-shadow:0 4px 16px rgba(22,43,67,.12)}h2{margin-top:0;color:#1265ad}.lead{font-size:17px}.note{background:#eef7ff;border-left:4px solid #268bc7;padding:12px;margin:15px 0}.research{background:#f6f8fa;border-radius:9px;padding:13px;margin:15px 0;font-size:14px}.btn{display:block;width:100%;border:0;border-radius:8px;padding:14px 18px;background:#1265ad;color:#fff;font-size:17px;font-weight:700;cursor:pointer;text-align:center;text-decoration:none;margin-top:10px}.btn2{background:#fff;color:#1265ad;border:1px solid #1265ad}.btnlogout{background:#a43737}.field{width:100%;padding:12px;border:1px solid #b9c7d5;border-radius:7px;font-size:16px;margin:5px 0 12px}.small{font-size:12px;color:#68788a;margin-top:15px}.ok{font-size:24px;color:#168b4a;font-weight:700}.bad{font-size:22px;color:#a43737;font-weight:700}@media(max-width:520px){.grid{grid-template-columns:1fr}.hero h1{font-size:25px}.card{margin:12px;padding:17px}}</style>
</head><body><div class=\"top\">TaraSec <span>Hotspot</span></div>
<div class=\"hero\"><div class=\"wrap\"><h1>Security &amp; connectivity</h1><p>Internet access through a TaraSec-enabled hotspot.</p><div class=\"grid\"><div class=\"status\"><span class=\"dot\"></span><b>Connectivity</b><br>Hotspot available</div><div class=\"status\"><span class=\"dot\"></span><b>TaraSec</b><br>Protection active</div></div></div></div><div class=\"wrap\"><div class=\"card\">"
}

footer() {
    year=$(date +'%Y')
    echo "<div class=\"small\">TaraSec / Taransvar &middot; $year<br>This page is served locally by the hotspot.</div></div></div></body></html>"
    exit 0
}

access_allowed() {
    /usr/local/sbin/tarasec-access-check "$clientip"
}

hotspot_web_base() {
    local host="${gatewayaddress%%:*}"
    [ -n "$host" ] || host="192.168.50.1"
    printf 'http://%s:8080/hotspot' "$host"
}

authenticated_status_page() {
    local loginbase
    loginbase="$(hotspot_web_base)"
    if [ -z "${custom:-}" ]; then customhtml=""; else customhtml="<input type=\"hidden\" name=\"custom\" value=\"$custom\">"; fi
    echo "<div class=\"ok\">Welcome back</div>
<p class=\"lead\">This device has active TaraSec hotspot access.</p>
<form action=\"/opennds_preauth/\" method=\"get\"><input type=\"hidden\" name=\"fas\" value=\"$fas\">$customhtml$custom_passthrough<input type=\"hidden\" name=\"landing\" value=\"yes\"><input class=\"btn\" type=\"submit\" value=\"Continue to Internet\"></form>
<a class=\"btn btnlogout\" href=\"$loginbase/portal_status.php\">Log out</a>
<a class=\"btn btn2\" href=\"$loginbase/portal_status.php\">My access / account information</a>
<div class=\"note\"><b>About this hotspot</b><br>Learn about TaraSec and Taransvar, hotspot security, acceptable use and privacy from the information links below.</div>
<form action=\"/opennds_preauth/\" method=\"get\"><input type=\"hidden\" name=\"fas\" value=\"$fas\">$custom_passthrough<input type=\"hidden\" name=\"terms\" value=\"yes\"><input class=\"btn btn2\" type=\"submit\" value=\"TaraSec / Taransvar information\"></form>"
    footer
}

denied_page() {
    local loginbase
    loginbase="$(hotspot_web_base)"
    echo "<div class=\"bad\">Internet access is not active</div><p>This device does not currently have access on this hotspot.</p>
<div class=\"note\"><b>Already have a hotspot account?</b><br>Log in below. Your subscription or quota determines whether Internet access is granted.</div>
<form id=\"tslogin\" action=\"$loginbase/portal_login.php\" method=\"post\">
<input type=\"hidden\" name=\"client_ip\" value=\"$clientip\">
<input type=\"hidden\" name=\"fas\" value=\"$fas\">
$custom_inputs
<label for=\"tsuser\"><b>Username</b></label><input id=\"tsuser\" class=\"field\" name=\"name\" autocomplete=\"username\" required>
<label for=\"tspass\"><b>Password</b></label><input id=\"tspass\" class=\"field\" name=\"pass\" type=\"password\" autocomplete=\"current-password\" required>
<label class=\"small\" style=\"display:block;margin:0 0 12px\"><input id=\"tsremember\" type=\"checkbox\" checked> Remember username on this device</label>
<input class=\"btn\" type=\"submit\" value=\"Log in\"></form>
<script>(function(){try{var f=document.getElementById('tslogin'),u=document.getElementById('tsuser'),r=document.getElementById('tsremember'),k='tarasec_hotspot_username';var v=localStorage.getItem(k);if(v){u.value=v;}f.addEventListener('submit',function(){if(r.checked){localStorage.setItem(k,u.value);}else{localStorage.removeItem(k);}});}catch(e){}})();</script>
<div class=\"note\"><b>Need access?</b><br>Available plans and payment options are shown below when online payment is configured. You can also ask the hotspot operator for access.</div>"
    read_terms
}

thankyou_page() { authenticated_status_page; }

landing_page() {
    configure_log_location; . "$mountpoint/ndscids/ndsinfo"; auth_log
    if [ "$ndsstatus" = "authenticated" ]; then
        echo "<div class=\"ok\">Internet access enabled</div><p>You can close this window and continue using the Internet.</p>"
        footer
    else
        echo "<div class=\"bad\">Connection was not authorized</div><p>The request may have timed out. Please try again.</p><form><input class=\"btn\" type=\"button\" value=\"Try again\" onClick=\"location.href='http://$gatewayfqdn'\"></form>"
        footer
    fi
}

read_terms() { echo "<form action=\"/opennds_preauth/\" method=\"get\"><input type=\"hidden\" name=\"fas\" value=\"$fas\">$custom_passthrough<input type=\"hidden\" name=\"terms\" value=\"yes\"><input class=\"btn btn2\" type=\"submit\" value=\"Terms &amp; privacy\"></form>"; }

display_terms() {
    echo "<h2>TaraSec / Taransvar</h2><p><b>TaraSec</b> provides the hotspot connectivity and security functions used on this network. <b>Taransvar</b> is the wider collaborative approach behind the project.</p><p><b>Acceptable use.</b> Do not use this connection to attack systems, distribute malware, send abusive automated traffic, evade access controls, or otherwise misuse the service.</p><p><b>Security.</b> The hotspot may restrict or report network traffic when necessary to operate the service or protect networks. Security indicators are technical signals and can have innocent explanations, including accidental infection.</p><p><b>Connection data.</b> The hotspot necessarily handles technical information required to route and authorize your connection. TaraSec should avoid collecting unnecessary personal information from clean devices.</p><p><b>Optional participation.</b> Research participation, precise geographic location and other optional contributions require separate explanation and user choice. They are not implied by accepting basic hotspot access.</p><p><b>No guarantee.</b> Internet and security services can fail or be interrupted. Users remain responsible for protecting important data and devices.</p><form><input class=\"btn\" type=\"button\" value=\"Return\" onClick=\"history.go(-1);return true;\"></form>"
    footer
}

session_length="0"; upload_rate="0"; download_rate="0"; upload_quota="0"; download_quota="0"
quotas="$session_length $upload_rate $download_rate $upload_quota $download_quota"
ndscustomparams=""; ndscustomimages=""; ndscustomfiles=""; ndsparamlist="$ndsparamlist"; additionalthemevars=""; fasvarlist="$fasvarlist"; userinfo="$title"
