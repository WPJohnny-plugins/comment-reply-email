<?php
/* 
Plugin Name: Comment Reply Email
Description: Commenters can receive email notifications of replies to their comments.
Plugin URI: https://wpjohnny.com/comment-reply-email
Version: 1.5.1
Author: <a href="https://wpjohnny.com">WPJohnny</a>, <a href="https://profiles.wordpress.org/zeroneit/">zerOneIT</a>
Donate link: https://www.paypal.me/wpjohnny
Text Domain:  comment-reply-email
License:      GPL v2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.txt
*/
if(!defined('ABSPATH')) {die('You are not allowed to call this page directly.');}

if(!class_exists('CommentReplyEmail')):
    class CommentReplyEmail{
	    var $status = '';
	    var $message = '';
	    var $options = array();
	    var $optionKeys = array('mail_notify', 'mail_subject', 'mail_message', 'checkbox_text', 'clean_option', 'dn_hide_note');
	    var $dbOptions = 'commentreplyemail';

	    function __construct(){
		    $this->initOption();
		    $this->initHook();
	    }

	    function defaultOption($key=''){
		    if(empty($key))
			    return false;

		    if($key === 'mail_notify'){
			    return 'none';
		    }elseif($key === 'mail_subject'){
			    return __('Your comment at [blogname] has a new reply','comment-reply-email');
		    }elseif($key === 'mail_message'){
			    return __("<p><strong>[pc_author]</strong>,</p><p></p><p>Your comment on <strong>[postname]</strong> has a new reply by [cc_author]:</p>\n<p>[cc_content]</p><p></p>\n<p>Your original comment:<br />\n[pc_content]</p><p></p>\n<p>Comment link:<br />\n<a href=\"[commentlink]\">[commentlink]</a></p><p></p>\n<p><strong>This is a system message. Please don't reply to this email.</strong></p>",'comment-reply-email');
		    }elseif($key === 'clean_option'){
			    return 'no';
		    }elseif($key === 'dn_hide_note'){
			    return 'no';
		    }elseif($key === 'checkbox_text'){
			    return 'Email me when someone replies to my comment';
		    }else{
			    return false;
		    }
	    }

	    function resetOptionsToDefault(){
		    $this->options = array();

		    foreach($this->optionKeys as $key){
			    $this->options[$key] = $this->defaultOption($key);
		    }
		    update_option($this->dbOptions, $this->options);
	    }

	    function initOption(){
		    $optionsFromTable = get_option($this->dbOptions);
		    if (empty($optionsFromTable)){
			    $this->resetOptionsToDefault();
		    }

		    $flag = FALSE;
		    foreach($this->optionKeys as $key) {
			    if(isset($optionsFromTable[$key]) && !empty($optionsFromTable[$key])){
				    $this->options[$key] = $optionsFromTable[$key];
			    }else{
				    $this->options[$key] = $this->defaultOption($key);
				    $flag = TRUE;
			    }
		    }
		    if($flag === TRUE){
			    update_option($this->dbOptions, $this->options);
		    }
		    unset($optionsFromTable,$flag);
	    }

	    function initHook(){
		    add_action('init', array(&$this, 'initTextDomain'));
		    add_action('comment_post', array(&$this,'addEmailReply'),9998);
		    add_action('wp_set_comment_status', array(&$this,'updateStatus'),9999,2);
		    add_action('comment_post', array(&$this,'email'),9999, 3);
    //        add_action('comment_form', array(&$this,'addReplyIdFormField'),9999);
		    add_filter('comment_form_field_comment', array(&$this,'addReplyIdFormField'),9999);
		    add_action('admin_menu', array(&$this,'wpAdmin'));
	    }

	    function initTextDomain(){
		    load_plugin_textdomain('comment-reply-email',false,basename(dirname(__FILE__)));
	    }

	    function deactivate(){
		    if($this->options['clean_option'] === 'yes')
			    delete_option($this->dbOptions);
		    return true;
	    }

	    function updateStatus($id,$status){
		    $id = (int) $id;
		    if(isset($GLOBALS['comment']) && ($GLOBALS['comment']->comment_ID == $id)){
			    unset($GLOBALS['comment']);
			    $comment = get_comment($id);
			    $GLOBALS['comment'] = $comment;
		    }

		    if ($status== 'approve' && isset($comment) && intval($comment->comment_parent) > 0){
			    $this->sendMail($id,$comment->comment_parent,$comment->comment_post_ID);
		    }

		    return $id;
	    }
	    
	    function email($id, $isCommentApproved, $commentData){
		    
		    global $wpdb;
		    $con = $wpdb->dbh;
		    
		    if($commentData['comment_parent'] == 0 || $commentData['comment_post_ID'] == 0){
			    $canSendMail = 0;			
			    if (isset($_POST['action']) && $_POST['action'] == 'replyto-comment' && isset($_POST['comment_ID'])) {
				    $parentId = absint($_POST['comment_ID']);
				    if($this->options['mail_notify'] === 'parent_check'){
					    $request = $wpdb->get_row($wpdb->prepare("SELECT comment_mail_notify FROM $wpdb->comments WHERE comment_ID=%s", $parentId));
					    $canSendMail = $request->comment_mail_notify;
				    } else {
					    $canSendMail = 1;
				    }
                    
                    $parentComment = mysqli_real_escape_string($con, absint($_POST['comment_ID']));
                    $commentPost = $commentData['comment_post_ID'];
			    }
			    if ($canSendMail == 0) {
				    return $id;
			    }
		    } else {
			    $parentComment = $commentData['comment_parent'];
			    $commentPost = $commentData['comment_post_ID'];
		    }
		    
		    if($this->options['mail_notify'] != 'none'){
			    $this->sendMail($id,$parentComment,$commentPost);
		    }
		    return $id;
	    }
		    
	    function addEmailReply($id){
		    global $wpdb;

		    if(isset($_POST['comment_mail_notify'])){
			    $i = 0;
			    if($wpdb->query("Describe {$wpdb->comments} comment_mail_notify") == 0 && $i < 10){
				    $wpdb->query("ALTER TABLE {$wpdb->comments} ADD COLUMN comment_mail_notify TINYINT NOT NULL DEFAULT 0;");
				    $i++;
			    }
			    $wpdb->query("UPDATE {$wpdb->comments} SET comment_mail_notify='1' WHERE comment_ID='$id'");
		    }

		    return $id;
	    }

	    function sendMail($id,$parentId,$commentPostId){
		    global $wpdb, $userId, $userdata;

		    $post = get_post($commentPostId);

		    if(empty($post)){
			    unset($post);
			    return false;
		    }

		    if($this->options['mail_notify'] == 'admin'){
			    $cap = $wpdb->prefix . 'capabilities';
			    if((strtolower((string) array_shift(array_keys((array)($userdata->$cap)))) !== 'administrator') && ((int)$post->post_author !== (int)$userId)){
				    unset($post, $cap);
				    return false;
			    }
		    }
		    
		    $parentComment = get_comment($parentId);
		    if(empty($parentComment)){
			    unset($parentComment);
			    return false;
		    }

		    if(intval($parentComment->comment_mail_notify) === 0 && ($this->options['mail_notify'] === 'parent_uncheck' || $this->options['mail_notify'] === 'parent_check')){
			    unset($parentComment);
			    return false;
		    }

		    $parentCommentAuthorEmail = trim($parentComment->comment_author_email);

		    if(empty($parentCommentAuthorEmail) || !is_email($parentCommentAuthorEmail)){
			    unset($parentComment, $parentCommentAuthorEmail);
			    return false;
		    }

		    $currentComment = get_comment($id);
		    if(empty($currentComment)){
			    unset($parentComment, $currentComment);
			    return false;
		    }

		    if ($currentComment->comment_approved != '1')
		    {
			    unset($parentComment, $currentComment);
			    return false;
		    }

		    if($parentCommentAuthorEmail === trim($currentComment->comment_author_email)){ //Do not send email if you reply to your own comments
			    unset($parentComment,$currentComment);
			    return false;
		    }

		    $mailSubject = strip_tags($this->options['mail_subject']);
		    $mailSubject = str_replace('[blogname]', get_option('blogname'), $mailSubject);
		    $mailSubject = str_replace('[postname]', $post->post_title, $mailSubject);

		    $mailContent = $this->options['mail_message'];
		    $mailContent = str_replace('[pc_date]', mysql2date( get_option('date_format'), $parentComment->comment_date), $mailContent);
		    $mailContent = str_replace('[pc_content]', $parentComment->comment_content, $mailContent);
		    $mailContent = str_replace('[pc_author]', $parentComment->comment_author, $mailContent);
		    
		    $mailContent = str_replace('[cc_author]', $currentComment->comment_author, $mailContent);
		    $mailContent = str_replace('[cc_date]', mysql2date( get_option('date_format'), $currentComment->comment_date), $mailContent);
		    $mailContent = str_replace('[cc_url]', $currentComment->comment_url, $mailContent);
		    $mailContent = str_replace('[cc_content]', $currentComment->comment_content, $mailContent);

		    $mailContent = str_replace('[blogname]', get_option('blogname'), $mailContent);
		    $mailContent = str_replace('[blogurl]', get_option('home'), $mailContent);
		    $mailContent = str_replace('[postname]', $post->post_title, $mailContent);

		    $permalink =  get_comment_link($parentId);

		    $mailContent = str_replace('[commentlink]', $permalink, $mailContent);

		    $mailMeta = 'no-reply@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
		    $from = "From: \"".get_option('blogname')."\" <$mailMeta>";

		    $mailHeader = "$from\nContent-Type: text/html; charset=" . get_option('blog_charset') . "\n";

		    unset($mailMeta, $from, $post, $parentComment, $currentComment, $cap, $permalink);
		    
		    $mailContent = convert_smilies($mailContent);

		    $mailContent = apply_filters('comment_notification_text', $mailContent, $id);
		    $mailSubject = apply_filters('comment_notification_subject', $mailSubject, $id);
		    $mailHeader = apply_filters('comment_notification_headers', $mailHeader, $id);

		    wp_mail($parentCommentAuthorEmail, $mailSubject, $mailContent, $mailHeader);
		    unset($mailSubject,$parentCommentAuthorEmail,$mailContent, $mailHeader);
		    
		    return true;
	    }

	    function addReplyIdFormField($field){
		    $labelText = ! empty( $this->options['checkbox_text'] ) ?  $this->options['checkbox_text'] : __('Email me when someone replies to my comment', 'comment-reply-email');

		    if($this->options['mail_notify'] === 'parent_check')
			    return $field . '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" checked="checked" style="width: auto;" /><label for="comment_mail_notify" style="margin-left:7px;">' . strip_tags($labelText) . '</label></p>';
		    elseif($this->options['mail_notify'] === 'parent_uncheck')
			    return $field . '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" style="width: auto;" /><label for="comment_mail_notify" style="margin-left:7px;display: inline;position: relative;top: 2px;">' . strip_tags($labelText) . '</label></p>';
		    else{
                return $field; // @version 1.3
            }
	    }

	    function displayMessage() {
		    $message = '';
		    if ( $this->message != '') {
			    $message = $this->message;
			    $status = $this->status;
			    $this->message = $this->status = '';
		    }

		    if ( $message ) {
    ?>
			    <div id="message" class="<?php echo ($status != '') ? $status :'updated '; ?> fade">
				    <p><strong><?php echo $message; ?></strong></p>
			    </div>
    <?php	
		    }
		    unset($message,$status);
	    }

	    function wpAdmin(){
		    add_options_page(
			    __( 'Comment Reply Email Option', 'comment-reply-email' ),
			    __( 'Comment Reply', 'comment-reply-email' ),
			    'manage_options',
			    __FILE__,
			    array( $this, 'optionsPage' )
		    );
	    }

	    // Custom sanitization function to allow specific HTML tags
		function custom_sanitize_html($input) {
		    $allowed_tags = array(
		        'a' => array(
		            'href' => array(),
		            'title' => array()
		        ),
		        'br' => array(),
		        'em' => array(),
		        'strong' => array(),
		        'p' => array(),
		        'ul' => array(),
		        'ol' => array(),
		        'li' => array()
		    );
		    return wp_kses($input, $allowed_tags);
		}

	    function optionsPage(){

		    if (isset($_POST['updateoptions']) && check_admin_referer('sec-check-nonce', '_wpnonce')) {
		        foreach ((array) $this->options as $key => $oldvalue) {
		        	if ($key == 'mail_message') {
		        		$this->options[$key] = isset($_POST[$key]) ? $this->custom_sanitize_html($_POST[$key]) : $this->defaultOption($key);	
		        	} else {
		        		$this->options[$key] = isset($_POST[$key]) ? sanitize_text_field($_POST[$key]) : $this->defaultOption($key);	
		        	}
		        }
		        update_option($this->dbOptions, $this->options);
		        $this->message = __('Options saved', 'comment-reply-email');
		        $this->status = 'updated';
		    } elseif (isset($_POST['reset_options']) && check_admin_referer('sec-check-nonce', '_wpnonce')) {
		        $this->resetOptionsToDefault();
		        $this->message = __('Plugin configurations are back to default!', 'comment-reply-email');
		    }
		    
		    $this->displayMessage();
            ?>

            <div class="wrap">

	            <h1>Comment Reply Email</h1>
	            <form method="post" action="">
                    <?php wp_nonce_field('sec-check-nonce'); ?>
		            <h2 class="title"><?php _e('Send comment reply emails to commenters:','comment-reply-email'); ?></h2>
		            <p>
			            <input type="radio" name="mail_notify" id="do_none" value="none" <?php if ($this->options['mail_notify'] !== 'admin' || $this->options['mail_notify'] !== 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('DISABLED - feature disabled, no checkbox shown. Commenters do not receive email notifications of replies to their comment.','comment-reply-email'); ?></label>
		            </p>
		            <p>
			            <input type="radio" name="mail_notify" id="do_admin" value="admin" <?php if ($this->options['mail_notify'] === 'admin') { ?> checked="checked"<?php } ?>/><label><?php _e('ADMIN - no checkbox shown. Commenters receive email notification only when post author or site admin replies to their comment.','comment-reply-email'); ?></label>
		            </p>
		            <p>
			            <input type="radio" name="mail_notify" id="do_everyone" value="everyone" <?php if ($this->options['mail_notify'] === 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('ALL - no checkbox shown. Commenters receive email notification when someone replies to their comment.','comment-reply-email'); ?></label>
		            </p>
		            <p>
			            <input type="radio" name="mail_notify" id="do_parent_check" value="parent_check" <?php if ($this->options['mail_notify'] === 'parent_check') { ?> checked="checked"<?php } ?>/><label><?php _e('OPT-IN CHECKED - checkbox shown, and checked by default. Unless manually unchecked, commenters receive email notification when someone replies to their comment.','comment-reply-email'); ?></label>
		            </p>
		            <p>
			            <input type="radio" name="mail_notify" id="do_parent_uncheck" value="parent_uncheck" <?php if ($this->options['mail_notify'] === 'parent_uncheck') { ?> checked="checked"<?php } ?>/><label><?php _e('OPT-IN UNCHECKED - checkbox shown, but not checked by default. If checked, commenters receive email notification when someone replies to their comment.','comment-reply-email'); ?></label>
		            </p>
		            <hr>
		            <h2 class="title"><?php _e('Reply email SUBJECT:','comment-reply-email'); ?></h2>
		            <p>
			            <input type="text" name="mail_subject" id="mail_subject" value="<?php echo esc_attr($this->options['mail_subject']); ?>" size="80" />
		            </p>
		            <p><?php _e('Use only TEXT, or the following tags:','comment-reply-email'); ?></p>
		            <ul>
			            <li><code>[blogname]</code> <?php esc_html_e('for blog name', 'comment-reply-email'); ?></li>
                		<li><code>[postname]</code> <?php esc_html_e('for comment post name', 'comment-reply-email'); ?></li>
		            </ul>
		            <hr>
		            <h2 class="title"><?php esc_html_e('Reply email MESSAGE:','comment-reply-email'); ?></h2>
		            <p>
			            <textarea name="mail_message" id="mail_message" cols="100%" rows="10" ><?php echo esc_html($this->options['mail_message']); ?></textarea>
		            </p>
		            <hr>
		            <p><?php _e('Use only TEXT, HTML, or the following tags:','comment-reply-email'); ?></p>
		            <ul>
			            <li><code>[pc_author]</code> for parent comment author</li>
			            <li><code>[pc_date]</code> for parent comment date</li>
			            <li><code>[pc_content]</code> for parent comment content</li>
			            <li><code>[cc_author]</code> for child comment author</li>
			            <li><code>[cc_date]</code> for child comment date</li>
			            <li><code>[cc_url]</code> for child comment author url</li>
			            <li><code>[cc_content]</code> for child comment content</li>
			            <li><code>[commentlink]</code> for parent comment link</li>
			            <li><code>[blogname]</code> for blog name</li>
			            <li><code>[blogurl]</code> for blog url</li>
			            <li><code>[postname]</code> for post name</li>
		            </ul>
		            <hr>
		            <h2 class="title"><?php _e('Checkbox text','comment-reply-email'); ?></h2>
		            <p>
			            <input type="text" name="checkbox_text" id="checkbox_text" value="<?php echo esc_attr($this->options['checkbox_text']); ?>" size="80" />
		            </p>
		            <hr>
		            <h2 class="title"><?php _e('Plugin options','comment-reply-email'); ?></h2>
		            <p>
			            <input type="checkbox" name="clean_option" id="clean_option" value="yes" <?php if ($this->options['clean_option'] === 'yes') { ?> checked="checked"<?php } ?>/>
			            <label><?php _e('Delete plugin options after deactivation?','comment-reply-email'); ?></label>
		            </p>
		            <p class="submit">
			            <input type="submit" class="button button-primary" name="updateoptions" value="<?php esc_attr_e('Update Options', 'comment-reply-email'); ?>" />
                		<input type="submit" class="button button-secondary" name="reset_options" onclick="return confirm('<?php esc_attr_e('Do you really want to reset your current configuration?', 'comment-reply-email'); ?>');" value="<?php esc_attr_e('Reset Options', 'comment-reply-email'); ?>" />
		            </p>
	            </form>
	            
	            <p><?php esc_html_e('Like this plugin? You can', 'comment-reply-email'); ?> <a href="https://paypal.me/wpjohnny"><?php esc_html_e('buy me a beer', 'comment-reply-email'); ?></a> <?php esc_html_e('or', 'comment-reply-email'); ?> <a href="https://wordpress.org/plugins/comment-reply-email/"><?php esc_html_e('leave a 5-star review', 'comment-reply-email'); ?></a>.</p>
            </div>
            <?php
	    }
    }
endif;

$CommentReplyEmail = new CommentReplyEmail();
