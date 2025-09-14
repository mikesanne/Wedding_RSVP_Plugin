<?php
/*
Plugin Name: Wedding RSVP & Guest Database
Description: RSVP form + guest DB. Shortcode + Elementor widget + admin guest management + SMTP/OAuth2 + CSV import + HTML email templates.
Version:     1.7
Author:      Your Name
Text Domain: wedding-rsvp
*/

if (!defined('ABSPATH')) exit;

define('WPRSVP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPRSVP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPRSVP_VERSION','1.7');

/* -------- Activation: create/upgrade table -------- */
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
        guest_meal VARCHAR(100) DEFAULT NULL,
        rsvp_status VARCHAR(20) DEFAULT NULL,
        partner_id BIGINT(20) UNSIGNED DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY name_idx (first_name, last_name),
        KEY partner_idx (partner_id)
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

/* -------- Shortcode (frontend form) -------- */
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
          <h4>Partner (linked)</h4>
          <p id="wprsvp-partner-note"></p>
          <label>Partner RSVP<br>
            <select id="wprsvp-partner-rsvp" name="partner_rsvp_status">
              <option value="">-- choose --</option>
              <option value="yes">Yes</option>
              <option value="no">No</option>
              <option value="maybe">Maybe</option>
            </select>
          </label>
          <label>Partner meal preference<br>
            <select id="wprsvp-partner-meal" name="partner_meal">
              <option value="">-- choose --</option>
              <option value="meat">Meat</option>
              <option value="fish">Fish</option>
              <option value="veg">Vegetarian</option>
              <option value="vegan">Vegan</option>
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

    if (!$guest) wp_send_json_success(array('found'=>false));

    $result = array('found'=>true, 'guest'=>$guest);

    if (!empty($guest['partner_id'])){
        $partner = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $guest['partner_id']), ARRAY_A);
        if ($partner) $result['partner'] = $partner;
    }

    wp_send_json_success($result);
}

