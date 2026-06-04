<?php
// admin/sidebar_fragment.php

// 1. Get Current Page Name (for Active Highlight)
$curPage = basename($_SERVER['PHP_SELF']);

// 2. Database Connections Check
if (!isset($conn)) {
    if (file_exists('../db.php')) require_once '../db.php';
    elseif (file_exists('db.php')) require_once 'db.php';
}
if (!isset($mainConn)) {
    if (file_exists('../db_main.php')) require_once '../db_main.php';
    elseif (file_exists('db_main.php')) require_once 'db_main.php';
}

// 3. Fetch Counters (Badges)
$pendingWithdrawals = 0;
$pendingGigApprovals = 0;
$pendingShopOrders = 0;

if (isset($conn)) {
    // A. Pending Withdrawals
    $wQ = $conn->query("SELECT COUNT(*) as c FROM withdrawal_requests WHERE status='pending'");
    if ($wQ) $pendingWithdrawals = $wQ->fetch_assoc()['c'];

    // B. Gigs Waiting for Approval (Status = completed)
    $gQ = $conn->query("SELECT COUNT(*) as c FROM manual_tasks WHERE status='completed'");
    if ($gQ) $pendingGigApprovals = $gQ->fetch_assoc()['c'];
}

if (isset($mainConn)) {
    // C. Pending/Unassigned Shop Orders
    $sQ = $mainConn->query("SELECT COUNT(*) as c FROM orders WHERE (assigned_vendor_id IS NULL OR status='pending') AND service_id IS NOT NULL");
    if ($sQ) $pendingShopOrders = $sQ->fetch_assoc()['c'];
}
?>

