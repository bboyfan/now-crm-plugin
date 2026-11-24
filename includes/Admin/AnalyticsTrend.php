<?php
/**
 * Read-only trend charts for the NOW CRM admin.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

defined( 'ABSPATH' ) || exit;

final class AnalyticsTrend {

	private const PATTERNS = array( 'none', '8 4', '2 4', '12 3 2 3', '4 2', '10 3 4 3', '2 3 2 7', '14 5' );

	/**
	 * Shape names for one-day series, aligned with marker() cases.
	 */
	private const MARKERS = array( '圓點', '方形', '正三角', '菱形', '加號', '叉號', '倒三角', '空心圓' );

	/**
	 * Plot each series from the first day of its own period. The data table
	 * preserves the original dates, including when the periods differ in length.
	 *
	 * @param string                               $title  Trend name.
	 * @param array<string,array<string,int|float>> $series Label => ordered site date => value, including zero dates.
	 */
	public static function render( string $title, array $series ): void {
		$series = array_filter( $series );
		$low        = 0;
		$high       = 0;
		$days       = 1;
		$groups     = array();
		$has_single = false;

		foreach ( $series as $label => $values ) {
			$low        = min( $low, min( $values ) );
			$high       = max( $high, max( $values ) );
			$days       = max( $days, count( $values ) );
			$period     = array_key_first( $values ) . ' / ' . array_key_last( $values );
			$groups[ $period ][] = $label;
			$has_single = $has_single || 1 === count( $values );
		}
		?>
		<figure class="nowcrm-analytics__trend">
			<figcaption><strong><?php echo esc_html( $title ); ?></strong></figcaption>
			<?php if ( $high <= $low ) : ?>
				<p class="nowcrm-analytics__subtle"><?php esc_html_e( '這段期間沒有可繪製的活動。', 'now-crm' ); ?></p>
			<?php else : ?>
				<?php
				$span   = $high - $low;
				$step_x = 527 / max( 1, $days - 1 );
				$zero_y = 134 + 118 * $low / $span;
				?>
				<svg class="nowcrm-analytics__plot" viewBox="0 0 600 150" aria-hidden="true" focusable="false">
					<path d="M 68 16 H 595 M 68 75 H 595 M 68 134 H 595" fill="none" stroke="var(--nowcrm-line)" vector-effect="non-scaling-stroke" />
					<path d="M 68 <?php echo esc_attr( (string) round( $zero_y, 2 ) ); ?> H 595" fill="none" stroke="var(--nowcrm-muted)" vector-effect="non-scaling-stroke" />
					<g fill="var(--nowcrm-muted)" font-size="13" text-anchor="end" dominant-baseline="middle">
						<text x="62" y="16"><?php echo esc_html( self::value( $high ) ); ?></text>
						<text x="62" y="75"><?php echo esc_html( self::value( $low + $span / 2 ) ); ?></text>
						<text x="62" y="134"><?php echo esc_html( self::value( $low ) ); ?></text>
					</g>
					<?php
					$index = 0;

					foreach ( $series as $values ) {
						$points = array();
						$step   = 0;
						$last_y = 0.0;
						$shape  = $index;
						$stroke = self::stroke( $index );
						$dash   = self::PATTERNS[ $index++ % count( self::PATTERNS ) ];

						foreach ( $values as $value ) {
							$x        = 68 + $step_x * $step++;
							$y        = 134 - 118 * ( $value - $low ) / $span;
							$last_y   = (float) $y;
							$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
						}

						if ( 1 === count( $points ) ) {
							echo self::marker( $shape, 68.0, $last_y, $stroke ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own attributes.
						} else {
							printf( '<polyline points="%s" fill="none" stroke="%s" stroke-dasharray="%s" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />', esc_attr( implode( ' ', $points ) ), esc_attr( $stroke ), esc_attr( $dash ) );
						}
					}
					?>
				</svg>
				<p class="nowcrm-analytics__subtle">
					<?php
					printf(
						/* translators: 1: minimum chart scale, 2: maximum chart scale. */
						esc_html__( '刻度：%1$s 至 %2$s；時間由各期間第一天起算。', 'now-crm' ),
						esc_html( self::value( $low ) ),
						esc_html( self::value( $high ) )
					);
					?>
				</p>
				<ul class="nowcrm-analytics__trend-legend">
					<?php $index = 0; ?>
					<?php foreach ( $series as $label => $values ) : ?>
						<?php
						$single = 1 === count( $values );
						$first  = (string) array_key_first( $values );
						$last   = (string) array_key_last( $values );
						?>
						<li>
							<svg viewBox="0 0 28 8" width="28" height="8" aria-hidden="true" focusable="false">
								<?php if ( $single ) : ?>
									<?php echo self::marker( $index, 14.0, 4.0, self::stroke( $index ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes its own attributes. ?>
								<?php else : ?>
									<path d="M 0 4 H 28" stroke="<?php echo esc_attr( self::stroke( $index ) ); ?>" stroke-width="2" stroke-dasharray="<?php echo esc_attr( self::PATTERNS[ $index % count( self::PATTERNS ) ] ); ?>" />
								<?php endif; ?>
							</svg>
							<?php
							if ( $single ) {
								echo esc_html( $label . '（' . $first . '：' . self::value( $values[ $first ] ) . '，單日' . self::MARKERS[ $index % count( self::MARKERS ) ] . '）' );
							} else {
								echo esc_html( $label . '（' . $first . ' – ' . $last . '）' );
							}
							?>
						</li>
						<?php $index++; ?>
					<?php endforeach; ?>
				</ul>
				<?php if ( $has_single ) : ?>
					<p class="nowcrm-analytics__subtle">
						<?php esc_html_e( '單日系列以標記顯示（圓、方、正三角、菱形、加號、叉號、倒三角、空心圓），形狀對應圖例；確切數值見下方「查看每日數值」。', 'now-crm' ); ?>
					</p>
				<?php endif; ?>
				<details class="nowcrm-analytics__trend-data">
					<summary><?php esc_html_e( '查看每日數值', 'now-crm' ); ?></summary>
					<?php foreach ( $groups as $period => $labels ) : ?>
						<div class="nowcrm-contacts-surface" tabindex="0" role="region" aria-label="<?php echo esc_attr( $title . '：' . $period ); ?>">
							<table class="nowcrm-contacts-table">
								<caption class="screen-reader-text"><?php echo esc_html( $title . '：' . $period ); ?></caption>
								<thead><tr>
									<th scope="col"><?php esc_html_e( '日期', 'now-crm' ); ?></th>
									<?php foreach ( $labels as $label ) : ?>
										<th scope="col"><?php echo esc_html( $label ); ?></th>
									<?php endforeach; ?>
								</tr></thead>
								<tbody>
									<?php foreach ( $series[ $labels[0] ] as $date => $value ) : ?>
										<tr>
											<th scope="row"><?php echo esc_html( $date ); ?></th>
											<?php foreach ( $labels as $label ) : ?>
												<td><?php echo esc_html( isset( $series[ $label ][ $date ] ) ? self::value( $series[ $label ][ $date ] ) : '—' ); ?></td>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				</details>
			<?php endif; ?>
		</figure>
		<?php
	}

	/**
	 * One-day series marker. Each series index gets a different shape so the
	 * point stays identifiable without relying on color or a line.
	 *
	 * @param int    $shape Series index.
	 * @param float  $x     Center x.
	 * @param float  $y     Center y.
	 * @param string $color Stroke/fill color.
	 */
	private static function marker( int $shape, float $x, float $y, string $color ): string {
		$cx    = round( $x, 2 );
		$cy    = round( $y, 2 );
		$color = esc_attr( $color );

		switch ( $shape % 8 ) {
			case 1:
				return sprintf( '<rect x="%s" y="%s" width="6.6" height="6.6" fill="%s" />', round( $x - 3.3, 2 ), round( $y - 3.3, 2 ), $color );
			case 2:
				return sprintf( '<polygon points="%s,%s %s,%s %s,%s" fill="%s" />', $cx, round( $y - 3.4, 2 ), round( $x - 3.7, 2 ), round( $y + 3.1, 2 ), round( $x + 3.7, 2 ), round( $y + 3.1, 2 ), $color );
			case 3:
				return sprintf( '<polygon points="%s,%s %s,%s %s,%s %s,%s" fill="%s" />', $cx, round( $y - 3.5, 2 ), round( $x + 3.6, 2 ), $cy, $cx, round( $y + 3.5, 2 ), round( $x - 3.6, 2 ), $cy, $color );
			case 4:
				return sprintf( '<path d="M %s %s H %s M %s %s V %s" fill="none" stroke="%s" stroke-width="1.8" stroke-linecap="round" />', round( $x - 3, 2 ), $cy, round( $x + 3, 2 ), $cx, round( $y - 3, 2 ), round( $y + 3, 2 ), $color );
			case 5:
				return sprintf( '<path d="M %s %s L %s %s M %s %s L %s %s" fill="none" stroke="%s" stroke-width="1.8" stroke-linecap="round" />', round( $x - 2.8, 2 ), round( $y - 2.8, 2 ), round( $x + 2.8, 2 ), round( $y + 2.8, 2 ), round( $x + 2.8, 2 ), round( $y - 2.8, 2 ), round( $x - 2.8, 2 ), round( $y + 2.8, 2 ), $color );
			case 6:
				return sprintf( '<polygon points="%s,%s %s,%s %s,%s" fill="%s" />', $cx, round( $y + 3.4, 2 ), round( $x - 3.7, 2 ), round( $y - 3.1, 2 ), round( $x + 3.7, 2 ), round( $y - 3.1, 2 ), $color );
			case 7:
				return sprintf( '<circle cx="%s" cy="%s" r="3" fill="none" stroke="%s" stroke-width="1.8" />', $cx, $cy, $color );
			default:
				return sprintf( '<circle cx="%s" cy="%s" r="3.4" fill="%s" />', $cx, $cy, $color );
		}
	}

	private static function stroke( int $index ): string {
		return 0 === $index ? 'currentColor' : 'var(--nowcrm-muted)';
	}

	private static function value( int|float $value ): string {
		return number_format_i18n( $value, (float) (int) $value === (float) $value ? 0 : 4 );
	}
}
