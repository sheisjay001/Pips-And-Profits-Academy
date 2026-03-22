// Affiliate Admin Panel JavaScript
let adminData = null;
let monthlyCommissionsChart = null;
let topPerformersChart = null;

// Initialize admin panel
document.addEventListener('DOMContentLoaded', function() {
    // Check if user is admin
    checkAdminAccess();
    
    // Load admin data
    loadAdminData();
    
    // Setup event listeners
    setupEventListeners();
});

// Check admin access
async function checkAdminAccess() {
    try {
        const response = await fetch('api/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'check_session' })
        });
        
        const result = await response.json();
        if (!result.success || result.user.role !== 'admin') {
            showAlert('Access denied. Admin privileges required.', 'danger');
            setTimeout(() => {
                window.location.href = 'dashboard.html';
            }, 2000);
            return;
        }
    } catch (error) {
        console.error('Error checking admin access:', error);
        window.location.href = 'login.html';
    }
}

// Setup event listeners
function setupEventListeners() {
    // Search and filter listeners
    document.getElementById('affiliateSearch')?.addEventListener('input', filterAffiliates);
    document.getElementById('affiliateStatusFilter')?.addEventListener('change', filterAffiliates);
    document.getElementById('payoutStatusFilter')?.addEventListener('change', filterPayouts);
}

// Load admin data
async function loadAdminData() {
    showLoading(true);
    
    try {
        // Load all admin data
        const [affiliatesResponse, referralsResponse, payoutsResponse] = await Promise.all([
            fetch('api/affiliate-admin.php?action=get_all_affiliates'),
            fetch('api/affiliate-admin.php?action=get_all_referrals'),
            fetch('api/affiliate-admin.php?action=get_all_payouts')
        ]);
        
        const affiliates = await affiliatesResponse.json();
        const referrals = await referralsResponse.json();
        const payouts = await payoutsResponse.json();
        
        if (affiliates.success && referrals.success && payouts.success) {
            adminData = {
                affiliates: affiliates.affiliates || [],
                referrals: referrals.referrals || [],
                payouts: payouts.payouts || []
            };
            
            updateStatistics();
            updateAffiliatesTable();
            updateReferralsTable();
            updatePayoutsTable();
            initializeCharts();
        } else {
            showAlert('Error loading admin data', 'danger');
        }
    } catch (error) {
        console.error('Error loading admin data:', error);
        showAlert('Error loading admin data', 'danger');
    } finally {
        showLoading(false);
    }
}

// Update statistics
function updateStatistics() {
    const affiliates = adminData.affiliates;
    const referrals = adminData.referrals;
    const payouts = adminData.payouts;
    
    // Total affiliates
    document.getElementById('totalAffiliates').textContent = affiliates.length;
    
    // Total referrals
    document.getElementById('totalReferrals').textContent = referrals.length;
    
    // Total commissions
    const totalCommissions = referrals.reduce((sum, ref) => sum + (parseFloat(ref.commission_amount) || 0), 0);
    document.getElementById('totalCommissions').textContent = '$' + totalCommissions.toFixed(2);
    
    // Pending payouts
    const pendingPayouts = payouts
        .filter(p => p.status === 'pending')
        .reduce((sum, p) => sum + parseFloat(p.amount), 0);
    document.getElementById('pendingPayouts').textContent = '$' + pendingPayouts.toFixed(2);
}

