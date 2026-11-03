<?php

if (!defined('ABSPATH')) {
    exit;
}

final class CIKW_Admin_Data
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_cikw_save_categories', [$this, 'handle_save_categories']);
        add_action('admin_post_cikw_delete_category', [$this, 'handle_delete_category']);
        add_action('admin_post_cikw_save_prizes', [$this, 'handle_save_prizes']);
        add_action('admin_post_cikw_delete_prize', [$this, 'handle_delete_prize']);
        add_action('admin_post_cikw_reset_prize_stock', [$this, 'handle_reset_prize_stock']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'cikw-dashboard',
            __('Κατηγορίες', 'cik-prize-wheel'),
            __('Κατηγορίες', 'cik-prize-wheel'),
            'manage_options',
            'cikw-categories',
            [$this, 'render_categories_page']
        );

        add_submenu_page(
            'cikw-dashboard',
            __('Δώρα', 'cik-prize-wheel'),
            __('Δώρα', 'cik-prize-wheel'),
            'manage_options',
            'cikw-prizes',
            [$this, 'render_prizes_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if (!in_array($page, ['cikw-categories', 'cikw-prizes'], true)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('cikw-admin', CIKW_PLUGIN_URL . 'assets/admin/admin.css', [], CIKW_VERSION);
        wp_enqueue_script('cikw-admin', CIKW_PLUGIN_URL . 'assets/admin/admin.js', ['jquery', 'jquery-ui-sortable'], CIKW_VERSION, true);
    }

    public function render_categories_page(): void
    {
        $this->require_manager();

        $categories = $this->get_categories();
        $has_weight_warning = false;

        foreach ($categories as $category) {
            if ((int) $category['is_active'] === 1 && abs((float) $category['wheel_weight'] - 1.0) > 0.0001) {
                $has_weight_warning = true;
                break;
            }
        }

        echo '<div class="wrap cikw-admin-page">';
        echo '<h1>' . esc_html__('Κατηγορίες τροχού', 'cik-prize-wheel') . '</h1>';
        $this->render_message_notice();

        if ($has_weight_warning) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Προσοχή: τουλάχιστον μία ενεργή κατηγορία έχει βάρος διαφορετικό από 1.000. Αν τα βάρη διαφέρουν από τα ίσα οπτικά τμήματα, ο τροχός μπορεί να φαίνεται παραπλανητικός.', 'cik-prize-wheel') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cikw_save_categories');
        echo '<input type="hidden" name="action" value="cikw_save_categories" />';
        echo '<table class="widefat fixed striped cikw-edit-table cikw-sortable-table">';
        echo '<thead><tr>';
        echo '<th class="cikw-col-handle"></th>';
        echo '<th>' . esc_html__('Slug', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Όνομα', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Περιγραφή', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Χρώμα', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Βάρος', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Εικόνα', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Ενεργή', 'cik-prize-wheel') . '</th>';
        echo '<th>' . esc_html__('Ενέργειες', 'cik-prize-wheel') . '</th>';
        echo '</tr></thead><tbody class="cikw-sortable-body">';

        if (empty($categories)) {
            echo '<tr><td colspan="9">' . esc_html__('Δεν υπάρχουν ακόμη κατηγορίες.', 'cik-prize-wheel') . '</td></tr>';
        }

        foreach ($categories as $index => $category) {
            $id = (int) $category['id'];
            $delete_url = wp_nonce_url(
                add_query_arg(['action' => 'cikw_delete_category', 'category_id' => $id], admin_url('admin-post.php')),
                'cikw_delete_category_' . $id
            );

            echo '<tr class="cikw-sortable-row">';
            echo '<td class="cikw-drag-handle" aria-label="' . esc_attr__('Σύρετε για αλλαγή σειράς', 'cik-prize-wheel') . '">☰<input class="cikw-order" type="hidden" name="categories[' . esc_attr((string) $id) . '][segment_order]" value="' . esc_attr((string) ($index + 1)) . '" /></td>';
            echo '<td><input type="text" name="categories[' . esc_attr((string) $id) . '][slug]" value="' . esc_attr((string) $category['slug']) . '" /></td>';
            echo '<td><input type="text" name="categories[' . esc_attr((string) $id) . '][name]" value="' . esc_attr((string) $category['name']) . '" /></td>';
            echo '<td><textarea name="categories[' . esc_attr((string) $id) . '][description]" rows="3">' . esc_textarea((string) $category['description']) . '</textarea></td>';
            echo '<td><input type="color" name="categories[' . esc_attr((string) $id) . '][color]" value="' . esc_attr($this->sanitize_hex((string) $category['color'])) . '" /></td>';
            echo '<td><input type="number" step="0.001" min="0" name="categories[' . esc_attr((string) $id) . '][wheel_weight]" value="' . esc_attr((string) $category['wheel_weight']) . '" /></td>';
            echo '<td>' . $this->render_image_control('categories[' . $id . '][image_id]', (int) $category['image_id']) . '</td>';
            echo '<td><input type="checkbox" name="categories[' . esc_attr((string) $id) . '][is_active]" value="1" ' . checked((int) $category['is_active'], 1, false) . ' /></td>';
            echo '<td><a class="button-link-delete" href="' . esc_url($delete_url) . '">' . esc_html__('Διαγραφή', 'cik-prize-wheel') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Νέα κατηγορία', 'cik-prize-wheel') . '</h2>';
        echo '<div class="cikw-grid-form">';
        echo '<label>' . esc_html__('Slug', 'cik-prize-wheel') . '<input type="text" name="new_category[slug]" /></label>';
        echo '<label>' . esc_html__('Όνομα', 'cik-prize-wheel') . '<input type="text" name="new_category[name]" /></label>';
        echo '<label>' . esc_html__('Χρώμα', 'cik-prize-wheel') . '<input type="color" name="new_category[color]" value="#006eb6" /></label>';
        echo '<label>' . esc_html__('Βάρος', 'cik-prize-wheel') . '<input type="number" step="0.001" min="0" name="new_category[wheel_weight]" value="1.000" /></label>';
        echo '<label class="cikw-grid-wide">' . esc_html__('Περιγραφή', 'cik-prize-wheel') . '<textarea name="new_category[description]" rows="3"></textarea></label>';
        echo '<label>' . esc_html__('Εικόνα', 'cik-prize-wheel') . $this->render_image_control('new_category[image_id]', 0) . '</label>';
        echo '<label class="cikw-checkbox-label"><input type="checkbox" name="new_category[is_active]" value="1" checked /> ' . esc_html__('Ενεργή', 'cik-prize-wheel') . '</label>';
        echo '</div>';

        submit_button(__('Αποθήκευση κατηγοριών', 'cik-prize-wheel'));
        echo '</form>';
        echo '</div>';
    }

    public function render_prizes_page(): void
    {
        $this->require_manager();

        $categories = $this->get_categories();
        $prizes_by_category = $this->get_prizes_grouped_by_category();

        echo '<div class="wrap cikw-admin-page">';
        echo '<h1>' . esc_html__('Δώρα', 'cik-prize-wheel') . '</h1>';
        $this->render_message_notice();

        if (empty($categories)) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Προσθέστε πρώτα κατηγορίες για να ορίσετε δώρα.', 'cik-prize-wheel') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('cikw_save_prizes');
        echo '<input type="hidden" name="action" value="cikw_save_prizes" />';

        foreach ($categories as $category) {
            $category_id = (int) $category['id'];
            $category_prizes = $prizes_by_category[$category_id] ?? [];
            $summary = $this->calculate_probability_summary($category_prizes);
            $summary_class = $summary['sum'] > 100 ? 'cikw-summary cikw-summary-error' : 'cikw-summary';

            echo '<section class="cikw-category-prize-group">';
            echo '<h2>' . esc_html((string) $category['name']) . '</h2>';
            echo '<div class="' . esc_attr($summary_class) . '" data-cikw-summary="1">';
            echo esc_html(sprintf(__('Σύνολο %.3g%% - Καμία νίκη %.3g%%', 'cik-prize-wheel'), $summary['sum'], $summary['no_win']));
            if ($summary['sum'] > 100) {
                echo ' <strong>' . esc_html__('Υπέρβαση ορίου', 'cik-prize-wheel') . '</strong>';
            }
            echo '</div>';

            echo '<table class="widefat fixed striped cikw-edit-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Τίτλος', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Περιγραφή', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Εικόνα', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Πιθανότητα %', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Σύνολο stock', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Υπόλοιπο', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Ημερήσιο όριο', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Ενεργό', 'cik-prize-wheel') . '</th>';
            echo '<th>' . esc_html__('Ενέργειες', 'cik-prize-wheel') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($category_prizes as $prize) {
                $id = (int) $prize['id'];
                $delete_url = wp_nonce_url(add_query_arg(['action' => 'cikw_delete_prize', 'prize_id' => $id], admin_url('admin-post.php')), 'cikw_delete_prize_' . $id);
                $reset_url = wp_nonce_url(add_query_arg(['action' => 'cikw_reset_prize_stock', 'prize_id' => $id], admin_url('admin-post.php')), 'cikw_reset_prize_stock_' . $id);

                echo '<tr>';
                echo '<td><input type="hidden" name="prizes[' . esc_attr((string) $id) . '][category_id]" value="' . esc_attr((string) $category_id) . '" /><input type="text" name="prizes[' . esc_attr((string) $id) . '][title]" value="' . esc_attr((string) $prize['title']) . '" /></td>';
                echo '<td><textarea name="prizes[' . esc_attr((string) $id) . '][description]" rows="3">' . esc_textarea((string) $prize['description']) . '</textarea></td>';
                echo '<td>' . $this->render_image_control('prizes[' . $id . '][image_id]', (int) $prize['image_id']) . '</td>';
                echo '<td><input class="cikw-prize-probability" type="number" step="0.001" min="0" max="100" name="prizes[' . esc_attr((string) $id) . '][probability]" value="' . esc_attr((string) $prize['probability']) . '" /></td>';
                echo '<td><input type="number" min="0" name="prizes[' . esc_attr((string) $id) . '][stock_total]" value="' . esc_attr((string) $prize['stock_total']) . '" /></td>';
                echo '<td><input type="number" min="0" name="prizes[' . esc_attr((string) $id) . '][stock_remaining]" value="' . esc_attr((string) $prize['stock_remaining']) . '" /></td>';
                echo '<td><input type="number" min="0" name="prizes[' . esc_attr((string) $id) . '][daily_cap]" value="' . esc_attr(null === $prize['daily_cap'] ? '' : (string) $prize['daily_cap']) . '" /></td>';
                echo '<td><input class="cikw-prize-active" type="checkbox" name="prizes[' . esc_attr((string) $id) . '][is_active]" value="1" ' . checked((int) $prize['is_active'], 1, false) . ' /></td>';
                echo '<td><a href="' . esc_url($reset_url) . '">' . esc_html__('Reset stock', 'cik-prize-wheel') . '</a><br /><a class="button-link-delete" href="' . esc_url($delete_url) . '">' . esc_html__('Διαγραφή', 'cik-prize-wheel') . '</a></td>';
                echo '</tr>';
            }

            echo '<tr class="cikw-new-row">';
            echo '<td><input type="text" name="new_prizes[' . esc_attr((string) $category_id) . '][title]" placeholder="' . esc_attr__('Νέο δώρο', 'cik-prize-wheel') . '" /></td>';
            echo '<td><textarea name="new_prizes[' . esc_attr((string) $category_id) . '][description]" rows="3"></textarea></td>';
            echo '<td>' . $this->render_image_control('new_prizes[' . $category_id . '][image_id]', 0) . '</td>';
            echo '<td><input class="cikw-prize-probability" type="number" step="0.001" min="0" max="100" name="new_prizes[' . esc_attr((string) $category_id) . '][probability]" value="0" /></td>';
            echo '<td><input type="number" min="0" name="new_prizes[' . esc_attr((string) $category_id) . '][stock_total]" value="0" /></td>';
            echo '<td><input type="number" min="0" name="new_prizes[' . esc_attr((string) $category_id) . '][stock_remaining]" value="0" /></td>';
            echo '<td><input type="number" min="0" name="new_prizes[' . esc_attr((string) $category_id) . '][daily_cap]" /></td>';
            echo '<td><input class="cikw-prize-active" type="checkbox" name="new_prizes[' . esc_attr((string) $category_id) . '][is_active]" value="1" checked /></td>';
            echo '<td>' . esc_html__('Προσθήκη με αποθήκευση', 'cik-prize-wheel') . '</td>';
            echo '</tr>';
            echo '</tbody></table>';
            echo '</section>';
        }

        submit_button(__('Αποθήκευση δώρων', 'cik-prize-wheel'));
        echo '</form>';
        echo '</div>';
    }

    public function handle_save_categories(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_save_categories');

        global $wpdb;
        $table = CIKW_Activator::table_name('categories');
        $rows = isset($_POST['categories']) && is_array($_POST['categories']) ? wp_unslash($_POST['categories']) : [];

        foreach ($rows as $id => $row) {
            $id = absint($id);
            $data = $this->sanitize_category_row(is_array($row) ? $row : []);

            if ($id <= 0 || '' === $data['name']) {
                continue;
            }

            $wpdb->update(
                $table,
                $data,
                ['id' => $id],
                ['%s', '%s', '%s', '%d', '%s', '%f', '%d', '%d'],
                ['%d']
            );
        }

        $new = isset($_POST['new_category']) && is_array($_POST['new_category']) ? wp_unslash($_POST['new_category']) : [];
        $new_data = $this->sanitize_category_row($new);

        if ('' !== $new_data['name']) {
            $new_data['segment_order'] = $this->next_category_order();
            $wpdb->insert($table, $new_data, ['%s', '%s', '%s', '%d', '%s', '%f', '%d', '%d']);
        }

        $this->redirect('cikw-categories', ['cikw_message' => 'saved']);
    }

    public function handle_delete_category(): void
    {
        $this->require_manager();
        $id = isset($_GET['category_id']) ? absint($_GET['category_id']) : 0;
        check_admin_referer('cikw_delete_category_' . $id);

        if ($id <= 0) {
            $this->redirect('cikw-categories', ['cikw_message' => 'invalid']);
        }

        global $wpdb;
        $prizes = CIKW_Activator::table_name('prizes');
        $categories = CIKW_Activator::table_name('categories');
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE category_id = %d', $prizes, $id));

        if ($count > 0) {
            $this->redirect('cikw-categories', ['cikw_message' => 'category_has_prizes']);
        }

        $wpdb->delete($categories, ['id' => $id], ['%d']);
        $this->redirect('cikw-categories', ['cikw_message' => 'deleted']);
    }

    public function handle_save_prizes(): void
    {
        $this->require_manager();
        check_admin_referer('cikw_save_prizes');

        $existing_rows = isset($_POST['prizes']) && is_array($_POST['prizes']) ? wp_unslash($_POST['prizes']) : [];
        $new_rows = isset($_POST['new_prizes']) && is_array($_POST['new_prizes']) ? wp_unslash($_POST['new_prizes']) : [];
        $sanitized_existing = [];
        $sanitized_new = [];
        $active_probability_by_category = [];

        foreach ($existing_rows as $id => $row) {
            $id = absint($id);
            $data = $this->sanitize_prize_row(is_array($row) ? $row : []);

            if ($id <= 0 || '' === $data['title']) {
                continue;
            }

            $sanitized_existing[$id] = $data;
            if ((int) $data['is_active'] === 1) {
                $active_probability_by_category[$data['category_id']] = ($active_probability_by_category[$data['category_id']] ?? 0) + (float) $data['probability'];
            }
        }

        foreach ($new_rows as $category_id => $row) {
            $category_id = absint($category_id);
            $data = $this->sanitize_prize_row(is_array($row) ? array_merge($row, ['category_id' => $category_id]) : ['category_id' => $category_id]);

            if ($category_id <= 0 || '' === $data['title']) {
                continue;
            }

            $sanitized_new[] = $data;
            if ((int) $data['is_active'] === 1) {
                $active_probability_by_category[$data['category_id']] = ($active_probability_by_category[$data['category_id']] ?? 0) + (float) $data['probability'];
            }
        }

        foreach ($active_probability_by_category as $sum) {
            if ($sum > 100.0001) {
                $this->redirect('cikw-prizes', ['cikw_message' => 'probability_exceeded']);
            }
        }

        global $wpdb;
        $table = CIKW_Activator::table_name('prizes');
        $formats = ['%d', '%s', '%s', '%d', '%f', '%d', '%d', '%d', '%d'];

        foreach ($sanitized_existing as $id => $data) {
            $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
        }

        foreach ($sanitized_new as $data) {
            $wpdb->insert($table, $data, $formats);
        }

        $this->redirect('cikw-prizes', ['cikw_message' => 'saved']);
    }

    public function handle_delete_prize(): void
    {
        $this->require_manager();
        $id = isset($_GET['prize_id']) ? absint($_GET['prize_id']) : 0;
        check_admin_referer('cikw_delete_prize_' . $id);

        if ($id > 0) {
            global $wpdb;
            $wpdb->delete(CIKW_Activator::table_name('prizes'), ['id' => $id], ['%d']);
        }

        $this->redirect('cikw-prizes', ['cikw_message' => 'deleted']);
    }

    public function handle_reset_prize_stock(): void
    {
        $this->require_manager();
        $id = isset($_GET['prize_id']) ? absint($_GET['prize_id']) : 0;
        check_admin_referer('cikw_reset_prize_stock_' . $id);

        if ($id > 0) {
            global $wpdb;
            $table = CIKW_Activator::table_name('prizes');
            $wpdb->query($wpdb->prepare('UPDATE %i SET stock_remaining = stock_total WHERE id = %d', $table, $id));
        }

        $this->redirect('cikw-prizes', ['cikw_message' => 'stock_reset']);
    }

    private function get_categories(): array
    {
        global $wpdb;
        $table = CIKW_Activator::table_name('categories');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY segment_order ASC, id ASC', $table), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    private function get_prizes_grouped_by_category(): array
    {
        global $wpdb;
        $table = CIKW_Activator::table_name('prizes');
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY category_id ASC, id ASC', $table), ARRAY_A);
        $grouped = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $grouped[(int) $row['category_id']][] = $row;
        }

        return $grouped;
    }

    private function calculate_probability_summary(array $prizes): array
    {
        $sum = 0.0;

        foreach ($prizes as $prize) {
            if ((int) $prize['is_active'] === 1) {
                $sum += (float) $prize['probability'];
            }
        }

        return [
            'sum' => $sum,
            'no_win' => max(0, 100 - $sum),
        ];
    }

    private function sanitize_category_row(array $row): array
    {
        $name = sanitize_text_field((string) ($row['name'] ?? ''));
        $slug = sanitize_title((string) ($row['slug'] ?? ''));

        if ('' === $slug && '' !== $name) {
            $slug = sanitize_title($name);
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
            'image_id' => absint($row['image_id'] ?? 0),
            'color' => $this->sanitize_hex((string) ($row['color'] ?? '#006eb6')),
            'wheel_weight' => max(0, (float) ($row['wheel_weight'] ?? 1)),
            'segment_order' => absint($row['segment_order'] ?? 0),
            'is_active' => empty($row['is_active']) ? 0 : 1,
        ];
    }

    private function sanitize_prize_row(array $row): array
    {
        $stock_total = max(0, absint($row['stock_total'] ?? 0));
        $stock_remaining = max(0, absint($row['stock_remaining'] ?? 0));

        if ($stock_total > 0) {
            $stock_remaining = min($stock_remaining, $stock_total);
        }

        $daily_cap = isset($row['daily_cap']) && '' !== (string) $row['daily_cap'] ? max(0, absint($row['daily_cap'])) : null;

        return [
            'category_id' => absint($row['category_id'] ?? 0),
            'title' => sanitize_text_field((string) ($row['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
            'image_id' => absint($row['image_id'] ?? 0),
            'probability' => min(100, max(0, (float) ($row['probability'] ?? 0))),
            'stock_total' => $stock_total,
            'stock_remaining' => $stock_remaining,
            'daily_cap' => $daily_cap,
            'is_active' => empty($row['is_active']) ? 0 : 1,
        ];
    }

    private function next_category_order(): int
    {
        global $wpdb;
        $table = CIKW_Activator::table_name('categories');

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(segment_order), 0) + 1 FROM %i', $table));
    }

    private function sanitize_hex(string $color): string
    {
        $color = trim($color);

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return strtolower($color);
        }

        return '#006eb6';
    }

    private function render_image_control(string $name, int $image_id): string
    {
        $input_id = 'cikw-image-' . wp_unique_id();
        $preview = '';

        if ($image_id > 0) {
            $preview_src = wp_get_attachment_image_url($image_id, 'thumbnail');
            if ($preview_src) {
                $preview = '<img class="cikw-image-preview" src="' . esc_url($preview_src) . '" alt="" />';
            }
        }

        return '<div class="cikw-image-control">' . $preview . '<input id="' . esc_attr($input_id) . '" type="number" min="0" name="' . esc_attr($name) . '" value="' . esc_attr((string) $image_id) . '" /> <button type="button" class="button cikw-media-select" data-target="' . esc_attr($input_id) . '">' . esc_html__('Επιλογή', 'cik-prize-wheel') . '</button></div>';
    }

    private function render_message_notice(): void
    {
        $message = isset($_GET['cikw_message']) ? sanitize_key(wp_unslash((string) $_GET['cikw_message'])) : '';

        if ('' === $message) {
            return;
        }

        $messages = [
            'saved' => ['success', __('Οι αλλαγές αποθηκεύτηκαν.', 'cik-prize-wheel')],
            'deleted' => ['success', __('Η εγγραφή διαγράφηκε.', 'cik-prize-wheel')],
            'stock_reset' => ['success', __('Το υπόλοιπο stock επανήλθε στο συνολικό stock.', 'cik-prize-wheel')],
            'probability_exceeded' => ['error', __('Το άθροισμα ενεργών πιθανοτήτων σε μία κατηγορία ξεπερνά το 100%. Δεν αποθηκεύτηκαν αλλαγές.', 'cik-prize-wheel')],
            'category_has_prizes' => ['error', __('Η κατηγορία έχει δώρα. Διαγράψτε πρώτα τα δώρα της.', 'cik-prize-wheel')],
            'invalid' => ['error', __('Μη έγκυρη ενέργεια.', 'cik-prize-wheel')],
        ];

        if (!isset($messages[$message])) {
            return;
        }

        [$type, $text] = $messages[$message];
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    private function redirect(string $page, array $args = []): void
    {
        wp_safe_redirect(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php')));
        exit;
    }

    private function require_manager(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Δεν έχετε δικαίωμα πρόσβασης.', 'cik-prize-wheel'));
        }
    }
}