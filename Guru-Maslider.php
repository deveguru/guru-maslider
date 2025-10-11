<?php
/**
 * Plugin Name:       Guru MaSlider
 * Description:       A professional slider and grid builder for WordPress with 9 fully responsive layouts, advanced styling, and AJAX term selection.
 * Version:           3.3.1
 * Author:            alireza fatemi
 * Author URI:        https://alirezafatemi.ir
 * Plugin URI:        https://github.com/deveguru
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guru-maslider
 */

if (!defined('ABSPATH')) exit;

final class Guru_MaSlider_Advanced {
    private static $instance = null;
    private static $rendered_sliders = [];

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'init_hooks']);
        add_action('wp_ajax_gms_get_terms', [$this, 'ajax_get_terms']);
    }

    public function init_hooks() {
        $this->register_cpt();
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('save_post_guru_slider', [$this, 'save_metadata']);
        add_filter('manage_guru_slider_posts_columns', [$this, 'add_shortcode_column']);
        add_action('manage_guru_slider_posts_custom_column', [$this, 'render_shortcode_column'], 10, 2);
        add_shortcode('guru_maslider', [$this, 'render_shortcode']);
        add_action('wp_footer', [$this, 'inject_assets'], 100);
    }

    public function register_cpt() {
        $labels = ['name' => 'اسلایدرها', 'singular_name' => 'اسلایدر', 'menu_name' => 'Guru MaSlider', 'add_new' => 'افزودن جدید', 'add_new_item' => 'افزودن اسلایدر جدید', 'edit_item' => 'ویرایش اسلایدر', 'all_items' => 'همه اسلایدرها'];
        register_post_type('guru_slider', ['labels' => $labels, 'public' => false, 'show_ui' => true, 'show_in_menu' => true, 'menu_position' => 20, 'menu_icon' => 'dashicons-slides', 'supports' => ['title']]);
    }

    public function admin_assets($hook) {
        global $post;
        if ($hook !== 'post-new.php' && $hook !== 'post.php') return;
        if (isset($post->post_type) && $post->post_type === 'guru_slider') {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_script('wp-color-picker');
            wp_add_inline_style('wp-color-picker', '.wp-color-result-text { direction: ltr; }');
            wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".gms-color-picker").wpColorPicker();});');
            wp_add_inline_style('wp-admin', '#gms-tabs-panel .nav-tab{font-size:14px;padding:10px 16px}#gms-tabs-panel .tabs-panel{display:none;padding:20px;background:#fff;border:1px solid #ccd0d4;border-top:0}#gms-tabs-panel .tabs-panel.active{display:block}.gms-style-group{display:none;padding-top:15px;border-top:1px dashed #ddd;margin-top:15px}.gms-style-group.active{display:block}#gms-product-filter-row{display:none}.gms-ajax-select{width:100%}.ts-wrapper{direction:rtl!important}');
            wp_add_inline_script('post', '
            document.addEventListener("DOMContentLoaded", function() {
                const tabs = document.querySelectorAll("#gms-tabs-panel .nav-tab");
                const panels = document.querySelectorAll("#gms-tabs-panel .tabs-panel");
                tabs.forEach(tab => {
                    tab.addEventListener("click", function(e) {
                        e.preventDefault();
                        tabs.forEach(t => t.classList.remove("nav-tab-active"));
                        panels.forEach(p => p.classList.remove("active"));
                        this.classList.add("nav-tab-active");
                        document.querySelector(this.getAttribute("href")).classList.add("active");
                    });
                });

                const layoutSelect = document.querySelector("#gms_layout");
                const styleGroups = document.querySelectorAll(".gms-style-group");
                function toggleStyleGroups() {
                    const selectedLayout = layoutSelect.value;
                    styleGroups.forEach(group => {
                        if (group.getAttribute("data-layout").split(",").includes(selectedLayout)) {
                            group.classList.add("active");
                        } else {
                            group.classList.remove("active");
                        }
                    });
                }
                layoutSelect.addEventListener("change", toggleStyleGroups);
                toggleStyleGroups();

                const contentTypeSelect = document.getElementById("gms_content_type");
                const productFilterRow = document.getElementById("gms-product-filter-row");
                function toggleProductFilter() {
                    if (contentTypeSelect.value === "product") {
                        productFilterRow.style.display = "table-row";
                    } else {
                        productFilterRow.style.display = "none";
                    }
                }
                contentTypeSelect.addEventListener("change", toggleProductFilter);
                toggleProductFilter();
            });');
        }
    }

    public function ajax_get_terms() {
        check_ajax_referer('gms_term_search', 'nonce');
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : 'category';
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $terms = get_terms(['taxonomy' => $taxonomy, 'name__like' => $search, 'hide_empty' => false]);
        wp_send_json(array_map(fn($term) => ['id' => $term->term_id, 'text' => $term->name], $terms));
    }
    
    public function add_meta_boxes() {
        add_meta_box('guru_slider_config', 'تنظیمات اسلایدر', [$this, 'render_config_metabox'], 'guru_slider', 'normal', 'high');
        add_meta_box('guru_slider_shortcode', 'شورت‌کد', [$this, 'render_shortcode_metabox'], 'guru_slider', 'side', 'default');
    }

    public function render_shortcode_metabox($post) {
        $value = '[guru_maslider id="' . esc_attr($post->ID) . '"]';
        echo $post->post_status === 'publish' ? '<p>از این شورت‌کد برای نمایش استفاده کنید:</p><input type="text" readonly="readonly" value="' . $value . '" style="width:100%; text-align:left; direction:ltr;" onclick="this.select();" />' : '<p>ابتدا اسلایدر را منتشر کنید.</p>';
    }

    private function render_metabox_field($args) {
        $meta = $args['meta']; $key = $args['key']; $default = $args['default'] ?? '';
        $value = $meta[$key][0] ?? $default;
        $label = $args['label']; $type = $args['type']; $options = $args['options'] ?? [];
        $desc = $args['desc'] ?? ''; $class = $args['class'] ?? 'regular-text'; $tr_id = $args['tr_id'] ?? '';
        echo '<tr' . ($tr_id ? ' id="' . esc_attr($tr_id) . '"' : '') .'><th><label for="gms_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>';
        switch ($type) {
            case 'select':
                echo '<select name="' . esc_attr($key) . '" id="gms_' . esc_attr($key) . '">';
                foreach ($options as $opt_val => $opt_label) { echo '<option value="' . esc_attr($opt_val) . '" ' . selected($value, $opt_val, false) . '>' . esc_html($opt_label) . '</option>'; }
                echo '</select>'; break;
            case 'number': echo '<input type="number" name="' . esc_attr($key) . '" id="gms_' . esc_attr($key) . '" value="' . esc_attr($value) . '" min="1" max="50">'; break;
            case 'ajax_select':
                echo '<select name="' . esc_attr($key) . '[]" id="gms_' . esc_attr($key) . '" multiple="multiple" class="gms-ajax-select" data-taxonomy="' . esc_attr($args['taxonomy']) . '">';
                if (!empty($value) && is_array($value)) {
                    foreach ($value as $term_id) {
                        $term = get_term($term_id);
                        if ($term && !is_wp_error($term)) { echo '<option value="' . esc_attr($term->term_id) . '" selected>' . esc_html($term->name) . '</option>'; }
                    }
                }
                echo '</select>'; break;
            case 'color': echo '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="gms-color-picker" data-default-color="' . esc_attr($default) . '">'; break;
            case 'url': echo '<input type="url" name="' . esc_attr($key) . '" id="gms_' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="' . esc_attr($class) . '">'; break;
            default: echo '<input type="text" name="' . esc_attr($key) . '" id="gms_' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="' . esc_attr($class) . '">'; break;
        }
        if ($desc) echo '<p class="description">' . esc_html($desc) . '</p>';
        echo '</td></tr>';
    }

    public function render_config_metabox($post) {
        wp_nonce_field('guru_slider_nonce', 'guru_slider_nonce_field');
        $meta = get_post_meta($post->ID);
        $style_meta = get_post_meta($post->ID, '_gms_style', true);
        if (!is_array($style_meta)) $style_meta = [];
        $merged_meta = array_merge($meta, array_map(fn($v) => [$v], $style_meta));
        $layouts = ['1'=>'طرح ۱ - گرید ساده','2'=>'طرح ۲ - اسلایدر درگ','3'=>'طرح ۳ - کارت‌های بزرگ','4'=>'طرح ۴ - گالری تصاویر','5'=>'طرح ۵ - اسلایدر پرسپکتیو','6'=>'طرح ۶ - گرید مینیمال','7'=>'طرح ۷ - کارت‌های تمام‌عرض','8'=>'طرح ۸ - لیست عمودی','9'=>'طرح ۹ - گرید نامتقارن'];
        $product_filters = ['default' => 'پیش‌فرض (بر اساس تاریخ)', 'popularity' => 'محصولات پرفروش', 'price_desc' => 'گران‌ترین محصولات', 'price_asc' => 'ارزان‌ترین محصولات', 'views' => 'پربازدیدترین محصولات'];
        
        echo '<div id="gms-tabs-panel">';
        echo '<h2 class="nav-tab-wrapper"><a href="#gms-tab-general" class="nav-tab nav-tab-active">تنظیمات عمومی</a><a href="#gms-tab-style" class="nav-tab">تنظیمات استایل</a></h2>';
        echo '<div id="gms-tab-general" class="tabs-panel active"><table class="form-table">';
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'layout', 'label' => 'طرح نمایش', 'type' => 'select', 'options' => $layouts]);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'content_type', 'label' => 'منبع محتوا', 'type' => 'select', 'options' => ['post' => 'نوشته‌ها', 'product' => 'محصولات ووکامرس']]);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'product_filter', 'label' => 'فیلتر محصولات', 'type' => 'select', 'options' => $product_filters, 'tr_id' => 'gms-product-filter-row', 'desc' => 'این فیلترها فقط برای محصولات ووکامرس اعمال می‌شوند.']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'categories', 'label' => 'دسته‌بندی‌ها', 'type' => 'ajax_select', 'taxonomy' => 'category', 'desc' => 'شروع به تایپ نام دسته‌بندی کنید (برای نوشته‌ها و محصولات).']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'tags', 'label' => 'برچسب‌ها', 'type' => 'ajax_select', 'taxonomy' => 'post_tag', 'desc' => 'شروع به تایپ نام برچسب کنید (برای نوشته‌ها و محصولات).']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'count', 'label' => 'تعداد آیتم', 'type' => 'number', 'default' => 8]);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'orderby', 'label' => 'مرتب‌سازی', 'type' => 'select', 'options' => ['date' => 'تاریخ', 'title' => 'عنوان', 'rand' => 'تصادفی'], 'desc' => 'در صورت انتخاب فیلتر خاص برای محصولات، این گزینه نادیده گرفته می‌شود.']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'order', 'label' => 'ترتیب', 'type' => 'select', 'options' => ['DESC' => 'نزولی', 'ASC' => 'صعودی']]);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'view_more_link', 'label' => 'لینک مشاهده بیشتر', 'type' => 'url', 'desc' => 'لینک دکمه "مشاهده بیشتر" برای این اسلایدر. (اختیاری)']);
        echo '</table></div>';
        
        echo '<div id="gms-tab-style" class="tabs-panel"><p class="description">رنگ‌های مربوط به طرح انتخابی خود را در اینجا سفارشی کنید. اگر مقداری خالی باشد، از استایل پیش‌فرض استفاده خواهد شد.</p>';
        echo '<div class="gms-style-group" data-layout="1,2,3,4,5,6,7,8,9"><table class="form-table">';
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_bg', 'label' => 'رنگ پس‌زمینه بخش', 'type' => 'color', 'default' => '#EEEEEE']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_card_bg', 'label' => 'رنگ پس‌زمینه کارت', 'type' => 'color', 'default' => '#FFFFFF']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_title', 'label' => 'رنگ عنوان', 'type' => 'color', 'default' => '#333333']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_text', 'label' => 'رنگ متن/توضیحات', 'type' => 'color', 'default' => '#666666']);
        echo '</table></div>';

        echo '<div class="gms-style-group" data-layout="1,2,3,4,5,6,7,8,9"><table class="form-table">';
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_view_more_btn', 'label' => 'رنگ دکمه مشاهده بیشتر', 'type' => 'color', 'default' => '#00CED1']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_view_more_btn_hover', 'label' => 'رنگ هاور دکمه مشاهده بیشتر', 'type' => 'color', 'default' => '#40E0D0']);
        echo '</table></div>';

        echo '<div class="gms-style-group" data-layout="2,4,5,7"><table class="form-table">';
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_primary', 'label' => 'رنگ اصلی (دکمه/نوار)', 'type' => 'color', 'default' => '#00CED1']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_primary_hover', 'label' => 'رنگ هاور اصلی', 'type' => 'color', 'default' => '#40E0D0']);
        $this->render_metabox_field(['meta' => $merged_meta, 'key' => 'c_overlay', 'label' => 'رنگ روکش تصویر (Overlay)', 'type' => 'color', 'default' => 'rgba(0,0,0,0.5)']);
        echo '</table></div>';
        echo '</div>';
        echo '</div>';
        
        $ajax_url = admin_url('admin-ajax.php'); $nonce = wp_create_nonce('gms_term_search');
        ?>
        <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.bootstrap5.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.gms-ajax-select').forEach(function(el) {
                new TomSelect(el, {
                    valueField: 'id', labelField: 'text', searchField: 'text', create: false,
                    load: function(query, callback) {
                        const contentType = document.getElementById('gms_content_type').value;
                        let taxonomy = el.dataset.taxonomy;
                        if (contentType === 'product') {
                            if (taxonomy === 'category') taxonomy = 'product_cat';
                            if (taxonomy === 'post_tag') taxonomy = 'product_tag';
                        }
                        fetch('<?php echo $ajax_url; ?>', {
                            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: new URLSearchParams({'action':'gms_get_terms','nonce':'<?php echo $nonce; ?>','taxonomy':taxonomy,'search':query})
                        }).then(res => res.json()).then(data => callback(data)).catch(() => callback());
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function save_metadata($post_id) {
        if (!isset($_POST['guru_slider_nonce_field']) || !wp_verify_nonce($_POST['guru_slider_nonce_field'], 'guru_slider_nonce') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
        $fields = ['layout', 'content_type', 'product_filter', 'count', 'orderby', 'order', 'view_more_link'];
        foreach ($fields as $field) if (isset($_POST[$field])) update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        $tax_fields = ['categories', 'tags'];
        foreach($tax_fields as $tax_field) if (isset($_POST[$tax_field])) update_post_meta($post_id, $tax_field, array_map('intval', $_POST[$tax_field])); else delete_post_meta($post_id, $tax_field);
        $style_fields = ['c_bg', 'c_card_bg', 'c_title', 'c_text', 'c_primary', 'c_primary_hover', 'c_overlay', 'c_view_more_btn', 'c_view_more_btn_hover'];
        $style_data = [];
        foreach ($style_fields as $field) if (isset($_POST[$field]) && !empty($_POST[$field])) $style_data[$field] = sanitize_hex_color_no_hash(ltrim($_POST[$field], '#')) ? '#' . sanitize_hex_color_no_hash(ltrim($_POST[$field], '#')) : sanitize_text_field($_POST[$field]);
        update_post_meta($post_id, '_gms_style', $style_data);
    }
    
    public function add_shortcode_column($columns) { return array_merge(array_slice($columns, 0, 2), ['shortcode' => 'شورت‌کد'], array_slice($columns, 2)); }
    public function render_shortcode_column($column, $post_id) { if ($column === 'shortcode') echo '<input type="text" readonly value="[guru_maslider id=&quot;' . esc_attr($post_id) . '&quot;]" style="width:100%;text-align:left;direction:ltr" onclick="this.select();" />'; }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'guru_maslider');
        $id = intval($atts['id']);
        if (!$id || get_post_type($id) !== 'guru_slider') return '';
        self::$rendered_sliders[$id] = true;
        $meta = get_post_meta($id);
        $get = fn($k, $d='') => $meta[$k][0] ?? $d;
        $settings = [
            'layout'=>$get('layout','1'),
            'type'=>$get('content_type','post'),
            'product_filter'=>$get('product_filter','default'),
            'count'=>$get('count',8),
            'orderby'=>$get('orderby','date'),
            'order'=>$get('order','DESC'),
            'cats'=>maybe_unserialize($get('categories','a:0:{}')),
            'tags'=>maybe_unserialize($get('tags','a:0:{}')),
            'view_more_link'=>$get('view_more_link',''),
            'style'=>get_post_meta($id,'_gms_style',true)?:[]
        ];
        
        $settings['style']['c_view_more_btn'] = $settings['style']['c_view_more_btn'] ?? '#00CED1';
        $settings['style']['c_view_more_btn_hover'] = $settings['style']['c_view_more_btn_hover'] ?? '#40E0D0';

        $post_type = ($settings['type'] === 'product' && class_exists('WooCommerce')) ? 'product' : 'post';
        $args = ['post_type' => $post_type, 'posts_per_page' => $settings['count'], 'post_status' => 'publish', 'orderby' => $settings['orderby'], 'order' => $settings['order']];

        if ($post_type === 'product' && $settings['product_filter'] !== 'default') {
            switch ($settings['product_filter']) {
                case 'popularity': $args['meta_key'] = 'total_sales'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
                case 'price_desc': $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
                case 'price_asc':  $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'ASC'; break;
                case 'views':      $args['meta_key'] = 'post_views_count'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
            }
        }
        
        $tax_query = [];
        if (!empty($settings['cats'])) $tax_query[] = ['taxonomy' => ($post_type === 'product' ? 'product_cat' : 'category'), 'field' => 'term_id', 'terms' => $settings['cats']];
        if (!empty($settings['tags'])) $tax_query[] = ['taxonomy' => ($post_type === 'product' ? 'product_tag' : 'post_tag'), 'field' => 'term_id', 'terms' => $settings['tags']];
        if(count($tax_query) > 1) $tax_query['relation'] = 'OR';
        if(!empty($tax_query)) $args['tax_query'] = $tax_query;
        
        $query = new WP_Query($args);
        ob_start();
        echo '<div id="gms-' . $id . '" class="gms-container">';
        if (!empty($settings['view_more_link'])) {
            echo '<div class="gms-slider-header">';
            echo '<a href="' . esc_url($settings['view_more_link']) . '" class="gms-view-more-button">مشاهده بیشتر</a>';
            echo '</div>';
        }
        if ($query->have_posts()) {
            $render_method = "render_layout_{$settings['layout']}";
            if (method_exists($this, $render_method)) $this->$render_method($query, $id, $settings['style']);
        }
        echo '</div>';
        wp_reset_postdata(); return ob_get_clean();
    }
    
    private function get_img($size, $fallback_dims) { return get_the_post_thumbnail_url(get_the_ID(), $size) ?: "https://via.placeholder.com/{$fallback_dims}"; }
    
    private function render_layout_1($q, $id, $s) { 
        echo '<section class="latest-products-section"><div class="products-grid">'; 
        $delay_counter = 0;
        while ($q->have_posts()) : $q->the_post(); 
            printf('<a href="%s" class="product-card" style="animation-delay:%.1fs;"><img src="%s" alt="%s" class="product-image"><h3 class="product-title">%s</h3></a>', 
                esc_url(get_permalink()),
                $delay_counter * 0.1,
                $this->get_img('thumbnail','80x80'), 
                esc_attr(get_the_title()), 
                esc_html(get_the_title())
            );
            $delay_counter++;
        endwhile; 
        echo '</div></section>'; 
    }

    private function render_layout_2($q, $id, $s) { echo '<div class="maz-drag-slider-wrapper"><section class="slider-section"><div class="slider-container"><div class="slider-track">'; while ($q->have_posts()) : $q->the_post(); printf('<a href="%s" class="product-card"><img src="%s" alt="%s" class="product-image"><div class="product-content"><h3 class="product-title">%s</h3><p class="product-description">%s</p><span class="product-button">مشاهده</span></div></a>', esc_url(get_permalink()), $this->get_img('medium','300x220'), esc_attr(get_the_title()), esc_html(get_the_title()), esc_html(wp_trim_words(get_the_excerpt(), 12, '...'))); endwhile; echo '</div></div></section></div>'; }
    private function render_layout_3($q, $id, $s) { echo '<div class="maz-category-card-wrapper"><section class="category-section"><div class="category-grid">'; while ($q->have_posts()) : $q->the_post(); printf('<a href="%s" class="category-card"><img src="%s" alt="%s" class="card-background"><div class="card-overlay"></div><div class="card-content"><h3 class="card-title">%s</h3></div></a>', esc_url(get_permalink()), $this->get_img('large','600x400'), esc_attr(get_the_title()), esc_html(get_the_title())); endwhile; echo '</div></section></div>'; }
    private function render_layout_4($q, $id, $s) { echo '<div class="maz-image-gallery-section"><div class="maz-gallery-container"><div class="maz-image-grid">'; while ($q->have_posts()) : $q->the_post(); printf('<a href="%s" class="maz-image-card"><img class="maz-image-card-bg" src="%s" alt="%s"><div class="maz-image-card-overlay"><h3 class="maz-image-card-title">%s</h3></div></a>', esc_url(get_permalink()), $this->get_img('medium_large','400x400'), esc_attr(get_the_title()), esc_html(get_the_title())); endwhile; echo '</div></div></div>'; }
    private function render_layout_5($q, $id, $s) { echo '<div class="gms-perspective-slider"><div class="gms-p-slider-track">'; while ($q->have_posts()): $q->the_post(); printf('<div class="gms-p-slide"><a href="%s"><img src="%s" alt="%s"><span>%s</span></a></div>', esc_url(get_permalink()), $this->get_img('medium_large','400x300'), esc_attr(get_the_title()), esc_html(get_the_title())); endwhile; echo '</div></div>'; }
    private function render_layout_6($q, $id, $s) { echo '<div class="gms-minimal-grid">'; while ($q->have_posts()): $q->the_post(); printf('<a href="%s" class="gms-m-item"><div class="gms-m-img" style="background-image:url(%s)"></div><div class="gms-m-content"><h3>%s</h3></div></a>', esc_url(get_permalink()), $this->get_img('medium','300x300'), esc_html(get_the_title())); endwhile; echo '</div>'; }
    private function render_layout_7($q, $id, $s) { echo '<div class="gms-full-width-cards">'; while ($q->have_posts()): $q->the_post(); printf('<a href="%s" class="gms-fw-card"><div class="gms-fw-bg" style="background-image:url(%s)"></div><div class="gms-fw-overlay"></div><div class="gms-fw-content"><h2>%s</h2><p>%s</p><span class="gms-fw-button">ادامه مطلب</span></div></a>', esc_url(get_permalink()), $this->get_img('large','800x500'), esc_attr(get_the_title()), esc_html(wp_trim_words(get_the_excerpt(), 20, '...'))); endwhile; echo '</div>'; }
    private function render_layout_8($q, $id, $s) { echo '<div class="gms-ticker-wrap"><div class="gms-ticker-move">'; $posts = $q->posts; foreach ($posts as $post) { setup_postdata($post); printf('<div class="gms-ticker-item"><a href="%s"><img src="%s" alt="%s"/><span>%s</span></a></div>', esc_url(get_permalink()), $this->get_img('thumbnail','50x50'), esc_attr(get_the_title()), esc_html(get_the_title())); } if (count($posts) < 10) { foreach ($posts as $post) { setup_postdata($post); printf('<div class="gms-ticker-item"><a href="%s"><img src="%s" alt="%s"/><span>%s</span></a></div>', esc_url(get_permalink()), $this->get_img('thumbnail','50x50'), esc_attr(get_the_title()), esc_html(get_the_title())); } } echo '</div></div>'; }
    private function render_layout_9($q, $id, $s) { echo '<div class="gms-masonry-grid">'; while ($q->have_posts()): $q->the_post(); printf('<a href="%s" class="gms-ms-item"><img src="%s" alt="%s"/><div class="gms-ms-overlay"><h3>%s</h3></div></a>', esc_url(get_permalink()), $this->get_img('large','400x300'), esc_attr(get_the_title()), esc_html(get_the_title())); endwhile; echo '</div>'; }

    public function inject_assets() {
        if (empty(self::$rendered_sliders)) return;
        echo '<style>@import url("https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap");.gms-container{font-family:"Vazirmatn",sans-serif;margin:2rem 0}.gms-slider-header{display:flex;justify-content:flex-start;max-width:1200px;margin:0 auto;padding:0 20px 20px}.gms-view-more-button{display:inline-block;padding:10px 20px;background-color:var(--gms-view-more-btn-bg,#00CED1);color:#fff;text-decoration:none;border-radius:8px;font-weight:600;transition:background-color .3s ease}.gms-view-more-button:hover{background-color:var(--gms-view-more-btn-hover-bg,#40E0D0)}.latest-products-section{background-color:var(--gms-bg,#f9f9f9);padding:50px 20px}.products-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px;max-width:1200px;margin:0 auto}.product-card{display:flex;align-items:center;background-color:var(--gms-card-bg,#fff);border-radius:12px;padding:10px;text-decoration:none;box-shadow:0 4px 15px rgba(0,0,0,.05);transition:transform .3s,box-shadow .3s;overflow:hidden;opacity:0;transform:translateY(20px);animation:fadeInUp .6s ease-out forwards}.product-card:hover{transform:translateY(-8px);box-shadow:0 12px 25px rgba(0,0,0,.1)}.product-image{width:80px;height:80px;flex-shrink:0;border-radius:8px;object-fit:cover;margin-left:15px}.product-title{color:var(--gms-title-color,#333);font-size:1rem;font-weight:600;line-height:1.4}@keyframes fadeInUp{to{opacity:1;transform:translateY(0)}}.maz-drag-slider-wrapper{background-color:var(--gms-bg,#eee);padding:40px 0}.slider-container{max-width:1400px;margin:0 auto;padding:0 15px}.slider-track{display:flex;overflow-x:auto;padding-bottom:25px;cursor:grab;-webkit-user-select:none;-moz-user-select:none;user-select:none;scrollbar-width:thin;scrollbar-color:var(--gms-primary,#00ced1) #d1d1d1}.slider-track.active{cursor:grabbing}.slider-track::-webkit-scrollbar{height:12px;background:#d1d1d1;border-radius:10px}.slider-track::-webkit-scrollbar-thumb{background:var(--gms-primary,#00ced1);border-radius:10px;border:3px solid #d1d1d1}.maz-drag-slider-wrapper .product-card{flex:0 0 300px;margin-right:15px;text-decoration:none;background:var(--gms-card-bg,#fff);border-radius:15px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;transition:transform .3s,box-shadow .3s}.maz-drag-slider-wrapper .product-card:hover{transform:translateY(-10px)}.maz-drag-slider-wrapper .product-image{width:100%;height:200px;object-fit:cover}.maz-drag-slider-wrapper .product-content{padding:20px;text-align:right}.maz-drag-slider-wrapper .product-title{color:var(--gms-title-color,#333);font-size:1.1rem;margin:0 0 10px}.maz-drag-slider-wrapper .product-description{color:var(--gms-text-color,#666);font-size:.9rem;line-height:1.6}.product-button{display:inline-block;margin-top:15px;padding:8px 18px;background-color:var(--gms-primary,#00ced1);color:#fff;border-radius:8px;text-decoration:none;font-weight:600}.product-button:hover{background-color:var(--gms-primary-hover,#40e0d0)}.maz-category-card-wrapper{background-color:var(--gms-bg,#eee);padding:60px 20px}.category-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:30px;max-width:1300px;margin:auto}.category-card{display:block;text-decoration:none;position:relative;height:400px;border-radius:15px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.1);transition:transform .4s,box-shadow .4s}.category-card:hover{transform:scale(1.03);box-shadow:0 15px 40px rgba(0,0,0,.2)}.card-background{width:100%;height:100%;object-fit:cover;transition:transform .6s ease}.category-card:hover .card-background{transform:scale(1.1)}.card-overlay{position:absolute;inset:0;background:var(--gms-overlay,linear-gradient(0deg,rgba(0,0,0,.7),transparent 50%))}.card-content{position:absolute;bottom:0;right:0;left:0;padding:30px;color:var(--gms-title-color,#fff)}.card-title{font-size:2rem;font-weight:700}.maz-image-gallery-section{background-color:var(--gms-bg,#eee);padding:60px 20px}.maz-gallery-container{max-width:1400px;margin:auto}.maz-image-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:25px}.maz-image-card{position:relative;overflow:hidden;aspect-ratio:1/1;box-shadow:0 8px 25px rgba(0,0,0,.08);transition:transform .4s,box-shadow .4s;text-decoration:none}.maz-image-card:hover{transform:translateY(-10px);box-shadow:0 12px 30px rgba(0,0,0,.15)}.maz-image-card-bg{width:100%;height:100%;object-fit:cover;transition:transform .6s ease}.maz-image-card:hover .maz-image-card-bg{transform:scale(1.1)}.maz-image-card-overlay{position:absolute;inset:0;background:var(--gms-overlay,linear-gradient(0deg,rgba(0,206,209,.9) 0%,transparent 70%));opacity:0;transition:opacity .4s ease;display:flex;align-items:flex-end;justify-content:center;padding:20px}.maz-image-card:hover .maz-image-card-overlay{opacity:1}.maz-image-card-title{color:var(--gms-title-color,#fff);font-size:1.4rem;font-weight:700;transform:translateY(20px);transition:transform .4s ease .1s}.maz-image-card:hover .maz-image-card-title{transform:translateY(0)}.gms-perspective-slider{height:350px;padding:20px 0;display:flex;align-items:center;justify-content:center;perspective:800px;overflow:hidden;position:relative;background-color:var(--gms-bg,#eee)}.gms-p-slider-track{transform-style:preserve-3d;display:flex;animation:gms-p-scroll 30s linear infinite}.gms-perspective-slider:hover .gms-p-slider-track{animation-play-state:paused}.gms-p-slide{flex-shrink:0;width:300px;height:220px;position:relative;overflow:hidden;border-radius:10px;box-shadow:0 10px 20px rgba(0,0,0,.2);transition:transform .5s,opacity .5s;margin:0 1rem}.gms-p-slide a{display:block;width:100%;height:100%}.gms-p-slide img{width:100%;height:100%;object-fit:cover}.gms-p-slide span{position:absolute;bottom:0;right:0;left:0;padding:15px;background:var(--gms-overlay,linear-gradient(0deg,rgba(0,0,0,.7),transparent));color:var(--gms-title-color,#fff);font-weight:600;font-size:.9rem}@keyframes gms-p-scroll{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}.gms-minimal-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:30px;padding:40px 20px;background-color:var(--gms-bg,#eee)}.gms-m-item{display:block;text-decoration:none;position:relative;overflow:hidden;border-radius:12px;aspect-ratio:1/1}.gms-m-img{width:100%;height:100%;background-size:cover;background-position:center;transition:transform .5s ease}.gms-m-item:hover .gms-m-img{transform:scale(1.1)}.gms-m-content{position:absolute;inset:0;background:var(--gms-overlay,rgba(0,0,0,.4));display:flex;align-items:flex-end;padding:1.5rem;opacity:0;transform:translateY(15px);transition:opacity .4s,transform .4s}.gms-m-item:hover .gms-m-content{opacity:1;transform:translateY(0)}.gms-m-content h3{color:var(--gms-title-color,#fff);font-size:1.5rem;text-shadow:1px 1px 3px rgba(0,0,0,.5);margin:0}.gms-full-width-cards{display:flex;flex-direction:column;gap:1rem;padding:40px 15px;background-color:var(--gms-bg,#eee)}.gms-fw-card{position:relative;min-height:350px;border-radius:10px;overflow:hidden;display:flex;align-items:flex-end;padding:2rem;text-decoration:none;transition:box-shadow .3s}.gms-fw-card:hover{box-shadow:0 15px 30px rgba(0,0,0,.2)}.gms-fw-bg{position:absolute;inset:0;background-size:cover;background-position:center;transition:transform .5s}.gms-fw-card:hover .gms-fw-bg{transform:scale(1.05)}.gms-fw-overlay{position:absolute;inset:0;background:var(--gms-overlay,linear-gradient(60deg,rgba(0,0,0,.8) 0%,rgba(0,0,0,0) 70%))}.gms-fw-content{position:relative;z-index:1;max-width:60%}.gms-fw-content h2{color:var(--gms-title-color,#fff);font-size:clamp(1.2rem,3vw,2.2rem);margin-bottom:.5rem}.gms-fw-content p{color:var(--gms-text-color,#eee);opacity:.8;font-size:clamp(.9rem,2vw,1rem)}.gms-fw-button{display:inline-block;padding:.6rem 1.2rem;background-color:var(--gms-primary,#00ced1);color:#fff;border-radius:5px;margin-top:1rem;font-weight:600;transition:background-color .3s}.gms-fw-button:hover{background-color:var(--gms-primary-hover,#40e0d0)}.gms-ticker-wrap{height:300px;overflow:hidden;position:relative;background:var(--gms-card-bg,#fff);border:1px solid #ddd;padding:10px}.gms-ticker-wrap:before,.gms-ticker-wrap:after{content:"";position:absolute;left:0;right:0;height:40px;z-index:1}.gms-ticker-wrap:before{top:10px;background:linear-gradient(180deg,var(--gms-card-bg, #fff),rgba(255,255,255,0))}.gms-ticker-wrap:after{bottom:10px;background:linear-gradient(0deg,var(--gms-card-bg, #fff),rgba(255,255,255,0))}.gms-ticker-move{animation:gms-ticker-scroll 35s linear infinite}.gms-ticker-wrap:hover .gms-ticker-move{animation-play-state:paused}.gms-ticker-item a{display:flex;align-items:center;padding:10px;text-decoration:none;color:var(--gms-text-color,#333);border-bottom:1px solid #eee;transition:background-color .2s}.gms-ticker-item a:hover{background-color:#f9f9f9}.gms-ticker-item a span{color:var(--gms-title-color,#333)}.gms-ticker-item img{width:40px;height:40px;border-radius:50%;margin-left:10px;object-fit:cover}@keyframes gms-ticker-scroll{0%{transform:translateY(0)}100%{transform:translateY(-50%)}}.gms-masonry-grid{padding:20px;background-color:var(--gms-bg,#eee);columns:4;gap:15px}@media(max-width:1200px){.gms-masonry-grid{columns:3}}@media(max-width:768px){.gms-slider-header{justify-content:center;padding:0 15px 15px}.gms-masonry-grid{columns:2}.products-grid{grid-template-columns:repeat(2,1fr)}.latest-products-section{padding:30px 15px}.product-card{padding:8px}.product-image{width:60px;height:60px;margin-left:10px}.product-title{font-size:.9rem}.maz-drag-slider-wrapper{padding:30px 0}.slider-container{padding:0 10px}.maz-drag-slider-wrapper .product-card{flex:0 0 160px;flex-direction:column}.maz-drag-slider-wrapper .product-image{height:120px}.maz-drag-slider-wrapper .product-content{padding:10px;width:100%;text-align:center}.maz-drag-slider-wrapper .product-title{font-size:.85rem;line-height:1.3;margin:0}.maz-drag-slider-wrapper .product-description,.maz-drag-slider-wrapper .product-button{display:none}.maz-category-card-wrapper{padding:40px 15px}.category-grid{gap:20px}.category-card{height:300px}.card-content{padding:20px}.card-title{font-size:1.5rem}.maz-image-gallery-section{padding:40px 15px}.maz-image-grid{gap:15px}.maz-image-card-title{font-size:1.2rem}.gms-perspective-slider{height:250px}.gms-p-slide{width:220px;height:160px;margin:0 .5rem}.gms-minimal-grid{padding:30px 15px;gap:20px}.gms-m-content{padding:1rem}.gms-m-content h3{font-size:1.2rem}.gms-full-width-cards{padding:30px 10px}.gms-fw-card{min-height:250px;padding:1.5rem}.gms-fw-content{max-width:100%}.gms-fw-content h2{font-size:1.5rem}.gms-fw-content p{font-size:.85rem}.gms-fw-button{padding:.5rem 1rem;font-size:.9rem}.gms-ticker-item a{padding:8px}.gms-ticker-item img{width:30px;height:30px;margin-left:8px}}@media(max-width:480px){.gms-masonry-grid{columns:1}.latest-products-section{padding:20px 10px}.product-card{flex-direction:column;text-align:center;padding:15px}.product-image{width:70px;height:70px;margin:0 0 10px 0}.product-title{font-size:1rem}.maz-drag-slider-wrapper .product-card{flex:0 0 140px}.maz-drag-slider-wrapper .product-image{height:100px}.maz-category-card-wrapper{padding:30px 10px}.category-card{height:250px}.card-content{padding:15px}.card-title{font-size:1.3rem}.maz-image-gallery-section{padding:30px 10px}.maz-image-card-title{font-size:1rem}.gms-perspective-slider{height:200px}.gms-p-slide{width:180px;height:130px}.gms-minimal-grid{padding:20px 10px}.gms-m-content h3{font-size:1rem}.gms-full-width-cards{padding:20px 5px}.gms-fw-card{min-height:200px;padding:1rem}.gms-fw-content h2{font-size:1.2rem}.gms-fw-content p{font-size:.8rem}.gms-fw-button{font-size:.8rem;padding:.4rem .8rem}}</style>';
        $dynamic_css = '';
        foreach (array_keys(self::$rendered_sliders) as $id) {
            $s = get_post_meta($id, '_gms_style', true); if (!is_array($s) || empty($s)) continue;
            $c_view_more_btn = $s['c_view_more_btn'] ?? '#00CED1';
            $c_view_more_btn_hover = $s['c_view_more_btn_hover'] ?? '#40E0D0';

            $dynamic_css .= "#gms-{$id} {--gms-bg:".($s['c_bg']??'#EEEEEE').";--gms-card-bg:".($s['c_card_bg']??'#FFFFFF').";--gms-title-color:".($s['c_title']??'#333333').";--gms-text-color:".($s['c_text']??'#666666').";--gms-primary:".($s['c_primary']??'#00CED1').";--gms-primary-hover:".($s['c_primary_hover']??'#40E0D0').";--gms-overlay:".($s['c_overlay']??'rgba(0,0,0,0.5)').";--gms-view-more-btn-bg:".$c_view_more_btn.";--gms-view-more-btn-hover-bg:".$c_view_more_btn_hover.";}";
        }
        if($dynamic_css) echo '<style>'.$dynamic_css.'</style>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".slider-track").forEach(e=>{let t,n,o;e.addEventListener("mousedown",l=>{t=!0,e.classList.add("active"),n=l.pageX-e.offsetLeft,o=e.scrollLeft,l.preventDefault()}),e.addEventListener("mouseleave",()=>{t=!1,e.classList.remove("active")}),e.addEventListener("mouseup",()=>{t=!1,e.classList.remove("active")}),e.addEventListener("mousemove",l=>{t&&(l.preventDefault(),e.scrollLeft=o-(l.pageX-e.offsetLeft-n)*2)})})});</script>';
    }
}
Guru_MaSlider_Advanced::instance();
