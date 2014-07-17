<?php
include_once( 'class.contact-form-shortcode.php' );
include_once( 'class.contact-form-field.php' );
include_once( 'class.contact-form-akismet-adapter.php' );

/**
 * Class for the contact-form shortcode.
 * Parses shortcode to output the contact form as HTML
 * Sends email and stores the contact form response (a.k.a. "feedback")
 */
class Grunion_Contact_Form extends Grunion_Contact_Form_Shortcode {
	var $shortcode_name = 'contact-form';

	/**
	 * @var WP_Error stores form submission errors
	 */
	var $errors;

	/**
	 * @var Grunion_Contact_Form The most recent (inclusive) contact-form shortcode processed
	 */
	static $last;

	/**
	 * @var bool Whether to print the grunion.css style when processing the contact-form shortcode
	 */
	static $style = false;

	function __construct( $attributes, $content = null ) {
		global $post;

		// Set up the default subject and recipient for this form
		$default_to = get_option( 'admin_email' );
		$default_subject = "[" . get_option( 'blogname' ) . "]";

		if ( !empty( $attributes['widget'] ) && $attributes['widget'] ) {
			$attributes['id'] = 'widget-' . $attributes['widget'];

			$default_subject = sprintf( _x( '%1$s Sidebar', '%1$s = blog name', 'jetpack' ), $default_subject );
		} else if ( $post ) {
			$attributes['id'] = $post->ID;
			$default_subject = sprintf( _x( '%1$s %2$s', '%1$s = blog name, %2$s = post title', 'jetpack' ), $default_subject, Grunion_Contact_Form_Plugin::strip_tags( $post->post_title ) );
			$post_author = get_userdata( $post->post_author );
			$default_to = $post_author->user_email;
		}

		$this->defaults = array(
			'to'                 => $default_to,
			'subject'            => $default_subject,
			'show_subject'       => 'no', // only used in back-compat mode
			'widget'             => 0,    // Not exposed to the user. Works with Grunion_Contact_Form_Plugin::widget_atts()
			'id'                 => null, // Not exposed to the user. Set above.
			'submit_button_text' => __( 'Submit &#187;', 'jetpack' ),
		);

		$attributes = shortcode_atts( $this->defaults, $attributes );

		// We only add the contact-field shortcode temporarily while processing the contact-form shortcode
		add_shortcode( 'contact-field', array( $this, 'parse_contact_field' ) );

		parent::__construct( $attributes, $content );

		// There were no fields in the contact form. The form was probably just [contact-form /]. Build a default form.
		if ( empty( $this->fields ) ) {
			// same as the original Grunion v1 form
			$default_form = '
				[contact-field label="' . __( 'Name', 'jetpack' )    . '" type="name"  required="true" /]
				[contact-field label="' . __( 'Email', 'jetpack' )   . '" type="email" required="true" /]
				[contact-field label="' . __( 'Website', 'jetpack' ) . '" type="url" /]';

			if ( 'yes' == strtolower( $this->get_attribute( 'show_subject' ) ) ) {
				$default_form .= '
					[contact-field label="' . __( 'Subject', 'jetpack' ) . '" type="subject" /]';
			}

			$default_form .= '
				[contact-field label="' . __( 'Message', 'jetpack' ) . '" type="textarea" /]';

			$this->parse_content( $default_form );
		}

		// $this->body and $this->fields have been setup.  We no longer need the contact-field shortcode.
		remove_shortcode( 'contact-field' );
	}

