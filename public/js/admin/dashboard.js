jQuery(document).ready(function($) {
	// Activity Chart - data will be provided via wp_localize_script
	if (typeof voxforDashboard !== 'undefined' && voxforDashboard.activityData) {
		const activityCtx = document.getElementById('voxfor-ml-activity-chart');
		if (activityCtx) {
			const activityData = voxforDashboard.activityData;
			const activityChart = new Chart(activityCtx.getContext('2d'), {
				type: 'line',
				data: {
					labels: activityData.map(item => {
						const date = new Date(item.date);
						return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
					}),
					datasets: [{
						label: 'Translations',
						data: activityData.map(item => item.translations),
						borderColor: '#0073aa',
						backgroundColor: 'rgba(0, 115, 170, 0.1)',
						yAxisID: 'y',
					}, {
						label: 'Words',
						data: activityData.map(item => item.words),
						borderColor: '#46b450',
						backgroundColor: 'rgba(70, 180, 80, 0.1)',
						yAxisID: 'y-words',
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: {
						mode: 'index',
						intersect: false,
					},
					plugins: {
						legend: {
							position: 'top',
						}
					},
					scales: {
						y: {
							type: 'linear',
							display: true,
							position: 'left',
						},
						'y-words': {
							type: 'linear',
							display: true,
							position: 'right',
							grid: {
								drawOnChartArea: false,
							},
						},
					},
				}
			});
		}
	}
}); 