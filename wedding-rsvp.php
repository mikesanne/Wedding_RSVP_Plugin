<?php
/*
Plugin Name: Wedding RSVP & Guest Database
Plugin URI:  https://example.com/
Description: Search/create wedding guests and collect RSVPs. Frontend shortcode + admin list + CSV export + Elementor widget + email confirmation.
Version:     1.1
Author:      Your Name
Text Domain: wedding-rsvp
*/

if (!defined('ABSPATH')) exit;

define('WPRSVP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPRSVP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPRSVP_VERSION','1.1');

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

/* -------- Admin page -------- */
add_action('admin_menu','wprsvp_admin_menu');
function wprsvp_admin_menu(){
    add_menu_page('Wedding Guests','Wedding Guests','manage_options','wprsvp-guests','wprsvp_admin_page');
}
function wprsvp_admin_page(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix.'wedding_guests';
    if (isset($_GET['action']) && $_GET['action']==='export_csv'){
        $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY last_name, first_name",ARRAY_A);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wedding-guests.csv');
        $out=fopen('php://output','w'); fputcsv($out,array_keys($rows[0])); foreach($rows as $r) fputcsv($out,$r); fclose($out); exit;
    }
    $rows=$wpdb->get_results("SELECT * FROM $table ORDER BY last_name, first_name LIMIT 200",ARRAY_A);
    echo '<div class="wrap"><h1>Wedding Guests</h1><p><a class="button" href="'.esc_url(admin_url('admin.php?page=wprsvp-guests&action=export_csv')).'">Export CSV</a></p>';
    echo '<table class="widefat"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Partner</th><th>Guest meal</th><th>Partner meal</th><th>RSVP</th></tr></thead><tbody>';
    if ($rows){ foreach($rows as $r){ echo '<tr><td>'.$r['id'].'</td><td>'.$r['first_name'].' '.$r['last_name'].'</td><td>'.$r['email'].'</td><td>'.$r['partner_first_name'].' '.$r['partner_last_name'].'</td><td>'.$r['guest_meal'].'</td><td>'.$r['partner_meal'].'</td><td>'.$r['rsvp_status'].'</td></tr>'; } } else { echo '<tr><td colspan="7">No guests</td></tr>'; }
    echo '</tbody></table></div>';
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

/* End of plugin */
