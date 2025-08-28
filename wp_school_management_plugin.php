<?php
/**
 * Plugin Name: School Management Shortcodes & Integration
 * Description: Adds teacher mark upload, student & parent reports, and role-aware dashboards. Works with Elementor (use shortcodes) and Ultimate Member for auth/roles. Creates a custom table to store marks and auto-computes totals, averages and grades. Compatible with School Management System Pro if present (light integration). 
 * Version: 1.0.0
 * Author: ChatGPT (template)
 * Text Domain: sms-shortcodes
 */

if (!defined('ABSPATH')) exit;

class SMS_Shortcodes_Plugin {
    private static $instance = null;
    private $table;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'sms_marks';

        register_activation_hook(__FILE__, array($this, 'on_activate'));
        register_deactivation_hook(__FILE__, array($this, 'on_deactivate'));

        add_action('init', array($this, 'register_shortcodes'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_sms_link_child', array($this, 'handle_link_child')); // form handler
    }

    public function on_activate() {
        $this->create_table();
        // add default roles if not exists - but we assume Ultimate Member creates roles.
    }

    public function on_deactivate() {
        // keep data. If you want to remove table, implement here.
    }

    private function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id BIGINT(20) UNSIGNED NOT NULL,
            teacher_id BIGINT(20) UNSIGNED NOT NULL,
            term VARCHAR(64) DEFAULT '',
            year SMALLINT(6) DEFAULT 0,
            subject_marks LONGTEXT NOT NULL,
            total FLOAT DEFAULT 0,
            average FLOAT DEFAULT 0,
            grade VARCHAR(10) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function register_shortcodes() {
        add_shortcode('sms_teacher_upload', array($this, 'shortcode_teacher_upload'));
        add_shortcode('sms_student_report', array($this, 'shortcode_student_report'));
        add_shortcode('sms_parent_reports', array($this, 'shortcode_parent_reports'));
        add_shortcode('sms_dashboard', array($this, 'shortcode_dashboard'));
        add_shortcode('sms_reports', array($this, 'shortcode_all_reports'));
    }

    // Admin menu for linking parents -> children
    public function admin_menu() {
        add_users_page('SMS: Link Parent-Child', 'SMS Links', 'manage_options', 'sms-links', array($this, 'admin_links_page'));
    }

    public function admin_links_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1>SMS: Link Parent to Child</h1>
            <p>Use this form to connect a parent user account to one or more student accounts. The parent must have the "parent" role (or similar) assigned by Ultimate Member.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('sms_link_child_nonce'); ?>
                <input type="hidden" name="action" value="sms_link_child">
                <table class="form-table">
                    <tr>
                        <th><label for="parent_id">Parent User ID</label></th>
                        <td><input type="number" name="parent_id" id="parent_id" required></td>
                    </tr>
                    <tr>
                        <th><label for="child_ids">Child User IDs (comma separated)</label></th>
                        <td><input type="text" name="child_ids" id="child_ids" placeholder="23,45,56" required></td>
                    </tr>
                </table>
                <?php submit_button('Link Parent to Children'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_link_child() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('sms_link_child_nonce');
        $parent = intval($_POST['parent_id']);
        $childs_raw = sanitize_text_field($_POST['child_ids']);
        $childs = array_filter(array_map('intval', explode(',', $childs_raw)));
        if ($parent && !empty($childs)) {
            update_user_meta($parent, 'sms_children', $childs);
            wp_redirect(add_query_arg('sms_linked', '1', wp_get_referer() ?: admin_url()));
            exit;
        }
        wp_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    // Helper grade computation
    private function compute_totals_and_grade($subject_marks_array) {
        // $subject_marks_array = array of floats
        $total = 0;
        $count = 0;
        foreach ($subject_marks_array as $m) {
            $val = floatval($m);
            $total += $val;
            $count++;
        }
        $average = $count ? ($total / $count) : 0;

        // Default grade boundaries; you may expose in settings later
        if ($average >= 80) $grade = 'A';
        elseif ($average >= 70) $grade = 'B';
        elseif ($average >= 60) $grade = 'C';
        elseif ($average >= 50) $grade = 'D';
        else $grade = 'F';

        return array('total' => round($total,2), 'average' => round($average,2), 'grade' => $grade);
    }

    // Shortcode: teacher upload form
    public function shortcode_teacher_upload($atts) {
        if (!is_user_logged_in()) return '<p>Please <a href="' . wp_login_url() . '">log in</a> to upload marks.</p>';
        $user = wp_get_current_user();
        // Check role - allow if user has role 'teacher' or 'administrator'
        $roles = (array) $user->roles;
        if (!in_array('teacher', $roles) && !in_array('administrator', $roles) && !in_array('um_teacher', $roles)) {
            return '<p>You do not have permission to upload marks.</p>';
        }

        // Handle submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sms_teacher_nonce'])) {
            if (!wp_verify_nonce($_POST['sms_teacher_nonce'], 'sms_teacher_action')) {
                return '<p>Security check failed.</p>';
            }
            $student_identifier = sanitize_text_field($_POST['student_identifier']); // userid or email
            $student_id = 0;
            if (is_numeric($student_identifier)) $student_id = intval($student_identifier);
            else {
                $u = get_user_by('email', $student_identifier);
                if ($u) $student_id = $u->ID;
            }
            if (!$student_id) return '<p>Invalid student selected. Use user ID or email of a registered student.</p>';

            $term = sanitize_text_field($_POST['term']);
            $year = intval($_POST['year']);

            // parse subjects and marks - expect subject[] and mark[] arrays
            $subjects = isset($_POST['subject']) ? array_map('sanitize_text_field', $_POST['subject']) : array();
            $marks = isset($_POST['mark']) ? array_map('floatval', $_POST['mark']) : array();

            // build associative array
            $subject_marks = array();
            for ($i=0; $i<count($subjects); $i++) {
                $sub = trim($subjects[$i]);
                if ($sub === '') continue;
                $subject_marks[$sub] = isset($marks[$i]) ? floatval($marks[$i]) : 0;
            }

            if (empty($subject_marks)) return '<p>No marks supplied.</p>';

            $computed = $this->compute_totals_and_grade(array_values($subject_marks));
            global $wpdb;
            $inserted = $wpdb->insert($this->table, array(
                'student_id' => $student_id,
                'teacher_id' => $user->ID,
                'term' => $term,
                'year' => $year,
                'subject_marks' => maybe_serialize($subject_marks),
                'total' => $computed['total'],
                'average' => $computed['average'],
                'grade' => $computed['grade'],
            ), array('%d','%d','%s','%d','%s','%f','%f','%s'));

            if ($inserted !== false) {
                // If School Management System Pro plugin exists, attempt to call its import or sync method (best-effort)
                if (class_exists('School_Management_Pro') || function_exists('school_management_pro_sync')) {
                    // Attempt integration - since APIs vary, this is a graceful no-op if not available
                    if (function_exists('school_management_pro_sync')) {
                        do_action('school_management_pro_sync_record', $student_id);
                    }
                }
                return '<div class="sms-success">Marks uploaded successfully. Total: ' . esc_html($computed['total']) . ' Average: ' . esc_html($computed['average']) . ' Grade: ' . esc_html($computed['grade']) . '</div>';
            } else {
                return '<div class="sms-error">Failed to save marks (DB error).</div>';
            }
        }

        // Render form
        ob_start();
        ?>
        <form method="post" class="sms-teacher-upload">
            <?php wp_nonce_field('sms_teacher_action', 'sms_teacher_nonce'); ?>
            <p>
                <label>Student (User ID or Email)<br>
                <input type="text" name="student_identifier" required></label>
            </p>
            <p>
                <label>Term<br>
                <input type="text" name="term" placeholder="e.g. Term 1" required></label>
            </p>
            <p>
                <label>Year<br>
                <input type="number" name="year" value="<?php echo date('Y'); ?>" required></label>
            </p>

            <div id="sms-subjects">
                <p>Enter Subjects and Marks</p>
                <div class="sms-sub-row">
                    <input type="text" name="subject[]" placeholder="Subject name" required>
                    <input type="number" step="0.01" name="mark[]" placeholder="Mark" required>
                    <button type="button" class="sms-remove">Remove</button>
                </div>
            </div>

            <p>
                <button type="button" id="sms-add-subject">Add another subject</button>
            </p>

            <p><input type="submit" value="Upload Marks"></p>
        </form>
        <script>
        (function(){
            document.getElementById('sms-add-subject').addEventListener('click', function(){
                var wrapper = document.getElementById('sms-subjects');
                var row = document.createElement('div'); row.className='sms-sub-row';
                row.innerHTML = '<input type="text" name="subject[]" placeholder="Subject name" required> <input type="number" step="0.01" name="mark[]" placeholder="Mark" required> <button type="button" class="sms-remove">Remove</button>';
                wrapper.appendChild(row);
            });
            document.addEventListener('click', function(e){
                if (e.target && e.target.classList && e.target.classList.contains('sms-remove')) {
                    e.target.parentNode.remove();
                }
            });
        })();
        </script>
        <style>.sms-sub-row{margin-bottom:8px;} .sms-success{background:#e6ffed;border:1px solid #b7f0c7;padding:8px;} .sms-error{background:#ffdede;border:1px solid #f0b7b7;padding:8px;}</style>
        <?php
        return ob_get_clean();
    }

    // Shortcode: student report
    public function shortcode_student_report($atts) {
        if (!is_user_logged_in()) return '<p>Please <a href="' . wp_login_url() . '">log in</a> to view reports.</p>';
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        if (!in_array('student', $roles) && !in_array('administrator', $roles) && !in_array('um_student', $roles)) {
            return '<p>You do not have permission to view student reports using this page.</p>';
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE student_id = %d ORDER BY created_at DESC", $user->ID), ARRAY_A);

        if (empty($rows)) return '<p>No reports found for you yet.</p>';

        ob_start();
        echo '<div class="sms-reports">';
        foreach ($rows as $r) {
            $subjects = maybe_unserialize($r['subject_marks']);
            echo '<div class="sms-report-card">';
            echo '<h3>Term: ' . esc_html($r['term']) . ' &middot; Year: ' . esc_html($r['year']) . '</h3>';
            echo '<table class="sms-table" style="width:100%;border-collapse:collapse;">';
            echo '<thead><tr><th style="border:1px solid #ddd;padding:6px">Subject</th><th style="border:1px solid #ddd;padding:6px">Mark</th></tr></thead><tbody>';
            foreach ($subjects as $sub => $mark) {
                echo '<tr><td style="border:1px solid #ddd;padding:6px">' . esc_html($sub) . '</td><td style="border:1px solid #ddd;padding:6px;text-align:right">' . esc_html($mark) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p>Total: ' . esc_html($r['total']) . ' &nbsp; Average: ' . esc_html($r['average']) . ' &nbsp; Grade: ' . esc_html($r['grade']) . '</p>';
            echo '<small>Uploaded on: ' . esc_html($r['created_at']) . '</small>';
            echo '</div><hr />';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // Shortcode: parent report view
    public function shortcode_parent_reports($atts) {
        if (!is_user_logged_in()) return '<p>Please <a href="' . wp_login_url() . '">log in</a> to view child reports.</p>';
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        if (!in_array('parent', $roles) && !in_array('administrator', $roles) && !in_array('um_parent', $roles)) {
            return '<p>You do not have permission to view parent reports.</p>';
        }

        // get child ids from usermeta
        $children = get_user_meta($user->ID, 'sms_children', true);
        if (empty($children) || !is_array($children)) return '<p>No children connected to your account yet. Contact the school admin to link.</p>';

        global $wpdb;
        ob_start();
        echo '<div class="sms-parent-reports">';
        foreach ($children as $child_id) {
            $student = get_user_by('id', $child_id);
            if (!$student) continue;
            echo '<h2>Reports for ' . esc_html($student->display_name) . ' (User ID: ' . intval($child_id) . ')</h2>';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE student_id = %d ORDER BY created_at DESC", $child_id), ARRAY_A);
            if (empty($rows)) { echo '<p>No reports found for this child yet.</p>'; continue; }
            foreach ($rows as $r) {
                $subjects = maybe_unserialize($r['subject_marks']);
                echo '<div class="sms-report-card">';
                echo '<h4>Term: ' . esc_html($r['term']) . ' &middot; Year: ' . esc_html($r['year']) . '</h4>';
                echo '<table class="sms-table" style="width:100%;border-collapse:collapse;">';
                echo '<thead><tr><th style="border:1px solid #ddd;padding:6px">Subject</th><th style="border:1px solid #ddd;padding:6px">Mark</th></tr></thead><tbody>';
                foreach ($subjects as $sub => $mark) {
                    echo '<tr><td style="border:1px solid #ddd;padding:6px">' . esc_html($sub) . '</td><td style="border:1px solid #ddd;padding:6px;text-align:right">' . esc_html($mark) . '</td></tr>';
                }
                echo '</tbody></table>';
                echo '<p>Total: ' . esc_html($r['total']) . ' &nbsp; Average: ' . esc_html($r['average']) . ' &nbsp; Grade: ' . esc_html($r['grade']) . '</p>';
                echo '<small>Uploaded on: ' . esc_html($r['created_at']) . '</small>';
                echo '</div><hr />';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    // Dashboard - shows role-specific quick links
    public function shortcode_dashboard($atts) {
        if (!is_user_logged_in()) return '<p>Please <a href="' . wp_login_url() . '">log in</a>.</p>';
        $user = wp_get_current_user();
        $roles = (array) $user->roles;
        ob_start();
        echo '<div class="sms-dashboard">';
        echo '<h2>Welcome, ' . esc_html($user->display_name) . '</h2>';
        if (in_array('teacher', $roles) || in_array('administrator', $roles) || in_array('um_teacher', $roles)) {
            echo '<h3>Teacher Quick Links</h3><ul>';
            echo '<li><a href="' . esc_url(site_url('/teacher-upload')) . '">Upload Marks</a> (use page with shortcode [sms_teacher_upload])</li>';
            echo '<li><a href="' . esc_url(admin_url('users.php')) . '">Manage Students</a></li>';
            echo '</ul>';
        }
        if (in_array('student', $roles) || in_array('um_student', $roles)) {
            echo '<h3>Student</h3><ul>';
            echo '<li><a href="' . esc_url(site_url('/student-reports')) . '">View Reports</a> (use page with shortcode [sms_student_report])</li>';
            echo '</ul>';
        }
        if (in_array('parent', $roles) || in_array('um_parent', $roles)) {
            echo '<h3>Parent</h3><ul>';
            echo '<li><a href="' . esc_url(site_url('/parent-reports')) . '">View Child Reports</a> (use page with shortcode [sms_parent_reports])</li>';
            echo '</ul>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // Shortcode: public reports listing (for admins or authorized viewers)
    public function shortcode_all_reports($atts) {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return '<p>Only site administrators can view all reports using this page.</p>';
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC", ARRAY_A);
        ob_start();
        echo '<h2>All Uploaded Marks</h2>';
        if (empty($rows)) { echo '<p>No reports yet.</p>'; return ob_get_clean(); }
        echo '<table style="width:100%;border-collapse:collapse;">';
        echo '<thead><tr><th style="border:1px solid #ddd;padding:6px">Student</th><th style="border:1px solid #ddd;padding:6px">Term</th><th style="border:1px solid #ddd;padding:6px">Year</th><th style="border:1px solid #ddd;padding:6px">Total</th><th style="border:1px solid #ddd;padding:6px">Average</th><th style="border:1px solid #ddd;padding:6px">Grade</th><th style="border:1px solid #ddd;padding:6px">Uploaded</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $student = get_user_by('id', $r['student_id']);
            $student_name = $student ? $student->display_name : 'ID ' . intval($r['student_id']);
            echo '<tr>';
            echo '<td style="border:1px solid #ddd;padding:6px">' . esc_html($student_name) . '</td>';
            echo '<td style="border:1px solid #ddd;padding:6px">' . esc_html($r['term']) . '</td>';
            echo '<td style="border:1px solid #ddd;padding:6px">' . esc_html($r['year']) . '</td>';
            echo '<td style="border:1px solid #ddd;padding:6px;text-align:right">' . esc_html($r['total']) . '</td>';
            echo '<td style="border:1px solid #ddd;padding:6px;text-align:right">' . esc_html($r['average']) . '</td>';
            echo '<td style="border:1px solid #ddd;padding:6px">' . esc_html($r['grade']) . '</td>';
            echo '<td style="border:1px solid #ddd;padding:6px">' . esc_html($r['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }
}

SMS_Shortcodes_Plugin::instance();

// EOF
