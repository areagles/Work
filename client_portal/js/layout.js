(function () {
    const PUBLIC_PAGES = new Set(["login.html", "register.html", "login.php", "register.php"]);

    function portalHref(path) {
        if (window.PortalCore && typeof window.PortalCore.portalUrl === "function") {
            return window.PortalCore.portalUrl(path);
        }
        return path;
    }

    function currentPageName() {
        const file = window.location.pathname.split("/").pop() || "dashboard.html";
        return file.split("?")[0];
    }

    function isPublicPage() {
        return PUBLIC_PAGES.has(currentPageName());
    }

    function ensureIdentityStyles() {
        const existing = document.querySelector('link[data-admin-identity="1"], link[href$="css/admin-identity.css"], link[href*="/css/admin-identity.css"]');
        if (existing) {
            return;
        }

        const link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = "css/admin-identity.css";
        link.setAttribute("data-admin-identity", "1");
        document.head.appendChild(link);
    }

    function renderHeader() {
        const placeholder = document.getElementById("app-header");
        if (!placeholder || placeholder.children.length) {
            return;
        }

        placeholder.innerHTML = `
            <div class="header">
                <div class="user-info">
                    <h2 id="layout_u_name">مرحباً بك</h2>
                    <p id="layout_subtitle">بوابة العملاء</p>
                </div>
                <img src="assets/images/icon.png" class="user-avatar" id="layout_u_avatar" alt="User">
            </div>
        `;

        const avatar = document.getElementById("layout_u_avatar");
        if (avatar) {
            avatar.addEventListener("error", function () {
                avatar.src = "assets/images/icon.png";
            });
        }
    }

    function renderBottomNav() {
        if (document.querySelector(".bottom-nav")) {
            return;
        }

        const navHTML = `
            <div class="bottom-nav">
                <a href="${portalHref("dashboard.html")}" class="nav-item" data-page="dashboard">
                    <i class="fa-solid fa-house-chimney"></i> الرئيسية
                </a>
                <a href="${portalHref("orders.html")}" class="nav-item" data-page="orders">
                    <i class="fa-solid fa-box-open"></i> الطلبات
                </a>
                <a href="${portalHref("quotes.html")}" class="nav-item" data-page="quotes">
                    <i class="fa-solid fa-file-invoice"></i> العروض
                </a>

                <div class="fab-wrapper">
                    <button type="button" class="fab" id="quickAddBtn" aria-label="إنشاء جديد">
                        <i class="fa-solid fa-plus"></i>
                    </button>
                </div>

                <a href="${portalHref("invoices.html")}" class="nav-item" data-page="invoices">
                    <i class="fa-solid fa-wallet"></i> المالية
                </a>
                <a href="${portalHref("profile.html")}" class="nav-item" data-page="profile">
                    <i class="fa-solid fa-user-shield"></i> حسابي
                </a>
                <a href="#" class="nav-item logout-nav" id="logoutAction">
                    <i class="fa-solid fa-power-off"></i> خروج
                </a>
            </div>

            <div id="addMenuModal" class="add-menu-overlay">
                <div id="addMenuContent" class="add-menu-content">
                    <h3 style="color:var(--admin-gold); margin:0 0 20px 0; text-align:center; font-size:1.1rem; font-weight:800;">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> إنشاء جديد
                    </h3>

                    <a href="${portalHref("new_order.html?type=order")}" class="action-btn">
                        <div class="ab-icon" style="background:rgba(32, 191, 107, 0.12); color:#20bf6b;">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div class="ab-text">
                            <h4>أمر تشغيل جديد</h4>
                            <p>إرسال طلب إنتاج مباشر للفريق.</p>
                        </div>
                        <i class="fa-solid fa-arrow-left" style="margin-right:auto; color:#555;"></i>
                    </a>

                    <a href="${portalHref("new_order.html?type=quote")}" class="action-btn">
                        <div class="ab-icon" style="background:rgba(212, 175, 55, 0.14); color:#d4af37;">
                            <i class="fa-solid fa-file-signature"></i>
                        </div>
                        <div class="ab-text">
                            <h4>طلب عرض سعر</h4>
                            <p>إرسال متطلبات المشروع لدراسة التكلفة.</p>
                        </div>
                        <i class="fa-solid fa-arrow-left" style="margin-right:auto; color:#555;"></i>
                    </a>

                    <button type="button" class="close-menu-btn" id="closeAddMenuBtn">إغلاق القائمة</button>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML("beforeend", navHTML);

        const quickAddBtn = document.getElementById("quickAddBtn");
        if (quickAddBtn) {
            quickAddBtn.addEventListener("click", toggleAddMenu);
        }

        const closeAddMenuBtn = document.getElementById("closeAddMenuBtn");
        if (closeAddMenuBtn) {
            closeAddMenuBtn.addEventListener("click", toggleAddMenu);
        }

        const overlay = document.getElementById("addMenuModal");
        if (overlay) {
            overlay.addEventListener("click", function (event) {
                if (event.target.id === "addMenuModal") {
                    toggleAddMenu();
                }
            });
        }

        const logoutAction = document.getElementById("logoutAction");
        if (logoutAction) {
            logoutAction.addEventListener("click", function (event) {
                event.preventDefault();
                confirmLogout();
            });
        }
    }

    function toggleAddMenu() {
        const modal = document.getElementById("addMenuModal");
        const content = document.getElementById("addMenuContent");
        if (!modal || !content) {
            return;
        }

        const isVisible = modal.style.display === "flex";

        if (isVisible) {
            content.classList.remove("show");
            modal.style.opacity = "0";
            setTimeout(function () {
                modal.style.display = "none";
            }, 220);
            return;
        }

        modal.style.display = "flex";
        requestAnimationFrame(function () {
            modal.style.opacity = "1";
            content.classList.add("show");
        });
    }

    function highlightActiveLink() {
        const current = currentPageName().replace(".html", "");
        const items = document.querySelectorAll(".nav-item[data-page]");
        items.forEach(function (item) {
            if (item.dataset.page === current || (current === "" && item.dataset.page === "dashboard")) {
                item.classList.add("active");
            } else {
                item.classList.remove("active");
            }
        });
    }

    async function confirmLogout() {
        if (!window.confirm("هل أنت متأكد من تسجيل الخروج؟")) {
            return;
        }
        try {
            const fetcher = window.PortalCore && window.PortalCore.apiFetch ? window.PortalCore.apiFetch : null;
            if (!fetcher) {
                throw new Error("logout_unavailable");
            }
            const result = await fetcher(portalHref("api/logout.php"), {
                method: "POST",
                body: {}
            });
            window.location.href = (result && result.redirect) ? result.redirect : portalHref("login.html?logged_out=1");
        } catch (error) {
            window.location.href = portalHref("login.html?logged_out=1");
        }
    }

    async function updateUserData() {
        try {
            const fetcher = window.PortalCore && window.PortalCore.apiFetch ? window.PortalCore.apiFetch : null;
            let result = null;

            if (fetcher) {
                result = await fetcher(portalHref("api/dashboard_data.php"), { cacheBust: true });
            } else {
                const response = await fetch(portalHref("api/dashboard_data.php") + "?t=" + Date.now());
                result = await response.json();
            }

            if (result && result.status === "success") {
                const nameEl = document.getElementById("layout_u_name");
                const subtitleEl = document.getElementById("layout_subtitle");
                const avatarEl = document.getElementById("layout_u_avatar");

                if (nameEl) {
                    nameEl.textContent = result.data.name || "مرحباً بك";
                }
                if (subtitleEl && result.data.phone) {
                    subtitleEl.textContent = "رقم الحساب: " + result.data.phone;
                }
                if (avatarEl && result.data.avatar_url) {
                    avatarEl.src = result.data.avatar_url;
                }
            }
        } catch (error) {
            if (error && error.code === "UNAUTHORIZED") {
                window.location.href = portalHref("login.html");
            }
        }
    }

    function wireKeyboardCloseMenu() {
        document.addEventListener("keydown", function (event) {
            if (event.key !== "Escape") {
                return;
            }
            const modal = document.getElementById("addMenuModal");
            if (modal && modal.style.display === "flex") {
                toggleAddMenu();
            }
        });
    }

    function initLayout() {
        ensureIdentityStyles();

        if (isPublicPage()) {
            return;
        }

        renderHeader();
        renderBottomNav();
        highlightActiveLink();
        updateUserData();
        wireKeyboardCloseMenu();
    }

    window.toggleAddMenu = toggleAddMenu;
    window.confirmLogout = confirmLogout;

    document.addEventListener("DOMContentLoaded", initLayout);
})();
