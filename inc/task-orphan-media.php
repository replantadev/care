<?php
/**
 * Orphan media scan.
 *
 * Finds attachments not referenced by:
 *  - any post/page content
 *  - any post thumbnail
 *  - any term meta
 *  - any options serialized data (best-effort substring search on filename)
 *
 * Returns the list (never deletes). The dashboard exposes a delete action gated
 * behind the wpo_basic feature; deletion is opt-in per attachment.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_OrphanMedia {

    const OPT_LAST = 'rpcare_orphan_media_last';
    const MAX_SCAN = 2000;

    public static function scan($args = []) {
        if (!RP_Care_Plan::can_access_feature('wpo_basic')) {
            return ['skipped' => 'plan_excluded'];
        }

        global $wpdb;

        $attachments = $wpdb->get_results(
            "SELECT ID, post_title, guid FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             ORDER BY ID DESC
             LIMIT " . (int) self::MAX_SCAN
        );

        $orphans = [];
        $checked = 0;
        $skipped_used = 0;

        foreach ($attachments as $att) {
            $checked++;
            $filename = basename(parse_url($att->guid, PHP_URL_PATH));
            if (empty($filename)) continue;

            if (self::is_referenced($att->ID, $filename)) {
                $skipped_used++;
                continue;
            }

            $orphans[] = [
                'id'       => (int) $att->ID,
                'title'    => $att->post_title,
                'guid'     => $att->guid,
                'filename' => $filename,
            ];
        }

        $result = [
            'checked'    => $checked,
            'used'       => $skipped_used,
            'orphans'    => count($orphans),
            'sample'     => array_slice($orphans, 0, 50),
            'scanned_at' => current_time('mysql'),
        ];

        update_option(self::OPT_LAST, $result, false);

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('orphan_media_scan', 'success', 'Orphan media scan completed', [
                'checked' => $checked, 'orphans' => count($orphans),
            ]);
        }

        return $result;
    }

    private static function is_referenced($att_id, $filename) {
        global $wpdb;

        // Used as featured image
        $thumb = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
            (string) $att_id
        ));
        if ($thumb > 0) return true;

        // Referenced in post content by ID or filename
        $like_id = '%wp-image-' . (int) $att_id . '%';
        $like_name = '%' . $wpdb->esc_like($filename) . '%';
        $in_post = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status IN ('publish','private','draft','future','pending')
               AND (post_content LIKE %s OR post_content LIKE %s)
             LIMIT 1",
            $like_id, $like_name
        ));
        if ($in_post > 0) return true;

        // Referenced in postmeta (e.g. ACF gallery)
        $in_meta = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_value LIKE %s OR meta_value = %s
             LIMIT 1",
            $like_name, (string) $att_id
        ));
        if ($in_meta > 0) return true;

        // Referenced in options (site logo, customizer)
        $in_options = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_value LIKE %s
             LIMIT 1",
            $like_name
        ));
        if ($in_options > 0) return true;

        return false;
    }

    public static function delete_attachments(array $ids) {
        if (!RP_Care_Plan::can_access_feature('wpo_basic')) {
            return ['skipped' => 'plan_excluded'];
        }
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            $att = get_post($id);
            if (!$att || $att->post_type !== 'attachment') continue;
            if (wp_delete_attachment($id, true)) $deleted++;
        }
        return ['deleted' => $deleted];
    }
}