/* -------- AJAX: submit + partner linking -------- */
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
    $rsvp = sanitize_text_field($_POST['rsvp_status'] ?? '');
    $partner_rsvp = sanitize_text_field($_POST['partner_rsvp_status'] ?? '');
    $partner_meal = sanitize_text_field($_POST['partner_meal'] ?? '');
    $notes = sanitize_textarea_field($_POST['notes'] ?? '');

    if (empty($first) || empty($last)) wp_send_json_error(array('message'=>'First and last name required'));

    // Find existing main guest by id or by name
    $existing = null;
    if ($guest_id) $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $guest_id), ARRAY_A);
    if (!$existing) $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE LOWER(first_name)=LOWER(%s) AND LOWER(last_name)=LOWER(%s) LIMIT 1", $first, $last), ARRAY_A);

    if ($existing){
        // update main guest
        $wpdb->update($table, array(
            'first_name'=>$first,'last_name'=>$last,'email'=>$email,
            'guest_meal'=>$guest_meal,'rsvp_status'=>$rsvp,'notes'=>$notes
        ), array('id'=>$existing['id']));
        $main_id = $existing['id'];
    } else {
        // insert main guest
        $wpdb->insert($table, array(
            'first_name'=>$first,'last_name'=>$last,'email'=>$email,'guest_meal'=>$guest_meal,
            'rsvp_status'=>$rsvp,'notes'=>$notes
        ));
        $main_id = $wpdb->insert_id;
    }

    // Partner handling: if partner_id provided update partner; else if partner names provided try link/create
    $partner_id = intval($_POST['partner_id'] ?? 0);
    if ($partner_id){
        $wpdb->update($table, array('rsvp_status'=>$partner_rsvp,'guest_meal'=>$partner_meal), array('id'=>$partner_id));
        // ensure link both ways
        $wpdb->update($table, array('partner_id'=>$partner_id), array('id'=>$main_id));
        $wpdb->update($table, array('partner_id'=>$main_id), array('id'=>$partner_id));
    } else {
        $pfirst = sanitize_text_field($_POST['partner_first_name'] ?? '');
        $plast  = sanitize_text_field($_POST['partner_last_name'] ?? '');
        if ($pfirst && $plast){
            $partner = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE LOWER(first_name)=LOWER(%s) AND LOWER(last_name)=LOWER(%s) LIMIT 1", $pfirst, $plast), ARRAY_A);
            if ($partner){
                $partner_id = $partner['id'];
                $wpdb->update($table, array('partner_id'=>$partner_id), array('id'=>$main_id));
                $wpdb->update($table, array('partner_id'=>$main_id), array('id'=>$partner_id));
                if ($partner_rsvp || $partner_meal){
                    $wpdb->update($table, array('rsvp_status'=>$partner_rsvp,'guest_meal'=>$partner_meal), array('id'=>$partner_id));
                }
            } else {
                $wpdb->insert($table, array(
                    'first_name'=>$pfirst,'last_name'=>$plast,'guest_meal'=>$partner_meal,
                    'rsvp_status'=>$partner_rsvp,'notes'=>'Created as partner via RSVP'
                ));
                $partner_id = $wpdb->insert_id;
                $wpdb->update($table, array('partner_id'=>$partner_id), array('id'=>$main_id));
                $wpdb->update($table, array('partner_id'=>$main_id), array('id'=>$partner_id));
            }
        }
    }

    // send confirmations (use HTML templates when configured)
    $send_confirmation = function($to, $first_name, $rsvp_status, $meal){
        if (!$to) return;
        $subject_template = get_option('wprsvp_email_subject', 'Your RSVP has been received');
        $html_template = get_option('wprsvp_email_html', '');
        $plain_template = get_option('wprsvp_email_plain', '');

        $replacements = array(
            '{{first}}' => $first_name,
            '{{rsvp}}'  => $rsvp_status,
            '{{meal}}'  => $meal
        );

        $subject = strtr($subject_template, $replacements);
        if ($html_template){
            $body_html = strtr($html_template, $replacements);
            $body_plain = $plain_template ? strtr($plain_template, $replacements) : strip_tags($body_html);
            $headers = array('Content-Type: text/html; charset=UTF-8');
            wp_mail($to, $subject, $body_html, $headers);
        } else {
            $body = $plain_template ? strtr($plain_template, $replacements) : "Hi $first_name,\n\nThank you — we received your RSVP.\nRSVP: $rsvp_status\nMeal: $meal\n\nSee you soon!";
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            wp_mail($to, $subject, $body, $headers);
        }
    };

    $main = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $main_id), ARRAY_A);
    if ($main && !empty($main['email'])) $send_confirmation($main['email'], $main['first_name'], $main['rsvp_status'], $main['guest_meal']);

    if (!empty($partner_id)){
        $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $partner_id), ARRAY_A);
        if ($p && !empty($p['email'])) $send_confirmation($p['email'], $p['first_name'], $p['rsvp_status'], $p['guest_meal']);
    }

    wp_send_json_success(array('message'=>'RSVP processed','guest_id'=>$main_id,'partner_id'=>$partner_id));
}

