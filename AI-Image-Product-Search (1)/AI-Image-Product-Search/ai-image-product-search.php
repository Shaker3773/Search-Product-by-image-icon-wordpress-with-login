<?php
/*
Plugin Name: Image Product Search (Google Style)
Description: Image based WooCommerce product search (AI optional, no duplicate, guaranteed result)
Version: 4.0.1
Author: Freel@ncer Ove
*/

if ( ! defined('ABSPATH') ) exit;

define('AI_IMG_SEARCH_URL', plugin_dir_url(__FILE__));

/* ================================
   ENQUEUE (JS + CSS UNCHANGED)
================================ */
add_action('wp_enqueue_scripts', function () {

    wp_enqueue_style(
        'ai-img-search-css',
        AI_IMG_SEARCH_URL . 'assets/css/style.css',
        [],
        '4.0.1'
    );

    wp_enqueue_script(
        'ai-img-search-js',
        AI_IMG_SEARCH_URL . 'assets/js/script.js',
        ['jquery'],
        '4.0.1',
        true
    );

    wp_localize_script('ai-img-search-js', 'AI_IMG_SEARCH', [
        'ajax' => admin_url('admin-ajax.php')
    ]);
});

/* ================================
   OPTIONAL OPENAI (AI OPTIONAL)
================================ */
function ai_img_openai_keywords( string $image ): array {

    if ( ! defined('AI_IMG_OPENAI_KEY') || ! AI_IMG_OPENAI_KEY ) return [];
    if ( ! file_exists($image) ) return [];

    $img = base64_encode(file_get_contents($image));

    $body = [
        'model' => 'gpt-4.1-mini',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type'=>'text','text'=>'Return JSON only: {"keywords":[]}'],
                ['type'=>'image_url','image_url'=>['url'=>'data:image/jpeg;base64,'.$img]]
            ]
        ]],
        'temperature'=>0.1,
        'max_tokens'=>120
    ];

    $res = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        [
            'headers'=>[
                'Authorization'=>'Bearer '.AI_IMG_OPENAI_KEY,
                'Content-Type'=>'application/json'
            ],
            'body'=>json_encode($body),
            'timeout'=>20
        ]
    );

    if ( is_wp_error($res) ) return [];

    $raw  = json_decode(wp_remote_retrieve_body($res), true);
    $json = json_decode($raw['choices'][0]['message']['content'] ?? '', true);

    return is_array($json['keywords'] ?? null)
        ? array_map('strtolower', $json['keywords'])
        : [];
}

/* ================================
   DYNAMIC FALLBACK (NO AI)
================================ */
function ai_img_dynamic_keywords(): array {

    if ( ! function_exists('wc_get_products') ) return [];

    $words = [];

    foreach ( wc_get_products(['limit'=>100,'status'=>'publish']) as $p ) {

        $words = array_merge(
            explode(' ', strtolower($p->get_name())),
            explode(' ', strtolower(strip_tags(wc_get_product_category_list($p->get_id())))),
            explode(' ', strtolower(strip_tags(wc_get_product_tag_list($p->get_id()))))
        );
    }

    $words = array_filter($words, fn($w)=>strlen($w)>=3 && !is_numeric($w));
    $freq  = array_count_values($words);
    arsort($freq);

    return empty($freq)
        ? ['shirt','shoe','bag','dress','watch','phone']
        : array_slice(array_keys($freq), 0, 20);
}

/* ================================
   AJAX HANDLER (LOGIN ONLY)
================================ */
add_action('wp_ajax_ai_img_search','ai_img_search_handler');
// ❌ nopriv নেই

