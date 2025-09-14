<?php
/*
Plugin Name: Wedding RSVP & Guest Database
Plugin URI:  https://example.com/
Description: RSVP form + guest DB. Shortcode + Elementor widget + admin guest management + SMTP settings + email confirmations.
Version:     1.3
Author:      Your Name
Text Domain: wedding-rsvp
*/

if (!defined('ABSPATH')) exit;

define('WPRSVP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPRSVP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPRSVP_VERSION','1.3');

/* -------- Activation: create table -------- */
register_activation_hook(__FILE__, 'wprsvp_activate');
function wprsvp_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wedding_guests';
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(191) DEFAULT NULL,
        partner_first_name VARCHAR(100) DEFAULT NULL,
        partner_last_name VARCHAR(100) DEFAULT NULL,
        guest_meal VARCHAR(100) DEFAULT NULL,
        partner_meal VARCHAR(100) DEFAULT NULL,
        rsvp_status VARCHAR(20) DEFAULT NULL,
        partner_rsvp_status VARCHAR(20) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY name_idx (first_name, last_name)
    ) $charset_collate;";

    dbDelta($sql);
}

/* -------- Enqueue scripts + styles -------- */
add_action('wp_enqueue_scripts','wprsvp_enqueue_scripts');
function wprsvp_enqueue_scripts(){
    wp_enqueue_script('wprsvp-js', WPRSVP_PLUGIN_URL . 'assets/js/rsvp-form.js', array(), WPRSVP_VERSION, true);
    wp_localize_script('wprsvp-js', 'wprsvp_vars', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wprsvp_nonce')
    ));
    wp_enqueue_style('wprsvp-css', WPRSVP_PLUGIN_URL . 'assets/css/rsvp-style.css', array(), WPRSVP_VERSION);
}

/* -------- Shortcode -------- */
add_shortcode('wedding_rsvp_form','wprsvp_render_form');
function wprsvp_render_form($atts){
    ob_start();
    ?>
    <div id="wprsvp-wrap" class="wprsvp-wrap">
      <form id="wprsvp-initial" class="wprsvp-form">
        <h3>Your RSVP</h3>
        <label>First name<br><input id="wprsvp-first" name="first_name" required></label>
        <label>Last name<br><input id="wprsvp-last" name="last_name" required></label>
        <button id="wprsvp-find">Find me</button>
      </form>

      <form id="wprsvp-full" class="wprsvp-form" style="display:none; margin-top:1rem;">
        <input type="hidden" id="wprsvp-id" name="guest_id" value="">
        <label>Email (optional)<br><input id="wprsvp-email" name="email" type="email"></label>
        <label>Your meal preference<br>
          <select id="wprsvp-meal" name="guest_meal">
            <option value="">-- choose --</option>
            <option value="meat">Meat</option>
            <option value="fish">Fish</option>
            <option value="veg">Vegetarian</option>
            <option value="vegan">Vegan</option>
          </select>
        </label>
        <label>Your RSVP<br>
          <select id="wprsvp-rsvp" name="rsvp_status" required>
            <option value="">-- choose --</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
            <option value="maybe">Maybe</option>
          </select>
        </label>

        <div id="wprsvp-partner-block" style="display:none; border-left:2px solid #eee; padding-left:1rem; margin-top:1rem;">
          <h4>Partner</h4>
          <label>Partner first name<br><input id="wprsvp-partner-first" name="partner_first_name"></label>
          <label>Partner last name<br><input id="wprsvp-partner-last" name="partner_last_name"></label>
          <label>Partner meal preference<br>
            <select id="wprsvp-partner-meal" name="partner_meal">
              <option value="">-- choose --</option>
              <option value="meat">Meat</option>
              <option value="fish">Fish</option>
              <option value="veg">Vegetarian</option>
              <option value="vegan">Vegan</option>
            </select>
          </label>
          <label>Partner RSVP<br>
            <select id="wprsvp-partner-rsvp" name="partner_rsvp_status">
              <option value="">-- choose --</option>
              <option value="yes">Yes</option>
              <option value="no">No</option>
              <option value="maybe">Maybe</option>
            </select>
          </label>
        </div>

        <label style="display:block; margin-top:1rem;">
          <textarea name="notes" placeholder="Optional note (dietary, message...)" rows="3" style="width:100%;"></textarea>
        </label>

        <button id="wprsvp-submit">Submit RSVP</button>
      </form>

      <div id="wprsvp-message" style="display:none; margin-top:1rem;"></div>
    </div>
    <?php
    return ob_get_clean();
}