/* -------- Settings: SMTP / OAuth2 / Email templates / CSV import -------- */
add_action('admin_menu', function(){
    add_options_page('Wedding RSVP Settings', 'Wedding RSVP', 'manage_options', 'wprsvp-settings', 'wprsvp_render_settings_page');
    // Guest admin page added below
});
function wprsvp_render_settings_page(){
    if (!current_user_can('manage_options')) return;
    // handle save
    if (isset($_POST['wprsvp_save_settings'])){
        check_admin_referer('wprsvp_save_settings');
        update_option('wprsvp_smtp_host', sanitize_text_field($_POST['smtp_host']));
        update_option('wprsvp_smtp_port', intval($_POST['smtp_port']));
        update_option('wprsvp_smtp_secure', sanitize_text_field($_POST['smtp_secure']));
        update_option('wprsvp_smtp_user', sanitize_text_field($_POST['smtp_user']));
        update_option('wprsvp_smtp_pass', sanitize_text_field($_POST['smtp_pass']));
        update_option('wprsvp_oauth_client_id', sanitize_text_field($_POST['oauth_client_id']));
        update_option('wprsvp_oauth_client_secret', sanitize_text_field($_POST['oauth_client_secret']));
        update_option('wprsvp_oauth_refresh_token', sanitize_text_field($_POST['oauth_refresh_token']));
        update_option('wprsvp_from_email', sanitize_email($_POST['from_email']));
        update_option('wprsvp_from_name', sanitize_text_field($_POST['from_name']));
        update_option('wprsvp_email_subject', sanitize_text_field($_POST['email_subject']));
        update_option('wprsvp_email_html', wp_kses_post($_POST['email_html']));
        update_option('wprsvp_email_plain', sanitize_textarea_field($_POST['email_plain']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }

    $smtp_host   = get_option('wprsvp_smtp_host','smtp.gmail.com');
    $smtp_port   = get_option('wprsvp_smtp_port','465');
    $smtp_secure = get_option('wprsvp_smtp_secure','ssl');
    $smtp_user   = get_option('wprsvp_smtp_user','');
    $smtp_pass   = get_option('wprsvp_smtp_pass','');
    $oauth_client_id = get_option('wprsvp_oauth_client_id','');
    $oauth_client_secret = get_option('wprsvp_oauth_client_secret','');
    $oauth_refresh_token = get_option('wprsvp_oauth_refresh_token','');
    $from_email  = get_option('wprsvp_from_email', get_bloginfo('admin_email'));
    $from_name   = get_option('wprsvp_from_name', get_bloginfo('name'));
    $email_subject = get_option('wprsvp_email_subject','Your RSVP has been received');
    $email_html = get_option('wprsvp_email_html','');
    $email_plain = get_option('wprsvp_email_plain','');

    ?>
    <div class="wrap">
      <h1>Wedding RSVP Settings</h1>
      <form method="post">
        <?php wp_nonce_field('wprsvp_save_settings'); ?>
        <h2>SMTP / Gmail</h2>
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
          <tr><th>SMTP Password (App Password)</th><td><input type="password" name="smtp_pass" value="<?php echo esc_attr($smtp_pass); ?>" style="width:300px"></td></tr>
        </table>

        <h2>OAuth2 (optional - for Gmail API/XOAUTH2)</h2>
        <p>If you want to use OAuth2 instead of username/password, create credentials in Google Cloud Console and paste the Client ID, Client Secret and Refresh Token here.</p>
        <table class="form-table">
          <tr><th>OAuth Client ID</th><td><input type="text" name="oauth_client_id" value="<?php echo esc_attr($oauth_client_id); ?>" style="width:400px"></td></tr>
          <tr><th>OAuth Client Secret</th><td><input type="text" name="oauth_client_secret" value="<?php echo esc_attr($oauth_client_secret); ?>" style="width:400px"></td></tr>
          <tr><th>OAuth Refresh Token</th><td><input type="text" name="oauth_refresh_token" value="<?php echo esc_attr($oauth_refresh_token); ?>" style="width:400px"></td></tr>
        </table>

        <h2>From / Email templates</h2>
        <table class="form-table">
          <tr><th>From Email</th><td><input type="email" name="from_email" value="<?php echo esc_attr($from_email); ?>"></td></tr>
          <tr><th>From Name</th><td><input type="text" name="from_name" value="<?php echo esc_attr($from_name); ?>"></td></tr>
          <tr><th>Email Subject</th><td><input type="text" name="email_subject" value="<?php echo esc_attr($email_subject); ?>" style="width:400px"></td></tr>
          <tr><th>Email HTML Template</th><td><textarea name="email_html" rows="8" cols="80"><?php echo esc_textarea($email_html); ?></textarea><p class="description">Use {{first}}, {{rsvp}}, {{meal}} placeholders.</p></td></tr>
          <tr><th>Email Plain Template</th><td><textarea name="email_plain" rows="6" cols="80"><?php echo esc_textarea($email_plain); ?></textarea></td></tr>
        </table>

        <p><input type="submit" name="wprsvp_save_settings" class="button-primary" value="Save Settings"></p>
      </form>

      <h2>Template preview</h2>
      <p>Preview placeholders replaced with sample data:</p>
      <div style="border:1px solid #ddd; padding:10px; background:#fff;">
        <h3><?php echo esc_html(strtr($email_subject, array('{{first}'=>'Anna'))); ?></h3>
        <div><?php echo wp_kses_post(strtr($email_html, array('{{first}'=>'Anna','{{rsvp}'=>'yes','{{meal}'=>'Vegetarian'))); ?></div>
        <pre><?php echo esc_html(strtr($email_plain, array('{{first}'=>'Anna','{{rsvp}'=>'yes','{{meal}'=>'Vegetarian'))); ?></pre>
      </div>
    </div>
    <?php
}

/* -------- OAuth2 token fetch helper -------- */
function wprsvp_get_google_access_token(){
    $client_id = get_option('wprsvp_oauth_client_id');
    $client_secret = get_option('wprsvp_oauth_client_secret');
    $refresh_token = get_option('wprsvp_oauth_refresh_token');
    if (!$client_id || !$client_secret || !$refresh_token) return false;

    $resp = wp_remote_post('https://oauth2.googleapis.com/token', array(
        'body' => array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token'
        ),
        'timeout' => 15
    ));

    if (is_wp_error($resp)) return false;
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);
    if (isset($data['access_token'])) return $data['access_token'];
    return false;
}

