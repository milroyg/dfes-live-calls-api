<?php
/*
Plugin Name: DFES API
Description: Custom REST API to handle DFES incident data.
Version: 1.0
Author: Milroy Gomes
*/

// Register activation hook to create the table
register_activation_hook(__FILE__, 'dfes_api_create_table');

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

// Register the REST API route
add_action('rest_api_init', function () {
    register_rest_route('dfes/v1', '/update', array(
        'methods' => ['GET', 'POST'],
        'callback' => 'dfes_api_handle_request',
        'permission_callback' => '__return_true'
    ));

});

// Handle API request
function dfes_api_handle_request(WP_REST_Request $request) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'dfes_incidents';

    // Retrieve parameters from URL
    $params = $request->get_params();
    
    $dsr_id = sanitize_text_field($params['dsr_id']);
    $date = sanitize_text_field($params['date']);
    $outtime = sanitize_text_field($params['outtime']);
    $intime = sanitize_text_field($params['intime']);
    $station = sanitize_text_field($params['station']);
    $call_type = sanitize_text_field($params['call_type']);
    $activity_live = sanitize_text_field($params['activity_live']);
    $near = sanitize_text_field($params['near']);
    $at = sanitize_text_field($params['at']);
    $vehicle = sanitize_text_field($params['vehicle']);
    $taluka = sanitize_text_field($params['taluka']);
    $village = sanitize_text_field($params['village']);
    $activity_sms = sanitize_text_field($params['activity_sms']);

  // 🛠️ Validate Date: Prevent outdated and future timestamps
date_default_timezone_set('Asia/Kolkata');  // Set to IST timezone

$current_timestamp = time();               // Current IST timestamp
$input_timestamp = intval($date);          // Convert input date to integer

// Allow timestamps only within the current hour range (prevent past and future)
$one_hour_before = $current_timestamp - 3600;   // 1 hour before current time
$one_hour_after = $current_timestamp + 3600;    // 1 hour after current time

// Compare timestamps in IST
if ($input_timestamp < $one_hour_before) {
    return new WP_REST_Response([
        'status' => 'error',
        'message' => 'OUTDATED TIME - Data not stored.'
    ], 400);
}

if ($input_timestamp > $one_hour_after) {
    return new WP_REST_Response([
        'status' => 'error',
        'message' => 'FUTURE TIME - Data not stored.'
    ], 400);
}

// If the timestamp is valid, continue with your logic
// return new WP_REST_Response([
//     'status' => 'success',
//     'message' => 'Incident updated successfully.'
// ], 200);



    // Check if the dsr_id already exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE dsr_id = %s", $dsr_id
    ));

    if ($existing) {
        // Update the record if dsr_id exists
        $wpdb->update(
            $table_name,
            [
                'date' => $date,
                'outtime' => $outtime,
                'intime' => $intime,
                'station' => $station,
                'call_type' => $call_type,
                'activity_live' => $activity_live,
                'near' => $near,
                'at' => $at,
                'vehicle' => $vehicle,
                'taluka' => $taluka,
                'village' => $village,
                'activity_sms' => $activity_sms
            ],
            ['dsr_id' => $dsr_id]
        );

        return new WP_REST_Response([
            'status' => 'success',
            'message' => 'Data updated successfully',
            'data' => $params
        ], 200);
    } else {
        // Insert new record if dsr_id doesn't exist
        $wpdb->insert($table_name, [
            'dsr_id' => $dsr_id,
            'date' => $date,
            'outtime' => $outtime,
            'intime' => $intime,
            'station' => $station,
            'call_type' => $call_type,
            'activity_live' => $activity_live,
            'near' => $near,
            'at' => $at,
            'vehicle' => $vehicle,
            'taluka' => $taluka,
            'village' => $village,
            'activity_sms' => $activity_sms
        ]);

        return new WP_REST_Response([
            'status' => 'success',
            'message' => 'Data inserted successfully'
            // 'data' => $params
        ], 201);
    }
}

// // Register a new API route for fetching the last 24-hour incidents
add_action('rest_api_init', function () {
    register_rest_route('dfes/v1', '/live-calls', array(
        'methods'  => 'GET',
        'callback' => 'dfes_api_fetch_live_calls',
        'permission_callback' => '__return_true'
    ));
});
function dfes_api_fetch_live_calls(WP_REST_Request $request) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'dfes_incidents';

    // 🔥 Set timezone to IST for consistency
    date_default_timezone_set('Asia/Kolkata');

    // Get the current timestamp in IST
    $now = time();
    
    // Calculate 24 hours ago in IST
    $past_24_hours = $now - (24 * 60 * 60);  // 24 hours in seconds

    // Query to fetch incidents from the last 24 hours
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE date >= %d ORDER BY date DESC",
            $past_24_hours
        ),
        ARRAY_A
    );

    // Check if data is found
    if (empty($results)) {
        return new WP_REST_Response([
            'status' => 'success',
            'message' => 'No incidents found in the last 24 hours.',
            'data' => []
        ], 200);
    }

    // Return the incidents as JSON
    return new WP_REST_Response([
        'status' => 'success',
        'message' => 'Incidents from the last 24 hours.',
        'data' => $results
    ], 200);
}