/* -------- AJAX: search -------- */
add_action('wp_ajax_nopriv_wprsvp_search', 'wprsvp_search');
add_action('wp_ajax_wprsvp_search', 'wprsvp_search');
function wprsvp_search(){
    check_ajax_referer('wprsvp_nonce','nonce');
    $first = sanitize_text_field($_POST['first_name'] ?? '');
    $last  = sanitize_text_field($_POST['last_name'] ?? '');
    if (empty($first) || empty($last)) wp_send_json_error(array('message'=>'Missing name'));

    global $wpdb;
    $table = $wpdb->prefix . 'wedding_guests';
    $sql = $wpdb->prepare("SELECT * FROM $table WHERE LOWER(first_name)=LOWER(%s) AND LOWER(last_name)=LOWER(%s) LIMIT 1", $first, $last);
    $guest = $wpdb->get_row($sql, ARRAY_A);

    if ($guest) wp_send_json_success(array('found'=>true, 'guest'=>$guest));
    else wp_send_json_success(array('found'=>false));
}

/* -------- AJAX: submit -------- */
add_action('wp_ajax_nopriv_wprsvp_submit', 'wprsvp_submit');
add_action('wp_ajax_wprsvp_submit', 'wprsvp_submit');
function wprsvp_submit(){
    check_ajax_referer('wprsvp_nonce','nonce');
    global $wpdb;
    $table = $wpdb->prefix . 'wedding_guests';

    $guest_id = intval($_POST['guest_id'] ?? 0);
    $first = sanitize_text_field($_POST['first_name'] ?? '');
    $last  = sanitize_text_field($_POST['last_name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $guest_meal = sanitize_text_field($_POST['guest_meal'] ?? '');
    $partner_first = sanitize_text_field($_POST['partner_first_name'] ?? '');
    $partner_last  = sanitize_text_field($_POST['partner_last_name'] ?? '');
    $partner_meal  = sanitize_text_field($_POST['partner_meal'] ?? '');
    $rsvp = sanitize_text_field($_POST['rsvp_status'] ?? '');
    $partner_rsvp = sanitize_text_field($_POST['partner_rsvp_status'] ?? '');
    $notes = sanitize_textarea_field($_POST['notes'] ?? '');

    if (empty($first) || empty($last)) wp_send_json_error(array('message'=>'First and last name required'));

    // helper to send confirmation
    $send_confirmation = function($email, $first, $rsvp, $guest_meal, $partner_first, $partner_last, $partner_rsvp, $partner_meal){
        if (!$email) return;
        $subject = "Your RSVP has been received";
        $body = "Hi $first,\n\nThank you for your RSVP.\n".
                "RSVP: $rsvp\nMeal: $guest_meal\n";
        if ($partner_first || $partner_last){
            $body .= "\nPartner: $partner_first $partner_last\nPartner RSVP: $partner_rsvp\nPartner Meal: $partner_meal\n";
        }
        $body .= "\nWe look forward to seeing you!\n\nBest regards,\nThe Wedding Team";
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        wp_mail($email, $subject, $body, $headers);
    };

    if ($guest_id){
        $wpdb->update($table, array(
            'first_name'=>$first,'last_name'=>$last,'email'=>$email,
            'guest_meal'=>$guest_meal,'partner_first_name'=>$partner_first,
            'partner_last_name'=>$partner_last,'partner_meal'=>$partner_meal,
            'rsvp_status'=>$rsvp,'partner_rsvp_status'=>$partner_rsvp,'notes'=>$notes
        ), array('id'=>$guest_id));

        $send_confirmation($email,$first,$rsvp,$guest_meal,$partner_first,$partner_last,$partner_rsvp,$partner_meal);
        wp_send_json_success(array('message'=>'RSVP updated','guest_id'=>$guest_id));
    }

    $found = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE LOWER(first_name)=LOWER(%s) AND LOWER(last_name)=LOWER(%s) LIMIT 1",$first,$last),ARRAY_A);
    if ($found){
        $wpdb->update($table, array(
            'email'=>$email,'guest_meal'=>$guest_meal,
            'partner_first_name'=>$partner_first,'partner_last_name'=>$partner_last,'partner_meal'=>$partner_meal,
            'rsvp_status'=>$rsvp,'partner_rsvp_status'=>$partner_rsvp,'notes'=>$notes
        ), array('id'=>$found['id']));
        $send_confirmation($email,$first,$rsvp,$guest_meal,$partner_first,$partner_last,$partner_rsvp,$partner_meal);
        wp_send_json_success(array('message'=>'RSVP updated (existing)','guest_id'=>$found['id']));
    }

    $wpdb->insert($table, array(
        'first_name'=>$first,'last_name'=>$last,'email'=>$email,'guest_meal'=>$guest_meal,
        'partner_first_name'=>$partner_first,'partner_last_name'=>$partner_last,'partner_meal'=>$partner_meal,
        'rsvp_status'=>$rsvp,'partner_rsvp_status'=>$partner_rsvp,'notes'=>$notes
    ));
    $new_id = $wpdb->insert_id;
    $send_confirmation($email,$first,$rsvp,$guest_meal,$partner_first,$partner_last,$partner_rsvp,$partner_meal);
    wp_send_json_success(array('message'=>'RSVP recorded','guest_id'=>$new_id));
}

/* -------- Settings: SMTP and From -------- */
add_action('admin_menu', function(){
    add_options_page('Wedding RSVP Settings', 'Wedding RSVP', 'manage_options', 'wprsvp-settings', 'wprsvp_render_settings_page');
});
function wprsvp_render_settings_page(){
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['wprsvp_save_settings'])){
        check_admin_referer('wprsvp_save_settings');
        update_option('wprsvp_smtp_host', sanitize_text_field($_POST['smtp_host']));
        update_option('wprsvp_smtp_port', intval($_POST['smtp_port']));
        update_option('wprsvp_smtp_secure', sanitize_text_field($_POST['smtp_secure']));
        update_option('wprsvp_smtp_user', sanitize_text_field($_POST['smtp_user']));
        update_option('wprsvp_smtp_pass', sanitize_text_field($_POST['smtp_pass']));
        update_option('wprsvp_from_email', sanitize_email($_POST['from_email']));
        update_option('wprsvp_from_name', sanitize_text_field($_POST['from_name']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $smtp_host   = get_option('wprsvp_smtp_host','');
    $smtp_port   = get_option('wprsvp_smtp_port','587');
    $smtp_secure = get_option('wprsvp_smtp_secure','tls');
    $smtp_user   = get_option('wprsvp_smtp_user','');
    $smtp_pass   = get_option('wprsvp_smtp_pass','');
    $from_email  = get_option('wprsvp_from_email', get_bloginfo('admin_email'));
    $from_name   = get_option('wprsvp_from_name', get_bloginfo('name'));

    ?>
    <div class="wrap">
      <h1>Wedding RSVP Settings</h1>
      <form method="post">
        <?php wp_nonce_field('wprsvp_save_settings'); ?>
        <table class="form-table">
          <tr><th>SMTP Host</th><td><input type="text" name="smtp_host" value="<?php echo esc_attr($smtp_host); ?>" style="width:400px"></td></tr>
          <tr><th>SMTP Port</th><td><input type="number" name="smtp_port" value="<?php echo esc_attr($smtp_port); ?>"></td></tr>
          <tr><th>Encryption</th><td>
              <select name="smtp_secure">
                <option value="tls" <?php selected($smtp_secure,'tls'); ?>>TLS</option>
                <option value="ssl" <?php selected($smtp_secure,'ssl'); ?>>SSL</option>
                <option value="" <?php selected($smtp_secure,''); ?>>None</option>
              </select>
          </td></tr>
          <tr><th>SMTP Username</th><td><input type="text" name="smtp_user" value="<?php echo esc_attr($smtp_user); ?>" style="width:300px"></td></tr>
          <tr><th>SMTP Password</th><td><input type="password" name="smtp_pass" value="<?php echo esc_attr($smtp_pass); ?>" style="width:300px"></td></tr>
          <tr><th>From Email</th><td><input type="email" name="from_email" value="<?php echo esc_attr($from_email); ?>"></td></tr>
          <tr><th>From Name</th><td><input type="text" name="from_name" value="<?php echo esc_attr($from_name); ?>"></td></tr>
        </table>
        <p><input type="submit" name="wprsvp_save_settings" class="button-primary" value="Save Settings"></p>
      </form>
    </div>
    <?php
}

/* -------- Apply SMTP via phpmailer_init -------- */
add_action('phpmailer_init', function($phpmailer){
    $host = get_option('wprsvp_smtp_host');
    $port = get_option('wprsvp_smtp_port');
    $secure = get_option('wprsvp_smtp_secure');
    $user = get_option('wprsvp_smtp_user');
    $pass = get_option('wprsvp_smtp_pass');
    $from_email = get_option('wprsvp_from_email');
    $from_name  = get_option('wprsvp_from_name');

    if ($host && $user && $pass){
        $phpmailer->isSMTP();
        $phpmailer->Host = $host;
        $phpmailer->Port = $port;
        if ($secure) $phpmailer->SMTPSecure = $secure;
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $user;
        $phpmailer->Password = $pass;
        if ($from_email) $phpmailer->setFrom($from_email, $from_name ? $from_name : '');
    }
});

/* -------- Admin: Guest management (Add/Edit/Delete, List) -------- */
add_action('admin_menu', function(){
    add_menu_page('Wedding Guests', 'Wedding Guests', 'manage_options', 'wprsvp-guests', 'wprsvp_render_guests_page', 'dashicons-groups', 26);
});
function wprsvp_render_guests_page(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'wedding_guests';

    // Handle Add
    if (isset($_POST['wprsvp_add_guest'])){
        check_admin_referer('wprsvp_add_guest');
        $wpdb->insert($table, array(
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'email'      => sanitize_email($_POST['email']),
            'guest_meal' => sanitize_text_field($_POST['guest_meal']),
            'rsvp_status'=> sanitize_text_field($_POST['rsvp_status']),
            'partner_first_name' => sanitize_text_field($_POST['partner_first_name']),
            'partner_last_name'  => sanitize_text_field($_POST['partner_last_name']),
            'partner_meal' => sanitize_text_field($_POST['partner_meal']),
            'partner_rsvp_status' => sanitize_text_field($_POST['partner_rsvp_status']),
            'notes' => sanitize_textarea_field($_POST['notes'])
        ));
        echo '<div class="updated"><p>Guest added.</p></div>';
    }

    // Handle Update
    if (isset($_POST['wprsvp_update_guest'])){
        check_admin_referer('wprsvp_update_guest');
        $id = intval($_POST['guest_id']);
        $wpdb->update($table, array(
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'email'      => sanitize_email($_POST['email']),
            'guest_meal' => sanitize_text_field($_POST['guest_meal']),
            'rsvp_status'=> sanitize_text_field($_POST['rsvp_status']),
            'partner_first_name' => sanitize_text_field($_POST['partner_first_name']),
            'partner_last_name'  => sanitize_text_field($_POST['partner_last_name']),
            'partner_meal' => sanitize_text_field($_POST['partner_meal']),
            'partner_rsvp_status' => sanitize_text_field($_POST['partner_rsvp_status']),
            'notes' => sanitize_textarea_field($_POST['notes'])
        ), array('id'=>$id));
        echo '<div class="updated"><p>Guest updated.</p></div>';
    }

    // Handle Delete (via GET with nonce)
    if (isset($_GET['delete']) && isset($_GET['_wpnonce'])){
        $del_id = intval($_GET['delete']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'wprsvp_delete_'.$del_id)){
            $wpdb->delete($table, array('id'=>$del_id));
            echo '<div class="updated"><p>Guest deleted.</p></div>';
        } else {
            echo '<div class="error"><p>Invalid nonce.</p></div>';
        }
    }

    // If editing, show edit form
    if (isset($_GET['action']) && $_GET['action']=='edit' && isset($_GET['id'])){
        $edit_id = intval($_GET['id']);
        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $edit_id), ARRAY_A);
        if (!$guest) { echo '<div class="error"><p>Guest not found.</p></div>'; return; }
        ?>
        <div class="wrap">
          <h1>Edit Guest</h1>
          <form method="post">
            <?php wp_nonce_field('wprsvp_update_guest'); ?>
            <input type="hidden" name="guest_id" value="<?php echo esc_attr($guest['id']); ?>">
            <table class="form-table">
              <tr><th>First name</th><td><input type="text" name="first_name" value="<?php echo esc_attr($guest['first_name']); ?>" required></td></tr>
              <tr><th>Last name</th><td><input type="text" name="last_name" value="<?php echo esc_attr($guest['last_name']); ?>" required></td></tr>
              <tr><th>Email</th><td><input type="email" name="email" value="<?php echo esc_attr($guest['email']); ?>"></td></tr>
              <tr><th>Guest meal</th><td><input type="text" name="guest_meal" value="<?php echo esc_attr($guest['guest_meal']); ?>"></td></tr>
              <tr><th>RSVP</th><td><input type="text" name="rsvp_status" value="<?php echo esc_attr($guest['rsvp_status']); ?>"></td></tr>
              <tr><th>Partner first</th><td><input type="text" name="partner_first_name" value="<?php echo esc_attr($guest['partner_first_name']); ?>"></td></tr>
              <tr><th>Partner last</th><td><input type="text" name="partner_last_name" value="<?php echo esc_attr($guest['partner_last_name']); ?>"></td></tr>
              <tr><th>Partner meal</th><td><input type="text" name="partner_meal" value="<?php echo esc_attr($guest['partner_meal']); ?>"></td></tr>
              <tr><th>Partner RSVP</th><td><input type="text" name="partner_rsvp_status" value="<?php echo esc_attr($guest['partner_rsvp_status']); ?>"></td></tr>
              <tr><th>Notes</th><td><textarea name="notes" rows="4" cols="50"><?php echo esc_textarea($guest['notes']); ?></textarea></td></tr>
            </table>
            <p><input type="submit" name="wprsvp_update_guest" class="button-primary" value="Update Guest"></p>
          </form>
        </div>
        <?php
        return;
    }

    // List + add form
    $guests = $wpdb->get_results("SELECT * FROM $table ORDER BY last_name, first_name", ARRAY_A);
    ?>
    <div class="wrap">
      <h1>Wedding Guests</h1>

      <h2>Add New Guest</h2>
      <form method="post">
        <?php wp_nonce_field('wprsvp_add_guest'); ?>
        <table class="form-table">
          <tr><th>First Name</th><td><input type="text" name="first_name" required></td></tr>
          <tr><th>Last Name</th><td><input type="text" name="last_name" required></td></tr>
          <tr><th>Email</th><td><input type="email" name="email"></td></tr>
          <tr><th>Meal</th><td><input type="text" name="guest_meal"></td></tr>
          <tr><th>RSVP</th><td><select name="rsvp_status"><option value="">--</option><option value="yes">Yes</option><option value="no">No</option><option value="maybe">Maybe</option></select></td></tr>
          <tr><th>Partner First</th><td><input type="text" name="partner_first_name"></td></tr>
          <tr><th>Partner Last</th><td><input type="text" name="partner_last_name"></td></tr>
          <tr><th>Partner Meal</th><td><input type="text" name="partner_meal"></td></tr>
          <tr><th>Partner RSVP</th><td><select name="partner_rsvp_status"><option value="">--</option><option value="yes">Yes</option><option value="no">No</option><option value="maybe">Maybe</option></select></td></tr>
          <tr><th>Notes</th><td><textarea name="notes" rows="3" cols="40"></textarea></td></tr>
        </table>
        <p><input type="submit" name="wprsvp_add_guest" class="button-primary" value="Add Guest"></p>
      </form>

      <h2>Guest List</h2>
      <table class="widefat striped">
        <thead><tr>
          <th>ID</th><th>Name</th><th>Email</th><th>Meal</th><th>RSVP</th><th>Partner</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php if ($guests) { foreach($guests as $g): ?>
            <tr>
              <td><?php echo esc_html($g['id']); ?></td>
              <td><?php echo esc_html($g['first_name'].' '.$g['last_name']); ?></td>
              <td><?php echo esc_html($g['email']); ?></td>
              <td><?php echo esc_html($g['guest_meal']); ?></td>
              <td><?php echo esc_html($g['rsvp_status']); ?></td>
              <td><?php echo esc_html($g['partner_first_name'].' '.$g['partner_last_name'].' ('. $g['partner_rsvp_status'] .')'); ?></td>
              <td>
                <a href="<?php echo esc_url(add_query_arg(array('page'=>'wprsvp-guests','action'=>'edit','id'=>$g['id']), admin_url('admin.php'))); ?>">Edit</a> | 
                <a href="<?php echo esc_url(add_query_arg(array('page'=>'wprsvp-guests','delete'=>$g['id'],'_wpnonce'=>wp_create_nonce('wprsvp_delete_'.$g['id'])), admin_url('admin.php'))); ?>" onclick="return confirm('Delete this guest?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; } else { echo '<tr><td colspan="7">No guests found.</td></tr>'; } ?>
        </tbody>
      </table>
    </div>
    <?php
}

/* -------- Elementor Widget -------- */
add_action('elementor/widgets/widgets_registered', function($widgets_manager){
    if (!class_exists('Elementor\\Widget_Base')) return;
    class WPRSVP_Elementor_Widget extends \Elementor\Widget_Base {
        public function get_name(){ return 'wprsvp_form'; }
        public function get_title(){ return 'Wedding RSVP Form'; }
        public function get_icon(){ return 'eicon-form-horizontal'; }
        public function get_categories(){ return ['general']; }
        protected function render(){ echo do_shortcode('[wedding_rsvp_form]'); }
    }
    $widgets_manager->register(new \WPRSVP_Elementor_Widget());
});