<style>
    /* Standardized Sidebar Architecture */
    .sidebar {
        width: 280px;
        background: var(--glass, rgba(255, 255, 255, 0.7));
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-radius: 24px;
        padding: 1.5rem;
        height: fit-content;
        border: 1px solid var(--glass-border, rgba(255, 255, 255, 0.4));
        box-shadow: var(--shadow, 0 10px 30px -5px rgba(0, 0, 0, 0.1));
        position: sticky;
        top: 100px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        flex-shrink: 0;
        z-index: 999;
    }

    /* Desktop Collapsed State */
    .sidebar.collapsed {
        width: 80px;
        padding: 1.5rem 0.75rem;
    }

    .sidebar.collapsed .sidebar-header,
    .sidebar.collapsed .sidebar-header-desktop,
    .sidebar.collapsed a span {
        display: none;
    }

    .sidebar.collapsed a {
        justify-content: center;
        padding: 12px;
        gap: 0;
    }

    /* Sidebar Navigation Items */
    .sidebar ul {
        list-style: none !important;
        padding: 0 !important;
        margin: 0 !important;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .sidebar ul li a {
        text-decoration: none;
        color: #64748b;
        font-weight: 600;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-radius: 14px;
        transition: all 0.2s ease;
        white-space: nowrap;
        position: relative;
    }

    .sidebar ul li a i {
        font-size: 1.1rem;
        width: 20px;
        text-align: center;
    }

    .sidebar ul li a:hover {
        background: rgba(79, 70, 229, 0.08);
        color: #4f46e5;
        transform: translateX(5px);
    }

    .sidebar.collapsed ul li a:hover {
        transform: scale(1.1);
    }

    .sidebar ul li a.active {
        background: #4f46e5;
        color: white;
        box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3);
    }

    .sidebar .sidebar-header {
        margin-top: 2rem;
        margin-bottom: 0.75rem;
        color: #94a3b8;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 1.5px;
        padding-left: 1rem;
        text-transform: uppercase;
    }

    .sidebar .badge {
        background: #ef4444;
        color: white;
        font-size: 0.7rem;
        font-weight: 800;
        padding: 2px 8px;
        border-radius: 20px;
        margin-left: auto;
    }

    .sidebar.collapsed .badge {
        position: absolute;
        top: 4px;
        right: 4px;
        margin: 0;
        min-width: 18px;
        height: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
    }

    /* Mobile Responsive Architecture */
    @media (max-width: 1024px) {
        .sidebar {
            position: fixed !important;
            left: -300px !important;
            top: 0 !important;
            bottom: 0 !important;
            z-index: 10000 !important;
            border-radius: 0 24px 24px 0 !important;
            height: 100vh !important;
            width: 280px !important;
            background: white !important;
            opacity: 1 !important;
            visibility: visible !important;
            box-shadow: none;
            overflow-y: auto !important;
        }

        .sidebar.active {
            left: 0 !important;
            box-shadow: 0 0 0 1000px rgba(15, 23, 42, 0.5) !important;
        }

        .sidebar-mobile-header {
            display: flex !important;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }
    }

    @media (min-width: 1025px) {
        .sidebar-mobile-header { display: none !important; }
    }

    /* Activity Container */
    .activity-container {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    /* Activity Toasts styling */
    .activity-toast {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        border: 1px solid rgba(0, 0, 0, 0.05);
        border-left: 4px solid #4f46e5;
        padding: 16px;
        width: 320px;
        position: relative;
        cursor: pointer;
        transition: all 0.2s ease;
        animation: slideInToast 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        font-family: 'Inter', sans-serif;
    }
    
    .activity-toast:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 35px rgba(0, 0, 0, 0.12);
    }
    
    .toast-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 6px;
    }
    
    .toast-title {
        font-weight: 700;
        font-size: 13.5px;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .toast-time {
        font-size: 11px;
        color: #94a3b8;
        font-weight: 500;
    }
    
    .toast-body {
        font-size: 12px;
        color: #475569;
        line-height: 1.5;
        white-space: pre-wrap;
    }
    
    .toast-close {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 20px;
        height: 20px;
        background: #f1f5f9;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        cursor: pointer;
        color: #94a3b8;
        transition: all 0.2s ease;
    }
    
    .toast-close:hover {
        background: #e2e8f0;
        color: #475569;
    }
    
    @keyframes slideInToast {
        from { transform: translateX(120%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes fadeOut {
        from { opacity: 1; transform: scale(1); }
        to { opacity: 0; transform: scale(0.9); }
    }
</style>

<aside class="sidebar">
    <!-- Desktop Header -->
    <div class="sidebar-header-desktop" style="display: flex; justify-content: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid rgba(226,232,240,0.5);">
        <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-height: 45px; max-width: 180px; object-fit: contain;">
    </div>

    <!-- Mobile-Only Header -->
    <div class="sidebar-mobile-header">
        <div style="display: flex; align-items: center; gap: 10px;">
            <img src="../surpriseville-logo.png" alt="Surprise Ville" style="max-height: 35px; max-width: 150px; object-fit: contain;">
        </div>
        <div onclick="toggleSidebar()" style="width: 32px; height: 32px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b;">
            <i class="fa-solid fa-xmark"></i>
        </div>
    </div>

    <ul>
        <li>
            <a href="dashboard.php" class="<?= $curPage == 'dashboard.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-pie"></i> <span>Dashboard</span>
            </a>
        </li>

        <li class="sidebar-header">Orders & Tasks</li>

        <li>
            <a href="manage_gigs.php" class="<?= $curPage == 'manage_gigs.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-clipboard-list"></i> <span>Offline Orders</span>
                <?php if ($pendingGigApprovals > 0): ?>
                    <span class="badge"><?= $pendingGigApprovals ?></span>
                <?php endif; ?>
            </a>
        </li>

        <li>
            <a href="orders.php" class="<?= $curPage == 'orders.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-cart-shopping"></i> <span>Shop Orders</span>
                <?php if ($pendingShopOrders > 0): ?>
                    <span class="badge"><?= $pendingShopOrders ?></span>
                <?php endif; ?>
            </a>
        </li>

        <li>
            <a href="crm_bookings.php" class="<?= $curPage == 'crm_bookings.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-sync"></i> <span>CRM Bookings</span>
            </a>
        </li>

        <li class="sidebar-header">Tracking</li>

        <li>
            <a href="tracking.php" class="<?= ($curPage == 'tracking.php' || $curPage == 'order_tracking.php') ? 'active' : '' ?>">
                <i class="fa-solid fa-location-crosshairs"></i> <span>Order Tracking</span>
            </a>
        </li>

        <li>
            <a href="track_vendor.php" class="<?= $curPage == 'track_vendor.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-map-location-dot"></i> <span>Vendor Tracking</span>
            </a>
        </li>

        <li class="sidebar-header">Vendors & Money</li>

        <li>
            <a href="vendors.php" class="<?= $curPage == 'vendors.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> <span>Vendors</span>
            </a>
        </li>

        <li>
            <a href="decorator_rankings.php" class="<?= $curPage == 'decorator_rankings.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-ranking-star"></i> <span>Decorator Rankings</span>
            </a>
        </li>

        <li>
            <a href="decorator_videos.php" class="<?= $curPage == 'decorator_videos.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-video"></i> <span>Video Reviews</span>
            </a>
        </li>
        <li>
            <a href="manage_blogs.php" class="<?= $curPage == 'manage_blogs.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-feather"></i> <span>Manage Blogs</span>
            </a>
        </li>
        <li>
            <a href="manage_journey_reviews.php" class="<?= $curPage == 'manage_journey_reviews.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-heart"></i> <span>Vendor Journeys</span>
            </a>
        </li>

        <li>
            <a href="manage_packages.php" class="<?= $curPage == 'manage_packages.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-box-open"></i> <span>Manage Packages</span>
            </a>
        </li>

        <li>
            <a href="gig_categories.php" class="<?= $curPage == 'gig_categories.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-layer-group"></i> <span>Task Categories</span>
            </a>
        </li>

        <li>
            <a href="categories.php" class="<?= $curPage == 'categories.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-box-open"></i> <span>Shop Categories</span>
            </a>
        </li>

        <li>
            <a href="withdrawals.php" class="<?= $curPage == 'withdrawals.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-wallet"></i> <span>Withdrawals</span>
                <?php if ($pendingWithdrawals > 0): ?>
                    <span class="badge"><?= $pendingWithdrawals ?></span>
                <?php endif; ?>
            </a>
        </li>

        <li>
            <a href="earnings_report.php" class="<?= $curPage == 'earnings_report.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-file-invoice-dollar"></i> <span>Earnings Report</span>
            </a>
        </li>

        <li class="sidebar-header">System Tools</li>

        <li>
            <a href="settings.php" class="<?= $curPage == 'settings.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-sliders"></i> <span>Settings</span>
            </a>
        </li>

        <li>
            <a href="allocation_logs.php" class="<?= $curPage == 'allocation_logs.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left"></i> <span>Allocation Logs</span>
            </a>
        </li>
    </ul>
</aside>

<!-- Activity Notification Container -->
<div class="activity-container" id="activity-container"></div>

<script>
    // Global Sidebar Toggle
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;
        
        if (window.innerWidth > 1024) {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        } else {
            sidebar.classList.toggle('active');
        }
    }

    // Apply stored preference ONLY on Desktop
    function applySidebarState() {
        const sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        if (window.innerWidth > 1024) {
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                sidebar.classList.add('collapsed');
            }
            sidebar.classList.remove('active'); // Never active (drawer) on desktop
        } else {
            sidebar.classList.remove('collapsed'); // Never collapsed (mini) on mobile
        }
    }

    document.addEventListener('DOMContentLoaded', applySidebarState);
    window.addEventListener('resize', applySidebarState);

    // Global Click-Outside Dismiss (Mobile only)
    document.addEventListener('click', (e) => {
        const sidebar = document.querySelector('.sidebar');
        const toggle = document.querySelector('.sidebar-toggle');
        if (window.innerWidth <= 1024 && sidebar && sidebar.classList.contains('active')) {
            if (!sidebar.contains(e.target) && !toggle?.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        }
    });

    let seenActivities = new Set();
    let isInitialLoad = true;

    window.dismissToast = function(actId) {
        let dismissed = JSON.parse(localStorage.getItem('dismissed_toasts') || '[]');
        if (!dismissed.includes(actId)) {
            dismissed.push(actId);
            localStorage.setItem('dismissed_toasts', JSON.stringify(dismissed));
        }
    };

    async function checkActivity() {
        try {
            const res = await fetch('api/recent_activity.php');
            const data = await res.json();
            if (data && !data.error) {
                data.forEach(act => {
                    const actId = act.type + '_' + act.id + '_' + act.status;
                    if (!seenActivities.has(actId)) {
                        if (act.type !== 'chat') {
                            act.toast_id = actId;
                            let dismissed = JSON.parse(localStorage.getItem('dismissed_toasts') || '[]');
                            if (!dismissed.includes(actId)) {
                                if (!isInitialLoad) showToast(act);
                            }
                        }
                        seenActivities.add(actId);
                    }
                });
            }
            isInitialLoad = false;
        } catch (e) {}
    }

    function showToast(act) {
        const container = document.getElementById('activity-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `activity-toast status-${act.status}`;
        const icon = act.type === 'chat' ? 'fa-comments' : (act.type === 'shop' ? 'fa-cart-shopping' : 'fa-list-check');
        const bodyText = act.body || `Update: <strong>${act.status.toUpperCase()}</strong>. Click to view.`;
        const toastId = act.toast_id || (act.type + '_' + act.id + '_' + act.status);
        
        toast.innerHTML = `
            <div class="toast-close" onclick="dismissToast('${toastId}'); this.parentElement.remove();"><i class="fa-solid fa-times"></i></div>
            <div class="toast-header">
                <div class="toast-title"><i class="fa-solid ${icon}"></i> ${act.title}</div>
                <div class="toast-time">Just now</div>
            </div>
            <div class="toast-body">${bodyText}</div>
        `;
        toast.onclick = (e) => {
            if (e.target.closest('.toast-close')) return;
            window.location.href = act.link;
        };
        container.appendChild(toast);
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.animation = 'fadeOut 0.5s forwards';
                setTimeout(() => toast.remove(), 500);
            }
        }, 8000);
    }
    setInterval(checkActivity, 30000);
    checkActivity();

    // Also refresh floating chat badge count in background every 25s
    async function refreshChatBadge() {
        try {
            const rootPath = window.location.pathname.includes('/vendor/') || window.location.pathname.includes('/admin/') ? '../' : '';
            const res = await fetch(rootPath + 'ajax_unread_chats.php');
            const data = await res.json();
            if (data && data.success) {
                const badge = document.getElementById('floatingChatBadge');
                const trigger = document.querySelector('.floating-chat-trigger');
                if (badge) {
                    badge.innerText = data.count;
                    badge.style.display = data.count > 0 ? 'flex' : 'none';
                }
                if (trigger) {
                    if (data.count > 0) {
                        trigger.style.boxShadow = '0 5px 25px rgba(239,68,68,0.5)';
                        trigger.style.background = '#ef4444';
                    } else {
                        trigger.style.boxShadow = '0 5px 20px rgba(67,97,238,0.4)';
                        trigger.style.background = '#4361ee';
                    }
                }
                // Show toast for new chat messages
                if (!isInitialLoad && data.count > 0) {
                    data.chats.forEach(chat => {
                        const chatActId = 'chat_floating_' + chat.id + '_' + chat.time;
                        if (!seenActivities.has(chatActId)) {
                            seenActivities.add(chatActId);
                            let dismissed = JSON.parse(localStorage.getItem('dismissed_toasts') || '[]');
                            if (!dismissed.includes(chatActId)) {
                                showToast({
                                    toast_id: chatActId,
                                    type: 'chat',
                                    title: chat.title,
                                    body: '💬 "' + chat.message.substring(0, 50) + '"',
                                    status: 'unread',
                                    link: chat.link
                                });
                            }
                        }
                    });
                }
            }
        } catch(e) {}
    }
    setInterval(refreshChatBadge, 25000);
    document.addEventListener('DOMContentLoaded', refreshChatBadge);
</script>

<!-- Floating Chat Widget Container -->
<div class="floating-chat-container" id="floatingChatContainer" style="position: fixed; bottom: 25px; right: 25px; z-index: 10000; font-family: 'Inter', sans-serif;">
    <!-- Flyout Panel -->
    <div class="floating-chat-card" id="floatingChatCard" style="display: none; width: 320px; max-height: 400px; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; margin-bottom: 15px; flex-direction: column; overflow: hidden; animation: slideUp 0.3s ease;">
        <div style="background: #4361ee; color: #fff; padding: 15px 20px; font-weight: 700; font-size: 14px; display: flex; justify-content: space-between; align-items: center;">
            <span>💬 Active Conversations</span>
            <span onclick="toggleChatCard()" style="cursor: pointer; opacity: 0.8; font-size: 18px;">&times;</span>
        </div>
        <div id="floatingChatList" style="overflow-y: auto; max-height: 330px; display: flex; flex-direction: column; background: #f8fafc;">
            <div style="padding: 20px; text-align: center; color: #64748b; font-size: 13px;">No new messages.</div>
        </div>
    </div>
    <!-- Floating Trigger Button -->
    <button class="floating-chat-trigger" onclick="toggleChatCard()" style="width: 60px; height: 60px; border-radius: 50%; background: #4361ee; border: none; color: #fff; font-size: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 5px 20px rgba(67, 97, 238, 0.4); position: relative; transition: all 0.3s ease; outline: none;">
        <i class="fa-solid fa-comments"></i>
        <span id="floatingChatBadge" style="position: absolute; top: -5px; right: -5px; background: #ef4444; color: #fff; font-size: 11px; font-weight: 800; min-width: 20px; height: 20px; border-radius: 50%; display: none; align-items: center; justify-content: center; border: 2px solid #fff; animation: pulse 2s infinite;">0</span>
    </button>
</div>

<style>
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
}
.floating-chat-item {
    padding: 12px 20px;
    border-bottom: 1px solid #e2e8f0;
    background: #fff;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    flex-direction: column;
    gap: 4px;
    text-decoration: none;
    color: inherit;
}
.floating-chat-item:hover {
    background: #eff6ff;
}
.floating-chat-item-title {
    font-weight: 700;
    font-size: 13px;
    color: #1e293b;
}
.floating-chat-item-msg {
    font-size: 12px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.floating-chat-item-time {
    font-size: 10px;
    color: #94a3b8;
    align-self: flex-end;
}
</style>

<script>
function toggleChatCard() {
    const card = document.getElementById('floatingChatCard');
    if (!card) return;
    if (card.style.display === 'none' || card.style.display === '') {
        card.style.display = 'flex';
        fetchFloatingChats();
    } else {
        card.style.display = 'none';
    }
}

async function fetchFloatingChats() {
    try {
        const rootPath = window.location.pathname.includes('/vendor/') || window.location.pathname.includes('/admin/') ? '../' : '';
        const res = await fetch(rootPath + 'ajax_unread_chats.php');
        const data = await res.json();
        
        if (data && data.success) {
            // Update badge
            const badge = document.getElementById('floatingChatBadge');
            if (badge) {
                badge.innerText = data.count;
                badge.style.display = data.count > 0 ? 'flex' : 'none';
            }
            
            // Update trigger style if unread count > 0
            const trigger = document.querySelector('.floating-chat-trigger');
            if (trigger) {
                if (data.count > 0) {
                    trigger.style.boxShadow = '0 5px 25px rgba(239, 68, 68, 0.5)';
                    trigger.style.background = '#ef4444';
                } else {
                    trigger.style.boxShadow = '0 5px 20px rgba(67, 97, 238, 0.4)';
                    trigger.style.background = '#4361ee';
                }
            }
            
            // Populate list
            const list = document.getElementById('floatingChatList');
            if (list) {
                if (data.count === 0) {
                    list.innerHTML = '<div style="padding: 20px; text-align: center; color: #64748b; font-size: 13px;">No new messages.</div>';
                } else {
                    let html = '';
                    data.chats.forEach(chat => {
                        html += `
                            <a href="${chat.link}" class="floating-chat-item">
                                <div class="floating-chat-item-title">${chat.title}</div>
                                <div class="floating-chat-item-msg">${chat.message}</div>
                                <div class="floating-chat-item-time">${chat.time}</div>
                            </a>
                        `;
                    });
                    list.innerHTML = html;
                }
            }
        }
    } catch (e) {
        console.error('Error fetching unread chats:', e);
    }
}

// Poll count every 25 seconds
setInterval(fetchFloatingChats, 25000);
// Run on load
document.addEventListener('DOMContentLoaded', fetchFloatingChats);
</script>

<!-- Global Incoming Call Alert Modal -->
<div id="incomingCallModal" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); z-index: 99999; align-items: center; justify-content: center; font-family: 'Inter', sans-serif;">
    <div style="background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 24px; padding: 40px; text-align: center; color: white; width: 380px; max-width: 90%; box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
        <div style="margin-bottom: 20px; position: relative;">
            <div style="width: 80px; height: 80px; background: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; animation: pulseCall 1.5s infinite;">
                <i class="fa-solid fa-phone" style="font-size: 32px; color: white;"></i>
            </div>
        </div>
        <h3 id="incomingCallTitle" style="font-size: 20px; font-weight: 800; margin-bottom: 10px;">Incoming Call</h3>
        <p id="incomingCallBusiness" style="font-size: 16px; font-weight: 600; color: #a1a1aa; margin-bottom: 5px;">ABC Decorators</p>
        <p id="incomingCallVendor" style="font-size: 13px; font-weight: 500; color: #71717a; margin-bottom: 30px;">Partner: John Doe</p>
        
        <div style="display: flex; gap: 15px; justify-content: center;">
            <button id="btnDeclineIncoming" style="flex: 1; padding: 14px; border-radius: 14px; background: #ef4444; border: none; color: white; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; transition: background 0.2s;">
                <i class="fa-solid fa-phone-slash"></i> Decline
            </button>
            <button id="btnAcceptIncoming" style="flex: 1; padding: 14px; border-radius: 14px; background: #10b981; border: none; color: white; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; transition: background 0.2s;">
                <i class="fa-solid fa-phone"></i> Accept
            </button>
        </div>
    </div>
