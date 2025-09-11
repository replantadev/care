/**
 * Replanta Care - Admin Dashboard JavaScript
 * Professional WordPress maintenance automation
 */

(function($) {
    'use strict';

    let dashboardData = {};
    let refreshInterval = null;

    $(document).ready(function() {
        initConnectionTest();
        initManualTasks();
        initSettingsForm();
        initDashboard();
        initRealTimeUpdates();
        loadDashboardData();
    });

    function initConnectionTest() {
        $('#test-connection').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const hubUrl = $('input[name="rpcare_options[hub_url]"]').val();
            const siteToken = $('input[name="rpcare_options[site_token]"]').val();
            
            if (!hubUrl || !siteToken) {
                showNotification('Por favor, introduce URL del Hub y Token del Sitio', 'error');
                return;
            }
            
            button.addClass('testing').html('<span class="dashicons dashicons-update spin"></span> Probando...');
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_test_connection',
                    nonce: rpcare_ajax.nonce,
                    hub_url: hubUrl,
                    site_token: siteToken
                },
                success: function(response) {
                    if (response.success) {
                        showConnectionStatus('success', response.data);
                        showNotification('Conexión exitosa con el Hub', 'success');
                    } else {
                        showConnectionStatus('error', response.data);
                        showNotification('Error de conexión: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    showConnectionStatus('error', 'Error de conexión: ' + error);
                    showNotification('Error de red: ' + error, 'error');
                },
                complete: function() {
                    button.removeClass('testing').html('<span class="dashicons dashicons-admin-plugins"></span> Probar Conexión');
                }
            });
        });
    }

    function initManualTasks() {
        $('.rpcare-task-buttons .button').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const task = button.data('task');
            
            if (!task) return;
            
            button.addClass('loading').prop('disabled', true);
            
            const originalHtml = button.html();
            const taskName = getTaskName(task);
            
            button.html('<span class="dashicons dashicons-update spin"></span> Ejecutando ' + taskName + '...');
            
            $.ajax({
                url: rpcare_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpcare_run_task',
                    nonce: rpcare_ajax.nonce,
                    task: task
                },
                success: function(response) {
                    if (response.success) {
                        showTaskResult(task, 'success', response.data);
                        showNotification(taskName + ' completada exitosamente', 'success');
                        updateTaskCounter(task, true);
                    } else {
                        showTaskResult(task, 'error', response.data);
                        showNotification('Error en ' + taskName + ': ' + response.data.message, 'error');
                        updateTaskCounter(task, false);
                    }
                },
                error: function(xhr, status, error) {
                    showTaskResult(task, 'error', {
                        message: 'Error de conexión: ' + error
                    });
                    showNotification('Error de red en ' + taskName, 'error');
                },
                complete: function() {
                    button.removeClass('loading').prop('disabled', false).html(originalHtml);
                    loadDashboardData();
                }
            });
        });
    }

    function showTaskResult(task, status, data) {
        const resultsContainer = $('#rpcare-task-results');
        const taskName = getTaskName(task);
        const statusIcon = status === 'success' ? 'yes-alt' : 'dismiss';
        
        const resultHtml = '<div class="rpcare-task-result ' + status + '">' +
            '<div class="task-header">' +
                '<h4><span class="dashicons dashicons-' + statusIcon + '"></span> ' + taskName + '</h4>' +
                '<span class="task-timestamp">' + new Date().toLocaleString() + '</span>' +
            '</div>' +
            '<div class="task-message">' + (data.message || '') + '</div>' +
            (data.details ? '<div class="task-details">' + formatTaskDetails(data.details) + '</div>' : '') +
        '</div>';
        
        resultsContainer.prepend(resultHtml);
        
        resultsContainer.find('.rpcare-task-result:gt(4)').remove();
        
        resultsContainer.get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function getTaskName(task) {
        const names = {
            'updates': 'Actualizaciones',
            'backup': 'Copia de Seguridad',
            'cache': 'Limpieza de Caché',
            'security': 'Escaneo de Seguridad',
            'health': 'Chequeo de Salud',
            'report': 'Generar Reporte',
            'wpo': 'Optimización',
            'seo': 'Análisis SEO',
            '404': 'Enlaces Rotos'
        };
        
        return names[task] || task;
    }

    function formatTaskDetails(details) {
        if (typeof details === 'string') {
            return details;
        }
        
        if (typeof details === 'object') {
            return '<pre>' + JSON.stringify(details, null, 2) + '</pre>';
        }
        
        return details;
    }

    function initSettingsForm() {
        let saveTimeout;
        
        $('.form-table input, .form-table select').on('change', function() {
            clearTimeout(saveTimeout);
            saveTimeout = setTimeout(function() {
                saveDraft();
            }, 2000);
        });
        
        $('select[name="rpcare_options[plan]"]').on('change', function() {
            const plan = $(this).val();
            updatePlanFeatures(plan);
        });
        
        $('input[name="rpcare_options[notification_types][]"]').on('change', function() {
            updateNotificationPreview();
        });
    }

    function saveDraft() {
        const formData = $('form').serialize();
        localStorage.setItem('rpcare_draft', formData);
        showSaveIndicator();
    }

    function showSaveIndicator() {
        let indicator = $('.rpcare-save-indicator');
        
        if (indicator.length === 0) {
            indicator = $('<div class="rpcare-save-indicator"><span class="dashicons dashicons-saved"></span> Borrador guardado</div>')
                .appendTo('.wrap');
        }
        
        indicator.fadeIn().delay(2000).fadeOut();
    }

    function updatePlanFeatures(plan) {
        const features = {
            'semilla': [
                'auto_updates',
                'backup',
                'security_monitoring'
            ],
            'raiz': [
                'auto_updates',
                'backup',
                'security_monitoring',
                'performance_optimization',
                'seo_monitoring'
            ],
            'ecosistema': [
                'auto_updates',
                'backup',
                'security_monitoring',
                'performance_optimization',
                'seo_monitoring',
                'uptime_monitoring',
                'malware_scanning',
                'staging_environment',
                'priority_support',
                'white_label_reports'
            ]
        };
        
        const planFeatures = features[plan] || [];
        
        $('.rpcare-plan-info li').each(function() {
            const feature = $(this).data('feature');
            
            if (planFeatures.includes(feature)) {
                $(this).removeClass('feature-disabled').addClass('feature-enabled');
            } else {
                $(this).removeClass('feature-enabled').addClass('feature-disabled');
            }
        });
    }

    function updateNotificationPreview() {
        const selectedTypes = [];
        
        $('input[name="rpcare_options[notification_types][]"]:checked').each(function() {
            selectedTypes.push($(this).val());
        });
        
        const preview = $('.notification-preview');
        
        if (selectedTypes.length === 0) {
            preview.text('No se enviarán notificaciones');
        } else {
            preview.text('Recibirás notificaciones sobre: ' + selectedTypes.join(', '));
        }
    }

    function initDashboard() {
        initTooltips();
        initProgressBars();
        initMetricsCards();
        initTaskHistory();
    }

    function initRealTimeUpdates() {
        refreshInterval = setInterval(function() {
            loadDashboardData();
        }, 30000);
    }

    function loadDashboardData() {
        $.ajax({
            url: rpcare_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rpcare_get_status',
                nonce: rpcare_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    dashboardData = response.data;
                    updateDashboardDisplay();
                }
            }
        });
    }

    function updateDashboardDisplay() {
        if (dashboardData.last_backup) {
            $('.status-last-backup').text(dashboardData.last_backup);
        }
        
        if (dashboardData.next_task) {
            $('.status-next-task').text(dashboardData.next_task);
        }
        
        if (dashboardData.health_score) {
            updateHealthScore(dashboardData.health_score);
        }
        
        if (dashboardData.metrics) {
            updateMetricsCards(dashboardData.metrics);
        }
    }

    function updateHealthScore(score) {
        const scoreElement = $('.health-score');
        const progressBar = $('.health-progress');
        
        scoreElement.text(score + '%');
        progressBar.css('width', score + '%');
        
        progressBar.removeClass('health-good health-warning health-critical');
        
        if (score >= 80) {
            progressBar.addClass('health-good');
        } else if (score >= 60) {
            progressBar.addClass('health-warning');
        } else {
            progressBar.addClass('health-critical');
        }
    }

    function updateMetricsCards(metrics) {
        $('.metric-card').each(function() {
            const card = $(this);
            const metricType = card.data('metric');
            
            if (metrics[metricType]) {
                card.find('.metric-value').text(metrics[metricType].value);
                card.find('.metric-change').text(metrics[metricType].change);
            }
        });
    }

    function updateTaskCounter(task, success) {
        const counter = $('.task-counter[data-task="' + task + '"]');
        
        if (counter.length) {
            const current = parseInt(counter.text()) || 0;
            counter.text(current + 1);
            
            if (success) {
                counter.addClass('success-increment');
                setTimeout(function() {
                    counter.removeClass('success-increment');
                }, 1000);
            }
        }
    }

    function initTooltips() {
        $('[data-tooltip]').each(function() {
            const element = $(this);
            const tooltip = element.data('tooltip');
            
            element.on('mouseenter', function() {
                showTooltip(element, tooltip);
            }).on('mouseleave', function() {
                hideTooltip();
            });
        });
    }

    function showTooltip(element, text) {
        const tooltip = $('<div class="rpcare-tooltip">' + text + '</div>');
        $('body').append(tooltip);
        
        const elementOffset = element.offset();
        const elementHeight = element.outerHeight();
        
        tooltip.css({
            top: elementOffset.top + elementHeight + 10,
            left: elementOffset.left,
            display: 'block'
        });
    }

    function hideTooltip() {
        $('.rpcare-tooltip').remove();
    }

    function initProgressBars() {
        $('.rpcare-progress').each(function() {
            const progressBar = $(this);
            const percentage = progressBar.data('percentage') || 0;
            
            progressBar.find('.progress-fill').animate({
                width: percentage + '%'
            }, 1000);
        });
    }

    function initMetricsCards() {
        $('.metric-card').on('click', function() {
            const card = $(this);
            const metricType = card.data('metric');
            
            if (metricType) {
                showMetricDetails(metricType);
            }
        });
    }

    function showMetricDetails(metricType) {
        const modal = $('<div class="rpcare-modal-overlay"><div class="rpcare-modal"><div class="modal-header"><h3>Detalles de ' + metricType + '</h3><button class="modal-close">&times;</button></div><div class="modal-content">Cargando...</div></div></div>');
        
        $('body').append(modal);
        
        modal.find('.modal-close').on('click', function() {
            modal.remove();
        });
        
        modal.on('click', function(e) {
            if (e.target === modal[0]) {
                modal.remove();
            }
        });
        
        $.ajax({
            url: rpcare_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rpcare_get_metric_details',
                nonce: rpcare_ajax.nonce,
                metric: metricType
            },
            success: function(response) {
                if (response.success) {
                    modal.find('.modal-content').html(response.data);
                } else {
                    modal.find('.modal-content').html('Error al cargar detalles');
                }
            },
            error: function() {
                modal.find('.modal-content').html('Error de conexión');
            }
        });
    }

    function initTaskHistory() {
        $('.task-history-toggle').on('click', function() {
            const historyContainer = $('.task-history-container');
            
            if (historyContainer.is(':visible')) {
                historyContainer.slideUp();
                $(this).text('Mostrar Historial');
            } else {
                loadTaskHistory();
                historyContainer.slideDown();
                $(this).text('Ocultar Historial');
            }
        });
    }

    function loadTaskHistory() {
        $.ajax({
            url: rpcare_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'rpcare_get_task_history',
                nonce: rpcare_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.task-history-content').html(response.data);
                }
            }
        });
    }

    function showConnectionStatus(type, message) {
        let statusDiv = $('#connection-status');
        
        if (statusDiv.length === 0) {
            statusDiv = $('<div id="connection-status" class="connection-status"></div>');
            $('#test-connection').after(statusDiv);
        }
        
        const icon = type === 'success' ? 'yes-alt' : 'dismiss';
        
        statusDiv
            .removeClass('success error')
            .addClass(type)
            .html('<span class="dashicons dashicons-' + icon + '"></span> ' + message)
            .fadeIn();
            
        setTimeout(function() {
            statusDiv.fadeOut();
        }, 5000);
    }

    function showNotification(message, type = 'info', duration = 5000) {
        const icons = {
            'success': 'yes-alt',
            'error': 'dismiss',
            'warning': 'warning',
            'info': 'info'
        };
        
        const notification = $('<div class="rpcare-notification ' + type + '">' +
            '<span class="dashicons dashicons-' + icons[type] + '"></span>' +
            '<span class="notification-message">' + message + '</span>' +
            '<button class="notification-close" type="button">&times;</button>' +
        '</div>');
        
        $('body').append(notification);
        
        notification.css({
            position: 'fixed',
            top: '20px',
            right: '20px',
            zIndex: 99999
        });
        
        notification.slideDown();
        
        setTimeout(function() {
            notification.slideUp(function() {
                notification.remove();
            });
        }, duration);
        
        notification.find('.notification-close').on('click', function() {
            notification.slideUp(function() {
                notification.remove();
            });
        });
    }

    window.ReplantaCare = {
        showNotification: showNotification,
        loadDashboardData: loadDashboardData,
        updateHealthScore: updateHealthScore
    };

})(jQuery);
