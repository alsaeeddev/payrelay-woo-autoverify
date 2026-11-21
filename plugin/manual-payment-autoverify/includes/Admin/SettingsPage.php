<?php
/**
 * Settings Page — manages plugin configuration via the WordPress Settings API.
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

/**
 * SettingsPage handles option registration, validation, rendering, and custom regex hooks.
 */
class SettingsPage {

	public const OPTION_GROUP = 'mpav_settings_group';
	public const PAGE_SLUG    = 'mpav-settings';

	/**
	 * Registers Settings API sections, fields, and regex pattern filters.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Hook custom regex overrides into parser filters.
		add_filter( 'mpav_jazzcash_patterns', array( $this, 'filter_jazzcash_patterns' ), 20 );
		add_filter( 'mpav_easypaisa_patterns', array( $this, 'filter_easypaisa_patterns' ), 20 );
	}

	/**
	 * Static getter for plugin options.
	 *
	 * @param string $key     Option name.
	 * @param mixed  $default Fallback value if option is not set.
	 * @return mixed
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		return get_option( $key, $default );
	}

	/**
	 * Registers settings, sections, and fields with WordPress.
	 */
	public function register_settings(): void {
		// ── Section 1: General Settings ─────────────────────────────────────────
		add_settings_section(
			'mpav_general_section',
			__( 'Verification & Timing Settings', 'manual-payment-autoverify' ),
			array( $this, 'render_general_section_intro' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'mpav_sms_match_window_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_match_window' ),
				'default'           => 60,
			)
		);

