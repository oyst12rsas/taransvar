#!/bin/bash
# TaraSec captive portal ThemeSpec for openNDS 10.x.
# Deliberately self-contained: captive portal mini-browsers often block
# external CSS, JavaScript and CDN resources before authentication.
# Based on the openNDS ThemeSpec interface (GPL-compatible integration).

# Theme title used in openNDS logs.
title="TaraSec Hotspot"

# No remote assets are required by this theme.
download_data_files() { :; }
download_image_files() { :; }

generate_splash_sequence() {
    click_to_continue
}

header() {
    echo "<!doctype html>
<html lang=\"en\">
<head>
<meta http-equiv=\"Cache-Control\" content=\"no-cache, no-store, must-revalidate\">
<meta http-equiv=\"Pragma\" content=\"no-cache\">
<meta http-equiv=\"Expires\" content=\"0\">
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">
<title>TaraSec Hotspot</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#eef3f8;color:#172233;line-height:1.45}.top{background:#17212d;color:#fff;padding:15px 20px;font-weight:700;font-size:20px}.top span{font-weight:400;color:#b8c5d3;font-size:13px;margin-left:8px}.hero{background:linear-gradient(135deg,#1265ad,#268bc7);color:#fff;padding:28px 18px}.wrap{max-width:760px;margin:auto}.hero h1{font-size:30px;margin:0 0 8px}.hero p{margin:0;opacity:.95}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.status{background:#17212d;border-radius:10px;padding:14px}.dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#35c96f;margin-right:7px}.card{background:#fff;margin:18px auto;padding:22px;border-radius:12px;box-shadow:0 4px 16px rgba(22,43,67,.12)}h2{margin-top:0;color:#1265ad}.lead{font-size:17px}.note{background:#eef7ff;border-left:4px solid #268bc7;padding:12px;margin:15px 0}.research{background:#f6f8fa;border-radius:9px;padding:13px;margin:15px 0;font-size:14px}.btn{width:100%;border:0;border-radius:8px;padding:14px 18px;background:#1265ad;color:#fff;font-size:17px;font-weight:700;cursor:pointer}.btn2{background:#fff;color:#1265ad;border:1px solid #1265ad;margin-top:9px}.small{font-size:12px;color:#68788a;margin-top:15px}.ok{font-size:24px;color:#168b4a;font-weight:700}.bad{font-size:22px;color:#a43737;font-weight:700}@media(max-width:520px){.grid{grid-template-columns:1fr}.hero h1{font-size:25px}.card{margin:12px;padding:17px}}
</style>
</head>
<body>
<div class=\"top\">TaraSec <span>Hotspot</span></div>
<div class=\"hero\"><div class=\"wrap\"><h1>Security &amp; connectivity</h1><p>Internet access through a TaraSec-enabled hotspot.</p><div class=\"grid\"><div class=\"status\"><span class=\"dot\"></span><b>Connectivity</b><br>Hotspot available</div><div class=\"status\"><span class=\"dot\"></span><b>TaraSec</b><br>Protection active</div></div></div></div>
<div class=\"wrap\"><div class=\"card\">"
}

footer() {
    year=$(date +'%Y')
    echo "<div class=\"small\">TaraSec / Taransvar &middot; $year<br>This page is served locally by the hotspot before Internet access is enabled.</div></div></div></body></html>"
    exit 0
}

click_to_continue() {
    if [ "$continue" = "clicked" ]; then
        thankyou_page
        footer
    fi
    continue_form
    footer
}

continue_form() {
    echo "<h2>Welcome to TaraSec WiFi</h2>
<p class=\"lead\">You are connected to <b>$client_zone</b>. Continue to enable Internet access.</p>
<div class=\"note\"><b>What TaraSec is doing</b><br>The hotspot provides Internet connectivity and can use network-level security information to help identify potentially infected or abusive traffic. A warning does not by itself mean a person has committed an offence; devices can become infected accidentally.</div>
<div class=\"research\"><b>Privacy and research</b><br>Normal hotspot operation requires technical connection information such as addresses and session data. Optional TaraSec research or precise location sharing must be presented separately and is not enabled merely by pressing Continue here.</div>
<form action=\"/opennds_preauth/\" method=\"get\">
<input type=\"hidden\" name=\"fas\" value=\"$fas\">
<input type=\"hidden\" name=\"continue\" value=\"clicked\">
$custom_inputs
<input class=\"btn\" type=\"submit\" value=\"Continue to Internet\">
</form>"
    read_terms
    footer
}

