<?php
/*
Plugin Name: DFES API
Description: Custom REST API to handle DFES incident data.
Version: 1.0
Author: Milroy Gomes
*/

// =============================
// 1️⃣ ACTIVATION HOOKS
// =============================
register_activation_hook(__FILE__, 'dfes_api_on_activate');
register_deactivation_hook(__FILE__, 'dfes_api_on_deactivate');

function dfes_api_on_activate() {
    dfes_api_create_table();

    // Schedule purge event
    if (!wp_next_scheduled('dfes_purge_old_records')) {
        wp_schedule_event(time(), 'hourly', 'dfes_purge_old_records');
    }
}

function dfes_api_on_deactivate() {
    wp_clear_scheduled_hook('dfes_purge_old_records');
}

// =============================
// 2️⃣ MAIN HOOK REGISTRATION
// =============================
add_action('plugins_loaded', 'dfes_api_register_hooks');

function dfes_api_register_hooks() {
    // Rewrite rules
    add_action('init', 'dfes_api_add_rewrite_rules');

    // Custom query vars
    add_filter('query_vars', 'dfes_api_add_query_vars');

    // Template redirects
    add_action('template_redirect', 'dfes_api_template_redirect_handler');

    // Cron purge
    add_action('dfes_purge_old_records', 'dfes_api_purge_old_records');
}

// =============================
// 3️⃣ DB TABLE CREATION
// =============================
function dfes_api_create_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dfes_incidents';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        dsr_id VARCHAR(50) NOT NULL,
        date VARCHAR(50) NOT NULL,
        outtime VARCHAR(10),
        intime VARCHAR(10),
        station VARCHAR(100),
        call_type VARCHAR(100),
        activity_live VARCHAR(255),
        near VARCHAR(255),
        at VARCHAR(255),
        vehicle VARCHAR(100),
        taluka VARCHAR(100),
        village VARCHAR(100),
        activity_sms VARCHAR(255),
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// =============================
// 4️⃣ REWRITE RULES & QUERY VARS
// =============================
function dfes_api_add_rewrite_rules() {
    add_rewrite_rule('^dfes/data/live/update/?$', 'index.php?dfes_update=1', 'top');
    add_rewrite_rule('^disaster-management/live-calls/data/?$', 'index.php?dfes_live_calls=1', 'top');
}

function dfes_api_add_query_vars($vars) {
    $vars[] = 'dfes_update';
    $vars[] = 'dfes_live_calls';
    return $vars;
}

// =============================
// 5️⃣ TEMPLATE REDIRECT HANDLER
// =============================
function dfes_api_template_redirect_handler() {
    if (get_query_var('dfes_update')) {
        $request = new WP_REST_Request('GET');
        foreach ($_REQUEST as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = dfes_api_handle_request($request);
        wp_send_json($response->get_data(), $response->get_status());
    }

    if (get_query_var('dfes_live_calls')) {
        $response = dfes_api_fetch_live_calls(new WP_REST_Request('GET'));
        wp_send_json($response->get_data(), $response->get_status());
    }
}

// =============================
// 6️⃣ API HANDLER - INSERT/UPDATE
// =============================
function dfes_api_handle_request(WP_REST_Request $request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dfes_incidents';
    $params = $request->get_params();

    // Sanitize
    $dsr_id        = sanitize_text_field($params['dsr_id']);
    $date          = sanitize_text_field($params['date']);
    $outtime       = sanitize_text_field($params['outtime']);
    $intime        = sanitize_text_field($params['intime']);
    $station       = sanitize_text_field($params['station']);
    $call_type     = sanitize_text_field($params['call_type']);
    $activity_live = sanitize_text_field($params['activity_live']);
    $near          = sanitize_text_field($params['near']);
    $at            = sanitize_text_field($params['at']);
    $vehicle       = sanitize_text_field($params['vehicle']);
    $taluka        = sanitize_text_field($params['taluka']);
    $village       = sanitize_text_field($params['village']);
    $activity_sms  = sanitize_text_field($params['activity_sms']);

    // Time validation
    date_default_timezone_set('Asia/Kolkata');
    $current_timestamp = time();
    $input_timestamp   = intval($date);
    $one_hour_before   = $current_timestamp - 3600;

    if ($input_timestamp < $one_hour_before) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'OUTDATED TIME - Data not stored.'], 400);
    }
    if ($input_timestamp > $current_timestamp) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'FUTURE TIME - Data not stored.'], 400);
    }

    // Insert or update
    $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE dsr_id = %s", $dsr_id));

    if ($existing) {
        $wpdb->update(
            $table_name,
            compact('date', 'outtime', 'intime', 'station', 'call_type', 'activity_live', 'near', 'at', 'vehicle', 'taluka', 'village', 'activity_sms'),
            ['dsr_id' => $dsr_id]
        );

        // ✅ Send SMS only on update
        dfes_send_esms($station, $outtime, $activity_live, $near, $at, $village);

        return new WP_REST_Response(['status' => 'success', 'message' => 'Data updated successfully', 'data' => $params], 200);

    } else {
        $wpdb->insert(
            $table_name,
            compact('dsr_id', 'date', 'outtime', 'intime', 'station', 'call_type', 'activity_live', 'near', 'at', 'vehicle', 'taluka', 'village', 'activity_sms')
        );

        // ✅ Send SMS only on insert
        dfes_send_esms($station, $outtime, $activity_live, $near, $at, $village);

        return new WP_REST_Response(['status' => 'success', 'message' => 'Data inserted successfully'], 201);
    }
}