// Update affiliates table
function updateAffiliatesTable() {
    const tbody = document.getElementById('affiliatesTableBody');
    const affiliates = adminData.affiliates;
    
    if (affiliates.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center">No affiliates found</td></tr>';
        return;
    }
    
    tbody.innerHTML = affiliates.map(affiliate => `
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(affiliate.name)}&background=6f42c1&color=fff" 
                         alt="${affiliate.name}" class="rounded-circle me-2" width="32" height="32">
                    <div>
                        <div class="fw-bold">${affiliate.name}</div>
                        <small class="text-muted">${affiliate.email}</small>
                    </div>
                </div>
            </td>
            <td><code>${affiliate.affiliate_code}</code></td>
            <td>${affiliate.commission_rate}%</td>
            <td>$${(affiliate.total_earnings || 0).toFixed(2)}</td>
            <td>$${(affiliate.current_balance || 0).toFixed(2)}</td>
            <td>${affiliate.referral_count || 0}</td>
            <td><span class="status-badge status-${affiliate.status}">${affiliate.status}</span></td>
            <td>
                <div class="btn-group" role="group">
                    <button class="btn btn-sm btn-outline-primary" onclick="toggleAffiliateStatus(${affiliate.id}, '${affiliate.status}')">
                        <i class="fas fa-${affiliate.status === 'active' ? 'pause' : 'play'}"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-info" onclick="viewAffiliateDetails(${affiliate.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

// Update referrals table
function updateReferralsTable() {
    const tbody = document.getElementById('referralsTableBody');
    const referrals = adminData.referrals;
    
    if (referrals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">No referrals found</td></tr>';
        return;
    }
    
    tbody.innerHTML = referrals.map(referral => `
        <tr>
            <td>
                <div class="d-flex align-items-center">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(referral.referred_name)}&background=6f42c1&color=fff" 
                         alt="${referral.referred_name}" class="rounded-circle me-2" width="32" height="32">
                    <div>
                        <div class="fw-bold">${referral.referred_name}</div>
                        <small class="text-muted">${referral.referred_email}</small>
                    </div>
                </div>
            </td>
            <td>${referral.affiliate_name}</td>
            <td><code>${referral.referral_code}</code></td>
            <td>$${(referral.commission_amount || 0).toFixed(2)}</td>
            <td>${formatDate(referral.signup_date)}</td>
            <td><span class="status-badge status-${referral.status}">${referral.status}</span></td>
        </tr>
    `).join('');
}

// Update payouts table
function updatePayoutsTable() {
    const tbody = document.getElementById('payoutsTableBody');
    const payouts = adminData.payouts;
    
    if (payouts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center">No payouts found</td></tr>';
        return;
    }
    
    tbody.innerHTML = payouts.map(payout => `
        <tr>
            <td>${payout.affiliate_name}</td>
            <td>$${parseFloat(payout.amount).toFixed(2)}</td>
            <td><span class="status-badge status-${payout.status}">${payout.status}</span></td>
            <td>${formatDate(payout.payout_date)}</td>
            <td>${payout.processed_date ? formatDate(payout.processed_date) : 'N/A'}</td>
            <td>${payout.transaction_id || 'N/A'}</td>
            <td>
                <div class="btn-group" role="group">
                    ${payout.status === 'pending' ? `
                        <button class="btn btn-sm btn-outline-success" onclick="updatePayoutStatus(${payout.id}, 'processing')">
                            <i class="fas fa-play"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="updatePayoutStatus(${payout.id}, 'cancelled')">
                            <i class="fas fa-times"></i>
                        </button>
                    ` : ''}
                    <button class="btn btn-sm btn-outline-info" onclick="viewPayoutDetails(${payout.id})">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

// Initialize charts
function initializeCharts() {
    // Monthly commissions chart
    const monthlyCtx = document.getElementById('monthlyCommissionsChart').getContext('2d');
    const monthlyData = generateMonthlyCommissionsData();
    
    monthlyCommissionsChart = new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: monthlyData.labels,
            datasets: [{
                label: 'Monthly Commissions',
                data: monthlyData.data,
                borderColor: 'rgb(102, 126, 234)',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                }
            }
        }
    });
    
    // Top performers chart
    const performersCtx = document.getElementById('topPerformersChart').getContext('2d');
    const performersData = generateTopPerformersData();
    
    topPerformersChart = new Chart(performersCtx, {
        type: 'bar',
        data: {
            labels: performersData.labels,
            datasets: [{
                label: 'Total Earnings',
                data: performersData.data,
                backgroundColor: 'rgba(102, 126, 234, 0.8)',
                borderColor: 'rgb(102, 126, 234)',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return '$' + context.parsed.y.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                }
            }
        }
    });
}

// Generate monthly commissions data
function generateMonthlyCommissionsData() {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const currentMonth = new Date().getMonth();
    const labels = [];
    const data = [];
    
    for (let i = 5; i >= 0; i--) {
        const monthIndex = (currentMonth - i + 12) % 12;
        labels.push(months[monthIndex]);
        // Calculate actual monthly commissions from data
        const monthCommissions = adminData.referrals
            .filter(ref => {
                const refDate = new Date(ref.confirmation_date);
                return refDate.getMonth() === monthIndex && ref.status === 'confirmed';
            })
            .reduce((sum, ref) => sum + (parseFloat(ref.commission_amount) || 0), 0);
        data.push(monthCommissions);
    }
    
    return { labels, data };
}

