<?php
/* 
Plugin Name: Comment Reply Email
Plugin URI: https://wpjohnny.com/comment-reply-email
Version: 1.0.0
Author: WPJohnny
Description: Commenters can receive email notifications of replies to their comments.
Author URI: https://wpjohnny.com
Donate link: https://www.paypal.me/wpjohnny
Text Domain:  comment-reply-email
*/

if(!class_exists('comment_reply_email')):
class comment_reply_email{
	var $version = '1.0.0.001';
	var $status = '';
	var $message = '';
	var $options = array();
	var $options_keys = array('mail_notify', 'mail_subject', 'mail_message', 'clean_option', 'dn_hide_note');
	var $db_options = 'commentreplyemail';

	function comment_reply_email(){
		$this->initoption();
		$this->inithook();
	}

	function defaultoption($key=''){
		if(empty($key))
			return false;

		if($key === 'mail_notify'){
			return 'none';
		}elseif($key === 'mail_subject'){
			return __('Your comment at [blogname] has a new reply','comment-reply-email');
		}elseif($key === 'mail_message'){
			return __("<p><strong>[pc_author]</strong>,</p><p>Your comment on <strong>[postname]</strong> has a new reply by [cc_author]:</p>\n<p>[cc_content]</p>\n<p>Your original comment:<br />\n[pc_content]</p>\n<p>Comment link:<br />\n<a href=\"[commentlink]\">[commentlink]</a></p>\n<p><strong>This is a system message. Please don't reply to this email.</strong></p>",'comment-reply-email');
		}elseif($key === 'clean_option'){
			return 'no';
		}elseif($key === 'dn_hide_note'){
			return 'no';
		}else{
			return false;
		}
	}

	function resetToDefaultOptions(){
		$this->options = array();

		foreach($this->options_keys as $key){
			$this->options[$key] = $this->defaultoption($key);
		}
		update_option($this->db_options, $this->options);
	}

	function initoption(){
		$optionsFromTable = get_option($this->db_options);
		if (empty($optionsFromTable)){
			$this->resetToDefaultOptions();
		}

		$flag = FALSE;
		foreach($this->options_keys as $key) {
			if(isset($optionsFromTable[$key]) && !empty($optionsFromTable[$key])){
				$this->options[$key] = $optionsFromTable[$key];
			}else{
				$this->options[$key] = $this->defaultoption($key);
				$flag = TRUE;
			}
		}
		if($flag === TRUE){
			update_option($this->db_options, $this->options);
		}
		unset($optionsFromTable,$flag);
	}

	function inithook(){
		add_action('init', array(&$this, 'init_textdomain'));
		add_action('comment_post', array(&$this,'add_mail_reply'),9998);
		add_action('wp_set_comment_status', array(&$this,'status_change'),9999,2);
		add_action('comment_post', array(&$this,'email'),9999);
		add_action('comment_form', array(&$this,'addreplyidformfield'),9999);
		add_action('admin_menu', array(&$this,'wpadmin'));
	}

	function init_textdomain(){
		load_plugin_textdomain('comment-reply-email',false,basename(dirname(__FILE__)));
	}

	function deactivate(){
		if($this->options['clean_option'] === 'yes')
			delete_option($this->db_options);
		return true;
	}

	function status_change($id,$status){
		$id = (int) $id;
		if(isset($GLOBALS['comment']) && ($GLOBALS['comment']->comment_ID == $id)){
			unset($GLOBALS['comment']);
			$comment = get_comment($id);
			$GLOBALS['comment'] = $comment;
		}

		if ($status== 'approve' && intval($comment->comment_parent)>0){
			$this->mailer($id,$comment->comment_parent,$comment->comment_post_ID);
		}

		return $id;
	}
	