	function initialize_standard_field_values( $field_ids ) {
		// Initialize all these "standard" fields to null
		$this->comment_author_email = $this->comment_author_email_label = // v
		$this->comment_author       = $this->comment_author_label       = // v
		$this->comment_author_url   = $this->comment_author_url_label   = // v
		$this->comment_content      = $this->comment_content_label      = null;

		$this->comment_author_IP = Grunion_Contact_Form_Plugin::get_ip_address();

		// For each of the "standard" fields, grab their field label and value.
		$field_to_var = array(
			'name' => 'comment_author',
			'email' => 'comment_author_email',
			'url' => 'comment_author_url',
			'textarea' => 'comment_content'
		);

		foreach ( array_keys( $field_to_var ) as $field_id ) {
			if ( ! isset( $field_ids[$field_id] ) ) {
				continue;
			}

			$var = $field_to_var[$field_id];
			$field = $this->fields[$field_ids[$field_id]];

			$this->{$var} = Grunion_Contact_Form_Plugin::strip_tags( stripslashes( apply_filters( 'pre_comment_author_name', addslashes( $field->value ) ) ) );
			$this->{$var . '_label'} = Grunion_Contact_Form_Plugin::strip_tags( $field->get_attribute( 'label' ) );
		}

		if ( 'http://' == $this->comment_author_url ) {
			$this->comment_author_url = '';
		}

		// Replace the admin specified field if exposed to the user
		if ( isset( $field_ids['subject'] ) ) {
			$field = $this->fields[$field_ids['subject']];
			if ( $field->value ) {
				$this->contact_form_subject = Grunion_Contact_Form_Plugin::strip_tags( $field->value );
			}
		}

		// Specify user email as author of author not specified
		if ( ! $this->comment_author ) {
			$this->comment_author = $this->comment_author_email;
		}
	}

	/**
	 * Toggle for printing the grunion.css stylesheet
	 *
	 * @param bool $style
	 */
	static function style( $style ) {
		$previous_style = self::$style;
		self::$style = (bool) $style;
		return $previous_style;
	}

	/**
	 * Turn on printing of grunion.css stylesheet
	 * @see ::style()
	 * @internal
	 * @param bool $style
	 */
	static function _style_on() {
		return self::style( true );
	}

	/**
	 * The contact-form shortcode processor
	 *
	 * @param array $attributes Key => Value pairs as parsed by shortcode_parse_atts()
	 * @param string|null $content The shortcode's inner content: [contact-form]$content[/contact-form]
	 * @return string HTML for the concat form.
	 */
	static function parse( $attributes, $content ) {
		// Create a new Grunion_Contact_Form object (this class)
		$form = new Grunion_Contact_Form( $attributes, $content );

		$id = $form->get_attribute( 'id' );

		if ( !$id ) { // something terrible has happened
			return '[contact-form]';
		}

		if ( is_feed() ) {
			return '[contact-form]';
		}

		// Only allow one contact form per post/widget
		if ( self::$last && $id == self::$last->get_attribute( 'id' ) ) {
			// We're processing the same post

			if ( self::$last->attributes != $form->attributes || self::$last->content != $form->content ) {
				// And we're processing a different shortcode;
				return '';
			} // else, we're processing the same shortcode - probably a separate run of do_shortcode() - let it through

		} else {
			self::$last = $form;
		}

		// Enqueue the grunion.css stylesheet if self::$style allows it
		if ( self::$style && ( empty( $_REQUEST['action'] ) || $_REQUEST['action'] != 'grunion_shortcode_to_json' ) ) {
			// Enqueue the style here instead of printing it, because if some other plugin has run the_post()+rewind_posts(),
			// (like VideoPress does), the style tag gets "printed" the first time and discarded, leaving the contact form unstyled.
			// when WordPress does the real loop.
			wp_enqueue_style( 'grunion.css' );
		}

		if ( $form->get_attribute( 'widget' ) ) {
			// Submit form to the current URL
			$submit_url = remove_query_arg( array( 'contact-form-id', 'contact-form-sent', 'action', '_wpnonce' ) );
		} else {
			// Submit form to the post permalink
			$submit_url = get_permalink();
		}

		// May eventually want to send this to admin-post.php...
		$submit_url = apply_filters( 'grunion_contact_form_form_action', "{$submit_url}#contact-form-{$id}", $GLOBALS['post'], $id );


		return Grunion_Contact_Form_Plugin::template( 'contact-form', array(
			'id' => $id,
			'form' => $form,
			'submit_url' => $submit_url
		) );
	}