		add_settings_field(
			'mpav_sms_match_window_minutes',
			__( 'SMS Match Window (Minutes)', 'manual-payment-autoverify' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mpav_general_section',
			array(
				'label_for'   => 'mpav_sms_match_window_minutes',
				'name'        => 'mpav_sms_match_window_minutes',
				'value'       => self::get( 'mpav_sms_match_window_minutes', 60 ),
				'min'         => 1,
				'max'         => 10080,
				'step'        => 1,
				'description' => __( 'Maximum time window (in minutes) between customer claim submission and received SMS timestamp. Claims matched outside this window are flagged for manual review.', 'manual-payment-autoverify' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'mpav_claim_expiry_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_expiry_minutes' ),
				'default'           => 1440,
			)
		);

		add_settings_field(
			'mpav_claim_expiry_minutes',
			__( 'Claim Expiry Window (Minutes)', 'manual-payment-autoverify' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mpav_general_section',
			array(
				'label_for'   => 'mpav_claim_expiry_minutes',
				'name'        => 'mpav_claim_expiry_minutes',
				'value'       => self::get( 'mpav_claim_expiry_minutes', 1440 ),
				'min'         => 10,
				'max'         => 43200,
				'step'        => 10,
				'description' => __( 'Duration (in minutes) after which an unmatched pending claim is considered expired (default: 1440 = 24 hours).', 'manual-payment-autoverify' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'mpav_sms_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_retention_days' ),
				'default'           => 90,
			)
		);

		add_settings_field(
			'mpav_sms_retention_days',
			__( 'SMS Log Retention (Days)', 'manual-payment-autoverify' ),
			array( $this, 'render_number_field' ),
			self::PAGE_SLUG,
			'mpav_general_section',
			array(
				'label_for'   => 'mpav_sms_retention_days',
				'name'        => 'mpav_sms_retention_days',
				'value'       => self::get( 'mpav_sms_retention_days', 90 ),
				'min'         => 7,
				'max'         => 730,
				'step'        => 1,
				'description' => __( 'Number of days to keep forwarded SMS hashes before automated purge jobs clean them up.', 'manual-payment-autoverify' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'mpav_keep_data_on_uninstall',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_keep_data' ),
				'default'           => '1',
			)
		);

		add_settings_field(
			'mpav_keep_data_on_uninstall',
			__( 'Data Retention on Uninstall', 'manual-payment-autoverify' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE_SLUG,
			'mpav_general_section',
			array(
				'label_for'   => 'mpav_keep_data_on_uninstall',
				'name'        => 'mpav_keep_data_on_uninstall',
				'value'       => self::get( 'mpav_keep_data_on_uninstall', '1' ),
				'label'       => __( 'Keep transaction and claim records when plugin is uninstalled', 'manual-payment-autoverify' ),
				'description' => __( 'If unchecked, deleting the plugin will permanently erase all MPAV database tables and settings.', 'manual-payment-autoverify' ),
			)
		);

		// ── Section 2: Custom Regex Settings ─────────────────────────────────────
		add_settings_section(
			'mpav_regex_section',
			__( 'Custom SMS Parser Regular Expressions (Advanced)', 'manual-payment-autoverify' ),
			array( $this, 'render_regex_section_intro' ),
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'mpav_custom_regex_jazzcash',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_regex_jazzcash' ),
				'default'           => '',
			)
		);

		add_settings_field(
			'mpav_custom_regex_jazzcash',
			__( 'JazzCash Custom TID Regex', 'manual-payment-autoverify' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'mpav_regex_section',
			array(
				'label_for'   => 'mpav_custom_regex_jazzcash',
				'name'        => 'mpav_custom_regex_jazzcash',
				'value'       => self::get( 'mpav_custom_regex_jazzcash', '' ),
				'placeholder' => '/(?:TID|Trx\\s*ID)\\s*[:.-]?\\s*([A-Za-z0-9]{6,32})/i',
				'description' => __( 'Optional custom regex override for JazzCash TID extraction. Must contain at least one capture group for the TID. Leave blank to use built-in defaults.', 'manual-payment-autoverify' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			'mpav_custom_regex_easypaisa',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_regex_easypaisa' ),
				'default'           => '',
			)
		);

		add_settings_field(
			'mpav_custom_regex_easypaisa',
			__( 'Easypaisa Custom TID Regex', 'manual-payment-autoverify' ),
			array( $this, 'render_text_field' ),
			self::PAGE_SLUG,
			'mpav_regex_section',
			array(
				'label_for'   => 'mpav_custom_regex_easypaisa',
				'name'        => 'mpav_custom_regex_easypaisa',
				'value'       => self::get( 'mpav_custom_regex_easypaisa', '' ),
				'placeholder' => '/(?:TRX\\s*ID|TID|Txn\\s*ID)\\s*[:.-]?\\s*([A-Za-z0-9]{6,32})/i',
				'description' => __( 'Optional custom regex override for Easypaisa TID extraction. Must contain at least one capture group for the TID. Leave blank to use built-in defaults.', 'manual-payment-autoverify' ),
			)
		);
	}

	// ------------------------------------------------------------------ //
	// Sanitization callbacks
	// ------------------------------------------------------------------ //

	public function sanitize_match_window( mixed $value ): int {
		$int_val = (int) $value;
		if ( $int_val < 1 ) {
			add_settings_error(
				'mpav_sms_match_window_minutes',
				'invalid_window',
				__( 'Match window must be at least 1 minute.', 'manual-payment-autoverify' )
			);
			return (int) self::get( 'mpav_sms_match_window_minutes', 60 );
		}
		return min( $int_val, 10080 );
	}

	public function sanitize_expiry_minutes( mixed $value ): int {
		$int_val = (int) $value;
		if ( $int_val < 10 ) {
			add_settings_error(
				'mpav_claim_expiry_minutes',
				'invalid_expiry',
				__( 'Claim expiry window must be at least 10 minutes.', 'manual-payment-autoverify' )
			);
			return (int) self::get( 'mpav_claim_expiry_minutes', 1440 );
		}
		return min( $int_val, 43200 );
	}

	public function sanitize_retention_days( mixed $value ): int {
		$int_val = (int) $value;
		if ( $int_val < 1 ) {
			add_settings_error(
				'mpav_sms_retention_days',
				'invalid_retention',
				__( 'Retention days must be at least 1 day.', 'manual-payment-autoverify' )
			);
			return (int) self::get( 'mpav_sms_retention_days', 90 );
		}
		return min( $int_val, 730 );
	}

	public function sanitize_keep_data( mixed $value ): string {
		return ( '1' === (string) $value ) ? '1' : '0';
	}

	public function sanitize_regex_jazzcash( mixed $value ): string {
		return $this->validate_regex_pattern( (string) $value, 'mpav_custom_regex_jazzcash' );
	}

	public function sanitize_regex_easypaisa( mixed $value ): string {
		return $this->validate_regex_pattern( (string) $value, 'mpav_custom_regex_easypaisa' );
	}

	/**
	 * Validates a regex pattern for syntactic correctness and ReDoS safety.
	 *
	 * @param string $pattern  Candidate regex string.
	 * @param string $opt_name Option name for reporting errors.
	 * @return string Validated pattern or previous value if invalid.
	 */
	private function validate_regex_pattern( string $pattern, string $opt_name ): string {
		$trimmed = trim( $pattern );
		if ( '' === $trimmed ) {
			return '';
		}

		// Length guard to prevent excessively complex regex strings.
		if ( strlen( $trimmed ) > 512 ) {
			add_settings_error(
				$opt_name,
				'regex_too_long',
				__( 'Custom regex pattern is too long (maximum 512 characters).', 'manual-payment-autoverify' )
			);
			return (string) self::get( $opt_name, '' );
		}

		// Basic ReDoS check: look for dangerous nested quantifier constructions e.g. (a+)+ or (.*)*
		if ( preg_match( '/\([^)]*([+*]|\{\d+,?\d*\})\)[+*]|\(\?[^)]*([+*]|\{\d+,?\d*\})\)[+*]/', $trimmed ) ) {
			add_settings_error(
				$opt_name,
				'regex_redos_risk',
				__( 'Custom regex contains nested quantifiers with potential ReDoS vulnerability. Please rewrite using bounded quantifiers.', 'manual-payment-autoverify' )
			);
			return (string) self::get( $opt_name, '' );
		}

		// Test whether pattern is valid PCRE by running a dry-run preg_match.
		// Suppress PHP warning with @, inspect return value.
		$test_result = @preg_match( $trimmed, 'sample test string' );
		if ( false === $test_result ) {
			// Delimiter might be missing; test wrapping with standard delimiters '~...~i'.
			$wrapped = '~' . addcslashes( $trimmed, '~' ) . '~i';
			$test_wrapped = @preg_match( $wrapped, 'sample test string' );

			if ( false === $test_wrapped ) {
				add_settings_error(
					$opt_name,
					'regex_syntax_error',
					__( 'Custom regular expression has invalid syntax. Changes were not saved.', 'manual-payment-autoverify' )
				);
				return (string) self::get( $opt_name, '' );
			}

			// Store the wrapped pattern with delimiters.
			$trimmed = $wrapped;
		}

		return $trimmed;
	}

	// ------------------------------------------------------------------ //
	// Filter callbacks
	// ------------------------------------------------------------------ //

	/**
	 * Injects custom JazzCash TID regex if configured.
	 *
	 * @param array<string, string> $patterns
	 * @return array<string, string>
	 */
	public function filter_jazzcash_patterns( array $patterns ): array {
		$custom = (string) self::get( 'mpav_custom_regex_jazzcash', '' );
		if ( '' !== trim( $custom ) ) {
			$patterns['tid'] = $custom;
		}
		return $patterns;
	}

	/**
	 * Injects custom Easypaisa TID regex if configured.
	 *
	 * @param array<string, string> $patterns
	 * @return array<string, string>
	 */
	public function filter_easypaisa_patterns( array $patterns ): array {
		$custom = (string) self::get( 'mpav_custom_regex_easypaisa', '' );
		if ( '' !== trim( $custom ) ) {
			$patterns['tid'] = $custom;
		}
		return $patterns;
	}

	// ------------------------------------------------------------------ //
	// Field renderers
	// ------------------------------------------------------------------ //

	public function render_general_section_intro(): void {
		echo '<p class="description">' . esc_html__( 'Configure timing windows and automatic expiry thresholds for incoming payment claims.', 'manual-payment-autoverify' ) . '</p>';
	}

	public function render_regex_section_intro(): void {
		echo '<p class="description">' . esc_html__( 'If a payment provider updates their SMS notification format, you can override the TID extraction regex here without waiting for a plugin update.', 'manual-payment-autoverify' ) . '</p>';
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function render_number_field( array $args ): void {
		$name  = esc_attr( $args['name'] );
		$value = esc_attr( (string) $args['value'] );
		$min   = isset( $args['min'] ) ? 'min="' . esc_attr( (string) $args['min'] ) . '"' : '';
		$max   = isset( $args['max'] ) ? 'max="' . esc_attr( (string) $args['max'] ) . '"' : '';
		$step  = isset( $args['step'] ) ? 'step="' . esc_attr( (string) $args['step'] ) . '"' : '';

		echo sprintf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text" %3$s %4$s %5$s />',
			$name,
			$value,
			$min,
			$max,
			$step
		);

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function render_text_field( array $args ): void {
		$name        = esc_attr( $args['name'] );
		$value       = esc_attr( (string) $args['value'] );
		$placeholder = isset( $args['placeholder'] ) ? 'placeholder="' . esc_attr( (string) $args['placeholder'] ) . '"' : '';

		echo sprintf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text code" %3$s />',
			$name,
			$value,
			$placeholder
		);

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function render_checkbox_field( array $args ): void {
		$name    = esc_attr( $args['name'] );
		$checked = checked( '1', (string) $args['value'], false );
		$label   = esc_html( $args['label'] ?? '' );

		echo sprintf(
			'<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
			$name,
			$checked,
			$label
		);

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Renders the Settings Page HTML.
	 */
	public function render(): void {
		?>
		<div class="wrap mpav-admin-wrap">
			<h1><?php esc_html_e( 'Manual Payment Auto-Verify Settings', 'manual-payment-autoverify' ); ?></h1>
			<hr class="wp-header-end">

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

