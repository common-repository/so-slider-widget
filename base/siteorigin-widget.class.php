<?php

/**
 * Class SiteOrigin_Widget
 *
 * @author Greg Priday
 */
abstract class SiteOrigin_Widget extends WP_Widget {
	protected $form_options;
	protected $base_folder;
	protected $repeater_html;

	/**
	 * @var int How many seconds a CSS file is valid for.
	 */
	static $css_expire = 604800; // 7 days

	function __construct($id, $name, $widget_options = array(), $control_options = array(), $form_options = array(), $base_folder = false) {
		$this->form_options = $form_options;
		$this->base_folder = $base_folder;
		$this->repeater_html = array();
		parent::WP_Widget($id, $name, $widget_options, $control_options);

		$this->initialize();
	}

	/**
	 * Get the form options and allow child widgets to modify that form.
	 *
	 * @return mixed
	 */
	function form_options(){
		return $this->modify_form( $this->form_options );
	}

	/**
	 * Display the widget.
	 *
	 * @param array $args
	 * @param array $instance
	 */
	public function widget( $args, $instance ) {
		$instance = $this->modify_instance($instance);

		$args = wp_parse_args( $args, array(
			'before_widget' => '',
			'after_widget' => '',
			'before_title' => '',
			'after_title' => '',
		) );

		$style = $this->get_style_name( $instance );

		// Add any missing default values to the instance
		$instance = $this->add_defaults($this->form_options, $instance);

		$upload_dir = wp_upload_dir();
		$this->clear_file_cache();

		if($style !== false) {
			$hash = $this->get_style_hash($instance);
			$css_name = $this->id_base.'-'.$style.'-'.$hash;

			if( isset( $instance['is_preview'] ) && $instance['is_preview'] ) {
				siteorigin_widget_add_inline_css( $this->get_instance_css( $instance ) );
			}
			else {
				if( !file_exists( $upload_dir['basedir'] . '/siteorigin-widgets/' . $css_name .'.css' ) || ( defined('SITEORIGIN_WIDGETS_DEBUG') && SITEORIGIN_WIDGETS_DEBUG ) ) {
					// Attempt to recreate the CSS
					$this->save_css( $instance );
				}

				if( file_exists( $upload_dir['basedir'] . '/siteorigin-widgets/' . $css_name .'.css' ) ) {
					wp_enqueue_style(
						$css_name,
						$upload_dir['baseurl'] . '/siteorigin-widgets/' . $css_name .'.css'
					);
				}
				else {
					// Fall back to using inline CSS if we can't find the cached CSS file.
					siteorigin_widget_add_inline_css( $this->get_instance_css( $instance ) );
				}
			}
		}
		else {
			$css_name = $this->id_base.'-base';
		}


		$this->enqueue_frontend_scripts();

		echo $args['before_widget'];
		echo '<div class="so-widget-'.$this->id_base.' so-widget-'.$css_name.'">';
		@ include siteorigin_widget_get_plugin_dir_path( $this->id_base ) . '/tpl/' . $this->get_template_name($instance) . '.php';
		echo '</div>';
		echo $args['after_widget'];
	}

	public function sub_widget($class, $args, $instance){
		if(!class_exists($class)) return;
		$widget = new $class;

		$args['before_widget'] = '';
		$args['after_widget'] = '';

		$widget->widget($args, $instance);
	}

	/**
	 * Add default values to the instance.
	 *
	 * @param $form
	 * @param $instance
	 */
	function add_defaults($form, $instance){

		foreach($form as $id => $field) {

			if($field['type'] == 'repeater' && !empty($instance[$id]) ) {

				for($i = 0; $i < count($instance[$id]); $i++) {
					$instance[$id][$i] = $this->add_defaults( $field['fields'], $instance[$id][$i] );
				}

			}
			else {
				if( !isset($instance[$id]) && isset($field['default']) ) $instance[$id] = $field['default'];
			}
		}

		return $instance;
	}

