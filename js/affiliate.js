// Affiliate Dashboard JavaScript
let currentUserId = null;
let affiliateData = null;
let earningsChart = null;

// Initialize dashboard
document.addEventListener('DOMContentLoaded', function() {
    // Get current user from session
    getCurrentUser();
});

// Get current user from session
async function getCurrentUser() {
    try {
        const response = await fetch('api/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({ action: 'check_session' })
        });
        
        const result = await response.json();
        if (result.success && result.user) {
            currentUserId = result.user.id;
            loadAffiliateData();
        } else {
            window.location.href = 'login.html';
        }
    } catch (error) {
        console.error('Error getting current user:', error);
        window.location.href = 'login.html';
    }
}

// Load affiliate data
async function loadAffiliateData() {
    showLoading(true);
    
    try {
        const response = await fetch(`api/affiliate.php?action=get_affiliate_info&user_id=${currentUserId}`);
        const result = await response.json();
        
        if (result.success) {
            if (result.affiliate) {
                affiliateData = result;
                showAffiliateDashboard();
            } else {
                showBecomeAffiliateSection();
            }
        } else {
            showAlert('Error loading affiliate data: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error loading affiliate data:', error);
        showAlert('Error loading affiliate data', 'danger');
    } finally {
        showLoading(false);
    }
}

// Show affiliate dashboard
function showAffiliateDashboard() {
    document.getElementById('becomeAffiliateSection').style.display = 'none';
    document.getElementById('affiliateDashboard').style.display = 'block';
    document.getElementById('mainContent').style.display = 'block';
    
    // Update affiliate info
    document.getElementById('affiliateCode').textContent = affiliateData.affiliate.affiliate_code;
    
    // Update statistics
    updateStatistics();
    
    // Update referrals table
    updateReferralsTable();
    
    // Update bank account info
    updateBankAccountInfo();
    
    // Update withdrawals table
    updateWithdrawalsTable();
    
    // Initialize earnings chart
    initializeEarningsChart();
}

// Show become affiliate section
function showBecomeAffiliateSection() {
    document.getElementById('becomeAffiliateSection').style.display = 'block';
    document.getElementById('affiliateDashboard').style.display = 'none';
    document.getElementById('mainContent').style.display = 'block';
}

// Update statistics
function updateStatistics() {
    const stats = affiliateData.stats;
    
    document.getElementById('totalReferrals').textContent = stats.total_referrals || 0;
    document.getElementById('totalEarnings').textContent = '$' + (affiliateData.affiliate.total_earnings || 0).toFixed(2);
    document.getElementById('currentBalance').textContent = '$' + (affiliateData.affiliate.current_balance || 0).toFixed(2);
    document.getElementById('pendingReferrals').textContent = stats.pending_referrals || 0;
}

// Update referrals table
function updateReferralsTable() {
    const tbody = document.getElementById('referralsTableBody');
    const referrals = affiliateData.recentReferrals || [];
    
    if (referrals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">No referrals yet</td></tr>';
        return;
    }
    
    tbody.innerHTML = referrals.map(referral => `
        <tr>
            <td>${referral.referred_name || 'N/A'}</td>
            <td>${referral.referred_email || 'N/A'}</td>
            <td>${formatDate(referral.signup_date)}</td>
            <td>$${(referral.commission_amount || 0).toFixed(2)}</td>
            <td><span class="status-badge status-${referral.status}">${referral.status}</span></td>
        </tr>
    `).join('');
}

// Update bank account info
function updateBankAccountInfo() {
    const bankAccount = affiliateData.bankAccount;
    const container = document.getElementById('bankAccountInfo');
    
    if (bankAccount) {
        container.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Bank Name:</strong> ${bankAccount.bank_name}</p>
                    <p><strong>Account Name:</strong> ${bankAccount.account_name}</p>
                    <p><strong>Account Number:</strong> ****${bankAccount.account_number.slice(-4)}</p>
                </div>
                <div class="col-md-6">
                    <p><strong>Country:</strong> ${bankAccount.country}</p>
                    <p><strong>Currency:</strong> ${bankAccount.currency}</p>
                    <p><strong>Status:</strong> <span class="status-badge status-${bankAccount.is_verified ? 'confirmed' : 'pending'}">${bankAccount.is_verified ? 'Verified' : 'Pending'}</span></p>
                </div>
            </div>
        `;
    } else {
        container.innerHTML = '<p class="text-muted">No bank account added yet. Add your bank account to receive payouts.</p>';
    }
}

// Update withdrawals table
function updateWithdrawalsTable() {
    const tbody = document.getElementById('withdrawalsTableBody');
    const withdrawals = affiliateData.payoutHistory || [];
    
    if (withdrawals.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">No withdrawal requests yet</td></tr>';
        return;
    }
    
    tbody.innerHTML = withdrawals.map(withdrawal => `
        <tr>
            <td>$${withdrawal.amount.toFixed(2)}</td>
            <td><span class="status-badge status-${withdrawal.status}">${withdrawal.status}</span></td>
            <td>${formatDate(withdrawal.payout_date)}</td>
            <td>${withdrawal.processed_date ? formatDate(withdrawal.processed_date) : 'N/A'}</td>
            <td>${withdrawal.transaction_id || 'N/A'}</td>
        </tr>
    `).join('');
}

// Initialize earnings chart
function initializeEarningsChart() {
    const ctx = document.getElementById('earningsChart').getContext('2d');
    
    // Get monthly earnings data (mock data for now)
    const monthlyData = generateMockMonthlyData();
    
    earningsChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: monthlyData.labels,
            datasets: [{
                label: 'Monthly Earnings',
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
}

// Generate mock monthly data
function generateMockMonthlyData() {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const currentMonth = new Date().getMonth();
    const labels = [];
    const data = [];
    
    for (let i = 5; i >= 0; i--) {
        const monthIndex = (currentMonth - i + 12) % 12;
        labels.push(months[monthIndex]);
        data.push(Math.random() * 500 + 100); // Random data between $100-$600
    }
    
    return { labels, data };
}

// Become affiliate
async function becomeAffiliate() {
    try {
        const response = await fetch('api/affiliate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({
                action: 'become_affiliate',
                user_id: currentUserId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showAlert('Affiliate account created successfully! Your application is pending approval.', 'success');
            setTimeout(() => loadAffiliateData(), 2000);
        } else {
            showAlert('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error becoming affiliate:', error);
        showAlert('Error creating affiliate account', 'danger');
    }
}

// Copy affiliate code
function copyAffiliateCode() {
    const code = affiliateData.affiliate.affiliate_code;
    copyToClipboard(code, 'Affiliate code copied to clipboard!');
}

// Copy affiliate link
function copyAffiliateLink() {
    const link = affiliateData.affiliate.affiliate_link;
    copyToClipboard(link, 'Affiliate link copied to clipboard!');
}

// Share referral link
function shareReferralLink() {
    const link = affiliateData.affiliate.affiliate_link;
    
    if (navigator.share) {
        navigator.share({
            title: 'Join Pips & Profit Academy',
            text: 'Join Pips & Profit Academy using my referral link and start your forex trading journey!',
            url: link
        });
    } else {
        copyToClipboard(link, 'Affiliate link copied to clipboard!');
    }
}

// Copy to clipboard
function copyToClipboard(text, message) {
    navigator.clipboard.writeText(text).then(() => {
        showAlert(message, 'success');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showAlert(message, 'success');
    });
}

// Show bank account modal
function showBankAccountModal() {
    const modal = new bootstrap.Modal(document.getElementById('bankAccountModal'));
    
    // Pre-fill form if bank account exists
    if (affiliateData.bankAccount) {
        document.getElementById('bankName').value = affiliateData.bankAccount.bank_name || '';
        document.getElementById('accountName').value = affiliateData.bankAccount.account_name || '';
        document.getElementById('accountNumber').value = affiliateData.bankAccount.account_number || '';
        document.getElementById('routingNumber').value = affiliateData.bankAccount.routing_number || '';
        document.getElementById('swiftCode').value = affiliateData.bankAccount.swift_code || '';
        document.getElementById('country').value = affiliateData.bankAccount.country || '';
        document.getElementById('currency').value = affiliateData.bankAccount.currency || 'USD';
    }
    
    modal.show();
}

// Save bank account
async function saveBankAccount() {
    const formData = {
        action: 'add_bank_account',
        affiliate_id: affiliateData.affiliate.id,
        bank_name: document.getElementById('bankName').value,
        account_name: document.getElementById('accountName').value,
        account_number: document.getElementById('accountNumber').value,
        routing_number: document.getElementById('routingNumber').value,
        swift_code: document.getElementById('swiftCode').value,
        country: document.getElementById('country').value,
        currency: document.getElementById('currency').value
    };
    
    try {
        const response = await fetch('api/affiliate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        if (result.success) {
            showAlert('Bank account saved successfully!', 'success');
            bootstrap.Modal.getInstance(document.getElementById('bankAccountModal')).hide();
            loadAffiliateData(); // Reload data
        } else {
            showAlert('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error saving bank account:', error);
        showAlert('Error saving bank account', 'danger');
    }
}

// Show withdrawal modal
function showWithdrawalModal() {
    if (!affiliateData.bankAccount) {
        showAlert('Please add a bank account first before requesting a withdrawal.', 'warning');
        return;
    }
    
    document.getElementById('availableBalance').textContent = '$' + (affiliateData.affiliate.current_balance || 0).toFixed(2);
    document.getElementById('withdrawalAmount').value = '';
    document.getElementById('withdrawalNotes').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('withdrawalModal'));
    modal.show();
}

// Request withdrawal
async function requestWithdrawal() {
    const amount = parseFloat(document.getElementById('withdrawalAmount').value);
    const notes = document.getElementById('withdrawalNotes').value;
    const availableBalance = affiliateData.affiliate.current_balance || 0;
    
    if (amount < 10) {
        showAlert('Minimum withdrawal amount is $10.00', 'warning');
        return;
    }
    
    if (amount > availableBalance) {
        showAlert('Insufficient balance', 'warning');
        return;
    }
    
    const formData = {
        action: 'request_withdrawal',
        affiliate_id: affiliateData.affiliate.id,
        amount: amount,
        notes: notes
    };
    
    try {
        const response = await fetch('api/affiliate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        if (result.success) {
            showAlert('Withdrawal request submitted successfully! Payout date: ' + result.payout_date, 'success');
            bootstrap.Modal.getInstance(document.getElementById('withdrawalModal')).hide();
            loadAffiliateData(); // Reload data
        } else {
            showAlert('Error: ' + result.message, 'danger');
        }
    } catch (error) {
        console.error('Error requesting withdrawal:', error);
        showAlert('Error requesting withdrawal', 'danger');
    }
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

// Get CSRF token
function getCsrfToken() {
    // Get CSRF token from session or meta tag
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || 
           sessionStorage.getItem('csrf_token') || '';
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

// Check session function (add this to auth.php)
async function checkSession() {
    try {
        const response = await fetch('api/auth.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'check_session' })
        });
        
        const result = await response.json();
        return result.success ? result.user : null;
    } catch (error) {
        console.error('Error checking session:', error);
        return null;
    }
}