	static function success_message( $feedback_id, $form ) {
		$r_success_message = '';

		$feedback       = get_post( $feedback_id );
		$field_ids      = $form->get_field_ids();
		$content_fields = Grunion_Contact_Form_Plugin::parse_fields_from_content( $feedback_id );

		// Maps field_ids to post_meta keys
		$field_value_map = array(
			'name'     => 'author',
			'email'    => 'author_email',
			'url'      => 'author_url',
			'subject'  => 'subject',
			'textarea' => false, // not a post_meta key.  This is stored in post_content
		);

		$contact_form_message = "<blockquote>\n";

		// "Standard" field whitelist
		foreach ( $field_value_map as $type => $meta_key ) {
			if ( isset( $field_ids[$type] ) ) {
				$field = $form->fields[$field_ids[$type]];

				if ( $meta_key ) {
					if ( isset( $content_fields["_feedback_{$meta_key}"] ) )
						$value = $content_fields["_feedback_{$meta_key}"];
				} else {
					// The feedback content is stored as the first "half" of post_content
					$value = $feedback->post_content;
					list( $value ) = explode( '<!--more-->', $value );
					$value = trim( $value );
				}

				$contact_form_message .= sprintf(
					_x( '%1$s: %2$s', '%1$s = form field label, %2$s = form field value', 'jetpack' ),
					wp_kses( $field->get_attribute( 'label' ), array() ),
					wp_kses( $value, array() )
				) . '<br />';
			}
		}

		// Extra fields' prefixes start counting after all_fields
		$i = count( $content_fields['_feedback_all_fields'] ) + 1;

		// "Non-standard" fields
		if ( $field_ids['extra'] ) {
			// array indexed by field label (not field id)
			$extra_fields = get_post_meta( $feedback_id, '_feedback_extra_fields', true );

			foreach ( $field_ids['extra'] as $field_id ) {
				$field = $form->fields[$field_id];

				$label = $field->get_attribute( 'label' );
				$contact_form_message .= sprintf(
					_x( '%1$s: %2$s', '%1$s = form field label, %2$s = form field value', 'jetpack' ),
					wp_kses( $label, array() ),
					wp_kses( $extra_fields[$i . '_' . $label], array() )
				) . '<br />';

				$i++; // Increment prefix counter
			}
		}

		$contact_form_message .= "</blockquote><br /><br />";

		$r_success_message .= wp_kses( $contact_form_message, array( 'br' => array(), 'blockquote' => array() ) );

		return $r_success_message;
	}

	/**
	 * The contact-field shortcode processor
	 * We use an object method here instead of a static Grunion_Contact_Form_Field class method to parse contact-field shortcodes so that we can tie them to the contact-form object.
	 *
	 * @param array $attributes Key => Value pairs as parsed by shortcode_parse_atts()
	 * @param string|null $content The shortcode's inner content: [contact-field]$content[/contact-field]
	 * @return HTML for the contact form field
	 */
	function parse_contact_field( $attributes, $content ) {
		$field = new Grunion_Contact_Form_Field( $attributes, $content, $this );

		$field_id = $field->get_attribute( 'id' );
		if ( $field_id ) {
			$this->fields[$field_id] = $field;
		} else {
			$this->fields[] = $field;
		}

		if (
			isset( $_POST['action'] ) && 'grunion-contact-form' === $_POST['action']
		&&
			isset( $_POST['contact-form-id'] ) && $this->get_attribute( 'id' ) == $_POST['contact-form-id']
		) {
			// If we're processing a POST submission for this contact form, validate the field value so we can show errors as necessary.
			$field->validate();
		}

		// Output HTML
		return $field->render();
	}