function ai_img_search_handler() {

    // 🔒 Security
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message'=>'Unauthorized']);
    }

    if ( empty($_FILES['image']['tmp_name']) ) {
        wp_send_json(['products'=>[]]);
    }

    $image   = $_FILES['image']['tmp_name'];
    $results = [];
    $added   = []; // 🔒 duplicate lock

    // 1️⃣ keywords (AI optional)
    $keywords = ai_img_openai_keywords($image);
    if ( empty($keywords) ) {
        $keywords = ai_img_dynamic_keywords();
    }

    // 2️⃣ product scan
    foreach ( wc_get_products(['limit'=>120,'status'=>'publish']) as $p ) {

        $pid = $p->get_id();
        if ( isset($added[$pid]) ) continue;

        $score = 0;
        $title = strtolower($p->get_name());
        $cats  = strtolower(strip_tags(wc_get_product_category_list($pid)));
        $tags  = strtolower(strip_tags(wc_get_product_tag_list($pid)));

        foreach ( $keywords as $kw ) {
            if ( stripos($title,$kw)!==false ) $score+=3;
            if ( stripos($cats,$kw)!==false )  $score+=2;
            if ( stripos($tags,$kw)!==false )  $score+=2;
        }

        if ( $score === 0 && $cats ) $score = 1;

        if ( $score > 0 ) {
            $thumb = get_post_thumbnail_id($pid);
            if ( ! $thumb ) continue;

            $results[] = [
                'title' => $p->get_name(),
                'image' => wp_get_attachment_image_url($thumb,'medium'),
                'link'  => get_permalink($pid),
                'exact' => false
            ];

            $added[$pid] = true;
        }
    }

    // 3️⃣ absolute fallback
    if ( empty($results) ) {
        foreach ( wc_get_products(['limit'=>6,'orderby'=>'date','order'=>'DESC']) as $p ) {
            $thumb = get_post_thumbnail_id($p->get_id());
            if ( $thumb ) {
                $results[] = [
                    'title'=>$p->get_name(),
                    'image'=>wp_get_attachment_image_url($thumb,'medium'),
                    'link'=>get_permalink($p->get_id()),
                    'exact'=>false
                ];
            }
        }
    }

  // 🔒 FINAL HARD DEDUPLICATION (ABSOLUTE FIX)
$unique = [];

foreach ( $results as $item ) {
    if ( isset($unique[$item['link']]) ) continue;
    $unique[$item['link']] = $item;
}

// send only unique products
wp_send_json([
    'products' => array_slice(array_values($unique), 0, 6)
]);

}

/* ================================
   SHORTCODE (HIDE FOR LOGOUT)
================================ */
add_shortcode('ai_image_product_search', function(){

    // 🔒 Hide UI for logged-out users
    if ( ! is_user_logged_in() ) {
        return '';
    }

    ob_start(); ?>
    <div class="ai-img-wrapper">
        <div class="ai-search-bar" id="aiDropZone">
            <span class="ai-camera">📷</span>
            <img id="aiPreview" alt="">
            <span class="ai-placeholder" id="aiPlaceholder">
                Drag your image or click here
            </span>
            <span class="ai-remove" id="aiRemove">✕</span>
            <input type="file" id="aiFileInput" accept="image/*">
        </div>

        <div class="ai-loader" id="aiLoader">Searching products…</div>

        <div class="ai-modal" id="aiResultModal">
            <div class="ai-modal-overlay"></div>
            <div class="ai-modal-box">
                <span class="ai-modal-close" id="aiModalClose">✕</span>
                <div id="aiModalGrid" class="ai-grid"></div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
});

/* ================================
   ELEMENTOR WIDGET
================================ */
add_action('elementor/widgets/register', function( $widgets_manager ){

    if ( ! class_exists('\Elementor\Widget_Base') ) return;

    class AI_Image_Product_Search_Widget extends \Elementor\Widget_Base {

        public function get_name(){ return 'ai_image_product_search'; }
        public function get_title(){ return 'AI Image Product Search'; }
        public function get_icon(){ return 'eicon-search'; }
        public function get_categories(){ return ['general']; }

        protected function render(){
            echo do_shortcode('[ai_image_product_search]');
        }
    }

    $widgets_manager->register( new AI_Image_Product_Search_Widget() );
});
