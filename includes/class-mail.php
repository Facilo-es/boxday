<?php
/**
 * One WooCommerce email of ours, so it's listed in WooCommerce's settings and uses the store's template.
 *
 * @package BoxDay
 */

namespace BoxDay;

defined( 'ABSPATH' ) || exit;

/**
 * A BoxDay email.
 */
class Mail extends \WC_Email {

	/**
	 * Whether the farm can turn this email off.
	 *
	 * @var bool
	 */
	private $optional;

	/**
	 * Sets up the email.
	 *
	 * @param string $id          Email id.
	 * @param string $title       Title in the settings.
	 * @param string $description Description in the settings.
	 * @param bool   $optional    Whether it can be turned off.
	 */
	public function __construct( $id, $title, $description, $optional = false ) {
		$this->id             = $id;
		$this->title          = $title;
		$this->description    = $description;
		$this->optional       = $optional;
		$this->customer_email = true;
		parent::__construct();
		$this->email_type = 'html';
	}

	/**
	 * Keeps the mandatory emails on, whatever is saved.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->optional ? parent::is_enabled() : true;
	}

	/**
	 * Settings: only optional emails get the on/off switch.
	 */
	public function init_form_fields() {
		parent::init_form_fields();
		if ( ! $this->optional ) {
			unset( $this->form_fields['enabled'] );
		}
		unset( $this->form_fields['email_type'], $this->form_fields['subject'], $this->form_fields['heading'] );
	}

	/**
	 * Sends one message.
	 *
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $body    Escaped HTML.
	 * @return bool
	 */
	public function deliver( $to, $subject, $body ) {
		if ( ! $this->is_enabled() ) {
			return false;
		}
		$html = WC()->mailer()->wrap_message( $subject, $body );
		return $this->send( $to, $subject, $html, $this->get_headers(), array() );
	}
}