	function email($id){
		
		global $wpdb;
		$con = $wpdb->dbh;
		
		if((int) mysqli_real_escape_string($con, $_POST['comment_parent']) === 0 || (int) mysqli_real_escape_string($con, $_POST['comment_post_ID']) === 0){
			$sendemail = 0;			
			if (isset($_POST['action']) && $_POST['action'] == 'replyto-comment' && isset($_POST['comment_ID'])) {
				$id_parent = $_POST['comment_ID'];
				if($this->options['mail_notify'] === 'parent_check'){
					$request = $wpdb->get_row("SELECT comment_mail_notify FROM $wpdb->comments WHERE comment_ID='$id_parent'");
					$sendemail = $request->comment_mail_notify;
				} else {
					$sendemail = 1;
				}
			}
			if ($sendemail == 0) {
				return $id;
			}
			$comment_parent = mysqli_real_escape_string($con, $_POST['comment_ID']);
			$comment_post = mysqli_real_escape_string($con, $_POST['comment_post_ID']);
		} else {
			$comment_parent = mysqli_real_escape_string($con, $_POST['comment_parent']);
			$comment_post = mysqli_real_escape_string($con, $_POST['comment_post_ID']);
		}
		
		if($this->options['mail_notify'] != 'none'){
			$this->mailer($id,$comment_parent,$comment_post);
		}
		return $id;
	}
		