	/**
	 * Loops through $this->fields to generate a (structured) list of field IDs
	 * @return array
	 */
	function get_field_ids() {
		$field_ids = array(
			'all'   => array(), // array of all field_ids
			'extra' => array(), // array of all non-whitelisted field IDs

			// Whitelisted "standard" field IDs:
			// 'email'    => field_id,
			// 'name'     => field_id,
			// 'url'      => field_id,
			// 'subject'  => field_id,
			// 'textarea' => field_id,
		);

		foreach ( $this->fields as $id => $field ) {
			$field_ids['all'][] = $id;

			$type = $field->get_attribute( 'type' );
			if ( isset( $field_ids[$type] ) ) {
				// This type of field is already present in our whitelist of "standard" fields for this form
				// Put it in extra
				$field_ids['extra'][] = $id;
				continue;
			}

			switch ( $type ) {
			case 'email' :
			case 'name' :
			case 'url' :
			case 'subject' :
			case 'textarea' :
				$field_ids[$type] = $id;
				break;
			default :
				// Put everything else in extra
				$field_ids['extra'][] = $id;
			}
		}

		return $field_ids;
	}

	function find_emails_to_send_to() {
		$to = $this->get_attribute( 'to' );
		$to = str_replace( ' ', '', $to );
		$emails = explode( ',', $to );

		$valid_emails = array();

		foreach ( (array) $emails as $email ) {
			if ( !is_email( $email ) ) {
				continue;
			}

			if ( function_exists( 'is_email_address_unsafe' ) && is_email_address_unsafe( $email ) ) {
				continue;
			}

			$valid_emails[] = $email;
		}

		// No one to send it to :(
		if ( ! $valid_emails ) {
			return false;
		}

		return $valid_emails;
	}

	function processing_form_with_the_id( $widget ) {
		global $post;

		// Make sure we're processing the form we think we're processing... probably a redundant check.
		if ( $widget ) {
			return 'widget-' . $widget == $_POST['contact-form-id'];
		} else {
			return $post->ID == $_POST['contact-form-id'];
		}
	}

	function akismet_vars() {
		$var_names = array( 'comment_author', 'comment_author_email', 'comment_author_url', 'contact_form_subject', 'comment_author_IP' );
		$vars = array();
		foreach ( $var_names as $var ) {
			$this->{$var} = str_replace( array( "\n", "\r" ), '', $this->{$var} );
		}
		$vars[] = $this->comment_content;
		return Grunion_Contact_Form_Akismet_Adapter::prepare_for_akismet( $vars );
	}

	function insert_feedback_post( $is_spam, $subject, $akismet_values, $all_values, $extra_values ) {
		global $post;

		// keep a copy of the feedback as a custom post type
		$feedback_time   = current_time( 'mysql' );
		$feedback_title  = "{$this->comment_author} - {$feedback_time}";
		$feedback_status = $is_spam === TRUE ? 'spam' : 'publish';

		/* We need to make sure that the post author is always zero for contact
		 * form submissions.  This prevents export/import from trying to create
		 * new users based on form submissions from people who were logged in
		 * at the time.
		 *
		 * Unfortunately wp_insert_post() tries very hard to make sure the post
		 * author gets the currently logged in user id.  That is how we ended up
		 * with this work around. */

		$plugin = Grunion_Contact_Form_Plugin::init();
		add_filter( 'wp_insert_post_data', array( $plugin, 'insert_feedback_filter' ), 10, 2 );

		return wp_insert_post( array(
			'post_date'    => addslashes( $feedback_time ),
			'post_type'    => 'feedback',
			'post_status'  => addslashes( $feedback_status ),
			'post_parent'  => (int) $post->ID,
			'post_title'   => addslashes( wp_kses( $feedback_title, array() ) ),
			'post_content' => addslashes( wp_kses( $this->comment_content . "\n<!--more-->\n" . "AUTHOR: {$this->comment_author}\nAUTHOR EMAIL: {$this->comment_author_email}\nAUTHOR URL: {$this->comment_author_url}\nSUBJECT: {$subject}\nIP: {$this->comment_author_IP}\n" . print_r( $all_values, TRUE ), array() ) ), // so that search will pick up this data
			'post_name'    => md5( $feedback_title ),
		) );

		// once insert has finished we don't need this filter any more
		remove_filter( 'wp_insert_post_data', array( $plugin, 'insert_feedback_filter' ), 10, 2 );
	}