// =============================
// 7️⃣ HELPER - ESMS Sender
// =============================
function dfes_send_esms($station, $outtime, $activity_live, $near, $at, $village) {
     // Get WP uploads folder path
    $upload_dir = wp_upload_dir();
    $csv_file   = trailingslashit($upload_dir['basedir']) . 'numbers.csv';

    // Check if file exists
    if (!file_exists($csv_file)) return;

    if (($handle = fopen($csv_file, "r")) !== FALSE) { 
        $first = true;
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($first) { $first = false; continue; } // skip header row

            $mobile = trim($data[1]); // second column = mobile
            if (!empty($mobile)) {
                $message = "Fire Station: $station\nTime: $outtime\nIncident: $activity_live\nNear: $near\nAt: $at\nArea: $village\nDFES,Goa.";

                $url = "https://api.msg91.com/api/sendhttp.php?" .
                       "authkey=YOUR_AUTHKEY" .
                       "&sender=YOUR_SENDERID" .
                       "&mobiles={$mobile}" .
                       "&route=4" .
                       "&message=" . urlencode($message) .
                       "&DLT_TE_ID=YOUR_TEMPLATE_ID";

                wp_remote_get($url);
            }
        }
        fclose($handle);
    }
}

// =============================
// 8️⃣ API HANDLER - LAST 24 HOURS
// =============================
function dfes_api_fetch_live_calls(WP_REST_Request $request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dfes_incidents';

    date_default_timezone_set('Asia/Kolkata');
    $now = time();
    $past_24_hours = $now - (24 * 60 * 60);

    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table_name WHERE date >= %d ORDER BY date DESC", $past_24_hours),
        ARRAY_A
    );

    if (empty($results)) {
        return new WP_REST_Response([], 200);
    }

    // Transform keys
    $retitled = array_map(function($row) {
        return [
            'dsr_id'      => $row['dsr_id'],
            'date'        => $row['date'],
            'outtime'     => $row['outtime'],
            'intime'      => $row['intime'],
            'station'     => $row['station'],
            'type'        => $row['call_type'],
            'description' => $row['activity_live'],
            'near'        => $row['near'],
            'at'          => $row['at'],
            'vehicle'     => $row['vehicle'],
            'taluka'      => $row['taluka'],
            'village'     => $row['village'],
            'activity_sms'=> $row['activity_sms']
        ];
    }, $results);

    return new WP_REST_Response($retitled, 200);
}

// =============================
// 9️⃣ CRON - PURGE OLD RECORDS
// =============================
function dfes_api_purge_old_records() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dfes_incidents';

    date_default_timezone_set('Asia/Kolkata');
    $cutoff = time() - (24 * 60 * 60);

    $wpdb->query(
        $wpdb->prepare("DELETE FROM $table_name WHERE date < %d", $cutoff)
    );
}
