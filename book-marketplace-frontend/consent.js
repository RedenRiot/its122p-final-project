(function () {
    const CONSENT_KEY = "librowsePrivacyConsent";
    const COOKIE_NAME = "librowsePrivacyConsent";
    const VISITOR_KEY = "librowseVisitorKey";
    const VISIT_SESSION_PREFIX = "librowseVisitLogged:";

    function apiBase() {
        if (window.LIBROWSE_API_BASE) return String(window.LIBROWSE_API_BASE).replace(/\/$/, "");
        const host = window.location.hostname || "127.0.0.1";
        const port = window.location.port;
        if (port === "8000") {
            const protocol = window.location.protocol === "https:" ? "https:" : "http:";
            return `${protocol}//${host}:8000/api`;
        }
        return "/api";
    }

    function currentUser() {
        try {
            return JSON.parse(sessionStorage.getItem("librowseCurrentUser") || "null");
        } catch {
            return null;
        }
    }

    function getConsent() {
        try {
            return localStorage.getItem(CONSENT_KEY) || "";
        } catch {
            return "";
        }
    }

    function setCookieConsent(value) {
        const encoded = encodeURIComponent(value);
        document.cookie = `${COOKIE_NAME}=${encoded}; Max-Age=31536000; Path=/; SameSite=Lax`;
    }

    function loadStyle() {
        if (document.getElementById("librowse-consent-style")) return;
        const style = document.createElement("style");
        style.id = "librowse-consent-style";
        style.textContent = [
            ".librowse-consent-overlay{position:fixed;inset:0;z-index:10000;display:flex;align-items:flex-end;justify-content:center;padding:24px;background:rgba(40,28,22,.55);backdrop-filter:blur(2px)}",
            ".librowse-consent-card{width:min(100%,760px);background:#fffaf3;color:#4e3b30;border:1px solid #eadbc8;border-radius:20px;box-shadow:0 24px 60px rgba(0,0,0,.22);padding:24px 24px 20px;font:14px 'Nunito Sans',system-ui,sans-serif}",
            ".librowse-consent-card h2{margin:0 0 10px;font:700 22px 'Fraunces',Georgia,serif;color:#4e3b30}",
            ".librowse-consent-card p{margin:0 0 10px;line-height:1.6;color:#6b5040}",
            ".librowse-consent-list{margin:10px 0 18px;padding-left:18px;color:#6b5040}",
            ".librowse-consent-list li{margin:6px 0}",
            ".librowse-consent-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:flex-end}",
            ".librowse-consent-actions button{border:0;border-radius:999px;padding:11px 18px;font:700 14px 'Nunito Sans',system-ui,sans-serif;cursor:pointer}",
            ".librowse-consent-agree{background:#9a7458;color:#fffaf3}",
            ".librowse-consent-decline{background:#eadbc8;color:#4e3b30}",
            ".librowse-consent-note{font-size:12px;color:#8a715f;margin-top:10px}",
            ".librowse-consent-hidden{display:none!important}"
        ].join("");
        document.head.appendChild(style);
    }

    function getVisitorKey() {
        try {
            let key = localStorage.getItem(VISITOR_KEY);
            if (!key) {
                key = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
                localStorage.setItem(VISITOR_KEY, key);
            }
            return key;
        } catch {
            return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
        }
    }

    function clearOptionalTracking() {
        try {
            localStorage.removeItem(VISITOR_KEY);
            Object.keys(sessionStorage).forEach(key => {
                if (key.startsWith(VISIT_SESSION_PREFIX)) sessionStorage.removeItem(key);
            });
        } catch {
            // ignore storage failures
        }
    }

    function setConsent(value) {
        try {
            localStorage.setItem(CONSENT_KEY, value);
        } catch {
            // ignore storage failures
        }
        setCookieConsent(value);
        if (value !== "granted") clearOptionalTracking();
    }

    async function postLog(payload) {
        try {
            const headers = { "Content-Type": "application/json", "Cache-Control": "no-store" };
            const token = sessionStorage.getItem("librowseSessionToken");
            if (token) headers.Authorization = `Bearer ${token}`;
            await fetch(`${apiBase()}/activity_logs.php`, {
                method: "POST",
                headers,
                cache: "no-store",
                body: JSON.stringify(payload)
            });
        } catch {
            // Logging must never block the UI.
        }
    }

    function hasGrantedConsent() {
        return getConsent() === "granted";
    }

    function currentVisitKey() {
        return `${VISIT_SESSION_PREFIX}${location.pathname}`;
    }

    function shouldLogVisit() {
        return hasGrantedConsent() && sessionStorage.getItem(currentVisitKey()) !== "1";
    }

    async function logVisit() {
        if (!shouldLogVisit()) return;
        sessionStorage.setItem(currentVisitKey(), "1");
        const user = currentUser();
        await postLog({
            activity_type: "Visit",
            activity_action: `${document.title || "Page"} Visit`,
            outcome: "Neutral",
            page_path: location.pathname,
            visitor_key: getVisitorKey(),
            actor_user_id: user?.user_id || null,
            details: {
                page: document.title || location.pathname,
                path: location.pathname,
                role: user?.role || null,
                consent: "granted"
            }
        });
    }

    function closeBanner() {
        document.getElementById("librowse-consent-banner")?.classList.add("librowse-consent-hidden");
    }

    async function acceptConsent() {
        setConsent("granted");
        closeBanner();
        await logVisit();
    }

    function declineConsent() {
        setConsent("denied");
        closeBanner();
    }

    function createBanner() {
        if (document.getElementById("librowse-consent-banner")) return;
        loadStyle();

        const banner = document.createElement("section");
        banner.id = "librowse-consent-banner";
        banner.className = "librowse-consent-overlay";
        banner.setAttribute("role", "dialog");
        banner.setAttribute("aria-modal", "true");
        banner.setAttribute("aria-labelledby", "librowse-consent-title");
        banner.innerHTML = `
            <div class="librowse-consent-card">
                <h2 id="librowse-consent-title">Privacy Policy &amp; Cookie Choices</h2>
                <p>Librowse uses essential storage to keep you signed in, plus optional cookies/local storage to remember your privacy choice and record visit/activity analytics for the admin dashboard.</p>
                <ul class="librowse-consent-list">
                    <li><strong>Agree</strong> to allow optional cookies/local storage and activity/visit logging.</li>
                    <li><strong>Decline</strong> to disable optional tracking while keeping essential sign-in storage working.</li>
                    <li>You can change this choice later by clearing your browser data and revisiting the site.</li>
                </ul>
                <div class="librowse-consent-actions">
                    <button type="button" class="librowse-consent-decline" data-consent-decline>Decline</button>
                    <button type="button" class="librowse-consent-agree" data-consent-agree>Agree</button>
                </div>
                <p class="librowse-consent-note">By choosing Agree, you help us record visits, successful trades, and failed actions with reasons in the admin log view.</p>
            </div>
        `;
        banner.querySelector("[data-consent-agree]")?.addEventListener("click", acceptConsent);
        banner.querySelector("[data-consent-decline]")?.addEventListener("click", declineConsent);
        document.body.appendChild(banner);
    }

    async function initConsentTracking() {
        createBanner();
        if (getConsent() === "granted") {
            closeBanner();
            await logVisit();
        } else if (getConsent() === "denied") {
            closeBanner();
        }
    }

    window.librowseConsent = {
        getConsent,
        hasGrantedConsent,
        recordVisit: logVisit,
        getVisitorKey,
        acceptConsent,
        declineConsent
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initConsentTracking, { once: true });
    } else {
        void initConsentTracking();
    }
})();
