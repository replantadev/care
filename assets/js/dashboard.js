/**
 * Replanta Care Dashboard JavaScript
 * Handles tabbed interface, AJAX requests, and dynamic updates
 */

jQuery(document).ready(function($) {
    'use strict';

    // Global dashboard object
    window.RPCareDashboard = {
        initialized: false,
        refreshInterval: null,
        currentTab: 'status',
        
        init: function() {
            if (this.initialized) return;
            
            this.bindEvents();
            this.initTabs();
            this.refreshData();
            this.startAutoRefresh();
            this.initialized = true;
            
            console.log('Replanta Care Dashboard initialized');
        },
        
        bindEvents: function() {
            var self = this;
            
            // Tab navigation
            $('.rpcare-tab-btn').on('click', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                self.switchTab(tab);
            });
            
            // Action buttons
            $(document).on('click', '[data-rpcare-action]', function(e) {
                e.preventDefault();
                var action = $(this).data('rpcare-action');
                var args = $(this).data('rpcare-args') || {};
                self.executeAction(action, args);
            });
            
            // Refresh button
            $(document).on('click', '.rpcare-refresh-btn', function(e) {
                e.preventDefault();
                self.refreshData();
            });
            
            // Auto-refresh toggle
            $(document).on('change', '.rpcare-auto-refresh', function() {
                if ($(this).is(':checked')) {
                    self.startAutoRefresh();
                } else {
                    self.stopAutoRefresh();
                }
            });
        },
        
        initTabs: function() {
            // Show first tab by default
            var firstTab = $('.rpcare-tab-btn').first().data('tab');
            this.switchTab(firstTab || 'status');
        },
        
        switchTab: function(tabName) {
            this.currentTab = tabName;
            
            // Update tab navigation
            $('.rpcare-tab-btn').removeClass('active');
            $('.rpcare-tab-btn[data-tab="' + tabName + '"]').addClass('active');
            
            // Update tab panels
            $('.rpcare-tab-panel').removeClass('active');
            $('#rpcare-tab-' + tabName).addClass('active');
            
            // Trigger tab-specific refresh if needed
            this.onTabSwitch(tabName);
        },
        
        onTabSwitch: function(tabName) {
            switch(tabName) {
                case 'backups':
                    this.refreshBackupData();
                    break;
                case 'updates':
                    this.refreshUpdateData();
                    break;
                case 'health':
                    this.refreshHealthData();
                    break;
            }
        },
        
        refreshData: function() {
            this.showLoading();
            this.refreshDashboardData();
        },
        
        refreshDashboardData: function() {
            var self = this;
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_get_dashboard_data',
                    nonce: rpcare_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateDashboard(response.data);
                    } else {
                        self.showError('Error loading dashboard data: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    self.showError('AJAX error: ' + error);
                },
                complete: function() {
                    self.hideLoading();
                }
            });
        },
        
        refreshBackupData: function() {
            var self = this;
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_get_backup_data',
                    nonce: rpcare_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateBackupTab(response.data);
                    }
                }
            });
        },
        
        refreshUpdateData: function() {
            var self = this;
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_get_update_data',
                    nonce: rpcare_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateUpdatesTab(response.data);
                    }
                }
            });
        },
        
        refreshHealthData: function() {
            var self = this;
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_get_health_data',
                    nonce: rpcare_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.updateHealthTab(response.data);
                    }
                }
            });
        },
        
        updateDashboard: function(data) {
            // Update status cards
            if (data.status) {
                this.updateStatusCards(data.status);
            }
            
            // Update plan info
            if (data.plan) {
                this.updatePlanInfo(data.plan);
            }
            
            // Update last updated time
            $('.rpcare-last-updated').text('Last updated: ' + new Date().toLocaleTimeString());
        },
        
        updateStatusCards: function(status) {
            var statusMap = {
                'online': { icon: '🟢', class: 'rpcare-status-online' },
                'warning': { icon: '🟡', class: 'rpcare-status-warning' },
                'error': { icon: '🔴', class: 'rpcare-status-error' },
                'pending': { icon: '⚪', class: 'rpcare-status-pending' }
            };
            
            $.each(status, function(key, value) {
                var $card = $('.rpcare-status-card[data-status="' + key + '"]');
                if ($card.length) {
                    var statusInfo = statusMap[value.status] || statusMap['pending'];
                    
                    $card.find('.rpcare-status-icon')
                         .text(statusInfo.icon)
                         .removeClass('rpcare-status-online rpcare-status-warning rpcare-status-error rpcare-status-pending')
                         .addClass(statusInfo.class);
                    
                    $card.find('.rpcare-status-info p').text(value.value || value.status);
                    $card.find('.rpcare-status-info small').text(value.message || '');
                }
            });
        },
        
        updatePlanInfo: function(plan) {
            $('.rpcare-plan-info h3').text('Current Plan: ' + plan.name);
            $('.rpcare-plan-badge')
                .removeClass('rpcare-plan-semilla rpcare-plan-raiz rpcare-plan-ecosistema')
                .addClass('rpcare-plan-' + plan.type.toLowerCase())
                .text(plan.name);
        },
        
        updateBackupTab: function(data) {
            var $backupTab = $('#rpcare-tab-backups');
            
            // Update last backup info
            if (data.last_backup) {
                $backupTab.find('.rpcare-backup-info').html(this.generateBackupInfo(data.last_backup));
            }
            
            // Update backup list
            if (data.backups && data.backups.length) {
                var backupHtml = '';
                $.each(data.backups, function(index, backup) {
                    backupHtml += '<div class="rpcare-backup-item">';
                    backupHtml += '<strong>' + backup.name + '</strong><br>';
                    backupHtml += 'Date: ' + backup.date + '<br>';
                    backupHtml += 'Size: ' + backup.size;
                    backupHtml += '</div>';
                });
                $backupTab.find('.rpcare-backup-list').html(backupHtml);
            }
        },
        
        updateUpdatesTab: function(data) {
            var $updateTab = $('#rpcare-tab-updates');
            
            if (data.available_updates && data.available_updates.length) {
                var updateHtml = '';
                $.each(data.available_updates, function(index, update) {
                    updateHtml += '<div class="rpcare-update-item">';
                    updateHtml += '<strong>' + update.name + '</strong><br>';
                    updateHtml += 'Current: ' + update.current + ' → New: ' + update.new;
                    updateHtml += '</div>';
                });
                $updateTab.find('.rpcare-update-list').html(updateHtml);
            } else {
                $updateTab.find('.rpcare-update-list').html('<p>No updates available</p>');
            }
        },
        
        updateHealthTab: function(data) {
            var $healthTab = $('#rpcare-tab-health');
            
            // Update health score
            if (data.score !== undefined) {
                var scoreClass = data.score >= 80 ? 'good' : data.score >= 60 ? 'warning' : 'poor';
                $healthTab.find('.rpcare-health-score')
                         .text('Health Score: ' + data.score + '%')
                         .removeClass('good warning poor')
                         .addClass(scoreClass);
            }
            
            // Update issues
            if (data.issues && data.issues.length) {
                var issueHtml = '<h5>Issues Found:</h5><ul>';
                $.each(data.issues, function(index, issue) {
                    issueHtml += '<li>' + issue + '</li>';
                });
                issueHtml += '</ul>';
                $healthTab.find('.rpcare-health-issues').html(issueHtml);
            } else {
                $healthTab.find('.rpcare-health-issues').html('<p>No issues found. Your site is healthy!</p>');
            }
        },
        
        generateBackupInfo: function(backup) {
            var html = '<h5>Last Backup:</h5>';
            html += '<ul>';
            html += '<li><strong>Date:</strong> ' + backup.date + '</li>';
            html += '<li><strong>Size:</strong> ' + backup.size + '</li>';
            html += '<li><strong>Type:</strong> ' + backup.type + '</li>';
            if (backup.files) {
                html += '<li><strong>Files:</strong> ' + backup.files + '</li>';
            }
            if (backup.database) {
                html += '<li><strong>Database:</strong> ' + backup.database + '</li>';
            }
            html += '</ul>';
            return html;
        },
        
        executeAction: function(action, args) {
            var self = this;
            
            // Show confirmation for destructive actions
            var destructiveActions = ['clear_cache', 'run_backup', 'update_plugins'];
            if (destructiveActions.indexOf(action) !== -1) {
                if (!confirm('Are you sure you want to ' + action.replace('_', ' ') + '?')) {
                    return;
                }
            }
            
            this.showLoading('Executing ' + action.replace('_', ' ') + '...');
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_execute_task',
                    task: action,
                    args: args,
                    nonce: rpcare_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.showSuccess(response.data.message || 'Action completed successfully');
                        // Refresh data after successful action
                        setTimeout(function() {
                            self.refreshData();
                        }, 1000);
                    } else {
                        self.showError('Action failed: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    self.showError('AJAX error: ' + error);
                },
                complete: function() {
                    self.hideLoading();
                }
            });
        },
        
        startAutoRefresh: function() {
            var self = this;
            this.stopAutoRefresh(); // Clear any existing interval
            
            this.refreshInterval = setInterval(function() {
                self.refreshData();
            }, 300000); // Refresh every 5 minutes
        },
        
        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        },
        
        showLoading: function(message) {
            var $overlay = $('#rpcare-loading-overlay');
            if ($overlay.length === 0) {
                $overlay = $('<div id="rpcare-loading-overlay">' +
                           '<div class="rpcare-spinner"></div>' +
                           '<p>Loading...</p>' +
                           '</div>');
                $('.rpcare-dashboard-widget').append($overlay);
            }
            
            if (message) {
                $overlay.find('p').text(message);
            }
            
            $overlay.show();
        },
        
        hideLoading: function() {
            $('#rpcare-loading-overlay').hide();
        },
        
        showSuccess: function(message) {
            this.showNotice(message, 'success');
        },
        
        showError: function(message) {
            this.showNotice(message, 'error');
        },
        
        showNotice: function(message, type) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible">' +
                          '<p>' + message + '</p>' +
                          '<button type="button" class="notice-dismiss">' +
                          '<span class="screen-reader-text">Dismiss this notice.</span>' +
                          '</button>' +
                          '</div>');
            
            $('.rpcare-dashboard-widget').prepend($notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Manual dismiss
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            });
        }
    };
    
    // Initialize dashboard when ready
    if ($('.rpcare-dashboard-widget').length) {
        RPCareDashboard.init();
    }
    
    // Handle widget refresh in WordPress admin
    $(document).on('widget-updated widget-added', function(event, widget) {
        if (widget && widget.find('.rpcare-dashboard-widget').length) {
            setTimeout(function() {
                RPCareDashboard.init();
            }, 500);
        }
    });
    
    // Utility functions for external use
    window.RPCareDashboard.utils = {
        formatBytes: function(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
            
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        },
        
        formatDate: function(date) {
            if (typeof date === 'string') {
                date = new Date(date);
            }
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },
        
        debounce: function(func, wait, immediate) {
            var timeout;
            return function() {
                var context = this, args = arguments;
                var later = function() {
                    timeout = null;
                    if (!immediate) func.apply(context, args);
                };
                var callNow = immediate && !timeout;
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
                if (callNow) func.apply(context, args);
            };
        }
    };
});
