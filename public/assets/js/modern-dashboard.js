/**
 * PLN Inventory System - Modern Dashboard JS
 * Handles sidebar toggle, animations, and interactivity
 */

document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    initActiveMenu();
    initCharts();
    initTooltips();
    initAnimations();
});

/**
 * Sidebar Toggle Functionality
 */
function initSidebar() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const sidebarOverlay = document.querySelector('.sidebar-overlay');
    
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            if (sidebarOverlay) {
                sidebarOverlay.classList.toggle('active');
            }
        });
    }
    
    // Close sidebar when clicking overlay
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            sidebarOverlay.classList.remove('active');
        });
    }
    
    // Close sidebar on window resize to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 992) {
            if (sidebar) sidebar.classList.remove('active');
            if (sidebarOverlay) sidebarOverlay.classList.remove('active');
        }
    });
}

/**
 * Highlight Active Menu Item
 */
function initActiveMenu() {
    const currentPage = new URLSearchParams(window.location.search).get('page') || 'dashboard';
    const menuLinks = document.querySelectorAll('.sidebar-menu-link');
    
    menuLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href) {
            const linkPage = new URLSearchParams(href.split('?')[1]).get('page');
            if (linkPage === currentPage || 
                (currentPage === 'dashboard' && href.endsWith('index.php')) ||
                (currentPage === 'admin_dashboard' && href.includes('admin_dashboard'))) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        }
    });
}

/**
 * Initialize Chart.js Charts
 */
function initCharts() {
    // Transaction Line Chart
    const transactionCtx = document.getElementById('transactionChart');
    if (transactionCtx && typeof Chart !== 'undefined') {
        new Chart(transactionCtx, {
            type: 'line',
            data: {
                labels: ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'],
                datasets: [{
                    label: 'Barang Masuk',
                    data: [120, 190, 150, 220, 180, 140, 200],
                    borderColor: '#22c55e',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#22c55e'
                }, {
                    label: 'Barang Keluar',
                    data: [80, 120, 90, 140, 110, 80, 130],
                    borderColor: '#1a9aaa',
                    backgroundColor: 'rgba(26, 154, 170, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#1a9aaa'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            color: '#64748b'
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: '#1e293b',
                        padding: 12,
                        cornerRadius: 8
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#64748b'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        },
                        ticks: {
                            color: '#64748b'
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    }
    
    // Bar Chart for Top Borrowed Items
    // Skip if chart is already created by inline script (dashboard.php)
    const topBorrowedCtx = document.getElementById('topBorrowedChart');
    if (topBorrowedCtx && typeof Chart !== 'undefined' && typeof chartLabels !== 'undefined' && typeof topBorrowedChart === 'undefined') {
        new Chart(topBorrowedCtx, {
            type: 'bar',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Jumlah Peminjaman',
                    data: chartData,
                    backgroundColor: [
                        'rgba(26, 154, 170, 0.8)',
                        'rgba(45, 212, 191, 0.8)',
                        'rgba(34, 197, 94, 0.8)',
                        'rgba(245, 158, 11, 0.8)',
                        'rgba(239, 68, 68, 0.8)',
                        'rgba(59, 130, 246, 0.8)',
                        'rgba(139, 92, 246, 0.8)'
                    ],
                    borderColor: [
                        'rgb(26, 154, 170)',
                        'rgb(45, 212, 191)',
                        'rgb(34, 197, 94)',
                        'rgb(245, 158, 11)',
                        'rgb(239, 68, 68)',
                        'rgb(59, 130, 246)',
                        'rgb(139, 92, 246)'
                    ],
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#64748b',
                            stepSize: 1
                        },
                        grid: {
                            color: 'rgba(0,0,0,0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#64748b',
                            maxRotation: 45,
                            minRotation: 45
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
}

/**
 * Initialize Bootstrap Tooltips
 */
function initTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

/**
 * Initialize Animations
 */
function initAnimations() {
    // Animate stat cards on load
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
    
    // Animate notification badge
    const notifBadge = document.querySelector('.notification-badge');
    if (notifBadge) {
        setInterval(() => {
            notifBadge.style.transform = 'scale(1.2)';
            setTimeout(() => {
                notifBadge.style.transform = 'scale(1)';
            }, 200);
        }, 5000);
    }
}

/**
 * Toast Notification
 */
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 3000;
        min-width: 300px;
        animation: slideIn 0.3s ease;
    `;
    
    const iconMap = {
        success: 'bi-check-circle-fill',
        danger: 'bi-exclamation-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill'
    };
    
    toast.innerHTML = `
        <i class="bi ${iconMap[type] || iconMap.info} me-2"></i>
        <span>${message}</span>
        <button type="button" class="btn-close ms-auto" onclick="this.parentElement.remove()"></button>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

/**
 * Format Number to Indonesian Format
 */
function formatNumber(num) {
    return new Intl.NumberFormat('id-ID').format(num);
}

/**
 * Confirm Delete Dialog
 */
function confirmDelete(message = 'Apakah Anda yakin ingin menghapus?') {
    return confirm(message);
}

/**
 * Search Filter for Tables
 */
function filterTable(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    
    if (!input || !table) return;
    
    input.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            
            for (let j = 0; j < cells.length; j++) {
                const cellText = cells[j].textContent || cells[j].innerText;
                if (cellText.toLowerCase().indexOf(filter) > -1) {
                    found = true;
                    break;
                }
            }
            
            rows[i].style.display = found ? '' : 'none';
        }
    });
}

/**
 * Tab Button Functionality
 */
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('card-tab-btn')) {
        const parent = e.target.closest('.card-actions');
        if (parent) {
            parent.querySelectorAll('.card-tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            e.target.classList.add('active');
        }
    }
    
    if (e.target.classList.contains('table-filter-btn') && !e.target.classList.contains('primary')) {
        const parent = e.target.closest('.table-filters');
        if (parent) {
            parent.querySelectorAll('.table-filter-btn:not(.primary)').forEach(btn => {
                btn.classList.remove('active');
            });
            e.target.classList.add('active');
        }
    }
});
