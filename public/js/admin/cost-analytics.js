document.addEventListener('DOMContentLoaded', function() {
	// Check if we're on the cost analytics page
	if (typeof voxforCostAnalytics === 'undefined') {
		return; // Exit if localization data is not available
	}

	const breakdown = voxforCostAnalytics.breakdown;
	const strings = voxforCostAnalytics.strings;

	// Initialize custom SVG charts
	initSVGCharts();
	
	// Make charts responsive
	window.addEventListener('resize', function() {
		setTimeout(initSVGCharts, 100);
	});
	
	function initSVGCharts() {
		// Check if we have data
		if (!breakdown || breakdown.length === 0) {
			showNoDataMessage();
			return;
		}
		
		// Usage Chart
		const usageChartContainer = document.getElementById('usageChart');
		if (usageChartContainer) {
			createSVGChart(usageChartContainer, {
				data: breakdown,
				datasets: [
					{
						label: strings.charactersTranslated,
						key: 'characters',
						color: '#0073aa',
						fillColor: 'rgba(0, 115, 170, 0.1)',
						yAxis: 'left'
					},
					{
						label: strings.estimatedCost,
						key: 'cost',
						color: '#d63638',
						fillColor: 'rgba(214, 54, 56, 0.1)',
						yAxis: 'right'
					}
				],
				width: Math.min(900, window.innerWidth - 80),
				height: 300
			});
		}

		// Cost Chart
		const costChartContainer = document.getElementById('costChart');
		if (costChartContainer) {
			createSVGChart(costChartContainer, {
				data: breakdown,
				datasets: [
					{
						label: strings.estimatedCost,
						key: 'cost',
						color: '#d63638',
						fillColor: 'rgba(214, 54, 56, 0.1)',
						yAxis: 'left'
					}
				],
				width: Math.min(900, window.innerWidth - 80),
				height: 250
			});
		}
	}

	function createSVGChart(container, config) {
		const { data, datasets, width, height } = config;
		const margin = { top: 30, right: 40, bottom: 40, left: 50 };
		const chartWidth = width - margin.left - margin.right;
		const chartHeight = height - margin.top - margin.bottom;

		// Clear container and add loading state
		container.innerHTML = '<div style="text-align: center; padding: 20px; color: #666;">Loading chart...</div>';
		
		// Small delay to show loading state
		setTimeout(() => {
			container.innerHTML = '';
			renderChart();
		}, 100);
		
		function renderChart() {
			// Create SVG
			const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			svg.setAttribute('width', width);
			svg.setAttribute('height', height);
			svg.setAttribute('class', 'voxfor-ml-chart');

			// Create chart group
			const chartGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
			chartGroup.setAttribute('transform', `translate(${margin.left}, ${margin.top})`);

			// Get data ranges
			const xValues = data.map((_, i) => i);
			const leftYValues = datasets.filter(d => d.yAxis === 'left').flatMap(d => data.map(item => parseFloat(item[d.key]) || 0));
			const rightYValues = datasets.filter(d => d.yAxis === 'right').flatMap(d => data.map(item => parseFloat(item[d.key]) || 0));

			const leftYMax = Math.max(...leftYValues, 0);
			const rightYMax = Math.max(...rightYValues, 0);

			// Create scales
			const xScale = (i) => (i / Math.max(data.length - 1, 1)) * chartWidth;
			const leftYScale = (value) => chartHeight - (value / Math.max(leftYMax, 1)) * chartHeight;
			const rightYScale = (value) => chartHeight - (value / Math.max(rightYMax, 1)) * chartHeight;

			// Draw grid lines
			drawGrid(chartGroup, chartWidth, chartHeight, data.length, leftYMax);

			// Draw datasets
			datasets.forEach((dataset, datasetIndex) => {
				const yScale = dataset.yAxis === 'right' ? rightYScale : leftYScale;
				drawDataset(chartGroup, data, dataset, xScale, yScale, datasetIndex);
			});

			// Draw axes
			drawAxes(chartGroup, chartWidth, chartHeight, data, leftYMax, rightYMax);

			// Add legend
			drawLegend(svg, datasets, width, margin);

			svg.appendChild(chartGroup);
			container.appendChild(svg);
		}
	}

	function drawGrid(group, width, height, dataLength, yMax) {
		const gridGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
		gridGroup.setAttribute('class', 'grid');

		// Horizontal grid lines (fewer lines)
		for (let i = 0; i <= 4; i++) {
			const y = (i / 4) * height;
			const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('x1', 0);
			line.setAttribute('y1', height - y);
			line.setAttribute('x2', width);
			line.setAttribute('y2', height - y);
			line.setAttribute('stroke', '#f0f0f0');
			line.setAttribute('stroke-width', '1');
			line.setAttribute('opacity', '0.7');
			gridGroup.appendChild(line);
		}

		// Vertical grid lines (fewer lines)
		for (let i = 0; i < dataLength; i += Math.max(1, Math.floor(dataLength / 4))) {
			const x = (i / Math.max(dataLength - 1, 1)) * width;
			const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('x1', x);
			line.setAttribute('y1', 0);
			line.setAttribute('x2', x);
			line.setAttribute('y2', height);
			line.setAttribute('stroke', '#f5f5f5');
			line.setAttribute('stroke-width', '1');
			line.setAttribute('opacity', '0.5');
			gridGroup.appendChild(line);
		}

		group.appendChild(gridGroup);
	}

	function drawDataset(group, data, dataset, xScale, yScale, index) {
		const { key, color, fillColor } = dataset;
		
		// Create path for line
		let pathData = '';
		let fillPathData = '';
		
		data.forEach((item, i) => {
			const x = xScale(i);
			const y = yScale(parseFloat(item[key]) || 0);
			
			if (i === 0) {
				pathData += `M ${x} ${y}`;
				fillPathData += `M ${x} ${yScale(0)} L ${x} ${y}`;
			} else {
				pathData += ` L ${x} ${y}`;
				fillPathData += ` L ${x} ${y}`;
			}
		});
		
		// Close fill path
		if (data.length > 0) {
			const lastX = xScale(data.length - 1);
			fillPathData += ` L ${lastX} ${yScale(0)} Z`;
		}

		// Draw fill area
		if (fillColor) {
			const fillPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			fillPath.setAttribute('d', fillPathData);
			fillPath.setAttribute('fill', fillColor);
			fillPath.setAttribute('stroke', 'none');
			group.appendChild(fillPath);
		}

		// Draw line
		const linePath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		linePath.setAttribute('d', pathData);
		linePath.setAttribute('fill', 'none');
		linePath.setAttribute('stroke', color);
		linePath.setAttribute('stroke-width', '2');
		linePath.setAttribute('stroke-linejoin', 'round');
		linePath.setAttribute('stroke-linecap', 'round');
		group.appendChild(linePath);

		// Draw data points
		data.forEach((item, i) => {
			const x = xScale(i);
			const y = yScale(parseFloat(item[key]) || 0);
			
			const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
			circle.setAttribute('cx', x);
			circle.setAttribute('cy', y);
			circle.setAttribute('r', '3');
			circle.setAttribute('fill', color);
			circle.setAttribute('stroke', '#fff');
			circle.setAttribute('stroke-width', '1.5');
			circle.setAttribute('class', 'data-point');
			
			// Add tooltip
			const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
			const date = new Date(item.date).toLocaleDateString();
			title.textContent = `${date}: ${item[key]}`;
			circle.appendChild(title);
			
			group.appendChild(circle);
		});
	}

	function drawAxes(group, width, height, data, leftYMax, rightYMax) {
		// X-axis
		const xAxis = document.createElementNS('http://www.w3.org/2000/svg', 'line');
		xAxis.setAttribute('x1', 0);
		xAxis.setAttribute('y1', height);
		xAxis.setAttribute('x2', width);
		xAxis.setAttribute('y2', height);
		xAxis.setAttribute('stroke', '#333');
		xAxis.setAttribute('stroke-width', '2');
		group.appendChild(xAxis);

		// Y-axis (left)
		const leftYAxis = document.createElementNS('http://www.w3.org/2000/svg', 'line');
		leftYAxis.setAttribute('x1', 0);
		leftYAxis.setAttribute('y1', 0);
		leftYAxis.setAttribute('x2', 0);
		leftYAxis.setAttribute('y2', height);
		leftYAxis.setAttribute('stroke', '#333');
		leftYAxis.setAttribute('stroke-width', '2');
		group.appendChild(leftYAxis);

		// X-axis labels
		data.forEach((item, i) => {
			if (i % Math.max(1, Math.floor(data.length / 4)) === 0) {
				const x = (i / Math.max(data.length - 1, 1)) * width;
				const date = new Date(item.date);
				const label = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
				
				const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
				text.setAttribute('x', x);
				text.setAttribute('y', height + 20);
				text.setAttribute('text-anchor', 'middle');
				text.setAttribute('font-size', '12');
				text.setAttribute('fill', '#666');
				text.textContent = label;
				group.appendChild(text);
			}
		});

		// Y-axis labels (left)
		for (let i = 0; i <= 4; i++) {
			const value = (leftYMax / 4) * i;
			const y = height - (i / 4) * height;
			
			const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
			text.setAttribute('x', -10);
			text.setAttribute('y', y + 4);
			text.setAttribute('text-anchor', 'end');
			text.setAttribute('font-size', '12');
			text.setAttribute('fill', '#666');
			text.textContent = Math.round(value).toLocaleString();
			group.appendChild(text);
		}
	}

	function drawLegend(svg, datasets, width, margin) {
		const legendGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
		legendGroup.setAttribute('class', 'legend');
		
		let legendX = margin.left;
		const legendY = 15;
		
		datasets.forEach((dataset, i) => {
			// Legend color box
			const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
			rect.setAttribute('x', legendX);
			rect.setAttribute('y', legendY - 8);
			rect.setAttribute('width', '12');
			rect.setAttribute('height', '12');
			rect.setAttribute('fill', dataset.color);
			legendGroup.appendChild(rect);
			
			// Legend text
			const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
			text.setAttribute('x', legendX + 18);
			text.setAttribute('y', legendY + 2);
			text.setAttribute('font-size', '12');
			text.setAttribute('fill', '#333');
			text.textContent = dataset.label;
			legendGroup.appendChild(text);
			
			legendX += text.getComputedTextLength() + 40;
		});
		
		svg.appendChild(legendGroup);
	}
	
	function showNoDataMessage() {
		const containers = ['usageChart', 'costChart'];
		containers.forEach(containerId => {
			const container = document.getElementById(containerId);
			if (container) {
				container.innerHTML = `
					<div style="text-align: center; padding: 40px; color: #666;">
						<div style="font-size: 48px; margin-bottom: 16px;">📊</div>
						<h3 style="margin: 0 0 8px 0; color: #333;">No Data Available</h3>
						<p style="margin: 0;">Start translating content to see usage analytics here.</p>
					</div>
				`;
			}
		});
	}
}); 