// Generate top performers data
function generateTopPerformersData() {
    const topAffiliates = adminData.affiliates
        .sort((a, b) => (b.total_earnings || 0) - (a.total_earnings || 0))
        .slice(0, 5);
    
    return {
        labels: topAffiliates.map(a => a.name.split(' ')[0]), // First name only
        data: topAffiliates.map(a => a.total_earnings || 0)
    };
}

// Toggle affiliate status
async function toggleAffiliateStatus(affiliateId, currentStatus) {
    const newStatus = currentStatus === 'active' ? 'suspended' : 'active';
    
    if (!confirm(`Are you sure you want to ${newStatus === 'active' ? 'activate' : 'suspend'} this affiliate?`)) {
        return;
    }
    
    try {
        const response = await fetch('api/affiliate-admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'update_affiliate_status',
                affiliate_id: affiliateId,
                status: newStatus
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showAlert(`Affiliate ${newStatus}d successfully`, 'success');
            loadAdminData(); // Reload data
        } else {
            showAlert('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error updating affiliate status:', error);
        showAlert('Error updating affiliate status', 'danger');
    }
}

// Update payout status
async function updatePayoutStatus(payoutId, newStatus) {
    if (!confirm(`Are you sure you want to set payout status to ${newStatus}?`)) {
        return;
    }
    
    try {
        const response = await fetch('api/affiliate-admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'update_payout_status',
                payout_id: payoutId,
                status: newStatus
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showAlert(`Payout status updated to ${newStatus}`, 'success');
            loadAdminData(); // Reload data
        } else {
            showAlert('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error updating payout status:', error);
        showAlert('Error updating payout status', 'danger');
    }
}

// Process monthly payouts
async function processMonthlyPayouts() {
    if (!confirm('Are you sure you want to process all pending payouts for this month? This action cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('api/affiliate_commissions.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'process_monthly_payouts',
                admin_key: 'pips_admin_2024'
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showAlert(result.message, 'success');
            loadAdminData(); // Reload data
        } else {
            showAlert('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error processing monthly payouts:', error);
        showAlert('Error processing monthly payouts', 'danger');
    }
}

// Filter affiliates
function filterAffiliates() {
    const searchTerm = document.getElementById('affiliateSearch').value.toLowerCase();
    const statusFilter = document.getElementById('affiliateStatusFilter').value;
    
    const filteredAffiliates = adminData.affiliates.filter(affiliate => {
        const matchesSearch = affiliate.name.toLowerCase().includes(searchTerm) || 
                              affiliate.email.toLowerCase().includes(searchTerm) ||
                              affiliate.affiliate_code.toLowerCase().includes(searchTerm);
        const matchesStatus = !statusFilter || affiliate.status === statusFilter;
        
        return matchesSearch && matchesStatus;
    });
    
    // Update table with filtered data
    const tempData = adminData.affiliates;
    adminData.affiliates = filteredAffiliates;
    updateAffiliatesTable();
    adminData.affiliates = tempData;
}

// Filter payouts
function filterPayouts() {
    const statusFilter = document.getElementById('payoutStatusFilter').value;
    
    const filteredPayouts = adminData.payouts.filter(payout => {
        return !statusFilter || payout.status === statusFilter;
    });
    
    // Update table with filtered data
    const tempData = adminData.payouts;
    adminData.payouts = filteredPayouts;
    updatePayoutsTable();
    adminData.payouts = tempData;
}

// View affiliate details (placeholder)
function viewAffiliateDetails(affiliateId) {
    // This would open a modal with detailed affiliate information
    alert('Affiliate details view - to be implemented');
}

// View payout details (placeholder)
function viewPayoutDetails(payoutId) {
    // This would open a modal with detailed payout information
    alert('Payout details view - to be implemented');
}

// Show loading spinner
function showLoading(show) {
    document.getElementById('loadingSpinner').style.display = show ? 'block' : 'none';
    document.getElementById('mainContent').style.display = show ? 'none' : 'block';
}

// Show alert
function showAlert(message, type) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    const container = document.getElementById('alertContainer');
    container.innerHTML = alertHtml;
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        const alert = container.querySelector('.alert');
        if (alert) {
            bootstrap.Alert.getOrCreateInstance(alert).close();
        }
    }, 5000);
}

// Format date
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}
