<?php
/**
 * Cost Analytics Dashboard Template - DeepL API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$stats_manager      = new \VoxforML\Analytics\StatisticsManager();
$cost_data          = $stats_manager->getEstimatedCosts();
$cost_breakdown     = $stats_manager->getCostBreakdown( 30 );
$monthly_projection = $stats_manager->getMonthlyProjection();

// Get API info from real-time data
$is_free_api = $cost_data['is_free_api'] ?? true;
$api_type    = $cost_data['provider'] ?? 'DeepL Free API';
$api_limit   = $cost_data['character_limit'] ?? 500000;

// Calculate usage percentage for API limits
$usage_percentage = 0;
if ( $api_limit > 0 && $cost_data['monthly_characters'] > 0 ) {
	$usage_percentage = ( $cost_data['monthly_characters'] / $api_limit ) * 100;
}

// Display actual API response data if available
$api_status   = 'Connected';
$last_updated = current_time( 'mysql' );
if ( isset( $cost_data['api_usage_raw'] ) && $cost_data['api_usage_raw'] ) {
	$api_status = 'Live Data from DeepL API';
} else {
	$api_status = 'Database Estimate (API Unavailable)';
}

// Usage alerts based on API type
$cost_alerts = array();
if ( $is_free_api && $api_limit > 0 ) {
	if ( $usage_percentage > 90 ) {
		$cost_alerts[] = array(
			'type'    => 'error',
			// translators: %1$.1f is usage percentage, %2$.0f is characters used, %3$.0f is character limit
			'message' => sprintf( __( '⚠️ Critical: API limit usage at %1$.1f%% (%2$.0f/%3$.0f characters) - Consider upgrading!', 'voxfor-multilanguage' ), $usage_percentage, $cost_data['monthly_characters'], $api_limit ),
		);
	} elseif ( $usage_percentage > 75 ) {
		$cost_alerts[] = array(
			'type'    => 'warning',
			// translators: %1$.1f is usage percentage, %2$.0f is characters used, %3$.0f is character limit
			'message' => sprintf( __( '⚡ High usage: %1$.1f%% of API limit used (%2$.0f/%3$.0f characters)', 'voxfor-multilanguage' ), $usage_percentage, $cost_data['monthly_characters'], $api_limit ),
		);
	} elseif ( $usage_percentage > 50 ) {
		$cost_alerts[] = array(
			'type'    => 'info',
			// translators: %1$.1f is usage percentage, %2$.0f is characters used, %3$.0f is character limit
			'message' => sprintf( __( '📊 Moderate usage: %1$.1f%% of API limit used (%2$.0f/%3$.0f characters)', 'voxfor-multilanguage' ), $usage_percentage, $cost_data['monthly_characters'], $api_limit ),
		);
	}
} elseif ( $monthly_projection['projected_cost'] > 100 ) {
		$cost_alerts[] = array(
			'type'    => 'error',
			// translators: %.2f is the projected cost amount in dollars
			'message' => sprintf( __( '💰 High cost projection: $%.2f this month', 'voxfor-multilanguage' ), $monthly_projection['projected_cost'] ),
		);
} elseif ( $monthly_projection['projected_cost'] > 50 ) {
	$cost_alerts[] = array(
		'type'    => 'warning',
		// translators: %.2f is the projected cost amount in dollars
		'message' => sprintf( __( '💡 Monthly projection: $%.2f', 'voxfor-multilanguage' ), $monthly_projection['projected_cost'] ),
	);
}
?>

<div class="wrap voxfor-ml-cost-analytics">
	<h1><?php esc_html_e( 'Translation Usage Analytics', 'voxfor-multilanguage' ); ?></h1>
	

	
	<!-- Usage Overview Cards -->
	<div class="voxfor-ml-usage-overview">
		<div class="voxfor-ml-usage-cards">
			<div class="voxfor-ml-usage-card">
				<div class="card-icon">📊</div>
				<div class="card-content">
					<h3><?php esc_html_e( 'Total Characters', 'voxfor-multilanguage' ); ?></h3>
					<div class="usage-value"><?php echo number_format( $cost_data['total_characters'] ); ?></div>
					<div class="usage-detail"><?php esc_html_e( 'All time usage', 'voxfor-multilanguage' ); ?></div>
				</div>
			</div>
			
			<div class="voxfor-ml-usage-card">
				<div class="card-icon">📅</div>
				<div class="card-content">
					<h3><?php esc_html_e( 'Current Usage', 'voxfor-multilanguage' ); ?></h3>
					<div class="usage-value"><?php echo number_format( $cost_data['monthly_characters'] ); ?></div>
					<div class="usage-detail">
						<?php if ( isset( $cost_data['api_usage_raw'] ) && $cost_data['api_usage_raw'] ) : ?>
							<?php if ( $api_limit > 0 ) : ?>
								<?php echo number_format( $api_limit - $cost_data['monthly_characters'] ); ?> remaining
							<?php else : ?>
								$<?php echo number_format( $cost_data['monthly_cost'], 2 ); ?> estimated cost
							<?php endif; ?>
						<?php else : ?>
							<small style="color: #dc3232;">Database estimate only</small>
						<?php endif; ?>
					</div>
				</div>
			</div>
			
			<div class="voxfor-ml-usage-card">
				<div class="card-icon">📈</div>
				<div class="card-content">
					<h3><?php esc_html_e( 'Daily Average', 'voxfor-multilanguage' ); ?></h3>
					<div class="usage-value"><?php echo number_format( $monthly_projection['daily_average_characters'] ); ?></div>
					<div class="usage-detail"><?php esc_html_e( 'Characters per day', 'voxfor-multilanguage' ); ?></div>
				</div>
			</div>
			
			<div class="voxfor-ml-usage-card">
				<div class="card-icon">🔧</div>
				<div class="card-content">
					<h3><?php esc_html_e( 'API Status', 'voxfor-multilanguage' ); ?></h3>
					<div class="usage-value api-type"><?php echo esc_html( $api_type ); ?></div>
					<div class="usage-detail">
						<?php if ( $api_limit > 0 ) : ?>
							<?php echo number_format( $api_limit ); ?> chars limit
						<?php else : ?>
							Unlimited usage
						<?php endif; ?>
						<br><small style="color: <?php echo ( isset( $cost_data['api_usage_raw'] ) && $cost_data['api_usage_raw'] ) ? '#46b450' : '#dc3232'; ?>;">
							<?php echo esc_html( $api_status ); ?>
						</small>
					</div>
				</div>
			</div>
		</div>
	</div>
	
	<!-- Usage Data Table -->
	<?php if ( ! empty( $cost_breakdown ) ) : ?>
	<div class="voxfor-ml-section">
		<h2><?php esc_html_e( 'Usage History', 'voxfor-multilanguage' ); ?></h2>
		
		<div class="usage-table-container">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Characters', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Translations', 'voxfor-multilanguage' ); ?></th>
						<th><?php esc_html_e( 'Languages', 'voxfor-multilanguage' ); ?></th>
						<?php if ( ! $is_free_api ) : ?>
						<th><?php esc_html_e( 'Cost', 'voxfor-multilanguage' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $cost_breakdown, 0, 10 ) as $breakdown ) : ?>
						<tr>
							<td><?php echo esc_html( gmdate( 'M j, Y', strtotime( $breakdown['date'] ) ) ); ?></td>
							<td><?php echo number_format( $breakdown['total_characters'] ); ?></td>
							<td><?php echo number_format( $breakdown['translations'] ); ?></td>
							<td><?php echo count( $breakdown['languages'] ); ?></td>
							<?php if ( ! $is_free_api ) : ?>
								<td>$<?php echo number_format( $breakdown['total_cost'], 4 ); ?></td>
							<?php endif; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>

	<!-- Usage Tips -->
	<div class="voxfor-ml-section">
		<h2><?php esc_html_e( 'Usage Optimization Tips', 'voxfor-multilanguage' ); ?></h2>
		<div class="usage-tips">
			<div class="tip-card">
				<h4>💡 <?php esc_html_e( 'Efficient Translation', 'voxfor-multilanguage' ); ?></h4>
				<p><?php esc_html_e( 'Only translate content when clicking the "Translate" button on edit pages. Avoid automatic frontend translation to save API usage.', 'voxfor-multilanguage' ); ?></p>
	</div>
			
			<div class="tip-card">
				<h4>🔄 <?php esc_html_e( 'Translation Memory', 'voxfor-multilanguage' ); ?></h4>
				<p><?php esc_html_e( 'Already translated content is served from the database without using API credits, improving performance and saving costs.', 'voxfor-multilanguage' ); ?></p>
			</div>
			
			<?php if ( $is_free_api ) : ?>
			<div class="tip-card">
				<h4>⚡ <?php esc_html_e( 'Free API Limits', 'voxfor-multilanguage' ); ?></h4>
				<p><?php esc_html_e( 'Free API provides 500,000 characters per month. Monitor your usage and consider upgrading to Pro API for higher limits.', 'voxfor-multilanguage' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php
// Cost Analytics styles are now in public/css/admin/cost-analytics.css
// Styles are properly enqueued via wp_enqueue_style
?>

<?php
// Cost Analytics JavaScript is now in public/js/admin/cost-analytics.js
// Script is properly enqueued via wp_enqueue_script with localization
?>