/* -------- Apply SMTP / OAuth2 via phpmailer_init -------- */
add_action('phpmailer_init', function($phpmailer){
    $host = get_option('wprsvp_smtp_host');
    $port = get_option('wprsvp_smtp_port');
    $secure = get_option('wprsvp_smtp_secure');
    $user = get_option('wprsvp_smtp_user');
    $pass = get_option('wprsvp_smtp_pass');
    $from_email = get_option('wprsvp_from_email');
    $from_name  = get_option('wprsvp_from_name');

    $oauth_client_id = get_option('wprsvp_oauth_client_id');
    $oauth_client_secret = get_option('wprsvp_oauth_client_secret');
    $oauth_refresh_token = get_option('wprsvp_oauth_refresh_token');

    if ($oauth_client_id && $oauth_client_secret && $oauth_refresh_token){
        // try to obtain access token and use XOAUTH2
        $access_token = wprsvp_get_google_access_token();
        if ($access_token){
            $phpmailer->isSMTP();
            $phpmailer->Host = $host ?: 'smtp.gmail.com';
            $phpmailer->Port = $port ?: 587;
            if ($secure) $phpmailer->SMTPSecure = $secure;
            $phpmailer->SMTPAuth = true;
            $phpmailer->AuthType = 'XOAUTH2';
            // PHPMailer can use an oauth object; set minimal properties
            $phpmailer->oauth = (object) array(
                'clientId' => $oauth_client_id,
                'clientSecret' => $oauth_client_secret,
                'refreshToken' => $oauth_refresh_token,
                'userName' => $user,
                'accessToken' => $access_token
            );
            if ($from_email) $phpmailer->setFrom($from_email, $from_name ? $from_name : '');
            return;
        }
    }

    // fallback to username/password SMTP
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

/* -------- Admin: Guest management (Add/Edit/Delete, CSV import) -------- */
add_action('admin_menu', function(){
    add_menu_page('Wedding Guests', 'Wedding Guests', 'manage_options', 'wprsvp-guests', 'wprsvp_render_guests_page', 'dashicons-groups', 26);
});
function wprsvp_render_guests_page(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'wedding_guests';

    // Handle CSV upload
    if (isset($_POST['wprsvp_import_csv']) && !empty($_FILES['wprsvp_csv']['tmp_name'])){
        check_admin_referer('wprsvp_import_csv');
        $file = $_FILES['wprsvp_csv']['tmp_name'];
        $handle = fopen($file, 'r');
        $row = 0;
        $imported = 0;
        while (($data = fgetcsv($handle, 2000, ',')) !== FALSE){
            $row++;
            if ($row == 1) continue; // skip header
            // expected columns: first,last,email,partner_first,partner_last,guest_meal,rsvp
            $first = sanitize_text_field($data[0] ?? '');
            $last = sanitize_text_field($data[1] ?? '');
            $email = sanitize_email($data[2] ?? '');
            $pfirst = sanitize_text_field($data[3] ?? '');
            $plast = sanitize_text_field($data[4] ?? '');
            $meal = sanitize_text_field($data[5] ?? '');
            $rsvp = sanitize_text_field($data[6] ?? '');

            if (!$first || !$last) continue;
            // skip if exact duplicate
            $exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE LOWER(first_name)=LOWER(%s) AND LOWER(last_name)=LOWER(%s)", $first, $last), ARRAY_A);
            if ($exists) {
                // update
                $wpdb->update($table, array('email'=>$email,'guest_meal'=>$meal,'rsvp_status'=>$rsvp), array('id'=>$exists['id']));
                $main_id = $exists['id'];
            } else {
                $wpdb->insert($table, array('first_name'=>$first,'last_name'=>$last,'email'=>$email,'guest_meal'=>$meal,'rsvp_status'=>$rsvp));
                $main_id = $wpdb->insert_id;
                $imported++;
            }
            // partner linking
            if ($pfirst && $plast){
                $partner = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE LOWER(first_name)=LOWER(%s) AND LOWER(last_name)=LOWER(%s)", $pfirst, $plast), ARRAY_A);
                if ($partner){
                    $partner_id = $partner['id'];
                } else {
                    $wpdb->insert($table, array('first_name'=>$pfirst,'last_name'=>$plast,'notes'=>'Imported as partner'));
                    $partner_id = $wpdb->insert_id;
                }
                // link both ways
                $wpdb->update($table, array('partner_id'=>$partner_id), array('id'=>$main_id));
                $wpdb->update($table, array('partner_id'=>$main_id), array('id'=>$partner_id));
            }
        }
        fclose($handle);
        echo '<div class="updated"><p>CSV import complete. Imported rows: '.intval($imported).'</p></div>';
    }

    // Handle Add/Edit/Delete from previous implementation...
    if (isset($_POST['wprsvp_add_guest'])){
        check_admin_referer('wprsvp_add_guest');
        $wpdb->insert($table, array(
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'email'      => sanitize_email($_POST['email']),
            'guest_meal' => sanitize_text_field($_POST['guest_meal']),
            'rsvp_status'=> sanitize_text_field($_POST['rsvp_status']),
            'notes' => sanitize_textarea_field($_POST['notes'])
        ));
        // partner link
        if (!empty($_POST['partner_select'])){
            $pid = intval($_POST['partner_select']);
            $nid = $wpdb->insert_id;
            if ($pid && $nid){
                $wpdb->update($table, array('partner_id'=>$pid), array('id'=>$nid));
                $wpdb->update($table, array('partner_id'=>$nid), array('id'=>$pid));
            }
        }
        echo '<div class="updated"><p>Guest added.</p></div>';
    }

    if (isset($_POST['wprsvp_update_guest'])){
        check_admin_referer('wprsvp_update_guest');
        $id = intval($_POST['guest_id']);
        $wpdb->update($table, array(
            'first_name' => sanitize_text_field($_POST['first_name']),
            'last_name'  => sanitize_text_field($_POST['last_name']),
            'email'      => sanitize_email($_POST['email']),
            'guest_meal' => sanitize_text_field($_POST['guest_meal']),
            'rsvp_status'=> sanitize_text_field($_POST['rsvp_status']),
            'notes' => sanitize_textarea_field($_POST['notes'])
        ), array('id'=>$id));
        $sel = intval($_POST['partner_select'] ?? 0);
        $current = $wpdb->get_row($wpdb->prepare("SELECT partner_id FROM $table WHERE id=%d", $id), ARRAY_A);
        if ($current && $current['partner_id']) $wpdb->update($table, array('partner_id'=>null), array('id'=>$current['partner_id']));
        if ($sel){
            $wpdb->update($table, array('partner_id'=>$sel), array('id'=>$id));
            $wpdb->update($table, array('partner_id'=>$id), array('id'=>$sel));
        } else {
            $wpdb->update($table, array('partner_id'=>null), array('id'=>$id));
        }
        echo '<div class="updated"><p>Guest updated.</p></div>';
    }

    if (isset($_GET['delete']) && isset($_GET['_wpnonce'])){
        $del_id = intval($_GET['delete']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'wprsvp_delete_'.$del_id)){
            $cur = $wpdb->get_row($wpdb->prepare("SELECT partner_id FROM $table WHERE id=%d", $del_id), ARRAY_A);
            if ($cur && $cur['partner_id']) $wpdb->update($table, array('partner_id'=>null), array('id'=>$cur['partner_id']));
            $wpdb->delete($table, array('id'=>$del_id));
            echo '<div class="updated"><p>Guest deleted.</p></div>';
        } else {
            echo '<div class="error"><p>Invalid nonce.</p></div>';
        }
    }

    if (isset($_GET['action']) && $_GET['action']=='edit' && isset($_GET['id'])){
        $edit_id = intval($_GET['id']);
        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $edit_id), ARRAY_A);
        if (!$guest) { echo '<div class="error"><p>Guest not found.</p></div>'; return; }
        $all = $wpdb->get_results("SELECT id, first_name, last_name FROM $table WHERE id != $edit_id ORDER BY last_name, first_name", ARRAY_A);
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
              <tr><th>RSVP</th><td><select name="rsvp_status"><option value="">--</option><option value="yes" <?php selected($guest['rsvp_status'],'yes'); ?>>Yes</option><option value="no" <?php selected($guest['rsvp_status'],'no'); ?>>No</option><option value="maybe" <?php selected($guest['rsvp_status'],'maybe'); ?>>Maybe</option></select></td></tr>
              <tr><th>Partner</th><td>
                <select name="partner_select"><option value="">-- none --</option>
                  <?php foreach($all as $a){ $sel = ($guest['partner_id']==$a['id']) ? 'selected' : ''; echo '<option value="'.esc_attr($a['id']).'" '.$sel.'>'.esc_html($a['first_name'].' '.$a['last_name']).'</option>'; } ?>
                </select>
              </td></tr>
              <tr><th>Notes</th><td><textarea name="notes" rows="4" cols="50"><?php echo esc_textarea($guest['notes']); ?></textarea></td></tr>
            </table>
            <p><input type="submit" name="wprsvp_update_guest" class="button-primary" value="Update Guest"></p>
          </form>
        </div>
        <?php
        return;
    }

    // List + add form + CSV import form
    $guests = $wpdb->get_results("SELECT * FROM $table ORDER BY last_name, first_name", ARRAY_A);
    $all_select = $wpdb->get_results("SELECT id, first_name, last_name FROM $table ORDER BY last_name, first_name", ARRAY_A);
    ?>
    <div class="wrap">
      <h1>Wedding Guests</h1>

      <h2>Import CSV</h2>
      <p>CSV columns: first,last,email,partner_first,partner_last,guest_meal,rsvp (header row required)</p>
      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('wprsvp_import_csv'); ?>
        <input type="file" name="wprsvp_csv" accept=".csv" required>
        <p><input type="submit" name="wprsvp_import_csv" class="button-primary" value="Import CSV"></p>
      </form>

      <h2>Add New Guest</h2>
      <form method="post">
        <?php wp_nonce_field('wprsvp_add_guest'); ?>
        <table class="form-table">
          <tr><th>First Name</th><td><input type="text" name="first_name" required></td></tr>
          <tr><th>Last Name</th><td><input type="text" name="last_name" required></td></tr>
          <tr><th>Email</th><td><input type="email" name="email"></td></tr>
          <tr><th>Meal</th><td><input type="text" name="guest_meal"></td></tr>
          <tr><th>RSVP</th><td><select name="rsvp_status"><option value="">--</option><option value="yes">Yes</option><option value="no">No</option><option value="maybe">Maybe</option></select></td></tr>
          <tr><th>Link Partner</th><td>
            <select name="partner_select"><option value="">-- none --</option>
            <?php foreach($all_select as $s){ echo '<option value="'.esc_attr($s['id']).'">'.esc_html($s['first_name'].' '.$s['last_name']).'</option>'; } ?>
            </select>
          </td></tr>
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
          <?php if ($guests) { foreach($guests as $g): $partner_label = ''; if ($g['partner_id']) { $p = $wpdb->get_row($wpdb->prepare("SELECT first_name,last_name FROM $table WHERE id=%d", $g['partner_id']), ARRAY_A); if ($p) $partner_label = esc_html($p['first_name'].' '.$p['last_name']); } ?>
            <tr>
              <td><?php echo esc_html($g['id']); ?></td>
              <td><?php echo esc_html($g['first_name'].' '.$g['last_name']); ?></td>
              <td><?php echo esc_html($g['email']); ?></td>
              <td><?php echo esc_html($g['guest_meal']); ?></td>
              <td><?php echo esc_html($g['rsvp_status']); ?></td>
              <td><?php echo $partner_label ? '<a href="'.esc_url(add_query_arg(array('page'=>'wprsvp-guests','action'=>'edit','id'=>$g['partner_id']), admin_url('admin.php'))).'">'.$partner_label.'</a>' : ''; ?></td>
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
add_action('admin_menu','wprsvp_register_pages');
function wprsvp_register_pages(){ /* registered above */ }

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