</div>

<style>
@keyframes pulseCall {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
    70% { transform: scale(1); box-shadow: 0 0 0 20px rgba(16, 185, 129, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}
</style>

<script>
let currentIncomingCallId = null;
let currentIncomingOrderId = null;
let currentIncomingCallType = null;
let globalRingtoneInterval = null;
let globalAudioCtx = null;

function playSyntheticRing() {
    try {
        if (!globalAudioCtx) {
            globalAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (globalAudioCtx.state === 'suspended') {
            globalAudioCtx.resume();
        }
        
        const osc1 = globalAudioCtx.createOscillator();
        const osc2 = globalAudioCtx.createOscillator();
        const gainNode = globalAudioCtx.createGain();
        
        osc1.type = 'sine';
        osc1.frequency.value = 440;
        
        osc2.type = 'sine';
        osc2.frequency.value = 480;
        
        gainNode.gain.setValueAtTime(0, globalAudioCtx.currentTime);
        gainNode.gain.linearRampToValueAtTime(0.15, globalAudioCtx.currentTime + 0.1);
        gainNode.gain.setValueAtTime(0.15, globalAudioCtx.currentTime + 1.8);
        gainNode.gain.linearRampToValueAtTime(0, globalAudioCtx.currentTime + 2.0);
        
        osc1.connect(gainNode);
        osc2.connect(gainNode);
        gainNode.connect(globalAudioCtx.destination);
        
        osc1.start();
        osc2.start();
        
        osc1.stop(globalAudioCtx.currentTime + 2.0);
        osc2.stop(globalAudioCtx.currentTime + 2.0);
    } catch (e) {
        console.warn('Audio Context ring error:', e);
    }
}

async function checkIncomingCallsGlobal() {
    if (document.getElementById('incomingCallModal').style.display === 'flex') return;

    try {
        const apiUrl = '/admin/api/check_incoming_calls.php';
        const res = await fetch(apiUrl);
        const data = await res.json();
        if (data && data.success && data.incoming) {
            currentIncomingCallId = data.call_session_id;
            currentIncomingOrderId = data.order_id;
            currentIncomingCallType = data.call_type;
            
            document.getElementById('incomingCallBusiness').innerText = data.business_name;
            document.getElementById('incomingCallVendor').innerText = 'Partner: ' + data.vendor_name;
            document.getElementById('incomingCallTitle').innerText = (data.call_type === 'video' ? '📹 Video Call' : '📞 Voice Call');
            
            document.getElementById('incomingCallModal').style.display = 'flex';
            
            playSyntheticRing();
            globalRingtoneInterval = setInterval(playSyntheticRing, 3000);
        }
    } catch(e) {
        // Silent error to prevent polling noise in console
    }
}

document.getElementById('btnDeclineIncoming').onclick = async function() {
    if (!currentIncomingCallId) return;
    const sid = currentIncomingCallId;
    currentIncomingCallId = null;
    
    clearInterval(globalRingtoneInterval);
    document.getElementById('incomingCallModal').style.display = 'none';
    
    try {
        const fd = new FormData();
        fd.append('action', 'decline_call');
        fd.append('call_session_id', sid);
        await fetch('/webrtc_signal_proxy.php', { method: 'POST', body: fd, credentials: 'include' });
        
        // Log "Call declined" in chat history
        const logFd = new FormData();
        logFd.append('message', '📞 Call declined');
        logFd.append('order_id', currentIncomingOrderId);
        logFd.append('is_offline', '0');
        const adminMsgUrl = '/vendor/ajax/save_admin_message.php';
        await fetch(adminMsgUrl, { method: 'POST', body: logFd });
    } catch (e) {
        console.warn('Decline signal failed:', e);
    }
};

document.getElementById('btnAcceptIncoming').onclick = function() {
    if (!currentIncomingCallId) return;
    
    clearInterval(globalRingtoneInterval);
    document.getElementById('incomingCallModal').style.display = 'none';
    
    const callUrl = `/admin/call.php?order_id=${currentIncomingOrderId}&call_id=${currentIncomingCallId}&action=answer&call_type=${currentIncomingCallType}`;
        
    window.open(callUrl, 'webrtc_call_window', 'width=1000,height=750,toolbar=no,menubar=no,location=no,status=no');
};

setInterval(checkIncomingCallsGlobal, 4000);
document.addEventListener('DOMContentLoaded', checkIncomingCallsGlobal);
</script>