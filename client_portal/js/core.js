(function () {
    const DEFAULT_TIMEOUT = 15000;
    const PORTAL_MARKER = "/client_portal/";
    const PORTAL_BASE_PATH = detectPortalBasePath();
    let identityCache = null;
    let csrfToken = "";

    function detectPortalBasePath() {
        const path = String(window.location.pathname || "/");
        const idx = path.toLowerCase().indexOf(PORTAL_MARKER);
        if (idx >= 0) {
            return path.slice(0, idx + PORTAL_MARKER.length);
        }
        const dir = path.replace(/[^/]*$/, "");
        return dir.endsWith("/") ? dir : (dir + "/");
    }

    function portalUrl(path) {
        const raw = String(path || "").trim();
        if (!raw) return PORTAL_BASE_PATH;
        if (/^(?:[a-z]+:)?\/\//i.test(raw) || raw.startsWith("mailto:") || raw.startsWith("tel:") || raw.startsWith("javascript:") || raw.startsWith("#")) {
            return raw;
        }
        if (raw.startsWith("/")) {
            return raw;
        }
        return PORTAL_BASE_PATH + raw.replace(/^\.?\//, "");
    }

    function escapeHtml(value) {
        const str = String(value ?? "");
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/\"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function formatNumber(value, locale) {
        const num = Number(value || 0);
        return num.toLocaleString(locale || "en-US");
    }

    function formatMoney(value, currencyLabel, locale) {
        const num = Number(value || 0);
        return `${num.toLocaleString(locale || "en-US", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })} ${currencyLabel || "EGP"}`;
    }

    function normalizePhone(phone) {
        return String(phone || "")
            .replace(/\s+/g, "")
            .replace(/[^\d+]/g, "");
    }

    function sanitizeHexColor(value, fallback = "#d4af37") {
        const raw = String(value || "").trim();
        if (/^#[0-9a-f]{6}$/i.test(raw)) {
            return raw;
        }
        if (/^[0-9a-f]{6}$/i.test(raw)) {
            return "#" + raw;
        }
        return fallback;
    }

    function toDateOnly(value) {
        const raw = String(value || "").trim();
        if (!raw) {
            return "-";
        }
        return raw.split(" ")[0].split("T")[0] || "-";
    }

    function isUnauthorized(payload, response) {
        if (response && response.status === 401) {
            return true;
        }
        if (!payload || typeof payload !== "object") {
            return false;
        }
        if (payload.status === "unauthorized") {
            return true;
        }
        const message = String(payload.message || "").toLowerCase();
        return message === "unauthorized";
    }

    async function apiFetch(url, options) {
        const opts = options || {};
        const method = (opts.method || "GET").toUpperCase();
        const headers = Object.assign({ Accept: "application/json" }, opts.headers || {});
        const timeout = Number(opts.timeout || DEFAULT_TIMEOUT);
        const controller = new AbortController();
        const timer = setTimeout(function () {
            controller.abort();
        }, timeout);

        const request = {
            method,
            headers,
            credentials: "same-origin",
            signal: controller.signal
        };

        if (method !== "GET" && method !== "HEAD") {
            if (!csrfToken) {
                try {
                    await fetchPortalIdentity(false);
                } catch (error) {}
            }
            if (csrfToken) {
                request.headers["X-CSRF-Token"] = csrfToken;
            }
        }

        if (opts.body instanceof FormData) {
            request.body = opts.body;
            delete request.headers["Content-Type"];
        } else if (opts.body !== undefined && opts.body !== null) {
            request.body = JSON.stringify(opts.body);
            request.headers["Content-Type"] = request.headers["Content-Type"] || "application/json";
        }

        let finalUrl = String(url || "");
        if (opts.cacheBust) {
            finalUrl += (finalUrl.includes("?") ? "&" : "?") + "_ts=" + Date.now();
        }

        try {
            const response = await fetch(finalUrl, request);
            const text = await response.text();
            const payload = text ? JSON.parse(text) : {};
            if (payload && typeof payload === "object" && payload.csrf_token) {
                csrfToken = String(payload.csrf_token || "");
            }
            if (payload && payload.data && typeof payload.data === "object" && payload.data.csrf_token) {
                csrfToken = String(payload.data.csrf_token || "");
            }

            if (isUnauthorized(payload, response)) {
                const unauthorizedError = new Error("UNAUTHORIZED");
                unauthorizedError.code = "UNAUTHORIZED";
                throw unauthorizedError;
            }

            if (!response.ok) {
                throw new Error(payload.message || "HTTP Error");
            }

            return payload;
        } catch (error) {
            if (error && error.name === "AbortError") {
                throw new Error("انتهت مهلة الاتصال بالسيرفر");
            }
            if (error && error.message === "UNAUTHORIZED") {
                throw error;
            }
            throw new Error(error && error.message ? error.message : "حدث خطأ غير متوقع");
        } finally {
            clearTimeout(timer);
        }
    }

    function showToast(message, type, ttl) {
        const safeMsg = escapeHtml(message || "");
        const toastType = type || "info";
        const wrapId = "portalToastWrap";
        let wrap = document.getElementById(wrapId);

        if (!wrap) {
            wrap = document.createElement("div");
            wrap.id = wrapId;
            wrap.className = "portal-toast-wrap";
            document.body.appendChild(wrap);
        }

        const toast = document.createElement("div");
        toast.className = `portal-toast ${toastType}`;
        toast.innerHTML = safeMsg;
        wrap.appendChild(toast);

        setTimeout(function () {
            toast.remove();
        }, Number(ttl || 3400));
    }

    function buildDashboardInsights(data) {
        const insights = [];
        if (!data || typeof data !== "object") {
            return ["لا توجد بيانات كافية لبناء توصيات الآن."];
        }

        const balance = Number(data.balance || 0);
        const active = Number(data.active_orders || 0);
        const dueInvoices = Number(data.invoices_count || 0);
        const pendingQuotes = Number(data.pending_quotes || 0);

        if (balance > 0) {
            insights.push(`الرصيد المستحق ${formatMoney(balance)}، يفضّل متابعة قسم المالية.`);
        }
        if (dueInvoices > 0) {
            insights.push(`يوجد ${formatNumber(dueInvoices)} فاتورة غير مسددة، راجع جدول السداد لتجنب التأخير.`);
        }
        if (pendingQuotes > 0) {
            insights.push(`لديك ${formatNumber(pendingQuotes)} عروض بانتظار ردك، القرار السريع يختصر وقت التنفيذ.`);
        }
        if (active === 0) {
            insights.push("لا توجد مشاريع جارية الآن، فتح طلب جديد يضمن دخولك في خطة الإنتاج مبكراً.");
        } else if (active >= 3) {
            insights.push("يوجد عدة مشاريع جارية، حدّد أولويات التسليم مع فريقك لتفادي التزاحم.");
        }

        if (Array.isArray(data.recent_orders)) {
            const hasRejected = data.recent_orders.find(function (item) {
                return item && item.status === "cancelled" && item.rejection_reason;
            });
            if (hasRejected) {
                insights.push(`آخر سبب رفض مسجل: ${hasRejected.rejection_reason}`);
            }
        }

        if (insights.length === 0) {
            insights.push("لا توجد ملاحظات تشغيلية إضافية حالياً.");
        }

        return insights.slice(0, 4);
    }

    function renderInsights(containerId, insights) {
        const container = document.getElementById(containerId);
        if (!container) {
            return;
        }

        const list = Array.isArray(insights) ? insights : [];
        if (!list.length) {
            container.innerHTML = "";
            return;
        }

        container.innerHTML = list
            .map(function (line) {
                return `<div class="smart-banner"><i class="fa-solid fa-circle-info" style="margin-left:8px;"></i>${escapeHtml(line)}</div>`;
            })
            .join("");
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) {
            return;
        }

        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection || null;
        if (conn && conn.saveData) {
            return;
        }

        window.addEventListener('load', function () {
            const register = function () {
                navigator.serviceWorker.register('sw.js').catch(function () {});
            };
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(register, { timeout: 1800 });
            } else {
                setTimeout(register, 900);
            }
        });
    }

    async function fetchPortalIdentity(forceRefresh) {
        if (!forceRefresh && identityCache && typeof identityCache === "object") {
            return identityCache;
        }
        const payload = await apiFetch(portalUrl("api/identity.php"), {
            cacheBust: !!forceRefresh,
            timeout: 12000
        });
        if (!payload || payload.status !== "success" || !payload.data) {
            throw new Error("تعذر تحميل هوية النظام");
        }
        identityCache = payload.data;
        csrfToken = String(identityCache.csrf_token || csrfToken || "");
        return identityCache;
    }

    function applyThemeColor(themeColor) {
        const color = sanitizeHexColor(themeColor, "#d4af37");
        document.documentElement.style.setProperty("--admin-gold", color);
        document.documentElement.style.setProperty("--admin-gold-deep", color);
        document.documentElement.style.setProperty("--gold", color);
        document.documentElement.style.setProperty("--gold-end", color);
    }

    function applyPortalIdentity(identity, options) {
        const cfg = options || {};
        const appName = String(identity.app_name || "Arab Eagles").trim() || "Arab Eagles";
        const logoUrl = String(identity.logo_url || "").trim();
        const supportWhatsapp = String(identity.support_whatsapp_url || "").trim();
        const supportEmail = String(identity.support_email || "").trim();
        const pageTitlePrefix = String(cfg.pageTitlePrefix || "").trim();

        applyThemeColor(identity.theme_color || "#d4af37");

        if (pageTitlePrefix) {
            document.title = `${pageTitlePrefix} | ${appName}`;
        } else if (document.title) {
            document.title = document.title.replace(/Arab Eagles/gi, appName);
        }

        document.querySelectorAll("[data-portal-brand-name]").forEach(function (node) {
            node.textContent = appName;
        });

        if (logoUrl) {
            document.querySelectorAll("[data-portal-logo]").forEach(function (node) {
                if (node.tagName === "IMG") {
                    node.src = logoUrl;
                } else {
                    node.style.backgroundImage = `url("${logoUrl}")`;
                }
            });
        }

        document.querySelectorAll("[data-portal-link]").forEach(function (node) {
            const target = node.getAttribute("data-portal-link") || "";
            if (target) {
                node.setAttribute("href", portalUrl(target));
            }
        });

        if (supportWhatsapp) {
            document.querySelectorAll("[data-support-whatsapp]").forEach(function (node) {
                node.setAttribute("href", supportWhatsapp);
            });
        }
        if (supportEmail) {
            document.querySelectorAll("[data-support-email]").forEach(function (node) {
                node.setAttribute("href", `mailto:${supportEmail}`);
                if (node.getAttribute("data-support-email-text") === "1") {
                    node.textContent = supportEmail;
                }
            });
        }

        return identity;
    }

    async function bootstrapPublicIdentity(options) {
        try {
            const identity = await fetchPortalIdentity(false);
            return applyPortalIdentity(identity, options || {});
        } catch (error) {
            return null;
        }
    }

    registerServiceWorker();

    window.PortalCore = {
        apiFetch,
        portalBasePath: PORTAL_BASE_PATH,
        portalUrl,
        escapeHtml,
        formatMoney,
        formatNumber,
        normalizePhone,
        sanitizeHexColor,
        toDateOnly,
        showToast,
        buildDashboardInsights,
        renderInsights,
        fetchPortalIdentity,
        applyPortalIdentity,
        bootstrapPublicIdentity,
        getCsrfToken: function () {
            return csrfToken;
        }
    };
})();
