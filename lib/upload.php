<?php

/**
 * Attachment Uploader class
 *
 * @since 1.0
 * @package wpuf
 */
class WPUF_Uploader {

    function __construct() {

        add_action( 'wp_enqueue_scripts', array($this, 'enqueue_scripts') );

        add_action( 'wp_ajax_wpuf_file_upload', array($this, 'upload_file') );
        add_action( 'wp_ajax_wpuf_file_del', array($this, 'delete_file') );
    }

    function enqueue_scripts() {
        require_once ABSPATH . '/wp-admin/includes/template.php';

        $path = plugins_url( 'wp-user-frontend' );
        wp_enqueue_script( 'wpuf-upload', $path . '/js/upload.js', array('jquery', 'plupload-handlers') );

        wp_localize_script( 'wpuf-upload', 'wpuf_frontend_upload', array(
            'confirmMsg' => __( 'Are you sure?', 'wpuf' ),
            'nonce' => wp_create_nonce( 'wpuf_nonce' ),
            'plupload' => array(
                'url' => admin_url( 'admin-ajax.php' ) . '?nonce=' . wp_create_nonce( 'wpuf_featured_img' ),
                'flash_swf_url' => includes_url( 'js/plupload/plupload.flash.swf' ),
                'filters' => array(array('title' => __( 'Allowed Files' ), 'extensions' => '*')),
                'multipart' => true,
                'urlstream_upload' => true,
            )
        ) );
    }

    function upload_file() {
        $upload = array(
            'name' => $_FILES['wpuf_file']['name'],
            'type' => $_FILES['wpuf_file']['type'],
            'tmp_name' => $_FILES['wpuf_file']['tmp_name'],
            'error' => $_FILES['wpuf_file']['error'],
            'size' => $_FILES['wpuf_file']['size']
        );

        $attach = $this->handle_upload( $upload );

        if ( $attach['success'] ) {

            $response = array(
                'success' => true,
                'html' => $attach['html'],
            );

            echo json_encode( $response );
            exit;
        }


        $response = array('success' => false, 'message' => $attach['error']);
        echo json_encode( $response );
        exit;
    }

    /**
     * Generic function to upload a file
     *
     * @param string $field_name file input field name
     * @return bool|int attachment id on success, bool false instead
     */
    function handle_upload( $upload_data ) {

        $uploaded_file = wp_handle_upload( $upload_data, array('test_form' => false) );

        // If the wp_handle_upload call returned a local path for the image
        if ( isset( $uploaded_file['file'] ) ) {
            $file_loc = $uploaded_file['file'];
            $file_name = basename( $upload_data['name'] );
            $file_type = wp_check_filetype( $file_name );

            $attachment = array(
                'post_mime_type' => $file_type['type'],
                'post_title' => preg_replace( '/\.[^.]+$/', '', basename( $file_name ) ),
                'post_content' => '',
                'post_status' => 'inherit'
            );

            $attach_id = wp_insert_attachment( $attachment, $file_loc );
            $attach_data = wp_generate_attachment_metadata( $attach_id, $file_loc );
            wp_update_attachment_metadata( $attach_id, $attach_data );

            $html = $this->attach_html( $attach_id );

            return array('success' => true, 'html' => $html);
        }

        return array('success' => false, 'error' => $uploaded_file['error']);
    }

    public static function attach_html( $attach_id ) {
        $type = isset( $_GET['type'] ) ? $_GET['type'] : 'image';

        if (wp_attachment_is_image( $attach_id)) {
            $image = wp_get_attachment_image_src( $attach_id, 'thumbnail' );
            $image = $image[0];
        } else {
            $image = wp_mime_type_icon( $attach_id );
        }

        $html = '<li class="image-wrap thumbnail" style="width: 150px">';
        $html .= sprintf( '<div class="attachment-name"><img src="%s" alt="%s" /></div>', $image, esc_attr( $attachment->post_title ) );
        $html .= sprintf( '<div class="caption"><a href="#" class="btn btn-danger btn-small attachment-delete" data-attach_id="%d">%s</a></div>', $attach_id, __( 'Delete', 'wpuf' ) );
        $html .= sprintf( '<input type="hidden" name="wpuf_files[%s][]" value="%d" />', $type, $attach_id );
        $html .= '</li>';

        return $html;
    }

    function delete_file() {
        check_ajax_referer( 'wpuf_nonce', 'nonce' );

        $attach_id = isset( $_POST['attach_id'] ) ? intval( $_POST['attach_id'] ) : 0;
        $attachment = get_post( $attach_id );

        //post author or editor role
        if ( get_current_user_id() == $attachment->post_author || current_user_can( 'delete_private_pages' ) ) {
            wp_delete_attachment( $attach_id, true );
            echo 'success';
        }

        exit;
    }

    function associate_file( $attach_id, $post_id ) {
        wp_update_post( array(
            'ID' => $attach_id,
            'post_parent' => $post_id
        ) );
    }

}

$wpuf_feat_image = new WPUF_Uploader();