	function add_mail_reply($id){
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

	function mailer($id,$parent_id,$comment_post_id){
		global $wpdb, $user_ID, $userdata;

		$post = get_post($comment_post_id);

		if(empty($post)){
			unset($post);
			return false;
		}

		if($this->options['mail_notify'] == 'admin'){
			$cap = $wpdb->prefix . 'capabilities';
			if((strtolower((string) array_shift(array_keys((array)($userdata->$cap)))) !== 'administrator') && ((int)$post->post_author !== (int)$user_ID)){
				unset($post, $cap);
				return false;
			}
		}
		
		//$parent_email = trim($wpdb->get_var("SELECT comment_author_email FROM {$wpdb->comments} WHERE comment_ID='$parent_id'"));
		$pc = get_comment($parent_id);
		if(empty($pc)){
			unset($pc);
			return false;
		}

		if(intval($pc->comment_mail_notify) === 0 && ($this->options['mail_notify'] === 'parent_uncheck' || $this->options['mail_notify'] === 'parent_check')){
			unset($pc);
			return false;
		}

		$parent_email = trim($pc->comment_author_email);

		if(empty($parent_email) || !is_email($parent_email)){
			unset($pc, $parent_email);
			return false;
		}

		$cc = get_comment($id);
		if(empty($cc)){
			unset($pc,$cc);
			return false;
		}

		if ($cc->comment_approved != '1')
		{
			unset($pc,$cc);
			return false;
		}

		if($parent_email === trim($cc->comment_author_email)){ //Do not send email if you reply to your own comments
			unset($pc,$cc);
			return false;
		}

		$mail_subject = $this->options['mail_subject'];
		$mail_subject = str_replace('[blogname]', get_option('blogname'), $mail_subject);
		$mail_subject = str_replace('[postname]', $post->post_title, $mail_subject);

		$mail_message = $this->options['mail_message'];
		$mail_message = str_replace('[pc_date]', mysql2date( get_option('date_format'), $pc->comment_date), $mail_message);
		$mail_message = str_replace('[pc_content]', $pc->comment_content, $mail_message);
		$mail_message = str_replace('[pc_author]', $pc->comment_author, $mail_message);
		
		$mail_message = str_replace('[cc_author]', $cc->comment_author, $mail_message);
		$mail_message = str_replace('[cc_date]', mysql2date( get_option('date_format'), $cc->comment_date), $mail_message);
		$mail_message = str_replace('[cc_url]', $cc->comment_url, $mail_message);
		$mail_message = str_replace('[cc_content]', $cc->comment_content, $mail_message);

		$mail_message = str_replace('[blogname]', get_option('blogname'), $mail_message);
		$mail_message = str_replace('[blogurl]', get_option('home'), $mail_message);
		$mail_message = str_replace('[postname]', $post->post_title, $mail_message);

		//$permalink = get_permalink($comment_post_id);
		$permalink =  get_comment_link($parent_id);

		//$mail_message = str_replace('[commentlink]', $permalink . "#comment-{$parent_id}", $mail_message);
		$mail_message = str_replace('[commentlink]', $permalink, $mail_message);

		$wp_email = 'no-reply@' . preg_replace('#^www\.#', '', strtolower($_SERVER['SERVER_NAME']));
		$from = "From: \"".get_option('blogname')."\" <$wp_email>";

		$mail_headers = "$from\nContent-Type: text/html; charset=" . get_option('blog_charset') . "\n";

		unset($wp_email, $from, $post, $pc, $cc, $cap, $permalink);
		
		$mail_message = convert_smilies($mail_message);

		$mail_message = apply_filters('comment_notification_text', $mail_message, $id);
		$mail_subject = apply_filters('comment_notification_subject', $mail_subject, $id);
		$mail_headers = apply_filters('comment_notification_headers', $mail_headers, $id);

		wp_mail($parent_email, $mail_subject, $mail_message, $mail_headers);
		unset($mail_subject,$parent_email,$mail_message, $mail_headers);
		
		return true;
	}

	function addreplyidformfield(){
		if($this->options['mail_notify'] === 'parent_check')
			echo '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" checked="checked" style="width: auto;" /><label for="comment_mail_notify" style="margin-left:7px;">' . __('Email me when someone replies to my comment', 'comment-reply-email') . '</label></p>';
		elseif($this->options['mail_notify'] === 'parent_uncheck')
			echo '<p><input type="checkbox" name="comment_mail_notify" id="comment_mail_notify" value="comment_mail_notify" style="width: auto;" /><label for="comment_mail_notify" style="margin-left:7px;display: inline;position: relative;top: 2px;">' . __('Email me when someone replies to my comment', 'comment-reply-email') . '</label></p>';
		else{}
	}

	function displayMessage() {
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

	function wpadmin(){
		add_options_page(__('Comment Reply Email Option','comment-reply-email'), __('Comment Reply','comment-reply-email'), 5, __FILE__, array(&$this,'options_page'));
	}

	function options_page(){

		if(isset($_POST['updateoptions'])){
			foreach((array) $this->options as $key => $oldvalue) {
				$this->options[$key] = (isset($_POST[$key]) && !empty($_POST[$key])) ? stripslashes($_POST[$key]) : $this->defaultoption($key);
			}
			update_option($this->db_options, $this->options);
			$this->message = __('Options saved','comment-reply-email');
			$this->status = 'updated';
		}elseif( isset($_POST['reset_options']) ){
			$this->resetToDefaultOptions();
			$this->message = __('Plugin configurations are back to default!','comment-reply-email');
		}else{}
		$this->displayMessage();
?>

<div class="wrap">
	<style type="text/css">
		.cre-button {
			background-color: #ffcd00;
			border: none;
			padding: 5px 20px;
			border-radius: 3px;
			font-weight: 700;
		}
	</style>

	<h1>Comment Reply Email</h1>
	<form method="post" action="">
		<fieldset name="wp_basic_options"  class="options">
		<h3><?php _e('Send comment reply emails to commenters:','comment-reply-email'); ?></h3>
		<p>
			<input type="radio" name="mail_notify" id="do_none" value="none" <?php if ($this->options['mail_notify'] !== 'admin' || $this->options['mail_notify'] !== 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('DISABLED - no emails sent.','comment-reply-email'); ?></label>
			<br />
			<input type="radio" name="mail_notify" id="do_admin" value="admin" <?php if ($this->options['mail_notify'] === 'admin') { ?> checked="checked"<?php } ?>/><label><?php _e('ADMIN - only for post author or admin replies. No checkbox shown.','comment-reply-email'); ?></label>
			<br />
			<input type="radio" name="mail_notify" id="do_everyone" value="everyone" <?php if ($this->options['mail_notify'] === 'everyone') { ?> checked="checked"<?php } ?>/><label><?php _e('ALL - if anyone replies. No checkbox shown.','comment-reply-email'); ?></label>
			<br />
			<input type="radio" name="mail_notify" id="do_parent_check" value="parent_check" <?php if ($this->options['mail_notify'] === 'parent_check') { ?> checked="checked"<?php } ?>/><label><?php _e('OPT-IN CHECKED - if parent commenter checks the box (default checked).','comment-reply-email'); ?></label>
			<br />
			<input type="radio" name="mail_notify" id="do_parent_uncheck" value="parent_uncheck" <?php if ($this->options['mail_notify'] === 'parent_uncheck') { ?> checked="checked"<?php } ?>/><label><?php _e('OPT-IN UNCHECKED - if parent commenter checks the box (default unchecked).','comment-reply-email'); ?></label>
			<br />
		</p>
		<h3><?php _e('Reply email SUBJECT:','comment-reply-email'); ?></h3>
		<p><input type="text" name="mail_subject" id="mail_subject" value="<?php echo $this->options['mail_subject']; ?>" size="80" /></p>
		<p><?php _e('Use only TEXT, or the following tags: <ul style="list-style:disc;margin-left:20px;"><li><strong>[blogname]</strong> for blog name and </li><li><strong>[postname]</strong> for comment post name</li></ul>','comment-reply-email'); ?></p>
		<h3><?php _e('Reply email MESSAGE:','comment-reply-email'); ?></h3>
		<p><textarea name="mail_message" id="mail_message" cols="100%" rows="10" ><?php echo $this->options['mail_message']; ?></textarea></p>
		<p><?php _e('Use only TEXT, HTML, or the following tags: <ul style="list-style:disc;margin-left:20px;"><li><strong>[pc_author]</strong> for parent comment author</li><li><strong>[pc_date]</strong> for parent comment date</li><li><strong>[pc_content]</strong> for parent comment content</li><li><strong>[cc_author]</strong> for child comment author</li><li><strong>[cc_date]</strong> for child comment date</li><li><strong>[cc_url]</strong> for child comment author url</li><li><strong>[cc_content]</strong> for child comment content</li><li><strong>[commentlink]</strong> for parent comment link</li><li><strong>[blogname]</strong> for blog name</li><li><strong>[blogurl]</strong> for blog url</li><li><strong>[postname]</strong> for post name</li></ul>','comment-reply-email'); ?></p>
		<h3><?php _e('Delete plugin options after deactivation?','comment-reply-email'); ?></h3>
		<p>
			<label><?php _e('YES','comment-reply-email'); ?></label>
			<input type="checkbox" name="clean_option" id="clean_option" value="yes" <?php if ($this->options['clean_option'] === 'yes') { ?> checked="checked"<?php } ?>/>
		</p>
		<p class="submit">
			<input type="submit" class="cre-button" name="updateoptions" value="<?php _e('Update Options','comment-reply-email'); ?> &raquo;" />
			<input type="submit" class="cre-button" name="reset_options" onclick="return confirm('<?php _e('Do you really want to reset your current configuration?','comment-reply-email'); ?>');" value="<?php _e('Reset Options','comment-reply-email'); ?>" />
		</p>
		</fieldset>
	</form>
	
	<p>Like this plugin? You can <a href="https://paypal.me/wpjohnny">buy me a beer</a> or leave a 5-star review.</p>
  </div>
</div>
<?php
	}
}
endif;

/*
if(!function_exists('wpjam_modify_dashboard_widgets')){
	
	add_action('wp_dashboard_setup', 'wpjam_modify_dashboard_widgets' );
	function wpjam_modify_dashboard_widgets() {
		global $wp_meta_boxes;
		if( strpos(WPLANG, 'zh_') === false){
			wp_add_dashboard_widget('wpjam_dashboard_widget', 'Theme10', 'wpjam_dashboard_widget_function');
		}else{
			wp_add_dashboard_widget('wpjam_dashboard_widget', '我爱水煮鱼', 'wpjam_dashboard_widget_function');
		}
	}
	
	function wpjam_dashboard_widget_function() {
		if( strpos(WPLANG, 'zh_') === false){
			echo '<div class="rss-widget">';
			wp_widget_rss_output('http://theme10.com/feed/', array( 'show_author' => 0, 'show_date' => 1, 'show_summary' => 0 ));
			echo "</div>";
		}else{
		?>
		<p><a href="http://wpjam.com/&amp;utm_medium=wp-plugin&amp;utm_campaign=wp-plugin&amp;utm_source=<?php bloginfo('home');?>" title="WordPress JAM" target="_blank"><img src="http://wpjam.com/wp-content/themes/WPJ-Parent/images/logo_index_1.png" alt="WordPress JAM"></a><br />
        <a href="http://wpjam.com/&amp;utm_medium=wp-plugin&amp;utm_campaign=wp-plugin&amp;utm_source=<?php bloginfo('home');?>" title="WordPress JAM" target="_blank"> WordPress JAM</a> 是中国最好的 WordPress 二次开发团队，我们精通 WordPress，可以制作 WordPress 主题，开发 WordPress 插件，WordPress 整站优化。</p>
        <hr />
	<?php 
		echo '<div class="rss-widget">';
		wp_widget_rss_output('http://fairyfish.net/feed/', array( 'show_author' => 0, 'show_date' => 1, 'show_summary' => 0 ));
		echo "</div>";
		}
	}
} */

$new_comment_reply_email = new comment_reply_email();
?>