	function insert_post_meta( $post_id, $extra_values, $akismet_values, $to, $message ) {
		update_post_meta( $post_id, '_feedback_extra_fields', $this->addslashes_deep( $extra_values ) );
		update_post_meta( $post_id, '_feedback_akismet_values', $this->addslashes_deep( $akismet_values ) );
		update_post_meta( $post_id, '_feedback_email', $this->addslashes_deep( compact( 'to', 'message' ) ) );
	}

	function build_message( $widget, $extra_values ) {
		global $post;

		$message = '';
		foreach ( array( 'author', 'author_url', 'content' ) as $postfix ) {
			if ( empty( $this->{'comment_' . $postfix} ) ) {
				continue;
			}

			$message .= $this->{'comment_' . $postfix . '_label'} . ': ' . $this->{'comment_' . $postfix} . "\n";
		}

		if ( ! empty( $extra_values ) ) {
			foreach ( $extra_values as $label => $value ) {
				$message .= preg_replace( '#^\d+_#i', '', $label ) . ': ' . trim( $value ) . "\n";
			}
		}

		$url      = $widget ? home_url( '/' ) : get_permalink( $post->ID );
		$date_time_format = _x( '%1$s \a\t %2$s', '{$date_format} \a\t {$time_format}', 'jetpack' );
		$date_time_format = sprintf( $date_time_format, get_option( 'date_format' ), get_option( 'time_format' ) );
		$time = date_i18n( $date_time_format, current_time( 'timestamp' ) );

		$message .= "\n";
		$message .= __( 'Time:', 'jetpack' ) . ' ' . $time . "\n";
		$message .= __( 'IP Address:', 'jetpack' ) . ' ' . $this->comment_author_IP . "\n";
		$message .= __( 'Contact Form URL:', 'jetpack' ) . " $url\n";

		if ( is_user_logged_in() ) {
			$message .= "\n";
			$message .= sprintf(
				__( 'Sent by a verified %s user.', 'jetpack' ),
				isset( $GLOBALS['current_site']->site_name ) && $GLOBALS['current_site']->site_name ? $GLOBALS['current_site']->site_name : '"' . get_option( 'blogname' ) . '"'
			);
		} else {
			$message .= __( 'Sent by an unverified visitor to your site.', 'jetpack' );
		}

		$message = apply_filters( 'contact_form_message', $message );
		$message = Grunion_Contact_Form_Plugin::strip_tags( $message );

		return $message;
	}

	function maybe_send_mail( $post_id, $is_spam, $message, $to, $subject ) {
		$blog_url = parse_url( site_url() );
		$from_email_addr = 'wordpress@' . $blog_url['host'];
		$reply_to_addr = $to[0];
		if ( ! empty( $this->comment_author_email ) ) {
			$reply_to_addr = $this->comment_author_email;
		}

		$headers =  'From: "' . $this->comment_author  .'" <' . $from_email_addr  . ">\r\n" .
					'Reply-To: "' . $this->comment_author . '" <' . $reply_to_addr  . ">\r\n" .
					"Content-Type: text/plain; charset=\"" . get_option('blog_charset') . "\"";

		$spam = $is_spam ? '***SPAM*** ' : '';

		if ( $is_spam !== TRUE && true === apply_filters( 'grunion_should_send_email', true, $post_id ) ) {
			wp_mail( $to, "{$spam}{$subject}", $message, $headers );
		} elseif ( true === $is_spam && apply_filters( 'grunion_still_email_spam', FALSE ) == TRUE ) { // don't send spam by default.  Filterable.
			wp_mail( $to, "{$spam}{$subject}", $message, $headers );
		}
	}

	/**
	 * Get a running number prefixed assoc array with field label names and
	 * filled in values
	 */
	function get_label_value_pairs( $field_ids, $i ) {
		$values = array();
		foreach ( $field_ids as $field_id ) {
			$field = $this->fields[$field_id];
			$label = $i . '_' . $field->get_attribute( 'label' );
			$value = $field->value;

			$values[$label] = $value;
			$i++; // Increment prefix counter for the next extra field
		}

		return $values;
	}

