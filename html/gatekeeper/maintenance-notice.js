(() => {
    const banner = document.getElementById("maintenance-notice");
    const timeFormat = new Intl.DateTimeFormat(undefined, {
        year: "numeric", month: "short", day: "numeric",
        hour: "numeric", minute: "2-digit", timeZoneName: "short"
    });
    let notice = null;

    function render() {
        const now = Date.now() / 1000;
        banner.hidden = !notice || now >= notice.ends_at;
        if (banner.hidden) return;
        const title = now >= notice.starts_at ? "Maintenance in progress" : "Scheduled maintenance";
        const start = timeFormat.format(notice.starts_at * 1000);
        const end = timeFormat.format(notice.ends_at * 1000);
        banner.textContent = title + ". Service may be disrupted from " + start + " to " + end + ".";
    }

    async function refresh() {
        try {
            const response = await fetch("https://tarasec.org/script/maintenanceNotice.php", {
                cache: "no-store", credentials: "omit", signal: AbortSignal.timeout(5000)
            });
            if (!response.ok) return;
            const next = (await response.json()).notice;
            if (next === null || (next && Number.isSafeInteger(next.starts_at) &&
                Number.isSafeInteger(next.ends_at) && next.starts_at > 0 &&
                next.ends_at > next.starts_at && next.ends_at <= 253402300799)) notice = next;
        } catch {
            // Keep the last known notice visible until its scheduled end.
        }
        render();
    }

    refresh();
    setInterval(refresh, 60000);
    setInterval(render, 1000);
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) refresh();
    });
})();
