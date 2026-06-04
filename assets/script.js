// Auto-refresh alerts every 10 seconds
function startAlertRefresh() {
    setInterval(refreshAlerts, 10000); // 10 seconds
}

function refreshAlerts() {
    fetch('../backend/get_alerts.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateAlertsList(data.alerts);
                updateAlertCount(data.count);
            }
        })
        .catch(error => console.error('Error refreshing alerts:', error));
}

function updateAlertsList(alerts) {
    const container = document.getElementById('alerts-container');
    if (!container) return;
    
    if (alerts.length === 0) {
        container.innerHTML = '<div class="alert alert-warning">No pending alerts</div>';
        return;
    }
    
    let html = '';
    alerts.forEach(alert => {
        html += `
            <div class="job-card">
                <h3>${alert.design_name || 'Service Request'}</h3>
                <div class="job-info">
                    <div class="job-info-item">
                        <label>Service ID</label>
                        <span>${alert.service_id}</span>
                    </div>
                    <div class="job-info-item">
                        <label>City</label>
                        <span>${alert.city}</span>
                    </div>
                    <div class="job-info-item">
                        <label>Price</label>
                        <span>₹${alert.price}</span>
                    </div>
                    <div class="job-info-item">
                        <label>Received</label>
                        <span>${formatDateTime(alert.sent_at)}</span>
                    </div>
                </div>
                <div class="job-info">
                    <div class="job-info-item">
                        <label>Includes</label>
                        <span>${alert.includes || 'N/A'}</span>
                    </div>
                </div>
                <a href="job-details.php?id=${alert.order_id}" class="btn btn-primary">View Details</a>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateAlertCount(count) {
    const badge = document.getElementById('alert-count');
    if (badge) {
        badge.textContent = count;
    }
}

function formatDateTime(datetime) {
    const date = new Date(datetime);
    return date.toLocaleString();
}

// Accept Job
function acceptJob(orderId) {
    if (!confirm('Are you sure you want to accept this job?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('order_id', orderId);
    
    fetch('../backend/vendor_accept_job.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.href = 'my-jobs.php';
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while accepting the job');
    });
}

// Show loading spinner
function showLoading() {
    const spinner = document.createElement('div');
    spinner.className = 'spinner';
    spinner.id = 'loading-spinner';
    document.body.appendChild(spinner);
}

function hideLoading() {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) {
        spinner.remove();
    }
}
