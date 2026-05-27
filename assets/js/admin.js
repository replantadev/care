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
        console.log('🔧 Inicializando test de conexión...');
        console.log('Botón #test-connection existe:', $('#test-connection').length > 0);
        console.log('Variables rpcare_ajax:', typeof rpcare_ajax !== 'undefined' ? rpcare_ajax : 'NO DEFINIDO');
        
        $('#test-connection').on('click', function(e) {
            e.preventDefault();
            console.log('🔵 Click en botón test-connection');
            
            const button = $(this);
            const hubUrl = $('input[name="rpcare_options[hub_url]"]').val();
            const siteToken = $('input[name="rpcare_options[site_token]"]').val();
            
            console.log('Hub URL:', hubUrl);
            console.log('Site Token:', siteToken ? 'PRESENTE' : 'VACÍO');
            
            if (!hubUrl || !siteToken) {
                const message = 'Por favor, introduce URL del Hub y Token del Sitio';
                console.log('❌ Error:', message);
                showNotification(message, 'error');
                return;
            }
            
            // Check if rpcare_ajax is defined
            if (typeof rpcare_ajax === 'undefined') {
                console.error('❌ rpcare_ajax no está definido');
                showNotification('Error: Variables AJAX no cargadas', 'error');
                return;
            }
            
            button.addClass('testing').html('Probando...');
            console.log('🔄 Iniciando petición AJAX...');
            
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
                    console.log('✅ Respuesta AJAX exitosa:', response);
                    if (response.success) {
                        showConnectionStatus('success', response.data);
                        showNotification('Conexión exitosa con el Hub', 'success');
                    } else {
                        showConnectionStatus('error', response.data);
                        showNotification('Error de conexión: ' + response.data, 'error');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Error AJAX:', {xhr, status, error});
                    const errorMsg = 'Error de red: ' + error;
                    showConnectionStatus('error', errorMsg);
                    showNotification(errorMsg, 'error');
                },
                complete: function() {
                    console.log('🏁 Petición AJAX completada');
                    button.removeClass('testing').html('Probar conexión');
                }
            });
        });
    }

    function initManualTasks() {
        $(document).on('click', '.rpc-action-card', function(e) {
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
        const iconClass = status === 'success' ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-dismiss';

        const resultHtml =
            '<div class="rpc-result-card ' + status + '">' +
                '<div class="rpc-result-header">' +
                    '<span class="rpc-result-title"><span class="' + iconClass + '"></span> ' + taskName + '</span>' +
                    '<time class="rpc-result-time">' + new Date().toLocaleTimeString() + '</time>' +
                '</div>' +
                '<div class="rpc-result-message">' + (data.message || '') + '</div>' +
                (data.details ? '<details style="margin-top:8px;font-size:12px;"><summary style="cursor:pointer;color:var(--rp-muted)">Detalles</summary>' + formatTaskDetails(data.details) + '</details>' : '') +
            '</div>';

        resultsContainer.prepend(resultHtml);
        resultsContainer.find('.rpc-result-card:gt(4)').remove();
        if (resultsContainer.get(0)) {
            resultsContainer.get(0).scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
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
        // Update the header pill
        const pill = $('#rpc-hub-pill');
        if (pill.length) {
            pill.removeClass('connected disconnected').addClass(type === 'success' ? 'connected' : 'disconnected');
            pill.find('.rpc-pill-text').text(type === 'success' ? 'Conectado' : 'Sin conexión');
        }

        // Update inline result div below the test button
        const resultDiv = $('#rpc-connection-result');
        if (resultDiv.length) {
            resultDiv
                .removeClass('success error')
                .addClass(type)
                .html(message)
                .show();
            setTimeout(function() { resultDiv.fadeOut(); }, 5000);
        }
    }

    function showNotification(message, type, duration) {
        type = type || 'info';
        duration = duration || 5000;

        const icons = {
            success: 'yes-alt',
            error: 'dismiss',
            warning: 'warning',
            info: 'info-outline'
        };
        const iconClass = 'dashicons dashicons-' + (icons[type] || icons.info);
        const toastId = 'rpc-toast-' + Date.now();

        const toast = $(
            '<div class="rpc-toast ' + type + '" id="' + toastId + '">' +
                '<span class="rpc-toast-ico ' + iconClass + '"></span>' +
                '<div class="rpc-toast-body"><div class="rpc-toast-msg">' + message + '</div></div>' +
                '<button class="rpc-toast-x" type="button" aria-label="Cerrar">&times;</button>' +
            '</div>'
        );

        let container = $('#rpc-toasts');
        if (container.length === 0) {
            container = $('<div id="rpc-toasts"></div>').appendTo('body');
        }

        container.append(toast);
        requestAnimationFrame(function() { toast.addClass('show'); });

        const dismiss = function() {
            toast.removeClass('show');
            setTimeout(function() { toast.remove(); }, 350);
        };

        toast.find('.rpc-toast-x').on('click', dismiss);
        setTimeout(dismiss, duration);
    }

    window.ReplantaCare = {
        showNotification: showNotification,
        loadDashboardData: loadDashboardData,
        updateHealthScore: updateHealthScore
    };

})(jQuery);