thankyou_page() {
    if [ -z "$custom" ]; then customhtml=""; else customhtml="<input type=\"hidden\" name=\"custom\" value=\"$custom\">"; fi
    echo "<h2>Ready to connect</h2>
<p>Thanks for using this TaraSec hotspot. Press the button below to authorize this device and open Internet access.</p>
<div class=\"note\"><b>Security notice</b><br>TaraSec may warn users or hotspot operators when network behaviour suggests an infected device. The aim is to help clean devices and reduce harmful traffic, not to label ordinary users as criminals.</div>
<form action=\"/opennds_preauth/\" method=\"get\">
<input type=\"hidden\" name=\"fas\" value=\"$fas\">
$customhtml
$custom_passthrough
<input type=\"hidden\" name=\"landing\" value=\"yes\">
<input class=\"btn\" type=\"submit\" value=\"Enable Internet access\">
</form>"
    read_terms
    footer
}

landing_page() {
    originurl=$(printf "${originurl//%/\\x}")
    gatewayurl=$(printf "${gatewayurl//%/\\x}")
    configure_log_location
    . "$mountpoint/ndscids/ndsinfo"
    auth_log

    if [ "$ndsstatus" = "authenticated" ]; then
        echo "<div class=\"ok\">Internet access enabled</div><p>Your device is now authorized on this TaraSec hotspot.</p><p>You can return to your browser or other apps.</p><form><input class=\"btn\" type=\"button\" value=\"Hotspot status\" onClick=\"location.href='$gatewayurl'\"></form>"
    else
        echo "<div class=\"bad\">Connection was not authorized</div><p>The request may have timed out. Please return to the hotspot page and try again.</p><form><input class=\"btn\" type=\"button\" value=\"Try again\" onClick=\"location.href='http://$gatewayfqdn'\"></form>"
    fi
    footer
}

read_terms() {
    echo "<form action=\"/opennds_preauth/\" method=\"get\">
<input type=\"hidden\" name=\"fas\" value=\"$fas\">
$custom_passthrough
<input type=\"hidden\" name=\"terms\" value=\"yes\">
<input class=\"btn btn2\" type=\"submit\" value=\"Terms &amp; privacy\">
</form>"
}

display_terms() {
    echo "<h2>Hotspot terms &amp; privacy</h2>
<p><b>Acceptable use.</b> Do not use this connection to attack systems, distribute malware, send abusive automated traffic, evade access controls, or otherwise misuse the service.</p>
<p><b>Security.</b> The hotspot may restrict or report network traffic when necessary to operate the service or protect networks. Security indicators are technical signals and can have innocent explanations, including accidental infection.</p>
<p><b>Connection data.</b> The hotspot necessarily handles technical information required to route and authorize your connection. TaraSec should avoid collecting unnecessary personal information from clean devices.</p>
<p><b>Optional participation.</b> Research participation, precise geographic location and other optional contributions require separate explanation and user choice. They are not implied by accepting basic hotspot access.</p>
<p><b>No guarantee.</b> Internet and security services can fail or be interrupted. Users remain responsible for protecting important data and devices.</p>
<form><input class=\"btn\" type=\"button\" value=\"Return\" onClick=\"history.go(-1);return true;\"></form>"
    footer
}

# Session/rate/quota values: 0 means use global openNDS settings.
session_length="0"
upload_rate="0"
download_rate="0"
upload_quota="0"
download_quota="0"
quotas="$session_length $upload_rate $download_rate $upload_quota $download_quota"

# We intentionally require no remote custom assets or parameters.
ndscustomparams=""
ndscustomimages=""
ndscustomfiles=""
ndsparamlist="$ndsparamlist"
additionalthemevars=""
fasvarlist="$fasvarlist"
userinfo="$title"
