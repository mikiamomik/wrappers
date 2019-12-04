<?php

namespace Wrappers;

class Wrapper
{
    public static $wrapper_acf;
    public static $wrapper_tax;
    public static $wrapper_user;
    public static $wrapper_post;
    public static $wrapper_site;
    public static $wrapper_image;
    public static $wrapper_options;
    public static $wrapper_wp_query;

    /**
     * POST
     */
    public static function get_post($article_post, $filter_post_content = false)
    {
        global $enabled_post_types;
        $switched = false;
        $filtered = $filter_post_content ? 'filtered' : 'notfiltered';
        $current_blog_id = get_current_blog_id();
        $is_preview = static::is_preview() && ((isset($article_post->ID) && static::is_single($article_post->ID)) || static::is_single());
        if (isset($article_post->post_type) && !in_array($article_post->post_type, $enabled_post_types)) {
            return $article_post;
        }
        if ((is_numeric($article_post) && $article_post == 0) || (isset($article_post->ID) && $article_post->ID == 0)) {
            //casistica difficile => generalmente in locale se si punta ad elastic search prod
            return $is_preview ? $article_post : [];
        }

        if (isset($article_post->ID, $article_post->blog_id) && isset(static::$wrapper_post['post'][$filtered][$article_post->blog_id][$article_post->ID])) {
            return static::$wrapper_post['post'][$filtered][$article_post->blog_id][$article_post->ID];
        }
        if ($filtered == 'filtered' && isset($article_post->ID, $article_post->blog_id) && isset(static::$wrapper_post['post']['notfiltered'][$article_post->blog_id][$article_post->ID])) {
            $article_post = static::$wrapper_post['post']['notfiltered'][$article_post->blog_id][$article_post->ID];
            $article_post->post_content_filtered = $article_post->post_content = apply_filters('the_content', $article_post->post_content_initial);
            $article_post->post_content = apply_filters('get_post__post_content', $article_post->post_content, $article_post, true);
            $article_post->filtered = true;

            return static::$wrapper_post['post'][$filtered][$article_post->blog_id][$article_post->ID] = $article_post;
        }

        //check se arriva da elasticsearch
        if (!$is_preview && isset($article_post->elasticsearch, $article_post->ID) && $article_post->elasticsearch) {
            if (!$switched && isset($article_post->blog_id) && !empty($article_post->blog_id)) {
                static::switch_to_blog($article_post->blog_id);
                $switched = true;
            }
            add_action('pre_get_posts', [__NAMESPACE__, 'get_post__pre_get_posts'], 10000);
            $post_id = $article_post->ID;
            if ($post_revision = static::wp_is_post_revision($post_id)) { // If is a revision
                $post_id = $post_revision;
            }
            clean_post_cache($post_id);
            $_article_post = get_post($post_id);
            remove_action('pre_get_posts', [__NAMESPACE__, 'get_post__pre_get_posts'], 10000);

            if (!(is_wp_error($_article_post) || !isset($_article_post->ID))) {
                if (isset($article_post->elasticsearch)) {
                    unset($article_post->elasticsearch);
                }
                $_article_post->from_elasticsearch = true;
                $article_post = $_article_post;
            }
        }
        if (is_numeric($article_post)) {
            if (isset(static::$wrapper_post['post'][$filtered][$current_blog_id][$article_post])) {
                return static::$wrapper_post['post'][$filtered][$current_blog_id][$article_post];
            }
            if ($post_revision = static::wp_is_post_revision($article_post)) { // If is a revision
                $article_post = $post_revision;
            }
            $article_post = get_post($article_post);
            $article_post->from_numeric_post_id = true;
        }

        if (is_wp_error($article_post) || !isset($article_post->ID) || isset($article_post->elasticsearch)) {
            if ($switched) {
                static::restore_current_blog();
            }
            return $is_preview ? $article_post : [];
        }

        if (!$is_preview && (!isset($article_post->post_status) || ($article_post->post_status != 'publish' && $article_post->post_status != 'future'))) {
            if ($switched) {
                static::restore_current_blog();
            }
            return $is_preview ? $article_post : [];
        }

        $article_post->edit_link = is_user_logged_in() ? get_edit_post_link($article_post->ID) : '#';
        $article_post->permalink = get_permalink($article_post->ID);
        $article_post->blog_id = $article_post->site_id = get_current_blog_id();
        $article_post->post_format = get_post_format($article_post->ID);
        $article_post->post_content_cleared = wp_strip_all_tags(strip_shortcodes($article_post->post_content));
        $article_post->post_content_initial = $article_post->post_content;
        $article_post->pages = [];
        $article_post->post_content_filtered = $article_post->post_content_cleared;
        $article_post->post_content = $article_post->post_content_initial;
        $article_post->filtered = false;
        $article_post->is_preview = $is_preview;
        if ($filter_post_content) { // if is single post
            $article_post->filtered = true;
            $article_post->post_content_filtered = $article_post->post_content = apply_filters('the_content', $article_post->post_content_initial);
            $article_post->post_content = apply_filters('get_post__post_content', $article_post->post_content, $article_post, $filter_post_content);
        }

        $article_post->featured_image_id = get_post_thumbnail_id($article_post->ID);
        $article_post->featured_image = [];
        if (!is_wp_error($article_post->featured_image_id) && false !== $article_post->featured_image_id && is_numeric($article_post->featured_image_id)) {
            $article_post->featured_image['thumbnail'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'thumbnail');
            $article_post->featured_image['post-thumbnail'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'post-thumbnail');
            $article_post->featured_image['medium-cropped'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'medium-cropped');
            $article_post->featured_image['wide'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'wide');
            $article_post->featured_image['scale-wide'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'scale-wide');
            $article_post->featured_image['retina-scale-wide'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'retina-scale-wide');
            $article_post->featured_image['retina-scale-wide-portrait'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'retina-scale-wide-portrait');
            $article_post->featured_image['scale-half-wide-portrait'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'scale-half-wide-portrait');
            $article_post->featured_image['scale-wide-portrait'] = static::wp_get_attachment_image_src($article_post->featured_image_id, 'scale-wide-portrait');
        }

        //nextpage
        $article_post->post_content = str_replace('<p><!--nextpage--></p>', '<!--nextpage-->', $article_post->post_content);
        $article_post->post_content = str_replace('\n<!--nextpage-->\n', '<!--nextpage-->', $article_post->post_content);
        $article_post->post_content = str_replace('\n<!--nextpage-->', '<!--nextpage-->', $article_post->post_content);
        $article_post->post_content = str_replace('<!--nextpage-->\n', '<!--nextpage-->', $article_post->post_content);

        if (false !== strpos($article_post->post_content, '<!--nextpage-->')) {
            $article_post->pages = explode('<!--nextpage-->', $article_post->post_content);
            $article_post->post_content = str_replace('<!--nextpage-->', '<div class=\'nextpage\'></div>', $article_post->post_content);
        }

        if ($switched) {
            static::restore_current_blog();
        }
        $article_post->elaborated = true;

        return static::$wrapper_post['post'][$filtered][$article_post->blog_id][$article_post->ID] = $article_post;
    }

