<?php
/**
 * Advanced filter controls for the contact list.
 *
 * `parse_request()` turns the submitted controls into the typed plan from
 * AdvancedFilters. `render()` draws those groups back into native form
 * controls: one property select, one operator select, one value control, and a
 * remove button per row. Groups are separated with a visible OR.
 *
 * The form works without JavaScript: one blank row per group and one blank
 * group are always present, so a condition or a group can be added with a
 * normal submit. The small inline script only makes editing faster: it narrows
 * the operator list to the selected field, disables the value control for
 * value-less operators, and clones blank rows and groups.
 *
 * @package NowCRM
 */

namespace NowCRM\Admin;

use NowCRM\Contacts\AdvancedFilters;
use NowCRM\Terms\TermRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ContactAdvancedFilters {

	/**
	 * Input name for the condition rows.
	 */
	public const INPUT = 'nowcrm_adv';

	/**
	 * Submit button that removes one row. Its value is "group:row".
	 */
	public const REMOVE = 'nowcrm_adv_remove';

	/**
	 * Hidden JSON field for bulk and export requests.
	 */
	public const TRANSPORT = 'filter_advanced';

	/**
	 * Parse the submitted controls into a typed plan.
	 *
	 * Fully blank rows and groups are dropped. A partially filled row is kept so
	 * validation reports it instead of silently ignoring it. A removed row is
	 * dropped before validation. An invalid plan is a WP_Error that carries the
	 * submitted rows in its data, so the form can show them again.
	 *
	 * @param array<string, mixed> $request GET or POST data.
	 * @return array{version: int, groups: array<int, array<int, array<string, mixed>>>}|WP_Error|null Null means no advanced filter.
	 */
	public static function parse_request( array $request ) {
		$raw    = $request[ self::INPUT ] ?? null;
		$remove = isset( $request[ self::REMOVE ] ) && is_scalar( $request[ self::REMOVE ] )
			? (string) $request[ self::REMOVE ]
			: '';

		if ( null === $raw && '' === $remove ) {
			return null;
		}

		if ( null !== $raw && ! is_array( $raw ) ) {
			return new WP_Error(
				'nowcrm_advanced_filter_invalid',
				__( '進階篩選的格式不正確。', 'now-crm' )
			);
		}

		$raw = null === $raw ? array() : (array) wp_unslash( $raw );

		if ( '' !== $remove && preg_match( '/^(\d{1,3}):(\d{1,3})$/', $remove, $match ) ) {
			unset( $raw[ (int) $match[1] ][ (int) $match[2] ] );
		}

		$groups = array();

		foreach ( $raw as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rows = array();

			foreach ( $group as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$row = self::clean_row( $row );

				if ( self::is_blank_row( $row ) ) {
					continue;
				}

				$rows[] = $row;
			}

			if ( array() !== $rows ) {
				$groups[] = $rows;
			}
		}

		if ( array() === $groups ) {
			return null;
		}

		$plan = AdvancedFilters::normalize( $groups );

		if ( is_wp_error( $plan ) ) {
			$data           = (array) $plan->get_error_data();
			$data['groups'] = $groups;

			return new WP_Error( $plan->get_error_code(), $plan->get_error_message(), $data );
		}

		return $plan;
	}

	/**
	 * Build a plan from the compact list controls.
	 *
	 * Values inside one category are OR-ed. Categories are AND-ed, because
	 * every condition sits in one group. The result uses the same validator
	 * and compiler as the advanced editor, so the list, bulk actions, and
	 * export all apply one population.
	 *
	 * @param array<int, mixed> $statuses Status slugs.
	 * @param array<int, mixed> $list_ids List term IDs.
	 * @param array<int, mixed> $tag_ids  Tag term IDs.
	 * @return array{version: int, groups: array<int, array<int, array<string, mixed>>>}|WP_Error|null
	 */
	public static function plan_from_simple( array $statuses, array $list_ids, array $tag_ids ) {
		$conditions = array();
		$statuses   = self::clean_scalars( $statuses );
		$list_ids   = self::clean_ids( $list_ids );
		$tag_ids    = self::clean_ids( $tag_ids );

		if ( array() !== $statuses ) {
			$conditions[] = array(
				'provider' => AdvancedFilters::PROVIDER_SEGMENT,
				'field'    => 'status',
				'operator' => 'in',
				'value'    => $statuses,
			);
		}

		if ( array() !== $list_ids ) {
			$conditions[] = array(
				'provider' => AdvancedFilters::PROVIDER_SEGMENT,
				'field'    => 'lists',
				'operator' => 'in',
				'value'    => $list_ids,
			);
		}

		if ( array() !== $tag_ids ) {
			$conditions[] = array(
				'provider' => AdvancedFilters::PROVIDER_SEGMENT,
				'field'    => 'tags',
				'operator' => 'in',
				'value'    => $tag_ids,
			);
		}

		if ( array() === $conditions ) {
			return null;
		}

		return AdvancedFilters::normalize( array( $conditions ) );
	}

	/**
	 * Trimmed scalar strings, in submission order, without duplicates.
	 *
	 * @param array<int, mixed> $values Raw values.
	 * @return string[]
	 */
	private static function clean_scalars( array $values ): array {
		$clean = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value && ! in_array( $value, $clean, true ) ) {
				$clean[] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Positive integer IDs, in submission order, without duplicates.
	 *
	 * @param array<int, mixed> $values Raw values.
	 * @return int[]
	 */
	private static function clean_ids( array $values ): array {
		$clean = array();

		foreach ( $values as $value ) {
			$value = absint( $value );

			if ( $value > 0 && ! in_array( $value, $clean, true ) ) {
				$clean[] = $value;
			}
		}

		return $clean;
	}

	/**
	 * Keep only the keys a condition uses.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function clean_row( array $row ): array {
		$clean = array();

		foreach ( array( 'provider', 'field', 'property', 'operator' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) {
				$clean[ $key ] = (string) $row[ $key ];
			}
		}

		if ( isset( $row['source'] ) && is_array( $row['source'] ) && 2 === count( $row['source'] ) ) {
			$clean['source'] = array_values( $row['source'] );
		}

		if ( array_key_exists( 'value', $row ) ) {
			$value = $row['value'];

			if ( is_array( $value ) ) {
				$clean['value'] = array_values( array_filter( $value, 'is_scalar' ) );
			} elseif ( is_scalar( $value ) ) {
				$clean['value'] = (string) $value;
			}
		}

		return $clean;
	}

	/**
	 * Whether a row has no input at all.
	 *
	 * @param array<string, mixed> $row Cleaned row.
	 */
	private static function is_blank_row( array $row ): bool {
		foreach ( array( 'provider', 'field', 'property', 'operator' ) as $key ) {
			if ( '' !== trim( (string) ( $row[ $key ] ?? '' ) ) ) {
				return false;
			}
		}

		$value = $row['value'] ?? '';

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( '' !== trim( (string) $item ) ) {
					return false;
				}
			}

			return true;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Render the filter controls.
	 *
	 * @param array<string, mixed>|WP_Error|null $plan  Validated plan, or an error.
	 * @param WP_Error|null                      $error Parse error, when it was passed separately.
	 */
	public static function render( $plan = null, $error = null ): void {
		if ( is_wp_error( $plan ) ) {
			$error = $plan;
			$plan  = null;
		}

		$groups    = self::present_groups( $plan, $error );
		$fields    = AdvancedFilters::fields();
		$labels    = AdvancedFilters::operator_labels();
		$terms     = array(
			TermRepository::TYPE_LIST => ( new TermRepository() )->all( TermRepository::TYPE_LIST ),
			TermRepository::TYPE_TAG  => ( new TermRepository() )->all( TermRepository::TYPE_TAG ),
		);

		$ops_map = array();

		foreach ( $fields as $key => $definition ) {
			$ops_map[ $key ] = array_values( $definition['ops'] );
		}

		$ops_pairs = array();

		foreach ( $labels as $operator => $label ) {
			$ops_pairs[] = array( $operator, $label );
		}

		$schema = array();

		foreach ( $fields as $field_key => $definition ) {
			$type    = (string) ( $definition['type'] ?? '' );
			$options = array();

			if ( 'terms' === $type ) {
				$term_type = (string) ( $definition['term_type'] ?? '' );

				foreach ( (array) ( $terms[ $term_type ] ?? array() ) as $term ) {
					$options[ (string) $term['id'] ] = (string) $term['name'];
				}
			} elseif ( ! in_array( $type, array( 'text', 'custom_text', 'number', 'custom_number', 'date', 'custom_date' ), true ) ) {
				$options = (array) ( $definition['options'] ?? array() );
			}

			$schema[ $field_key ] = array(
				'type'    => $type,
				'options' => $options,
			);
		}

		if ( array() === $groups ) {
			$groups = array( array() );
		} elseif ( count( $groups ) < AdvancedFilters::MAX_GROUPS ) {
			// One blank group stays available while the limit allows it.
			$groups[] = array();
		}

		?>
		<fieldset id="nowcrm-adv-filters" class="nowcrm-advanced-filters"
			data-nowcrm-ops="<?php echo esc_attr( (string) wp_json_encode( $ops_map ) ); ?>"
			data-nowcrm-ops-all="<?php echo esc_attr( (string) wp_json_encode( $ops_pairs ) ); ?>"
			data-nowcrm-schema="<?php echo esc_attr( (string) wp_json_encode( $schema ) ); ?>">
			<legend><?php esc_html_e( '進階篩選', 'now-crm' ); ?></legend>
			<p class="description">
				<?php esc_html_e( '同一個群組內的條件必須全部符合；群組之間只要符合一個即可（OR）。', 'now-crm' ); ?>
			</p>

			<?php if ( $error instanceof WP_Error ) : ?>
				<div class="notice notice-error inline">
					<p><?php echo esc_html( $error->get_error_message() ); ?></p>
				</div>
			<?php endif; ?>

			<?php foreach ( $groups as $group_index => $rows ) : ?>
				<?php if ( $group_index > 0 ) : ?>
					<div class="nowcrm-advanced-or" aria-hidden="true"><?php esc_html_e( '或 (OR)', 'now-crm' ); ?></div>
				<?php endif; ?>

				<?php
				if ( count( $rows ) < AdvancedFilters::MAX_CONDITIONS ) {
					$rows[] = array();
				}
				?>
				<fieldset class="nowcrm-advanced-group" data-nowcrm-group="<?php echo esc_attr( (string) $group_index ); ?>">
					<legend>
						<?php
						printf(
							/* translators: %d: group number. */
							esc_html__( '群組 %d（條件之間：AND）', 'now-crm' ),
							(int) $group_index + 1
						);
						?>
					</legend>

					<?php foreach ( $rows as $row_index => $row ) : ?>
						<?php self::render_row( (int) $group_index, (int) $row_index, $row, $fields, $labels, $terms ); ?>
					<?php endforeach; ?>

					<button type="button" class="button-link nowcrm-adv-add-row"
						data-group="<?php echo esc_attr( (string) $group_index ); ?>">
						<?php esc_html_e( '＋ 新增條件', 'now-crm' ); ?>
					</button>
				</fieldset>
			<?php endforeach; ?>

			<?php if ( count( $groups ) < AdvancedFilters::MAX_GROUPS ) : ?>
				<button type="button" class="button nowcrm-adv-add-group">
					<?php esc_html_e( '＋ 新增群組', 'now-crm' ); ?>
				</button>
			<?php endif; ?>

			<script>
			( function () {
				var root = document.getElementById( 'nowcrm-adv-filters' );

				if ( ! root ) {
					return;
				}

				var fieldOps = {};
				var allOps   = [];
				var schema   = {};

				try {
					fieldOps = JSON.parse( root.getAttribute( 'data-nowcrm-ops' ) || '{}' );
					allOps   = JSON.parse( root.getAttribute( 'data-nowcrm-ops-all' ) || '[]' );
					schema   = JSON.parse( root.getAttribute( 'data-nowcrm-schema' ) || '{}' );
				} catch ( e ) {
					fieldOps = {};
					allOps   = [];
					schema   = {};
				}

				var orLabel = <?php echo wp_json_encode( __( '或 (OR)', 'now-crm' ) ); ?>;
				var valuePlaceholder = <?php echo wp_json_encode( __( 'YYYY-MM-DD，或天數', 'now-crm' ) ); ?>;

				function reindex() {
					root.querySelectorAll( '[data-nowcrm-group]' ).forEach( function ( group, g ) {
						group.setAttribute( 'data-nowcrm-group', String( g ) );
						var legend = group.querySelector( 'legend' );

						if ( legend ) {
							legend.textContent = <?php
								/* translators: %d: group number. */
								echo wp_json_encode( __( '群組 %d（條件之間：AND）', 'now-crm' ) );
							?>.replace( '%d', String( g + 1 ) );
						}

						var addRow = group.querySelector( '.nowcrm-adv-add-row' );

						if ( addRow ) {
							addRow.setAttribute( 'data-group', String( g ) );
						}

						group.querySelectorAll( '[data-nowcrm-row]' ).forEach( function ( row, r ) {
							row.setAttribute( 'data-nowcrm-row', String( r ) );
							row.querySelectorAll( '[name]' ).forEach( function ( el ) {
								el.name = el.name.replace( /^nowcrm_adv\[\d+\]\[\d+\]/, 'nowcrm_adv[' + g + '][' + r + ']' );
							} );
							var remove = row.querySelector( 'button[name="nowcrm_adv_remove"]' );

							if ( remove ) {
								remove.value = g + ':' + r;
							}
						} );
					} );
				}

				function clear( node ) {
					node.querySelectorAll( 'input' ).forEach( function ( input ) {
						if ( 'button' !== input.type && 'submit' !== input.type ) {
							input.value = '';
							if ( 'checkbox' === input.type || 'radio' === input.type ) {
								input.checked = false;
							}
						}
					} );
					node.querySelectorAll( 'select' ).forEach( function ( select ) {
						if ( select.multiple ) {
							Array.prototype.forEach.call( select.options, function ( option ) {
								option.selected = false;
							} );
						} else {
							select.selectedIndex = 0;
						}
					} );
				}

				function valueName( row, multiple ) {
					var property = row.querySelector( 'select[name$="[property]"]' );
					var base = property && property.name
						? property.name.replace( /\[property\]$/, '' )
						: 'nowcrm_adv[0][0]';

					return base + ( multiple ? '[value][]' : '[value]' );
				}

				function buildValue( row, key ) {
					var holder = row.querySelector( '.nowcrm-advanced-value' );

					if ( ! holder ) {
						return;
					}

					var definition = key && schema[ key ] ? schema[ key ] : null;
					var type = definition ? definition.type : 'text';
					var control;

					if ( 'number' === type || 'custom_number' === type ) {
						control = document.createElement( 'input' );
						control.type = 'number';
						control.step = 'any';
						control.name = valueName( row, false );
					} else if ( 'date' === type || 'custom_date' === type ) {
						control = document.createElement( 'input' );
						control.type = 'text';
						control.name = valueName( row, false );
						control.placeholder = valuePlaceholder;
					} else if ( ! definition || 'text' === type || 'custom_text' === type ) {
						control = document.createElement( 'input' );
						control.type = 'text';
						control.name = valueName( row, false );
					} else {
						control = document.createElement( 'select' );
						control.multiple = true;
						control.size = 6;
						control.name = valueName( row, true );

						var options = definition.options || {};

						Object.keys( options ).forEach( function ( value ) {
							var option = document.createElement( 'option' );
							option.value = value;
							option.textContent = options[ value ];
							control.appendChild( option );
						} );
					}

					holder.innerHTML = '';
					holder.appendChild( control );
					row.setAttribute( 'data-nowcrm-value-key', key || '' );
				}

				function syncValue( row ) {
					var property = row.querySelector( 'select[name$="[property]"]' );
					var operator = row.querySelector( 'select[name$="[operator]"]' );
					var key = property ? property.value : '';

					if ( row.getAttribute( 'data-nowcrm-value-key' ) !== key ) {
						buildValue( row, key );
					}

					var valueless = operator && ( 'is_null' === operator.value || 'not_null' === operator.value );

					row.querySelectorAll( '[name*="[value]"]' ).forEach( function ( control ) {
						control.disabled = !! valueless;
					} );
				}

				function syncRow( row ) {
					var property = row.querySelector( 'select[name$="[property]"]' );
					var operator = row.querySelector( 'select[name$="[operator]"]' );
					var allowed  = property && property.value ? fieldOps[ property.value ] : null;

					if ( operator && allowed ) {
						var current = operator.value;
						operator.innerHTML = '<option value=""></option>';

						allOps.forEach( function ( pair ) {
							if ( -1 === allowed.indexOf( pair[0] ) ) {
								return;
							}

							var option = document.createElement( 'option' );
							option.value = pair[0];
							option.textContent = pair[1];
							operator.appendChild( option );
						} );

						if ( allowed.indexOf( current ) !== -1 ) {
							operator.value = current;
						}
					} else if ( operator && ! allowed ) {
						operator.innerHTML = '<option value=""></option>';
					}

					syncValue( row );
				}

				root.addEventListener( 'change', function ( event ) {
					var row = event.target.closest( '[data-nowcrm-row]' );

					if ( row ) {
						syncRow( row );
					}
				} );

				root.addEventListener( 'click', function ( event ) {
					var addRow = event.target.closest( '.nowcrm-adv-add-row' );

					if ( addRow ) {
						var group = addRow.closest( '[data-nowcrm-group]' );
						var rows  = group.querySelectorAll( '[data-nowcrm-row]' );

						if ( rows.length >= <?php echo (int) AdvancedFilters::MAX_CONDITIONS; ?> ) {
							return;
						}

						var clone = rows[ rows.length - 1 ].cloneNode( true );
						clear( clone );
						group.insertBefore( clone, addRow );
						reindex();
						syncRow( clone );

						return;
					}

					var addGroup = event.target.closest( '.nowcrm-adv-add-group' );

					if ( addGroup ) {
						var groups = root.querySelectorAll( '[data-nowcrm-group]' );

						if ( groups.length >= <?php echo (int) AdvancedFilters::MAX_GROUPS; ?> ) {
							return;
						}

						var copy = groups[ groups.length - 1 ].cloneNode( true );
						clear( copy );

						var or = document.createElement( 'div' );
						or.className = 'nowcrm-advanced-or';
						or.setAttribute( 'aria-hidden', 'true' );
						or.textContent = orLabel;
						root.insertBefore( or, addGroup );
						root.insertBefore( copy, addGroup );
						reindex();

						copy.querySelectorAll( '[data-nowcrm-row]' ).forEach( syncRow );
					}
				} );

				root.querySelectorAll( '[data-nowcrm-row]' ).forEach( syncRow );
			} )();
			</script>
		</fieldset>
		<?php
	}

	/**
	 * Groups and rows for rendering, from a plan or an error payload.
	 *
	 * @param array<string, mixed>|null $plan  Plan.
	 * @param WP_Error|null             $error Error with raw rows in its data.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private static function present_groups( $plan, $error ): array {
		$source = array();

		if ( is_array( $plan ) && isset( $plan['groups'] ) && is_array( $plan['groups'] ) ) {
			$source = $plan['groups'];
		}

		if ( $error instanceof WP_Error ) {
			$data = $error->get_error_data();

			if ( is_array( $data ) && isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
				$source = $data['groups'];
			}
		}

		$groups = array();

		foreach ( $source as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rows = array();

			foreach ( $group as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = self::present_row( $row );
				}
			}

			if ( array() !== $rows ) {
				$groups[] = $rows;
			}
		}

		return $groups;
	}

	/**
	 * Provider, field, operator, and value of one row.
	 *
	 * @param array<string, mixed> $row Raw or normalized row.
	 * @return array{provider: string, field: string, operator: string, value: mixed}
	 */
	private static function present_row( array $row ): array {
		$provider = isset( $row['provider'] ) && is_scalar( $row['provider'] )
			? strtolower( trim( (string) $row['provider'] ) )
			: '';
		$field    = isset( $row['field'] ) && is_scalar( $row['field'] )
			? trim( (string) $row['field'] )
			: '';
		$property = isset( $row['property'] ) && is_scalar( $row['property'] )
			? trim( (string) $row['property'] )
			: '';

		if ( ( '' === $provider || '' === $field ) && str_contains( $property, '.' ) ) {
			list( $provider, $field ) = explode( '.', $property, 2 );
			$provider                = strtolower( trim( $provider ) );
			$field                   = trim( $field );
		}

		if ( ( '' === $provider || '' === $field ) && isset( $row['source'] ) && is_array( $row['source'] ) && 2 === count( $row['source'] ) ) {
			$source   = array_values( $row['source'] );
			$provider = is_scalar( $source[0] ) ? strtolower( trim( (string) $source[0] ) ) : '';
			$field    = is_scalar( $source[1] ) ? trim( (string) $source[1] ) : '';
		}

		return array(
			'provider' => $provider,
			'field'    => $field,
			'operator' => isset( $row['operator'] ) && is_scalar( $row['operator'] ) ? (string) $row['operator'] : '',
			'value'    => $row['value'] ?? '',
		);
	}

	/**
	 * Render one condition row.
	 *
	 * @param int                                $group_index Group index.
	 * @param int                                $row_index   Row index.
	 * @param array<string, mixed>               $row         Row values.
	 * @param array<string, array<string, mixed>> $fields     Field catalog.
	 * @param array<string, string>              $labels      Operator labels.
	 * @param array<string, array<int, array<string, mixed>>> $terms Terms by type.
	 */
	private static function render_row( int $group_index, int $row_index, array $row, array $fields, array $labels, array $terms ): void {
		$row     = self::present_row( $row );
		$key     = '' !== $row['provider'] && '' !== $row['field'] ? $row['provider'] . '.' . $row['field'] : '';
		$current = $key;
		$name    = self::INPUT . '[' . $group_index . '][' . $row_index . ']';
		$value   = $row['value'];
		?>
		<div class="nowcrm-advanced-row" data-nowcrm-row="<?php echo esc_attr( (string) $row_index ); ?>"
			data-nowcrm-value-key="<?php echo esc_attr( $key ); ?>">
			<label>
				<span><?php esc_html_e( '欄位', 'now-crm' ); ?></span>
				<select name="<?php echo esc_attr( $name . '[property]' ); ?>">
					<option value=""><?php esc_html_e( '選擇欄位', 'now-crm' ); ?></option>
					<?php foreach ( AdvancedFilters::provider_labels() as $provider => $provider_label ) : ?>
						<?php
						$provider_fields = array_filter(
							$fields,
							static fn( array $definition ): bool => $definition['provider'] === $provider
						);

						if ( array() === $provider_fields ) {
							continue;
						}
						?>
						<optgroup label="<?php echo esc_html( $provider_label ); ?>">
							<?php foreach ( $provider_fields as $field_key => $definition ) : ?>
								<option value="<?php echo esc_attr( $field_key ); ?>"
									<?php selected( $current, $field_key ); ?>>
									<?php echo esc_html( (string) $definition['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( '運算子', 'now-crm' ); ?></span>
				<select name="<?php echo esc_attr( $name . '[operator]' ); ?>">
					<option value=""><?php esc_html_e( '選擇運算子', 'now-crm' ); ?></option>
					<?php foreach ( $labels as $operator => $label ) : ?>
						<option value="<?php echo esc_attr( $operator ); ?>" <?php selected( $row['operator'], $operator ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<span class="nowcrm-advanced-value">
				<?php self::render_value( $fields[ $key ] ?? null, $value, $name, $terms ); ?>
			</span>

			<button type="submit" class="button-link nowcrm-advanced-remove" name="<?php echo esc_attr( self::REMOVE ); ?>"
				value="<?php echo esc_attr( $group_index . ':' . $row_index ); ?>">
				<?php esc_html_e( '移除', 'now-crm' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the value control for a field type.
	 *
	 * @param array<string, mixed>|null $definition Field definition, or null for a blank row.
	 * @param mixed                    $value      Current value.
	 * @param string                   $name       Input name prefix.
	 * @param array<string, array<int, array<string, mixed>>> $terms Terms by type.
	 */
	private static function render_value( ?array $definition, $value, string $name, array $terms ): void {
		$selected = array();

		foreach ( (array) ( is_array( $value ) ? $value : array( $value ) ) as $item ) {
			if ( is_scalar( $item ) ) {
				$selected[] = (string) $item;
			}
		}

		$type = (string) ( $definition['type'] ?? '' );

		if ( '' === $type || in_array( $type, array( 'text', 'custom_text' ), true ) ) {
			?>
			<input type="text" name="<?php echo esc_attr( $name . '[value]' ); ?>"
				value="<?php echo esc_attr( (string) ( $selected[0] ?? '' ) ); ?>" />
			<?php
			return;
		}

		if ( in_array( $type, array( 'number', 'custom_number' ), true ) ) {
			?>
			<input type="number" step="any" name="<?php echo esc_attr( $name . '[value]' ); ?>"
				value="<?php echo esc_attr( (string) ( $selected[0] ?? '' ) ); ?>" />
			<?php
			return;
		}

		if ( in_array( $type, array( 'date', 'custom_date' ), true ) ) {
			?>
			<input type="text" name="<?php echo esc_attr( $name . '[value]' ); ?>"
				value="<?php echo esc_attr( (string) ( $selected[0] ?? '' ) ); ?>"
				placeholder="<?php esc_attr_e( 'YYYY-MM-DD，或天數', 'now-crm' ); ?>" />
			<?php
			return;
		}

		$options = array();

		if ( 'terms' === $type ) {
			$term_type = (string) ( $definition['term_type'] ?? '' );
			$label     = TermRepository::TYPE_TAG === $term_type ? __( '標籤', 'now-crm' ) : __( '名單', 'now-crm' );

			$options[ $label ] = array();

			foreach ( (array) ( $terms[ $term_type ] ?? array() ) as $term ) {
				$options[ $label ][ (string) $term['id'] ] = (string) $term['name'];
			}
		} else {
			$options[ (string) ( $definition['label'] ?? '' ) ] = (array) ( $definition['options'] ?? array() );
		}

		?>
		<select multiple="multiple" size="6" name="<?php echo esc_attr( $name . '[value][]' ); ?>">
			<?php foreach ( $options as $group_label => $group_options ) : ?>
				<?php if ( count( $options ) > 1 ) : ?>
					<optgroup label="<?php echo esc_attr( (string) $group_label ); ?>">
				<?php endif; ?>
				<?php foreach ( (array) $group_options as $option_value => $option_label ) : ?>
					<option value="<?php echo esc_attr( (string) $option_value ); ?>"
						<?php echo in_array( (string) $option_value, $selected, true ) ? 'selected="selected"' : ''; ?>>
						<?php echo esc_html( (string) $option_label ); ?>
					</option>
				<?php endforeach; ?>
				<?php if ( count( $options ) > 1 ) : ?>
					</optgroup>
				<?php endif; ?>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
