// Admin Reports — Chart.js renderers (not executed when exporting/printing only).
(function () {
    'use strict';

    var payload = window.REPORT_PAYLOAD || { volume: { labels: [], data: [] }, utilization: { labels: [], data: [] }, hasData: false };
    var missing = typeof window.Chart === 'undefined';

    // ---------------- Patient Volume Trend (line/area) ----------------
    var volumeCanvas = document.getElementById('volumeChart');
    if (volumeCanvas && !missing) {
        var vol = payload.volume || { labels: [], data: [] };
        var fillCol = 'rgba(20, 147, 133, 0.16)';
        var strokeCol = 'rgba(20, 147, 133, 1)';

        new Chart(volumeCanvas, {
            type: 'line',
            data: {
                labels: vol.labels,
                datasets: [{
                    label: 'Appointments',
                    data: vol.data,
                    borderColor: strokeCol,
                    backgroundColor: fillCol,
                    fill: true,
                    tension: 0.35,
                    pointRadius: vol.labels.length > 28 ? 2 : 4,
                    pointBackgroundColor: strokeCol,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 10,
                        cornerRadius: 8
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: { precision: 0, color: '#6b7280' }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6b7280', maxRotation: 40, minRotation: 0, autoSkip: true, maxTicksLimit: 14 }
                    }
                }
            }
        });
    }

    // ---------------- Department Workload Utilization (doughnut) ----------------
    var utilCanvas = document.getElementById('utilChart');
    if (utilCanvas && !missing) {
        var util = payload.utilization || { labels: [], data: [] };
        var palette = ['#149385', '#2563eb', '#d97706', '#dc2626', '#7c3aed', '#059669', '#f59e0b', '#ef4444'];
        var colors = util.labels.map(function (_, i) { return palette[i % palette.length]; });

        new Chart(utilCanvas, {
            type: 'doughnut',
            data: {
                labels: util.labels,
                datasets: [{
                    data: util.data,
                    backgroundColor: colors,
                    borderColor: '#ffffff',
                    borderWidth: 3,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            padding: 14,
                            color: '#1f2937',
                            font: { size: 11 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#1f2937',
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.label + ': ' + ctx.parsed + '% utilized';
                            }
                        }
                    }
                }
            }
        });
    }
})();