	/**
	 * Display the widget form.
	 *
	 * @param array $instance
	 * @return string|void
	 */
	public function form( $instance ) {
		static $enqueued = false;
		if( empty( $enqueued ) ){
			$this->enqueue_scripts();
			$enqueued = true;
		}

		$instance = $this->modify_instance($instance);

		$form_id = 'siteorigin_widget_form_'.md5( uniqid( rand(), true ) );
		$class_name = str_replace('_', '-', strtolower(get_class($this)));

		?>
		<div class="siteorigin-widget-form siteorigin-widget-form-main siteorigin-widget-form-main-<?php echo esc_attr($class_name) ?>" id="<?php echo $form_id ?>" data-class="<?php echo get_class($this) ?>">
			<?php
			foreach( $this->form_options() as $field_name => $field) {

				$value = false;
				if( isset($instance[$field_name]) ) $value = $instance[$field_name];
				elseif( isset( $field['default'] ) ) $value = $field['default'];

				$this->render_field(
					$field_name,
					$field,
					$value,
					false
				);
			}
			?>
		</div>

		<?php if( !empty( $this->widget_options['help'] ) ) : ?>
			<a href="<?php echo esc_url($this->widget_options['help']) ?>" class="siteorigin-widget-help-link siteorigin-panels-help-link" target="_blank"><?php _e('Help', 'siteorigin-widgets') ?></a>
		<?php endif; ?>

		<script type="text/javascript">
			( function($){
				if(typeof window.sow_repeater_html == 'undefined') window.sow_repeater_html = {};
				window.sow_repeater_html["<?php echo get_class($this) ?>"] = <?php echo json_encode($this->repeater_html) ?>;
				if(typeof $.fn.sowSetupForm != 'undefined') {
					$('#<?php echo $form_id ?>').sowSetupForm();
				}
				if( !$('#siteorigin-widget-admin-css').length && $.isReady ) {
					alert('<?php esc_attr_e('Please refresh this page to start using this widget.', 'siteorigin-widgets') ?>')
				}
			} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Enqueue the admin scripts for the widget form.
	 */
	function enqueue_scripts(){

		if( !wp_script_is('siteorigin-widget-admin') ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style( 'siteorigin-widget-admin', plugin_dir_url(SITEORIGIN_WIDGETS_BASE_PARENT_FILE).'base/css/admin.css', array( 'media-views' ), SITEORIGIN_WIDGETS_BASE_VERSION );


			wp_enqueue_script( 'wp-color-picker' );
			wp_enqueue_media();
			wp_enqueue_script( 'siteorigin-widget-admin', plugin_dir_url(SITEORIGIN_WIDGETS_BASE_PARENT_FILE) . 'base/js/admin.min.js', array( 'jquery', 'jquery-ui-sortable', 'editor' ), SITEORIGIN_WIDGETS_BASE_VERSION, true );

			wp_localize_script( 'siteorigin-widget-admin', 'soWidgets', array(
				'sure' => __('Are you sure?', 'siteorigin-widgets')
			) );
		}

		if( !wp_script_is('siteorigin-widget-admin-posts-selector') && $this->using_posts_selector() ) {

			wp_enqueue_script( 'siteorigin-widget-admin-posts-selector', plugin_dir_url(SITEORIGIN_WIDGETS_BASE_PARENT_FILE) . 'base/js/posts-selector.min.js', array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-autocomplete', 'underscore', 'backbone' ), SITEORIGIN_WIDGETS_BASE_VERSION, true );

			wp_localize_script( 'siteorigin-widget-admin-posts-selector', 'sowPostsSelectorTpl', array(
				'modal' => file_get_contents( plugin_dir_path(__FILE__).'tpl/posts-selector/modal.html' ),
				'postSummary' => file_get_contents( plugin_dir_path(__FILE__).'tpl/posts-selector/post.html' ),
				'foundPosts' => '<div class="sow-post-count-message">' . sprintf( __('This query returns <a href="#" class="preview-query-posts">%s posts</a>.', 'siteorigin-widgets'), '<%= foundPosts %>') . '</div>',
				'fields' => siteorigin_widget_post_selector_form_fields(),
				'selector' => file_get_contents( plugin_dir_path(__FILE__).'tpl/posts-selector/selector.html' ),
			) );

			wp_localize_script( 'siteorigin-widget-admin-posts-selector', 'sowPostsSelectorVars', array(
				'modalTitle' => __('Select Posts', 'siteorigin-widgets'),
			) );
		}

		$this->enqueue_admin_scripts();
	}

	/**
	 * Checks if the current widget is using a posts selector
	 *
	 * @return bool
	 */
	function using_posts_selector(){
		foreach($this->form_options as $field) {
			if(!empty($field['type']) && $field['type'] == 'posts') return true;
		}
		return false;
	}

	/**
	 * Update the widget instance.
	 *
	 * @param array $new_instance
	 * @param array $old_instance
	 * @return array|void
	 */
	public function update( $new_instance, $old_instance ) {
		if( !class_exists('SiteOrigin_Widgets_Color_Object') ) require plugin_dir_path( __FILE__ ).'inc/color.php';
		$new_instance = $this->sanitize( $new_instance, $this->form_options() );
		$this->save_css($new_instance);
		return $new_instance;
	}

	/**
	 * Save the CSS to the filesystem
	 *
	 * @param $instance
	 * @return bool|string
	 */
	public function save_css( $instance ){
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if( WP_Filesystem() ) {
			global $wp_filesystem;
			$upload_dir = wp_upload_dir();

			if( !$wp_filesystem->is_dir( $upload_dir['basedir'] . '/siteorigin-widgets/' ) ) {
				$wp_filesystem->mkdir( $upload_dir['basedir'] . '/siteorigin-widgets/' );
			}

			$style = $this->get_style_name($instance);
			$hash = $this->get_style_hash( $instance );

			$name = $this->id_base.'-'.$style.'-'.$hash.'.css';

			$css = $this->get_instance_css($instance);

			if( !empty($css) ) {
				$wp_filesystem->delete($upload_dir['basedir'] . '/siteorigin-widgets/'.$name);
				$wp_filesystem->put_contents(
					$upload_dir['basedir'] . '/siteorigin-widgets/'.$name,
					$css
				);
			}

			return $hash;
		}
		else {
			return false;
		}
	}

	/**
	 * Clear all old CSS files
	 *
	 * @var bool $force Must we force a cache refresh.
	 */
	public static function clear_file_cache( $force_delete = false ){
		// Use this variable to ensure this only runs once
		static $done = false;
		if ( $done && !$force_delete ) return;

		if( !get_transient('sow:cleared') || $force_delete ) {

			require_once ABSPATH . 'wp-admin/includes/file.php';
			if( WP_Filesystem() ) {
				global $wp_filesystem;
				$upload_dir = wp_upload_dir();

				$list = $wp_filesystem->dirlist( $upload_dir['basedir'] . '/siteorigin-widgets/' );
				foreach($list as $file) {
					if( $file['lastmodunix'] < time() - self::$css_expire || $force_delete ) {
						// Delete the file
						$wp_filesystem->delete( $upload_dir['basedir'] . '/siteorigin-widgets/' . $file['name'] );
					}
				}
			}

			set_transient('sow:cleared', true, self::$css_expire);
		}

		$done = true;
	}

	/**
	 * Generate the CSS for the widget.
	 *
	 * @param $instance
	 * @return string
	 */
	public function get_instance_css( $instance ){
		if( !class_exists('lessc') ) require plugin_dir_path( __FILE__ ).'inc/lessc.inc.php';

		$style_name = $this->get_style_name($instance);
		if( empty($style_name) ) return '';

		$less = file_get_contents( siteorigin_widget_get_plugin_dir_path( $this->id_base ).'styles/'.$style_name . '.less' );

		// Substitute the variables
		if( !class_exists('SiteOrigin_Widgets_Color_Object') ) require plugin_dir_path( __FILE__ ) . 'inc/color.php';
		$vars = $this->get_less_variables($instance);
		if( !empty( $vars ) ){
			foreach($vars as $name => $value) {
				if(empty($value)) continue;
				$less = preg_replace('/\@'.preg_quote($name).' *\:.*?;/', '@'.$name.': '.$value.';', $less);
			}
		}

		$mixins = file_get_contents( plugin_dir_path(__FILE__).'less/mixins.less' );
		$less = str_replace('@import "../base/less/mixins";', $mixins, $less);


		$style = $this->get_style_name( $instance );
		$hash = $this->get_style_hash( $instance );
		$css_name = $this->id_base . '-' . $style . '-' . $hash;

		$less = '.so-widget-'.$css_name.' { '.$less.' } ';

		$c = new lessc();
		return $c->compile($less);
	}

	/**
	 * @param $instance
	 * @param $fields
	 */
	public function sanitize( $instance, $fields ) {

		foreach($fields as $name => $field) {
			if(empty($instance[$name])) $instance[$name] = false;
			elseif($field['type'] == 'select') {
				$keys = array_keys( $field['options'] );
				if( !in_array( $instance[$name], $keys ) ) $instance[$name] = isset($field['default']) ? $field['default'] : false;
			}
			elseif($field['type'] == 'number') {
				$instance[$name] = (float) $instance[$name];
			}
			elseif($field['type'] == 'repeater') {
				foreach($instance[$name] as $i => $sub_instance) {
					$instance[$name][$i] = $this->sanitize($sub_instance, $field['fields']);
				}
			}
			elseif($field['type'] == 'widget') {

				if( !empty($field['class']) && class_exists($field['class']) ) {
					$the_widget = new $field['class'];

					if( is_a($the_widget, 'SiteOrigin_Widget') ) {
						$instance[$name] = $the_widget->update($instance[$name], $instance[$name]);
					}
				}
			}
			elseif($field['type'] == 'section') {
				$instance[$name] = $this->sanitize($instance[$name], $field['fields']);
			}

			if( isset($field['sanitize']) ) {
				switch($field['sanitize']) {
					case 'url':
						$instance[$name] = esc_url_raw($instance[$name]);
						break;
				}
			}
		}

		return $instance;
	}

	/**
	 * @param $field_name
	 * @param array $repeater
	 * @param string $repeater_append
	 * @return mixed|string
	 */
	public function so_get_field_name($field_name, $repeater = array(), $repeater_append = '[]') {
		if( empty($repeater) ) return $this->get_field_name($field_name);
		else {

			$repeater_extras = '';
			foreach($repeater as $r) {
				$repeater_extras .= '['.$r.'][#'.$r.'#]';
			}

			$name = $this->get_field_name('{{{FIELD_NAME}}}');
			$name = str_replace('[{{{FIELD_NAME}}}]', $repeater_extras.'['.esc_attr($field_name).']', $name);
			return $name;
		}
	}

	/**
	 * Get the ID of this field.
	 *
	 * @param $field_name
	 * @param array $repeater
	 *
	 * @return string
	 */
	public function so_get_field_id($field_name, $repeater = array()) {
		if( empty($repeater) ) return $this->get_field_id($field_name);
		else {
			$name = $repeater;
			$name[] = $field_name;
			return $this->get_field_id(implode('-', $name));
		}
	}

	/**
	 * Render a form field
	 *
	 * @param $name
	 * @param $field
	 * @param $value
	 * @param array $repeater
	 */
	function render_field( $name, $field, $value, $repeater = array() ){
		?><div class="siteorigin-widget-field siteorigin-widget-field-type-<?php echo sanitize_html_class($field['type']) ?> siteorigin-widget-field-<?php echo sanitize_html_class($name) ?>"><?php

		if($field['type'] != 'repeater' && $field['type'] != 'checkbox' && $field['type'] != 'separator') {
			?><label for="<?php echo $this->so_get_field_id($name, $repeater) ?>"><?php echo $field['label'] ?></label><?php
		}

		switch( $field['type'] ) {
			case 'text' :
				?><input type="text" name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" value="<?php echo esc_attr($value) ?>" class="widefat siteorigin-widget-input" /><?php
				break;

			case 'color' :
				?><input type="text" name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" value="<?php echo esc_attr($value) ?>" class="widefat siteorigin-widget-input siteorigin-widget-input-color" /><?php
				break;

			case 'number' :
				?><input type="text" name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" value="<?php echo esc_attr($value) ?>" class="widefat siteorigin-widget-input siteorigin-widget-input-number" /><?php
				break;

			case 'textarea' :
				?><textarea type="text" name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" class="widefat siteorigin-widget-input" rows="<?php echo !empty($field['rows']) ? intval($field['rows']) : 4 ?>"><?php echo esc_textarea($value) ?></textarea><?php
				break;

			case 'editor' :
				?><textarea type="text" name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" class="widefat siteorigin-widget-input siteorigin-widget-input-editor" rows="<?php echo !empty($field['rows']) ? intval($field['rows']) : 4 ?>"><?php echo esc_textarea($value) ?></textarea><?php
				break;

			case 'select':
				?>
				<select name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" class="siteorigin-widget-input">
					<?php foreach( $field['options'] as $v => $t ) : ?>
						<option value="<?php echo esc_attr($v) ?>" <?php selected($v, $value) ?>><?php echo esc_html($t) ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'checkbox':
				?>
				<label for="<?php echo $this->so_get_field_id($name, $repeater) ?>">
					<input type="checkbox" name="<?php echo $this->so_get_field_name($name, $repeater) ?>" id="<?php echo $this->so_get_field_id($name, $repeater) ?>" class="siteorigin-widget-input" <?php checked( !empty( $value ) ) ?> />
					<?php echo $field['label'] ?>
				</label>
				<?php
				break;

			case 'media':
				if( version_compare( get_bloginfo('version'), '3.5', '<' ) ){
					printf(__('You need to <a href="%s">upgrade</a> to WordPress 3.5 to use media fields', 'siteorigin'), admin_url('update-core.php'));
					break;
				}

				if(!empty($value)) {
					if(is_array($value)) {
						$src = $value;
					}
					else {
						$post = get_post($value);
						$src = wp_get_attachment_image_src($value, 'thumbnail');
						if(empty($src)) $src = wp_get_attachment_image_src($value, 'thumbnail', true);
					}
				}
				else{
					$src = array('', 0, 0);
				}

				$choose_title = empty($args['choose']) ? __('Choose Media', 'siteorigin-widgets') : $args['choose'];
				$update_button = empty($args['update']) ? __('Set Media', 'siteorigin-widgets') : $args['update'];
				$library = empty($field['library']) ? 'image' : $field['library'];

				?>
				<div class="media-field-wrapper">
					<div class="current">
						<div class="thumbnail-wrapper">
							<img src="<?php echo esc_url( $src[0] ) ?>" class="thumbnail" <?php if( empty( $src[0] ) ) echo "style='display:none'" ?> />
						</div>
						<div class="title"><?php if( !empty( $post ) ) echo esc_attr( $post->post_title ) ?></div>
					</div>
					<a href="#" class="media-upload-button" data-choose="<?php echo esc_attr($choose_title) ?>" data-update="<?php echo esc_attr( $update_button ) ?>" data-library="<?php echo esc_attr($library) ?>">
						<?php echo esc_html($choose_title) ?>
					</a>

					<a href="#" class="media-remove-button"><?php _e('Remove', 'siteorigin') ?></a>
				</div>

				<input type="hidden" value="<?php echo esc_attr( is_array( $value ) ? '-1' : $value ) ?>" name="<?php echo $this->so_get_field_name( $name, $repeater ) ?>" class="siteorigin-widget-input" />
				<div class="clear"></div>
				<?php
				break;

			case 'posts' :
				?>
				<input type="hidden" value="<?php echo esc_attr( is_array( $value ) ? '' : $value ) ?>" name="<?php echo $this->so_get_field_name( $name, $repeater ) ?>" class="siteorigin-widget-input" />
				<a href="#" class="sow-select-posts button button-secondary">
					<span class="sow-current-count"><?php echo siteorigin_widget_post_selector_count_posts( is_array( $value ) ? '' : $value ) ?></span>
					<?php _e('Build Posts Query') ?>
				</a>
				<?php
				break;

			case 'repeater':
				ob_start();
				$repeater[] = $name;
				foreach($field['fields'] as $sub_field_name => $sub_field) {
					$this->render_field(
						$sub_field_name,
						$sub_field,
						isset($value[$sub_field_name]) ? $value[$sub_field_name] : false,
						$repeater
					);
				}
				$html = ob_get_clean();

				$this->repeater_html[$name] = $html;

				?>
				<div class="siteorigin-widget-field-repeater" data-item-name="<?php echo esc_attr( $field['item_name'] ) ?>" data-repeater-name="<?php echo esc_attr($name) ?>">
					<div class="siteorigin-widget-field-repeater-top">
						<div class="siteorigin-widget-field-repeater-expend"></div>
						<h3><?php echo $field['label'] ?></h3>
					</div>
					<div class="siteorigin-widget-field-repeater-items">
						<?php
						if( !empty( $value ) ) {
							foreach( $value as $v ) {
								?>
								<div class="siteorigin-widget-field-repeater-item">
									<div class="siteorigin-widget-field-repeater-item-top">
										<div class="siteorigin-widget-field-expand"></div>
										<div class="siteorigin-widget-field-remove"></div>
										<h4><?php echo esc_html($field['item_name']) ?></h4>
									</div>
									<div class="siteorigin-widget-field-repeater-item-form">
										<?php
										foreach($field['fields'] as $sub_field_name => $sub_field) {
											$this->render_field(
												$sub_field_name,
												$sub_field,
												isset($v[$sub_field_name]) ? $v[$sub_field_name] : false,
												$repeater
											);
										}
										?>
									</div>
								</div>
								<?php
							}
						}
						?>
					</div>
					<div class="siteorigin-widget-field-repeater-add"><?php _e('Add', 'siteorigin-widgets') ?></div>
				</div>
				<?php
				break;

			case 'widget' :
				// Create the extra form entries
				$sub_widget = new $field['class'];
				?><div class="siteorigin-widget-section <?php if( !empty($field['hide']) ) echo 'siteorigin-widget-section-hide'; ?>"><?php
				foreach( $sub_widget->form_options() as $sub_name => $sub_field) {

					if( isset($value[$sub_name]) ) $sub_value  = $value[$sub_name];
					elseif( isset($sub_field['default']) ) $sub_value  = $sub_field['default'];
					else $sub_value = false;

					$this->render_field(
						$name.']['.$sub_name,
						$sub_field,
						$sub_value,
						$repeater
					);
				}
				?></div><?php
				break;

			case 'icon':
				static $widget_icon_families;
				if( empty($widget_icon_families) ) $widget_icon_families = apply_filters('siteorigin_widgets_icon_families', array() );

				list($value_family, $null) = !empty($value) ? explode('-', $value, 2) : array('fontawesome', '');

				?>
				<div class="siteorigin-widget-icon-selector siteorigin-widget-field-subcontainer">
					<select class="siteorigin-widget-icon-family" >
						<?php foreach( $widget_icon_families as $family_id => $family_info ) : ?>
							<option value="<?php echo esc_attr($family_id) ?>" <?php selected($value_family, $family_id) ?>><?php echo esc_html( $family_info['name'] ) ?> (<?php echo count( $family_info['icons'] ) ?>)</option>
						<?php endforeach; ?>
					</select>

					<input type="hidden" name="<?php echo $this->so_get_field_name( $name, $repeater ) ?>" value="<?php echo esc_attr($value) ?>" class="siteorigin-widget-icon-icon siteorigin-widget-input" />

					<div class="siteorigin-widget-icon-icons"></div>
				</div>
				<?php

				break;

			case 'section' :
				?><div class="siteorigin-widget-section <?php if( !empty($field['hide']) ) echo 'siteorigin-widget-section-hide'; ?>"><?php
				foreach( (array) $field['fields'] as $sub_name=> $sub_field ) {
					if( isset($value[$sub_name]) ) $sub_value  = $value[$sub_name];
					elseif( isset($sub_field['default']) ) $sub_value  = $sub_field['default'];
					else $sub_value = false;

					$this->render_field(
						$name.']['.$sub_name,
						$sub_field,
						$sub_value,
						$repeater
					);
				}
				?></div><?php
				break;

			default:
				?><?php _e('Unknown Field', 'siteorigin-widgets') ?><?php
				break;

		}

		if(!empty($field['description'])) {
			?><div class="siteorigin-widget-field-description"><?php echo esc_html($field['description']) ?></div><?php
		}

		?></div><?php
	}

	/**
	 * Parse markdown
	 *
	 * @param $markdown
	 * @return string The HTML
	 */
	function parse_markdown($markdown){
		if( !class_exists('Markdown_Parser') ) include plugin_dir_path(__FILE__).'inc/markdown.php';
		$parser = new Markdown_Parser();

		return $parser->transform($markdown);
	}

	/**
	 * Get a hash that makes the design unique
	 *
	 * @param $instance
	 * @return string
	 */
	function get_style_hash($instance) {
		return substr( md5( serialize( $this->get_less_variables( $instance ) ) ), 0, 12 );
	}

	/**
	 * Get the template name that we'll be using to render this widget.
	 *
	 * @param $instance
	 * @return mixed
	 */
	abstract function get_template_name($instance);

	/**
	 * Get the template name that we'll be using to render this widget.
	 *
	 * @param $instance
	 * @return mixed
	 */
	abstract function get_style_name($instance);

	/**
	 * Get any variables that need to be substituted by
	 *
	 * @param $instance
	 * @return array
	 */
	function get_less_variables($instance){
		return array();
	}

	/**
	 * This function can be overwritten to modify form values in the child widget.
	 *
	 * @param $form
	 * @return mixed
	 */
	function modify_form($form) {
		return $form;
	}

	/**
	 * This function should be overwritten by child widgets to filter an instance. Run before rendering form and widget.
	 *
	 * @param $instance
	 *
	 * @return mixed
	 */
	function modify_instance( $instance ){
		return $instance;
	}

	/**
	 * Can be overwritten by child themes to enqueue scripts and styles for the frontend
	 */
	function enqueue_frontend_scripts(){ }

	/**
	 * Can be overwritten by child widgets to enqueue admin scripts and styles if necessary.
	 */
	function enqueue_admin_scripts(){ }

	/**
	 * Initialize this widget in whatever way we need to. Run before rendering widget or form.
	 */
	function initialize(){ }
}