    public static function get_post_types($args = [], $output = 'names', $operator = 'and')
    {
        if (!isset(static::$wrapper_post['post_types'][maybe_serialize($args)][$output][$operator])) {
            static::$wrapper_post['post_types'][maybe_serialize($args)][$output][$operator] = get_post_types($args, $output, $operator);
        }
        return static::$wrapper_post['post_types'][maybe_serialize($args)][$output][$operator];
    }

    public static function get_post_field($field, $article_post = null)
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->$field)) {
            return $article_post->$field;
        }
        return '';
    }

    public static function get_post_type_archive_link($post_type)
    {
        $current_blog_id = get_current_blog_id();
        if (!isset(static::$wrapper_post['post_type_archive'][$current_blog_id][$post_type])) {
            static::$wrapper_post['post_type_archive'][$current_blog_id][$post_type] = get_post_type_archive_link($post_type);
        }
        return static::$wrapper_post['post_type_archive'][$current_blog_id][$post_type];
    }

    public static function wp_is_post_revision($post_id)
    {
        $post_id = isset($post_id->ID) ? $post_id->ID : maybe_serialize($post_id);
        if (!isset(static::$wrapper_post['revision_post'][$post_id])) {
            static::$wrapper_post['revision_post'][$post_id] = wp_is_post_revision($post_id);
        }
        return static::$wrapper_post['revision_post'][$post_id];
    }

    public static function has_post_thumbnail($article_post = null)
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->featured_image_id)) {
            return (bool) $article_post->featured_image_id;
        }
        return has_post_thumbnail($article_post);
    }

    public static function get_post_thumbnail_id($article_post)
    {
        $article_post = static::get_post($article_post);
        if (isset($article_post->featured_image_id)) {
            return $article_post->featured_image_id;
        }
        return get_post_thumbnail_id($article_post);
    }

    public static function wp_get_attachment_image_src($image_id = '', $size = 'thumbnail')
    {
        if (empty($image_id)) {
            return [];
        }
        if (!isset(static::$wrapper_image[$image_id]['sizes'][$size])) {
            static::$wrapper_image[$image_id]['sizes'][$size] = wp_get_attachment_image_src($image_id, $size);
        }
        return static::$wrapper_image[$image_id]['sizes'][$size];
    }

    public static function wp_get_attachment_image($attachment_id, $size = 'thumbnail', $icon = false, $attr = '')
    {
        $html = '';
        $image = static::wp_get_attachment_image_src($attachment_id, $size, $icon);
        if ($image) {
            list($src, $width, $height) = $image;
            $hwstring = image_hwstring($width, $height);
            $size_class = $size;
            if (is_array($size_class)) {
                $size_class = join('x', $size_class);
            }
            $attachment = static::get_post($attachment_id);
            $default_attr = array(
                'src' => $src,
                'class' => "attachment-$size_class size-$size_class",
                'alt' => trim(strip_tags(static::get_post_meta($attachment_id, '_wp_attachment_image_alt', true))),
            );

            $attr = wp_parse_args($attr, $default_attr);

            // Generate 'srcset' and 'sizes' if not already present.
            if (empty($attr['srcset'])) {
                $image_meta = wp_get_attachment_metadata($attachment_id);

                if (is_array($image_meta)) {
                    $size_array = array(absint($width), absint($height));
                    $srcset = wp_calculate_image_srcset($size_array, $src, $image_meta, $attachment_id);
                    $sizes = wp_calculate_image_sizes($size_array, $src, $image_meta, $attachment_id);

                    if ($srcset && ($sizes || !empty($attr['sizes']))) {
                        $attr['srcset'] = $srcset;

                        if (empty($attr['sizes'])) {
                            $attr['sizes'] = $sizes;
                        }
                    }
                }
            }

            /**
             * Filters the list of attachment image attributes.
             *
             * @since 2.8.0
             *
             * @param array        $attr       Attributes for the image markup.
             * @param WP_Post      $attachment Image attachment post.
             * @param string|array $size       Requested size. Image size or array of width and height values
             *                                 (in that order). Default 'thumbnail'.
             */
            $attr = apply_filters('wp_get_attachment_image_attributes', $attr, $attachment, $size);
            $attr = array_map('esc_attr', $attr);
            $html = rtrim("<img $hwstring");
            foreach ($attr as $name => $value) {
                $html .= " $name=" . '"' . $value . '"';
            }
            $html .= ' />';
        }

        return $html;
    }

    public static function get_the_post_thumbnail_url($post = '', $size = 'post-thumbnail')
    {
        $image = static::wp_get_attachment_image_src(static::get_post_thumbnail_id($post), $size);
        return isset($image[0]) ? $image[0] : null;
    }

    public static function wp_get_post_parent_id($article_post)
    {
        $article_post = static::get_post($article_post);
        if (isset($article_post->post_parent)) {
            return (int) $article_post->post_parent;
        }
        return wp_get_post_parent_id($article_post);
    }

    public static function get_post_format($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->post_format)) {
            return $article_post->post_format;
        }
        return get_post_format($article_post);
    }

    public static function get_the_title($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->post_title)) {
            return $article_post->post_title;
        }
        return get_the_title($article_post);
    }

    public static function get_post_type($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->post_type)) {
            return $article_post->post_type;
        }
        return get_post_type($article_post);
    }

    public static function get_permalink($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->permalink)) {
            return $article_post->permalink;
        }
        return get_permalink($article_post);
    }

    public static function get_edit_post_link($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->edit_link)) {
            return $article_post->edit_link;
        }
        return get_edit_post_link($article_post);
    }

    public static function get_post_status($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->post_status)) {
            return $article_post->post_status;
        }
        return get_post_status($article_post);
    }

    public static function wp_get_attachment_caption($article_post = '')
    {
        global $post;
        $article_post = !empty($article_post) ? static::get_post($article_post) : static::get_post($post);
        if (isset($article_post->post_excerpt)) {
            return $article_post->post_excerpt;
        }
        return wp_get_attachment_caption($article_post);
    }

    public static function get_posts($args)
    {
        $key = md5(serialize($args));
        if (isset(static::$wrapper_post['get_posts'][$key]['posts'])) {
            return static::$wrapper_post['get_posts'][$key]['posts'];
        }

        $posts = get_posts($args);
        if (isset($posts[0]->ID)) {
            foreach ($posts as $k => $post) {
                if (isset($post->ID)) {
                    $posts[$k] = static::get_post($post);
                }
            }
        }
        static::$wrapper_post['get_posts'][$key] = [
            'args' => $args,
            'posts' => $posts,
        ];

        if (isset(static::$wrapper_post['get_posts'][$key]['posts'])) {
            return static::$wrapper_post['get_posts'][$key]['posts'];
        }

        return [];
    }

    public static function get_post_meta($_article_post, $name = '', $single = false)
    {
        $post_id = is_numeric($_article_post) ? $_article_post : (isset($_article_post->ID) ? $_article_post->ID : 0);
        if (empty($post_id)) {
            return [];
        }
        $key_single = $single ? 'single' : 'notsingle';
        if (!empty($name) && !isset(static::$wrapper_post['post'][$post_id]['post_meta'][$name][$key_single])) {
            static::$wrapper_post['post'][$post_id]['post_meta'][$name][$key_single] = get_post_meta($post_id, $name, $single);
        } else if (empty($name)) {
            $name = '__all_data';
            if (!empty($name) && !isset(static::$wrapper_post['post'][$post_id]['post_meta'][$name][$key_single])) {
                static::$wrapper_post['post'][$post_id]['post_meta'][$name][$key_single] = get_post_meta($post_id, '', $single);
            }
        }
        return static::$wrapper_post['post'][$post_id]['post_meta'][$name][$key_single];
    }

    public static function get_post__pre_get_posts($query)
    {
        $query->set('ep_integrate', false);
    }

    /**
     * OPTIONS
     */
    public static function get_option($option_name)
    {
        $blog_id = get_current_blog_id();
        if (isset(static::$wrapper_options[$blog_id][$option_name])) {
            return static::$wrapper_options[$blog_id][$option_name];
        }
        return static::$wrapper_options[$blog_id][$option_name] = get_option($option_name);
    }

    /**
     * ACF
     */
    public static function get_field($selector, $post_id = false, $format_value = true)
    {
        if (!function_exists('get_fields')) {
            return false;
        }
        $initial_selector = $selector;
        $selector_get_field = [$initial_selector];

        $key = get_current_blog_id() . serialize(function_exists('acf_get_valid_post_id') ? acf_get_valid_post_id($post_id) : $post_id) . ($format_value ? '' : '_noformat');
        if (isset(static::$wrapper_acf['field'][$key][$selector])) {
            return static::$wrapper_acf['field'][$key][$selector];
        }
        $obj_key = $selector . serialize(function_exists('acf_get_valid_post_id') ? acf_get_valid_post_id($post_id) : $post_id) . ($format_value ? '' : '_noformat');
        $this_obj = isset(static::$wrapper_acf['field_obj'][$obj_key]) ? static::$wrapper_acf['field_obj'][$obj_key] : get_field_object($selector, $post_id, $format_value);
        static::$wrapper_acf['field_obj'][$obj_key] = $this_obj;
        if (isset($this_obj['key'], $this_obj['name'])) { //selector is field key
            $selector_get_field = [$this_obj['key'], $this_obj['name']];
            if ($this_obj['key'] == $selector) {
                $selector = $this_obj['name'];
                if (isset(static::$wrapper_acf['field'][$key][$selector])) {
                    return static::$wrapper_acf['field'][$key][$selector];
                }
            }
        }

        // static::get_fields($post_id, $format_value, $selector_get_field);
        // if (isset(static::$wrapper_acf['field'][$key][$selector])) {
        //     return static::$wrapper_acf['field'][$key][$selector];
        // }
        $acf_field = [];
        foreach ($selector_get_field as $this_selector) {
            $acf_field = get_field($this_selector, $post_id, $format_value);
            if (!empty($acf_field)) {
                break;
            }
        }
        return static::$wrapper_acf['field'][$key][$selector] = $acf_field;
    }

    public static function get_fields($_post_id = false, $_format_value = true, $merge = false)
    {
        if (!function_exists('get_fields')) {
            return false;
        }
        $key = get_current_blog_id() . serialize(function_exists('acf_get_valid_post_id') ? acf_get_valid_post_id($_post_id) : $_post_id) . ($_format_value ? '' : '_noformat');
        static::$wrapper_acf['field'][$key] = !isset(static::$wrapper_acf['field'][$key]) ? get_fields($_post_id, $_format_value) : static::$wrapper_acf['field'][$key];
        return static::$wrapper_acf['field'][$key];
    }

    /**
     * Taxonomies
     */
    public static function get_taxonomies($args = array(), $output = 'names', $operator = 'and')
    {
        if (isset(static::$wrapper_tax['tax'][maybe_serialize($args)][$output][$operator])) {
            return static::$wrapper_tax['tax'][maybe_serialize($args)][$output][$operator];
        }
        return static::$wrapper_tax['tax'][maybe_serialize($args)][$output][$operator] = get_taxonomies($args, $output, $operator);
    }

    public static function getPostTerms($_post = '', $tax = 'category', $args = [])
    {
        $pid = $_post;
        if (!is_numeric($pid)) {
            global $post;
            $this_post = isset($pid->ID) ? $_post : $post;
            if (!isset($this_post->ID)) {
                return [];
            }
            $pid = $this_post->ID;
        }

        if (empty($args) || !is_array($args)) {
            $args = ['fields' => 'all'];
        }
        $serArgs = serialize($args);

        if (isset(static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid])) {
            return static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid];
        }

        $__terms = wp_get_post_terms($pid, $tax, $args);
        $__terms = is_wp_error($__terms) || !isset($__terms[0]->term_id) ? [] : $__terms;
        if (!empty($__terms) && isset($__terms[0]->term_id)) {
            foreach ($__terms as $k => $v) {
                if (!isset($v->term_link) || !isset($v->blog_id)) {
                    $__terms[$k]->blog_id = isset($v->blog_id) ? $v->blog_id : get_current_blog_id();
                    $__terms[$k]->term_link = static::getTermLink($v);
                }
                static::saveTerm($__terms[$k]);
            }
        }
        $__terms = apply_filters('mp_wrapper_getPostTerms', $__terms, $tax, $pid);

        return static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid] = $__terms;
    }

    public static function getPostTermsSlugs($_post = '', $tax = 'category', $args = [])
    {
        $pid = $_post;
        if (!is_numeric($pid)) {
            global $post;
            $this_post = isset($pid->ID) ? $_post : $post;
            if (!isset($this_post->ID)) {
                return [];
            }
            $pid = $this_post->ID;
        }

        if (empty($args) || !is_array($args)) {
            $args = ['fields' => 'all'];
        }
        $serArgs = serialize($args);
        static::getPostTerms($pid, $tax, $args);

        if (isset(static::$wrapper_tax['tax_post_terms_slugs'][$tax][$serArgs][$pid])) {
            return static::$wrapper_tax['tax_post_terms_slugs'][$tax][$serArgs][$pid];
        }

        static::$wrapper_tax['tax_post_terms_slugs'][$tax][$serArgs][$pid] = [];
        if (isset(static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid])) {
            foreach (static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid] as $term) {
                static::$wrapper_tax['tax_post_terms_slugs'][$tax][$serArgs][$pid][] = $term->slug;
            }
        }
        return static::$wrapper_tax['tax_post_terms_slugs'][$tax][$serArgs][$pid];
    }

    public static function getPostTermsIDS($_post = '', $tax = 'category', $args = [])
    {
        $pid = $_post;
        if (!is_numeric($pid)) {
            global $post;
            $this_post = isset($pid->ID) ? $_post : $post;
            if (!isset($this_post->ID)) {
                return [];
            }
            $pid = $this_post->ID;
        }

        if (empty($args) || !is_array($args)) {
            $args = ['fields' => 'all'];
        }
        $serArgs = serialize($args);
        static::getPostTerms($pid, $tax, $args);

        if (isset(static::$wrapper_tax['tax_post_terms_ids'][$tax][$serArgs][$pid])) {
            return static::$wrapper_tax['tax_post_terms_ids'][$tax][$serArgs][$pid];
        }

        static::$wrapper_tax['tax_post_terms_ids'][$tax][$serArgs][$pid] = [];
        if (isset(static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid])) {
            foreach (static::$wrapper_tax['tax_post_terms'][$tax][$serArgs][$pid] as $term) {
                static::$wrapper_tax['tax_post_terms_ids'][$tax][$serArgs][$pid][] = $term->term_id;
            }
        }
        return static::$wrapper_tax['tax_post_terms_ids'][$tax][$serArgs][$pid];
    }

    public static function getTerm($tid, $taxonomy = '', $output = OBJECT, $filter = 'raw')
    {
        if (isset($tid->term_id) && is_numeric($tid->term_id)) {
            return $tid;
        }

        if (!is_numeric($tid)) {
            return [];
        }

        if (!empty($taxonomy) && isset(static::$wrapper_tax['tax_terms'][$taxonomy][$tid])) {
            return static::$wrapper_tax['tax_terms'][$taxonomy][$tid];
        }

        if (isset(static::$wrapper_tax['tax_terms']['all'][$tid])) {
            return static::$wrapper_tax['tax_terms']['all'][$tid];
        }

        $return_term = get_term($tid, $taxonomy, $output, $filter);
        static::$wrapper_tax['tax_terms']['all'][$tid] = $return_term;
        if (!empty($taxonomy)) {
            static::$wrapper_tax['tax_terms'][$taxonomy][$tid] = $return_term;
        }
        if (!is_wp_error($return_term) && isset($return_term->term_id, $return_term->taxonomy)) {
            static::saveTerm($return_term);
        }
        return $return_term;
    }

    public static function getTermBy($field, $value, $taxonomy, $output = OBJECT, $filter = 'raw')
    {
        if (empty($value) || !is_string($value)) {
            return false;
        }

        if (isset(static::$wrapper_tax['tax_terms'][$field][$value])) {
            return static::$wrapper_tax['tax_terms'][$field][$value];
        }

        $args = [
            'get' => 'all',
            'number' => 1,
            'taxonomy' => $taxonomy,
            'update_term_meta_cache' => false,
            'orderby' => 'none',
            'suppress_filter' => true,
        ];

        switch ($field) {
            case 'slug':
                $args['slug'] = $value;
                break;
            case 'name':
                $args['name'] = $value;
                break;
            case 'term_taxonomy_id':
                $args['term_taxonomy_id'] = $value;
                unset($args['taxonomy']);
                break;
            default:
                return false;
        }

        $return_terms = static::getTerms($args);
        if (isset($return_terms[0]->term_id)) {
            $return_term = $return_terms[0];
        } else {
            $return_term = get_term_by($field, $value, $taxonomy, $output, $filter);
        }
        static::$wrapper_tax['tax_terms'][$field][$value] = $return_term;
        if (!is_wp_error($return_term) && isset($return_term->term_id, $return_term->taxonomy)) {
            static::saveTerm($return_term);
        }
        return $return_term;
    }

    public static function getTermBySlug($slug, $taxonomy, $output = OBJECT, $filter = 'raw')
    {
        if (empty($slug) || !is_string($slug)) {
            return false;
        }

        if (isset(static::$wrapper_tax['tax_terms']['slug'][$slug])) {
            return static::$wrapper_tax['tax_terms']['slug'][$slug];
        }

        $args = [
            'get' => 'all',
            'number' => 1,
            'taxonomy' => $taxonomy,
            'update_term_meta_cache' => false,
            'orderby' => 'none',
            'suppress_filter' => true,
            'slug' => $slug,
        ];

        $return_terms = static::getTerms($args);
        if (isset($return_terms[0]->term_id)) {
            $return_term = $return_terms[0];
        } else {
            $return_term = get_term_by('slug', $slug, $taxonomy, $output, $filter);
        }
        static::$wrapper_tax['tax_terms']['slug'][$slug] = $return_term;
        if (!is_wp_error($return_term) && isset($return_term->term_id, $return_term->taxonomy)) {
            static::saveTerm($return_term);
        }
        return $return_term;
    }

    public static function getTermByName($name, $taxonomy, $output = OBJECT, $filter = 'raw')
    {
        if (empty($name) || !is_string($name)) {
            return false;
        }

        if (isset(static::$wrapper_tax['tax_terms']['name'][$name])) {
            return static::$wrapper_tax['tax_terms']['name'][$name];
        }

        $args = [
            'get' => 'all',
            'number' => 1,
            'taxonomy' => $taxonomy,
            'update_term_meta_cache' => false,
            'orderby' => 'none',
            'suppress_filter' => true,
            'name' => $name,
        ];

        $return_terms = static::getTerms($args);
        if (isset($return_terms[0]->term_id)) {
            $return_term = $return_terms[0];
        } else {
            $return_term = get_term_by('name', $name, $taxonomy, $output, $filter);
        }
        static::$wrapper_tax['tax_terms']['name'][$name] = $return_term;
        if (!is_wp_error($return_term) && isset($return_term->term_id, $return_term->taxonomy)) {
            static::saveTerm($return_term);
        }
        return $return_term;
    }

    public static function getTerms($args)
    {
        if (!isset($args['taxonomy'])) {
            return false;
        }

        if (!empty($args['taxonomy'])) {
            $args['taxonomy'] = is_array($args['taxonomy']) ? $args['taxonomy'] : [$args['taxonomy']];
            foreach ($args['taxonomy'] as $taxonomy) {
                if (!taxonomy_exists($taxonomy)) {
                    return new WP_Error('invalid_taxonomy', __('Invalid taxonomy.'));
                }
            }
        }

        $serArgs = serialize($args);
        if (isset(static::$wrapper_tax['tax_get_terms'][$serArgs])) {
            $return_terms = static::$wrapper_tax['tax_get_terms'][$serArgs];
        } else {
            $return_terms = static::$wrapper_tax['tax_get_terms'][$serArgs] = get_terms($args);
        }

        if (!is_wp_error($return_terms) && isset($return_terms[0]->term_id, $return_terms[0]->taxonomy)) {
            foreach ($return_terms as $save_term) {
                static::saveTerm($save_term);
            }
        }
        return $return_terms;
    }

    public static function getTermLink($term, $taxonomy = '')
    {
        if (isset($term->term_link) && !empty($term->term_link)) {
            $return_term_link = $term->term_link;
            $_term = $term;
            $taxonomy = $_term->taxonomy;
        } else {
            $term_id = isset($term->term_id) ? $term->term_id : (is_numeric($term) ? $term : 0);
            $_term = static::getTerm($term_id, $taxonomy);
            $taxonomy = !empty($taxonomy) ? $taxonomy : (isset($_term->taxonomy) ? $_term->taxonomy : null);
            $return_term_link = isset($_term->term_link) ? $_term->term_link : (!is_wp_error($_term) ? get_term_link($_term, $taxonomy) : null);
        }

        return apply_filters('mp_wrapper_getTermLink', $return_term_link, $_term, $taxonomy);
    }

    public static function getEditTermLink($term, $taxonomy = '')
    {
        if (isset($term->edit_term_link) && !empty($term->edit_term_link)) {
            $return_edit_term_link = $term->edit_term_link;
            $_term = $term;
            $taxonomy = $_term->taxonomy;
        } else {
            $term_id = isset($term->term_id) ? $term->term_id : (is_numeric($term) ? $term : 0);
            $_term = static::getTerm($term_id, $taxonomy);
            $taxonomy = !empty($taxonomy) ? $taxonomy : (isset($_term->taxonomy) ? $_term->taxonomy : null);
            $return_edit_term_link = isset($_term->edit_term_link) ? $_term->edit_term_link : (!is_wp_error($_term) ? get_edit_term_link($_term, $taxonomy) : null);
        }

        return apply_filters('mp_wrapper_getEditTermLink', $return_edit_term_link, $_term, $taxonomy);
    }

    public static function getTermLinkBySlug($slug, $taxonomy)
    {
        return static::getTermLink(static::getTermBySlug($slug, $taxonomy));
    }

    public static function getTermLinkByName($name, $taxonomy)
    {
        return static::getTermLink(static::getTermByName($name, $taxonomy));
    }

    private static function saveTerm($save_term)
    {
        static::$wrapper_tax['tax_terms']['all'][$save_term->term_id] = $save_term;
        static::$wrapper_tax['tax_terms']['slug'][$save_term->slug] = $save_term;
        static::$wrapper_tax['tax_terms']['name'][$save_term->name] = $save_term;
        static::$wrapper_tax['tax_terms'][$save_term->taxonomy][$save_term->term_id] = $save_term;
        if (isset($save_term->blog_id)) {
            static::$wrapper_tax['tax_terms'][$save_term->blog_id][$save_term->term_id] = $save_term;
            static::$wrapper_tax['tax_terms'][$save_term->blog_id]['slug'][$save_term->slug] = $save_term;
            static::$wrapper_tax['tax_terms'][$save_term->blog_id]['name'][$save_term->name] = $save_term;
            static::$wrapper_tax['tax_terms'][$save_term->blog_id][$save_term->taxonomy][$save_term->term_id] = $save_term;
        }
    }

    /**
     * Users
     */
    public static function get_users($args = [])
    {
        $key = maybe_serialize($args);
        if (isset(static::$wrapper_user['get_users'][$key])) {
            return static::$wrapper_user['get_users'][$key];
        }
        return static::$wrapper_user['get_users'][$key] = get_users($args);
    }

    public static function getUserAcfFields($user_id)
    {
        if (!is_numeric($user_id)) {
            return [];
        }
        if (isset(static::$wrapper_user[$user_id]['acf'])) {
            return static::$wrapper_user[$user_id]['acf'];
        }
        return static::$wrapper_user[$user_id]['acf'] = static::get_fields('user_' . $user_id);
    }

    public static function getEditUserLink($user_id)
    {
        if (!is_numeric($user_id)) {
            return [];
        }
        if (isset(static::$wrapper_user[$user_id]['edit_link'])) {
            return static::$wrapper_user[$user_id]['edit_link'];
        }

        $link = get_edit_user_link($user_id);
        if (is_wp_error($link) || empty($link)) {
            $link = '#';
        }
        return static::$wrapper_user[$user_id]['edit_link'] = $link;
    }

    public static function getUserLink($user_id)
    {
        if (!is_numeric($user_id)) {
            return [];
        }
        if (isset(static::$wrapper_user[$user_id]['link'])) {
            return static::$wrapper_user[$user_id]['link'];
        }

        $link = get_author_posts_url($user_id);
        if (is_wp_error($link) || empty($link)) {
            $link = '#';
        }
        return static::$wrapper_user[$user_id]['link'] = $link;
    }

    public static function getUserMeta($user_id, $key = '', $single = false)
    {
        if (!is_numeric($user_id)) {
            return [];
        }
        $_key = !empty($key) ? ($single ? "1_" . $key : "0_" . $key) : 'all';
        if (isset(static::$wrapper_user[$user_id]['user_meta'][$_key])) {
            return static::$wrapper_user[$user_id]['user_meta'][$_key];
        }
        if ($_key == 'all') {
            return static::$wrapper_user[$user_id]['user_meta'][$_key] = get_user_meta($user_id);
        }
        return static::$wrapper_user[$user_id]['user_meta'][$_key] = get_user_meta($user_id, $key, $single);
    }

    public static function getTheAuthorMeta($meta, $user_id)
    {
        if (isset(static::$wrapper_user[$user_id]) && is_array(static::$wrapper_user[$user_id]['user_data']) && isset(static::$wrapper_user[$user_id]['user_data'][$meta])) {
            return static::$wrapper_user[$user_id]['user_data'][$meta];
        } else if (isset(static::$wrapper_user[$user_id]) && is_object(static::$wrapper_user[$user_id]['user_data']) && isset(static::$wrapper_user[$user_id]['user_data']->$meta)) {
            return static::$wrapper_user[$user_id]['user_data']->$meta;
        }
        $user_data = static::getUserData($user_id);
        if (is_object($user_data) && isset($user_data->$meta)) {
            return $user_data->$meta;
        } else if (is_array($user_data) && isset($user_data[$meta])) {
            return $user_data[$meta];
        }
        return get_the_author_meta($meta, $user_id);
    }

    public static function getUserData($user_id)
    {
        if (!is_numeric($user_id)) {
            return [];
        }
        if (isset(static::$wrapper_user[$user_id]['user_data'])) {
            return static::$wrapper_user[$user_id]['user_data'];
        }
        return static::$wrapper_user[$user_id]['user_data'] = get_userdata($user_id);
    }

    public static function getUserRoles($user_id)
    {
        if (!is_numeric($user_id)) {
            return [];
        }
        if (isset(static::$wrapper_user[$user_id]['roles'])) {
            return static::$wrapper_user[$user_id]['roles'];
        }
        $user_data = static::getUserData($user_id);
        return static::$wrapper_user[$user_id]['roles'] = isset($user_data->roles) ? $user_data->roles : [];
    }

    public static function getUserAvatar($user, $size_avatar = 150)
    {
        $user_id = is_numeric($user) ? (int) $user : 0;
        if (isset($user->term_id)) {
            $user_id = $user->term_id;
        } else if (isset($user->ID)) {
            $user_id = $user->ID;
        }
        if ($user_id == 0) {
            return [];
        }
        if (isset(static::$wrapper_user[$user_id]['avatar'][$size_avatar])) {
            return static::$wrapper_user[$user_id]['avatar'][$size_avatar];
        }
        return static::$wrapper_user[$user_id]['avatar'][$size_avatar] = get_avatar($user_id, $size_avatar);
    }

    /**
     * Multisite
     */
    public static function switch_to_blog($blog_id)
    {
        if (function_exists('switch_to_blog')) {
            switch_to_blog($blog_id);
        }
    }

    public static function restore_current_blog()
    {
        if (function_exists('restore_current_blog')) {
            restore_current_blog();
        }
    }

    /**
     * wp_query
     */
    public static function is_single($post = '')
    {
        $key = !empty($post) ? maybe_serialize($post) : 'this';
        if (!isset(static::$wrapper_wp_query['is_single'][$key])) {
            static::$wrapper_wp_query['is_single'][$key] = is_single($post);
        }
        return static::$wrapper_wp_query['is_single'][$key];
    }

    public static function is_attachment($attachment = '')
    {
        $key = !empty($attachment) ? maybe_serialize($attachment) : 'this';
        if (!isset(static::$wrapper_wp_query['is_attachment'][$key])) {
            static::$wrapper_wp_query['is_attachment'][$key] = is_attachment($attachment);
        }
        return static::$wrapper_wp_query['is_attachment'][$key];
    }

    public static function is_singular($post_types = '')
    {
        $key = !empty($post_types) ? maybe_serialize($post_types) : 'this';
        if (!isset(static::$wrapper_wp_query['is_singular'][$key])) {
            static::$wrapper_wp_query['is_singular'][$key] = is_singular($post_types);
        }
        return static::$wrapper_wp_query['is_singular'][$key];
    }

    public static function is_page($page = '')
    {
        $key = !empty($page) ? maybe_serialize($page) : 'this';
        if (!isset(static::$wrapper_wp_query['is_page'][$key])) {
            static::$wrapper_wp_query['is_page'][$key] = is_page($page);
        }
        return static::$wrapper_wp_query['is_page'][$key];
    }

    public static function is_tax($taxonomy = '', $term = '')
    {
        $key_tax = !empty($taxonomy) ? maybe_serialize($taxonomy) : 'this';
        $key_term = !empty($term) ? maybe_serialize($term) : 'that';
        if (!isset(static::$wrapper_wp_query['is_tax'][$key_tax][$key_tax])) {
            static::$wrapper_wp_query['is_tax'][$key_tax][$key_tax] = is_tax($taxonomy, $term);
        }
        return static::$wrapper_wp_query['is_tax'][$key_tax][$key_tax];
    }

    public static function is_archive()
    {
        if (!isset(static::$wrapper_wp_query['is_archive'])) {
            static::$wrapper_wp_query['is_archive'] = is_archive();
        }
        return static::$wrapper_wp_query['is_archive'];
    }

    public static function is_preview()
    {
        if (!isset(static::$wrapper_wp_query['is_preview'])) {
            static::$wrapper_wp_query['is_preview'] = is_preview();
        }
        return static::$wrapper_wp_query['is_preview'];
    }

    public static function is_front_page()
    {
        if (!isset(static::$wrapper_wp_query['is_front_page'])) {
            static::$wrapper_wp_query['is_front_page'] = is_front_page();
        }
        return static::$wrapper_wp_query['is_front_page'];
    }

    public static function is_home()
    {
        if (!isset(static::$wrapper_wp_query['is_home'])) {
            static::$wrapper_wp_query['is_home'] = is_home();
        }
        return static::$wrapper_wp_query['is_home'];
    }

    public static function is_category($category = '')
    {
        $key = !empty($category) ? maybe_serialize($category) : 'this';
        if (!isset(static::$wrapper_wp_query['is_category'][$key])) {
            static::$wrapper_wp_query['is_category'][$key] = is_category($category);
        }
        return static::$wrapper_wp_query['is_category'][$key];
    }

    public static function is_tag($tag = '')
    {
        $key = !empty($tag) ? maybe_serialize($tag) : 'this';
        if (!isset(static::$wrapper_wp_query['is_tag'][$key])) {
            static::$wrapper_wp_query['is_tag'][$key] = is_tag($tag);
        }
        return static::$wrapper_wp_query['is_tag'][$key];
    }

    public static function is_author($author = '')
    {
        $key = !empty($author) ? maybe_serialize($author) : 'this';
        if (!isset(static::$wrapper_wp_query['is_author'][$key])) {
            static::$wrapper_wp_query['is_author'][$key] = is_author($author);
        }
        return static::$wrapper_wp_query['is_author'][$key];
    }

    public static function is_search()
    {
        if (!isset(static::$wrapper_wp_query['is_search'])) {
            static::$wrapper_wp_query['is_search'] = is_search();
        }
        return static::$wrapper_wp_query['is_search'];
    }

    public static function is_admin()
    {
        if (!isset(static::$wrapper_wp_query['is_admin'])) {
            static::$wrapper_wp_query['is_admin'] = is_admin();
        }
        return static::$wrapper_wp_query['is_admin'];
    }

    public static function is_post_type_archive($post_types = '')
    {
        $key = !empty($post_types) ? maybe_serialize($post_types) : 'this';
        if (!isset(static::$wrapper_wp_query['is_post_type_archive'][$key])) {
            static::$wrapper_wp_query['is_post_type_archive'][$key] = is_post_type_archive($post_types);
        }
        return static::$wrapper_wp_query['is_post_type_archive'][$key];
    }

    /**
     * Sites
     */
    public static function get_sites()
    {
        if (!isset(static::$wrapper_site['get_sites'])) {
            static::$wrapper_site['get_sites'] = get_sites(['number' => 1000]);
            foreach (static::$wrapper_site['get_sites'] as $k => $site) {
                static::$wrapper_site['sites'][$site->blog_id] = $site;
            }
        }
        return static::$wrapper_site['get_sites'];
    }

    public static function get_blog_details($site_id)
    {
        if (!isset(static::$wrapper_site['sites'][maybe_serialize($site_id)])) {
            static::$wrapper_site['sites'][maybe_serialize($site_id)] = get_blog_details($site_id);
        }
        return static::$wrapper_site['sites'][maybe_serialize($site_id)];
    }

    /**
     * Utils
     */
    public static function add_var_to_url($variable_name, $variable_value, $url_string = null)
    {
        if (empty($url_string)) {
            $url_string = '//'.$_SERVER[HTTP_HOST].$_SERVER[REQUEST_URI];
        }
        // first we will remove the var (if it exists)
        // test if url has variables (contains "?")
        if (strpos($url_string, "?") !== false) {
            $start_pos = strpos($url_string, "?");
            $url_vars_strings = substr($url_string, $start_pos + 1);
            $names_and_values = explode("&", $url_vars_strings);
            $url_string = substr($url_string, 0, $start_pos);
            foreach ($names_and_values as $value) {
                list($var_name, $var_value) = explode("=", $value);
                if ($var_name != $variable_name) {
                    if (strpos($url_string, "?") === false) {
                        $url_string .= "?";
                    } else {
                        $url_string .= "&";
                    }
                    $url_string .= $var_name . "=" . $var_value;
                }
            }
        }
        // add variable name and variable value
        if (strpos($url_string, "?") === false) {
            $url_string .= "?" . $variable_name . "=" . $variable_value;
        } else {
            $url_string .= "&" . $variable_name . "=" . $variable_value;
        }
        return $url_string;
    }

    public static function isSecure()
    {
        $isSecure = false;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            $isSecure = true;
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
            $isSecure = true;
        }
        return $isSecure;
    }

    public static function base64url($data, $encode = true)
    {
        if ($encode) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        } else {

            return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
        }
    }

    public static function minifyString($string)
    {
        // Normalize whitespace
        $string = preg_replace('/\s+/', ' ', $string);
        // Remove spaces before and after comment
        $string = preg_replace('/(\s+)(\/\*(.*?)\*\/)(\s+)/', '$2', $string);
        // Remove comment blocks, everything between /* and */, unless
        // preserved with /*! ... */ or /** ... */
        $string = preg_replace('~/\*(?![\!|\*])(.*?)\*/~', '', $string);
        // Remove ; before }
        $string = preg_replace('/;(?=\s*})/', '', $string);
        // Remove space after , : ; { } */ >
        $string = preg_replace('/(,|:|;|\{|}|\*\/|>) /', '$1', $string);
        // Remove space before , ; { } ( ) >
        $string = preg_replace('/ (,|;|\{|}|\(|\)|>)/', '$1', $string);
        // Strips leading 0 on decimal values (converts 0.5px into .5px)
        $string = preg_replace('/(:| )0\.([0-9]+)(%|em|ex|px|in|cm|mm|pt|pc)/i', '${1}.${2}${3}', $string);
        // Strips units if value is 0 (converts 0px to 0)
        $string = preg_replace('/(:| )(\.?)0(%|em|ex|px|in|cm|mm|pt|pc)/i', '${1}0', $string);
        // Converts all zeros value into short-hand
        $string = preg_replace('/0 0 0 0/', '0', $string);
        // Shortern 6-character hex color codes to 3-character where possible
        $string = preg_replace('/#([a-f0-9])\\1([a-f0-9])\\2([a-f0-9])\\3/i', '#\1\2\3', $string);
        $string = str_replace(' ', '', $string);
        return trim($string);
    }

    public static function remoteFileExists($url)
    {
        $curl = curl_init($url);

        //don't fetch the actual page, you only want to check the connection is ok
        curl_setopt($curl, CURLOPT_NOBODY, true);

        //do request
        $result = curl_exec($curl);

        $ret = false;

        //if request did not fail
        if ($result !== false) {
            //if request was ok, check response code
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if ($statusCode == 200) {
                $ret = true;
            }
        }

        curl_close($curl);

        return $ret;
    }

    public static function slugify($text)
    {
        $text = preg_replace('~[^\\pL\d]+~u', '-', $text);
        $text = trim($text, '-');
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = strtolower($text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        if (empty($text)) {
            return 'n-a';
        }
        return $text;
    }

    public static function replaceFirst($search, $replace, $subject)
    {
        if ($search == '') {
            return $subject;
        }

        $position = strpos($subject, $search);

        if ($position !== false) {
            return substr_replace($subject, $replace, $position, strlen($search));
        }

        return $subject;
    }

    public static function cut_text($text, $excerpt_length = 35, $type = false)
    {
        if ('' != $text) {

            $text = strip_shortcodes($text);
            $text = str_replace(']]>', ']]&gt;', $text);
            $text = strip_tags($text);

            if ($type) {
                $newtext = substr($text, 0, $excerpt_length);
                if (strlen($newtext) <= $excerpt_length) {
                    $text = $newtext . '...';
                }
            } else {
                $words = explode(' ', $text, $excerpt_length + 1);
                if (count($words) > $excerpt_length) {
                    array_pop($words);
                    array_push($words, '...');
                    $text = implode(' ', $words);
                }
            }
        }
        return $text;
    }

    public static function str_starts_with($haystack, $needle)
    {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }

    public static function str_ends_with($haystack, $needle)
    {
        // search forward starting from end minus needle length characters
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
    }

}