	/**
	 * Process the contact form's POST submission
	 * Stores feedback.  Sends email.
	 */
	function process_submission() {
		global $post;

		$plugin = Grunion_Contact_Form_Plugin::init();

		$id     = $this->get_attribute( 'id' );
		$widget = $this->get_attribute( 'widget' );

		$this->contact_form_subject = $this->get_attribute( 'subject' );

		$to = $this->find_emails_to_send_to();

		// No one to send it to :( or we're not processing the form we think we're processing (... probably a redundant check)
		if ( ! $to || ! $this->processing_form_with_the_id( $widget ) ) {
			return false;
		}

		$field_ids = $this->get_field_ids();

		// May overwrite subject if it is exposed as a field
		$this->initialize_standard_field_values( $field_ids );

		// Trim subject regardless of being user specified or admin specified
		$this->contact_form_subject = trim( $this->contact_form_subject );

		// For the "standard" and "non-standard" fields, grab label and value
		// Extra fields have their prefix starting from count( $all_values ) + 1
		$all_values = $this->get_label_value_pairs( $field_ids['all'], 1 );
		$extra_values = $this->get_label_value_pairs( $field_ids['extra'], count( $all_values ) + 1 );

		$akismet_values = $this->akismet_vars( $this->contact_form_subject );

		// Is it spam?
		$akismet_result = apply_filters( 'contact_form_is_spam', $akismet_values );
		if ( is_wp_error( $akismet_result ) ) { // WP_Error to abort
			return $akismet_result; // abort
		}

		$is_spam = $akismet_result === true;

		// All the emails that should receive the feedback notification
		$to = (array) apply_filters( 'contact_form_to', $to );
		array_walk( $to, array( 'Grunion_Contact_Form_Plugin', 'strip_tags' ) );

		// Apply any filters to modify the subject, including token replacement
		$subject = apply_filters( 'contact_form_subject', $this->contact_form_subject, $all_values );

		$message = $this->build_message( $widget, $extra_values );

		// Strip all values before saving or displaying them
		foreach ( array( 'akismet_values', 'all_values', 'extra_values' ) as $var ) {
			array_walk( $$var, array( 'Grunion_Contact_Form_Plugin', 'strip_tags' ) );
		}

		$post_id = $this->insert_feedback_post( $is_spam, $subject, $akismet_values, $all_values, $extra_values );
		$this->insert_post_meta( $post_id, $extra_values, $akismet_values, $to, $message );

		do_action( 'grunion_pre_message_sent', $post_id, $all_values, $extra_values );

		$this->maybe_delete_old_spam();

		$this->maybe_send_mail( $post_id, $is_spam, $message, $to, $subject );

		return $this->render_result( $id, $post_id );
	}

	function maybe_delete_old_spam() {
		// schedule deletes of old spam feedbacks
		if ( ! wp_next_scheduled( 'grunion_scheduled_delete' ) ) {
			wp_schedule_event( time() + 250, 'daily', 'grunion_scheduled_delete' );
		}
	}

	function redirect_to_show_submission_result( $id, $post_id ) {
		$redirect = wp_get_referer();
		if ( !$redirect ) { // wp_get_referer() returns false if the referer is the same as the current page
			$redirect = $_SERVER['REQUEST_URI'];
		}

		$redirect = add_query_arg( urlencode_deep( array(
			'contact-form-id'   => $id,
			'contact-form-sent' => $post_id,
			'_wpnonce'          => wp_create_nonce( "contact-form-sent-{$post_id}" ), // wp_nonce_url HTMLencodes :(
		) ), $redirect );

		$redirect = apply_filters( 'grunion_contact_form_redirect_url', $redirect, $id, $post_id );

		wp_safe_redirect( $redirect );
	}

	function render_result( $id, $post_id ) {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return self::success_message( $post_id, $this );
		}

		$this->redirect_to_show_submission_result( $id, $post_id );
		exit;
	}

	function addslashes_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'addslashes_deep' ), $value );
		} elseif ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			foreach ( $vars as $key => $data ) {
				$value->{$key} = $this->addslashes_deep( $data );
			}
			return $value;
		}

		return addslashes( $value );
	}
}

?>