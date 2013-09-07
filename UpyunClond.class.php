<?php
/**
 * 
 * @author Cuelog
 * @link http://cuelog.com
 * @copyright 2013 Cuelog
 */
class UpYunCloud {
	
	/**
	 *
	 * @var 提示信息
	 */
	private $msg = null;
	/**
	 * 
	 * @var 文件MIME类型
	 */
	private $mime = null;
	/**
	 *
	 * @var 服务器线路
	 */
	private $upyun_api = array (
			'v0' => 'v0.api.upyun.com',
			'v1' => 'v1.api.upyun.com',
			'v2' => 'v2.api.upyun.com',
			'v3' => 'v3.api.upyun.com' 
	);
	/**
	 *
	 * @var wordpress中upload_dir函数的各项值 
	 */
	private $upload_dir = array ();
	
	/**
	 *
	 * @var 又拍云SDK实例
	 */
	private $UPyun = null;
	
	/**
	 *
	 * @var 插件设置参数
	 */
	private $option = array ();
	

	public function __construct(){
		$this->upload_dir = wp_upload_dir();
		$this->upyun_option_init();
		add_action ( 'admin_menu', array(&$this, 'upyun_option_menu') );
		add_action ( 'admin_notices', array(&$this, 'check_plugin_connection' ) );
		add_action ( 'wp_ajax_nopriv_upyun_ajax', array( &$this, 'upyun_ajax' ) );
		add_action ( 'wp_ajax_upyun_ajax', array( &$this, 'upyun_ajax') );
		add_filter ( 'wp_handle_upload', array( &$this, 'upload_completed' ) );
		add_filter ( 'wp_get_attachment_url', array( &$this, 'replace_url' ) );
		add_filter ( 'wp_generate_attachment_metadata', array( &$this, 'uplaod_to_upyun' ), 999 );
		add_filter ( 'wp_handle_upload_prefilter', array( &$this, 'before_upload' ), 999 );
		add_filter ( 'wp_update_attachment_metadata', array( &$this, 'uplaod_to_upyun' ) );
		add_filter ( 'wp_delete_file', array( &$this, 'delete_file_from_upyun' ) );
		$this->UPyun = new UpYun ( $this->option['bucket_name'], $this->option['admin_username'], $this->option['admin_password'], $this->upyun_api[$this->option['upyun_api']] );
	}
	
	/**
	 * 获取又拍云错误信息
	 * 
	 * @return Ambigous <boolean, unknown>
	 */
	private function get_upyun_error() {
		return $this->UPyun->get_error_msg ();
	}
	
	/**
	 * 显示错误信息
	 */
	private function show_msg($state = false) {
		$msg = $this->get_upyun_error ();
		$msg = $msg ? $msg ['error'] : $this->msg;
		$state = $state === false ? 'error' : 'updated';
		if (! empty ( $msg )) {
			echo "<div class='{$state}'><p>{$msg}</p></div>";
		}
	}
	
	
	/**
	 * 获取文件后缀
	 * @param unknown $file
	 * @return boolean
	 */
	private function is_img($file = null) {
		if(!is_null($file)){
			$allow_suffix = array (	'jpg',	'jpeg',	'png',	'gif' );
			$suffix = strtolower ( trim ( strrchr ( $file, '.' ), '.' ) );
			if (in_array ( $suffix, $allow_suffix )) {
				return true;
			}
		} else {
			$suffix = substr ( $this->mime, 0, strpos ( $this->mime, '/' ) );
			if ($suffix == 'image') {
				return true;
			}
		}
		return false;
	}

	
	/**
	 * 初始化插件参数
	 */
	private function upyun_option_init() {
		$default_option = array (
				'api_url' => 'v0',
				'remote_upload_root' => '/',
				'is_normal' => 'Y',
				'is_delete' => 'N' 
		);
		$this->option = get_option ( 'upyun_option', $default_option );
	}
	
	/**
	 * 获取附件文件集合
	 * @param wp uploas 路径 $path
	 * @return Ambigous <multitype:, multitype:string >
	 */
	private function get_file_list($path) {
		$result_list = array ();
		if (is_dir ( $path )) {
			if ($handle = opendir ( $path )) {
				while ( false !== ($file = readdir ( $handle )) ) {
					if ($file != "." && $file != "..") {
						$file_path = $path . '/' . $file;
						if (is_dir ( $file_path )) {
							$res = $this->get_file_list ( $file_path );
							$result_list = array_merge ( $res, $result_list );
						} else {
							$suffix = strtolower ( ltrim ( strrchr ( $file, '.' ), '.' ) );
							if ($this->option ['is_normal'] == 'Y') {
								$result_list [] = $this->iconv2cn ( $file_path, true );
							} else {
								if( in_array ( $suffix, array( 'jpg', 'png', 'jpeg', 'gif' ) ) ){
									$result_list[] = $this->iconv2cn($file_path, true);
								}
							}
						}
					}
				}
				closedir ( $handle );
			}
		}
		return $result_list;
	}
	
	/**
	 * 解决上传/下载文件包括中文名问题
	 */
	private function iconv2cn($str, $cn = false) {
		if (! UPYUN_IS_WIN) {
			return $str;
		}
		return $cn === true ? iconv ( 'GBK', 'UTF-8', $str ) : iconv ( 'UTF-8', 'GBK', $str );
	}
	
	/**
	 * 获取又拍云空间中的文件存放目录
	 * @param string $dir_file
	 * @return string
	 */
	private function get_remote_upload_path($dir_file){
		return $this->option ['remote_upload_root'] . '/' . ltrim( $dir_file, '/' );
	}
	
	/**
	 * 安装插件后检查参数设置
	 * @return boolean
	 */
	public function check_plugin_connection() {
		global $hook_suffix;
		if ($hook_suffix == 'plugins.php') {
			$this->UPyun->getFolderUsage ( '/' );
			$msg = $this->get_upyun_error ();
			if ( empty ( $this->option ['binding_url'] ) 
				|| empty ( $this->option ['bucket_name'] ) 
				|| empty ( $this->option ['admin_username'] ) 
				|| empty ( $this->option ['admin_password'] ) ) 
			{
				echo "<div class='error'><p>又拍云插件缺少相关参数，<a href='/wp-admin/options-general.php?page=set_upyun_option'>点击这里进行设置</a></p></div>";
				return false;
			}
		}
	}
	
	/**
	 * 上传附件前检查链接
	 * 
	 * @return Ambigous <boolean, unknown>
	 */
	public function before_upload($file) {
		$this->mime = trim ( strrchr ( $file ['type'], '/' ), '/' );
		if ($this->is_img ()) {
			$res = $this->UPyun->getFolderUsage ( '/' );
			$msg = $this->get_upyun_error ();
			if ($msg || $res === false) {
				return ! $msg ? array ( 'error' => '又拍云连接失败，请确认插件参数正确无误' ) : $msg;
			}
		}
		return $file;
	}
	
	
	/**
	 * 又拍云参数设置页面
	 */
	public function upyun_option_menu(){
		add_options_page( '又拍云设置', '又拍云设置', 'administrator', 'set_upyun_option', array($this, 'display_upyun_option_page') );
	}
	
	/**
	 * 替换附件的url地址
	 *
	 * @param 上传成功后的文件访问路径 $url        	
	 * @return string
	 */
	public function replace_url($url) {
		return str_replace ( $this->upload_dir ['baseurl'], $this->option ['remote_upload_root_url'], $url );
	}
	
	/**
	 * 新增或编辑附件后，上传到又拍云
	 * 
	 * @param 文件参数 $metadata        	
	 * @return array
	 */
	public function uplaod_to_upyun($metadata) {
		if (! empty ( $metadata ) && $this->is_img ( $metadata ['file'] )) {
			$files = array ();
			$files [] = substr ( $metadata ['file'], strripos ( $metadata ['file'], '/' ) + 1 );
			if (! empty ( $metadata ['sizes'] ['thumbnail'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['thumbnail'] ['file'];
			}
			if (! empty ( $metadata ['sizes'] ['medium'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['medium'] ['file'];
			}
			if (! empty ( $metadata ['sizes'] ['large'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['large'] ['file'];
			}
			if (! empty ( $metadata ['sizes'] ['post-thumbnail'] ['file'] )) {
				$files [] = $metadata ['sizes'] ['post-thumbnail'] ['file'];
			}
			
			$remote_upload_path = $this->get_remote_upload_path( $this->upload_dir ['subdir'] );
			set_time_limit ( 600 );
			foreach ( $files as $fs ) {
				$file_path = $this->upload_dir ['path'] . '/' . $fs;
				if (file_exists ( $file_path )) {
					$fls = file_get_contents ( $file_path );
					$res = $this->UPyun->writeFile ( $remote_upload_path . '/' . $fs, $fls, true );
					if ($res === false) {
						return $this->get_upyun_error ();
					}
					if ($this->option ['is_delete'] == 'Y') {
						unlink ( $file_path );
					}
					unset ( $fls );
				}
			}
		}
		return $metadata;
	}
	
	
	/**
	 * 这里只对非图片的文件做上传处理，因为 uplaod_to_upyun 方法无法获取非图片文件的meta信息
	 * @param Array $file
	 * @return Ambigous <Ambigous, boolean, string>|unknown
	 */
	public function upload_completed($file) {
		if (! $this->is_img () && $this->option ['is_normal'] == 'Y') {
			$file_name = str_replace ( $this->upload_dir ['baseurl'], '', $file ['url'] );
			$remote_upload_path = $this->get_remote_upload_path( $file_name );
			$fls = file_get_contents ( $file ['file'] );
			set_time_limit ( 600 );
			$res = $this->UPyun->writeFile ( $remote_upload_path, $fls, true );
			if ($res === false) {
				return $this->get_upyun_error ();
			}
			if ($this->option ['is_delete'] == 'Y') {
				unlink ( $file ['file'] );
			}
		}
		return $file;
	}

	/**
	 * 删除又拍云空间中的文件
	 *
	 * @param 删除的文件 $file
	 * @return string
	 */
	public function delete_file_from_upyun($file) {
		$delete_files = str_replace ( $this->upload_dir ['basedir'] . '/', '', $file );
		$delete_files = $this->get_remote_upload_path( $delete_files );
		if ($this->UPyun->getFileInfo ( $delete_files ) != false) {
			$this->UPyun->deleteFile ( $delete_files );
		}
		return $file;
	}
	
	
	/**
	 * 获取又拍云空间中的所有文件地址
	 * @param string $path
	 * @return multitype:string
	 */
	public function get_upyun_list($path = null) {
		$path = is_null ( $path ) ? ltrim( $this->option ['remote_upload_root'], '/' ) : $path;
		$list = $this->UPyun->getList ( $path );
		$files = array ();
		if ($list) {
			foreach ( $list as $k => $ls ) {
				if ($ls ['type'] == 'folder') {
					$res = $this->get_upyun_list ( $path . '/' . $ls ['name'] );
					if ($res) {
						$files = array_merge ( $files, $res );
					}
				} else {
					$files [] = 'http://' . $this->option ['binding_url'] . $path . '/' . $ls ['name'];
				}
			}
		}
		return $files;
	}

	/**
	 * 本地-又拍云上传/下载ajax操作
	 */
	public function upyun_ajax(){
		if (isset ( $_GET ['do'] )) {
			if ($_GET ['do'] == 'get_local_list') {
				$list = $this->get_file_list ( $this->upload_dir ['basedir'] );
				$count = count ( $list );
				$img_baseurl = array ();
				if ($count > 0) {
					foreach ( $list as $img ) {
						$img_baseurl [] = str_replace ( $this->upload_dir ['basedir'], $this->upload_dir ['baseurl'], $img );
					}
				}
				$res ['count'] = $count;
				$res ['url'] = $img_baseurl;
				die ( json_encode ( $res ) );
			} elseif ($_GET ['do'] == 'upload') {
				if (isset ( $_GET ['file_url'] )) {
					set_time_limit ( 600 );
					$file_path = str_replace ( $this->upload_dir ['baseurl'], $this->upload_dir ['basedir'], $_GET ['file_url'] );
					$file_path = $this->iconv2cn ( $file_path );
					if (file_exists ( $file_path )) {
						$file = file_get_contents ( $file_path );
						$remote_upload_path = $this->get_remote_upload_path( str_replace ( $this->upload_dir ['baseurl'], '', stripslashes ( $_GET ['file_url'] ) ) );
						$res = $this->UPyun->writeFile ( $remote_upload_path, $file, true );
						if ($res === false) {
							$msg = $this->get_upyun_error ();
							die ( '【Error】 >> ' . $_GET ['file_url'] . ' 原因：' . $msg ['error'] );
						}
						unset ( $file );
						$remote_file_url = str_replace ( $this->upload_dir ['baseurl'], $this->option ['remote_upload_root_url'], $_GET ['file_url'] );
						die ( '上传成功 >> ' . $remote_file_url );
					}
				}
			} elseif ($_GET ['do'] == 'get_upyun_list') {
				$list = $this->get_upyun_list ();
				$count = count ( $list );
				$res = array (
						'count' => $count,
						'url' => $list 
				);
				die ( json_encode ( $res ) );
			} elseif ($_GET ['do'] == 'download') {
				if (isset ( $_GET ['file_path'] )) {
					$file = str_replace ( $this->option ['remote_upload_root_url'], '', $_GET ['file_path'] );
					$local = str_replace ( $this->option ['remote_upload_root_url'], $this->upload_dir ['basedir'], $_GET ['file_path'] );
					$local = mb_convert_encoding ( $local, 'GBK' );
					$local_url = $this->upload_dir ['baseurl'] . $file;
					if ( file_exists ( $this->iconv2cn( $local ) ) ) {
						$msg = '【取消下载，文件已经存在】：' . $local_url;
					} else {
						$file_dir = $this->upload_dir ['basedir'] . substr ( $file, 0, strrpos ( $file, '/' ) );
						if (! is_dir ( $file_dir )) {
							if( ! mkdir ( $file_dir, 0755, true ) ) {
								die ( '【Error】 >> 创建目录失败，请确定是否有足够的权限：' . $file_dir );
							}
						}
						$fp = fopen ( $local, 'wb' );
						$res = $this->UPyun->readFile ( $this->option ['remote_upload_root'] . $file, $fp );
						$msg = $res === false ? '【Error】 >> 下载失败：' . $_GET ['file_path'] : '下载成功 >> ' . $local_url;
						fclose ( $fp );
					}
					die ( $msg );
				}
			}
		}
	}
	
	
	/**
	 * 参数设置页面
	 */
	public function display_upyun_option_page() {
		if (isset ( $_POST ['submit'] )) {
			if (! empty ( $_POST ['action'] )) {
				if (empty ( $this->option ['binding_url'] ) || empty ( $this->option ['bucket_name'] )) {
					$this->msg = '取消操作，你还没有设置又拍云空间绑定的域名或空间名';
					$this->show_msg ();
				} else {
					global $wpdb;
					$upyun_url = $this->option ['remote_upload_root_url'];
					$local_url = $this->upload_dir ['baseurl'];
					if ($_POST ['action'] == 'to_upyun') {
						$sql = "UPDATE $wpdb->posts set `post_content` = replace( `post_content` ,'{$local_url}','{$upyun_url}')";
					} elseif ($_POST ['action'] == 'to_local') {
						$sql = "UPDATE $wpdb->posts set `post_content` = replace( `post_content` ,'{$upyun_url}','{$local_url}')";
					}
					$num_rows = $wpdb->query ( $sql );
					$this->msg = "共有 {$num_rows} 篇文章替换";
					$this->show_msg ( true );
				}
			} else {
				// 绑定域名
				$this->option ['binding_url'] = str_replace ( 'http://', '', trim ( trim ( $_POST ['binding_url'] ), '/' ) );
				// 空间名
				$this->option ['bucket_name'] = trim ( $_POST ['bucket_name'] );
				// 用户名
				$this->option ['admin_username'] = trim ( $_POST ['admin_usernmae'] );
				// 密码
				if (! empty ( $_POST ['admin_password'] )) {
					$this->option ['admin_password'] = $_POST ['admin_password'];
				}
				// 线路
				$this->option ['upyun_api'] = $_POST ['upyun_api'];
				// 根目录
				$remote_upload_root = trim ( $_POST ['remote_upload_root'] );
				$this->option ['remote_upload_root'] = $remote_upload_root == '/' || empty ( $remote_upload_root ) ? '/' : rtrim ( $remote_upload_root, '/' );
				// 文件根目录访问url
				$this->option ['remote_upload_root_url'] = 'http://' . $this->option ['binding_url'] . trim( $this->option ['remote_upload_root'], '/' );
				// 空间类型
				$this->option ['is_normal'] = $_POST ['is_normal'] == 'Y' ? 'Y' : 'N';
				// 是否上传后删除本地文件
				$this->option ['is_delete'] = $_POST ['is_delete'] == 'Y' ? 'Y' : 'N';
				$res = update_option ( 'upyun_option', $this->option );
				$this->msg = $res == false ? '没有做任何修改' : '设置成功';
				$this->show_msg ( true );
			}
		}
		$size_res = $this->UPyun->getFolderUsage ( '/' );
		if ($size_res === false) {
			$upyun_size = '<label style="color:red;">连接失败，无法获取空间使用情况</label>';
		} else {
			$upyun_size = number_format ( $size_res / 1024 / 1024, 2 ) . ' MB';
		}
?>
<div class="wrap">
<?php screen_icon(); ?>
<h2>又拍云插件设置</h2>
	<form name="upyun_form" method="post" action="<?php echo admin_url('options-general.php?page=set_upyun_option'); ?>">
		<table class="form-table">
			<tr valign="top">
				<th scope="row">又拍云绑定的域名:</th>
				<td>
					<input name="binding_url" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['binding_url']; ?>" /> <span class="description">又拍云空间提供的的默认域名或者已经绑定又拍云空间的二级域名</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">空间名称:</th>
				<td><input name="bucket_name" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['bucket_name']; ?>" /> <span class="description">存放wordpress文件的空间名称</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">操作员用户名:</th>
				<td><input name="admin_usernmae" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['admin_username']; ?>" /> <span class="description">操作员用户名</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">操作员密码:</th>
				<td><input name="admin_password" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $size_res === false ? $this->option['admin_password'] : null; ?>" /> <span class="description">操作员密码（连接成功后不显示）</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">文件存放的根目录:</th>
				<td><input name="remote_upload_root" type="text" class="regular-text" size="100" id="rest_server" value="<?php echo $this->option['remote_upload_root']; ?>" /> <span class="description">又拍云空间中文件存放的根目录路径，例如："/wpimage"，默认存放路径为根目录  "/"</span></td>
			</tr>
			<tr valign="top">
				<th scope="row">空间类型:</th>
				<td>
					<p>
						<label><input type="radio" name="is_normal" value="Y" <?php echo $this->option['is_normal'] == 'Y' ? 'checked="checked"' : null; ?> /> 普通空间 </label> &nbsp; 
						<label><input type="radio" name="is_normal" value="N" <?php echo $this->option['is_normal'] == 'N' ? 'checked="checked"' : null; ?> /> 图片空间</label>
					</p>
					<p class="description">因为又拍云的空间类型（普通、图片）不同，如果选择是图片空间，附件不是图片格式的文件将不会上传到又拍云</p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">上传后是否删除附件文件:</th>
				<td>
					<p>
						<label><input type="radio" name="is_delete" value="Y" <?php echo $this->option['is_delete'] == 'Y' ? 'checked="checked"' : null; ?> /> 是 </label> &nbsp; 
						<label><input type="radio" name="is_delete" value="N" <?php echo $this->option['is_delete'] == 'N' ? 'checked="checked"' : null; ?> /> 否</label>
					</p>
					<p class="description">强烈建议此选项为<b>否</b>，可以在紧要关头时关闭插件直接恢复附件附件的访问</p>
				</td>
			</tr>
			<tr>
				<th scope="row">服务器线路选择:</th>
				<td>
					<select name="upyun_api" id="default_role">
						<option <?php echo $this->option['upyun_api'] == 'v0' ? 'selected="selected"' : null; ?> value="v0">自动</option>
						<option <?php echo $this->option['upyun_api'] == 'v1' ? 'selected="selected"' : null; ?> value="v1">电信</option>
						<option <?php echo $this->option['upyun_api'] == 'v2' ? 'selected="selected"' : null; ?> value="v2">联通网通</option>
						<option <?php echo $this->option['upyun_api'] == 'v3' ? 'selected="selected"' : null; ?> value="v3">移动铁通</option>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">目前空间使用量:</th>
				<td><strong style="color: #f60; font-size:14px;"><?php echo $upyun_size;?></strong></td>
			</tr>
		</table>
		<p class="submit">
			<input type="submit" class="button-primary" name="submit" value="保存设置" />
		</p>
	</form>
	<?php if($size_res !== false) { ?> 
	<hr />
	<?php screen_icon(); ?>
	<h2>将站点的所有附件上传到又拍云，并转换文章中的附件地址为又拍云空间的附件地址</h2>
	<p>PS1: 此操不会删除本地服务器上的附件</p>
	<p>PS2: 如果又拍云中有同名的附件，将会被覆盖</p>
	<p>PS3: 传输过程中请不要关闭页面，如果附件很多，等待所有附件上传完成</p>
	<p>PS4: 上传完成后请点击下面按钮转换附件地址</p>
	<p><input type="button" class="button-primary" id="upload_check" value="检查本地服务器附件列表" /></p>
	<p id="loading" style="display:none;"></p>
	<div id="upload_action" style="display:none;">
		<p><span style="color: red;">目录下共计附件：<strong id="image_count">0</strong> 个</span>&nbsp;&nbsp;<input type="button" disabled="disabled" class="button-primary" id="upload_btn" value="开始上传" /></p>
		<p id="upload_state" style="display:none;"><span style="color: red;">正在上传第：<strong id="now_number">1</strong> 张</span></p>
		<p id="upload_error" style="display:none;"><span style="color: red;">上传失败：<strong id="error_number">0</strong> 张</span></p>
		<p id="upload_result" style="display:none;color: red;padding-left:10px"></p>
		<div>
			<textarea id="upload_reslut_list" style="width: 100%; height: 300px;" readonly="readonly" disabled="disabled" ></textarea>
		</div>
	</div>
	<br />
	<form name="upyun_form" method="post" action="<?php echo admin_url('options-general.php?page=set_upyun_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="将本地URL转为又拍云URL" /> PS: 此操作涉及到数据库操作，也就是替换文章中的附件地址而已
		<input type="hidden" name="action" value="to_upyun" />
	</form>
	<br />
	<hr />
	<br />
	<?php screen_icon(); ?>
	<h2>恢复附件的本地访问，下载又拍云中所有附件并将文章中的附件地址恢复为本地服务器的访问地址</h2>
	<p>PS1: 如果本地服务器中有同名的附件，将会被覆盖</p>
	<p>PS2: 传输过程中请不要关闭页面，如果附件很多，等待所有附件下载完成</p>
	<p>PS3: 下载完成后请点击下面按钮转换附件地址</p>
	<p><input type="button" class="button-primary" id="download_check" value="查看又拍云文件列表" /></p>
	<p id="downloading" style="display:none;"></p>
	<div id="download_action" style="display:none;">
		<p><span style="color: red;">又拍云空间下共计文件：<strong id="download_image_count">0</strong> 个</span>&nbsp;&nbsp;<input type="button" disabled="disabled" class="button-primary" id="download_btn" value="开始下载" /></p>
		<p id="download_state" style="display:none;"><span style="color: red;">正在下载第：<strong id="download_now_number">1</strong> 张</span></p>
		<p id="download_error" style="display:none;"><span style="color: red;">下载失败：<strong id="download_error_number">0</strong> 张</span></p>
		<p id="download_result" style="display:none;color: red;padding-left:10px"></p>
		<div>
			<textarea id="download_result_list" style="width: 100%; height: 300px;" readonly="readonly" disabled="disabled" ></textarea>
		</div>
	</div>
	<br />
	<form name="upyun_form" method="post" action="<?php echo admin_url('options-general.php?page=set_upyun_option'); ?>">
		<input type="submit" class="button-primary" name="submit" value="恢复为本地URL" /> PS: 此操作涉及到数据库操作，也就是替换文章中的附件地址而已
		<input type="hidden" name="action" value="to_local" />
	</form>
	<div style="padding: 30px 10px 0;text-align: right;"><b>By :</b> <a href="http://cuelog.com" target="_blank">Cuelog.com</a></div>
<?php }?>
	
	<script type="text/javascript">
	jQuery(function($){
		var list_data = null;
		var error_list = '';
		var textarea = $('#upload_reslut_list');

		$('#upload_check').click(function(){
			$('#upload_action,#upload_error,#upload_result,#upload_state').hide();
			textarea.val(null);
			var upload_check = $(this);
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'upyun_ajax', 'do': 'get_local_list'},
				timeout: 30000,
				error: function(){
					alert('获取附件列表失败，可能是服务器超时了');
				},
				beforeSend: function(){
					upload_check.attr('disabled','disabled');
					$('#loading').fadeIn('fast').html('<img src="<?php echo plugins_url( 'loading.gif' , __FILE__ ); ?>" /> 加载中...');
				},
				success: function(data){
					upload_check.removeAttr('disabled');
					if(data && data.count > 0){
						$('#loading').hide();
						$('#upload_action').fadeIn('fast');
						$('#upload_btn').removeAttr('disabled');
						$('#image_count').text(data.count);
						var textarea_val;
						list_data = data;
						for(var i in data.url){
							textarea_val = textarea.val();
							textarea.val(data.url[i] + "\r\n" + textarea_val);
						}
					}else{
						$('#loading').html('没有找到任何附件');
					}
				}
			});
		});

		$('#upload_btn').click(function(){
			if(list_data.count == 0){
				alert('没有找到任何附件');
				return false;
			}
			var btn = $(this);
			var upload_state = $('#upload_state');
			$('#upload_result').hide();
			upload_state.slideDown('fast');
			btn.attr('disabled','disabled').val('上传过程中请勿关闭页面...');
			textarea.val('');
			var now_number = 0, error_number = 0;
			for(var i in list_data.url){
				$.ajax({
					url: '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'TEXT',
					data: {'action': 'upyun_ajax', 'do': 'upload', 'file_url': list_data.url[i]},
					error: function(){
						textarea.val('【Error】 上传失败，请使用FTP上传 >> '+list_data.url[i]);
					},
					success: function(data){
						$('#now_number').text(now_number + 1);
						if(data.indexOf('Error') > 0){
							error_number ++;
							error_list =  data + "\r\n" +error_list;
							$('#upload_error').slideDown('fast');
							$('#error_number').text(error_number);
						}
						textarea_val = textarea.val();
						textarea.val(data + "\r\n" + textarea_val);
						now_number ++;
					},
					complete: function(){
						if(now_number == list_data.count){
							btn.removeAttr('disabled').val('开始上传');
							$('#upload_state').hide();
							if(error_number == 0){
								$('#upload_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;"  /> 所有附件上传成功！').fadeIn('fast');
							}else{
								textarea.val(error_list);
								error_list = '';
							}
						}
					}
				});
			}
		});

		var down_list = null;
		var down_textarea = $('#download_result_list');

		
		$('#download_check').click(function(){
			$('#download_action,#download_error,#download_result,#download_state').hide();
			down_textarea.val(null);
			var download_check = $(this);
			$.ajax({
				url: '/wp-admin/admin-ajax.php',
				type: 'GET',
				dataType: 'JSON',
				data: {'action': 'upyun_ajax', 'do': 'get_upyun_list'},
				timeout: 30000,
				error: function(){
					alert('获取文件列表失败，可能是服务器超时了');
				},
				beforeSend: function(){
					download_check.attr('disabled','disabled');
					$('#downloading').fadeIn('fast').html('<img src="<?php echo plugins_url( 'loading.gif' , __FILE__ ); ?>" /> 加载中...');
				},
				success: function(data){
					download_check.removeAttr('disabled');
					if(data && data.count > 0){
						$('#downloading').hide();
						$('#download_action').fadeIn('fast');
						$('#download_btn').removeAttr('disabled');
						$('#download_image_count').text(data.count);
						var textarea_val;
						down_list = data;
						for(var i in data.url){
							textarea_val = down_textarea.val();
							down_textarea.val(data.url[i] + "\r\n" + textarea_val);
						}
					}else{
						$('#downloading').html('没有找到任何文件');
					}
				}
			});
		});



		$('#download_btn').click(function(){
			if(down_list.count == 0){
				alert('又拍云空间没有文件');
				return false;
			}
			var btn = $(this);
			var download_state = $('#download_state');
			$('#download_result').hide();
			download_state.slideDown('fast');
			btn.attr('disabled','disabled').val('下载过程中请勿关闭页面...');
			down_textarea.val('');
			var download_now_number = 0, download_error_number = 0;
			for(var i in down_list.url){
				$.ajax({
					url: '/wp-admin/admin-ajax.php',
					type: 'GET',
					dataType: 'TEXT',
					data: {'action': 'upyun_ajax', 'do': 'download', 'file_path': down_list.url[i]},
					error: function(){
						down_textarea.val('【Error】 下载失败，请手动下载 >> '+down_list.url[i]);
					},
					success: function(data){
						$('#download_now_number').text(download_now_number + 1);
						if(data.indexOf('Error') > 0){
							download_error_number ++;
							error_list =  data + "\r\n" +error_list;
							$('#download_error').slideDown('fast');
							$('#download_error_number').text(download_error_number);
						}
						textarea_val = down_textarea.val();
						down_textarea.val(data + "\r\n" + textarea_val);
						download_now_number ++;
					},
					complete: function(){
						if(download_now_number == down_list.count){
							btn.removeAttr('disabled').val('开始下载');
							$('#download_state').hide();
							if(download_error_number == 0){
								$('#download_result').html('<img src="<?php echo plugins_url( 'success.gif' , __FILE__ ); ?>" style="vertical-align: bottom;" /> 所有文件下载成功！').fadeIn('fast');
							}else{
								down_textarea.val(error_list);
								error_list = '';
							}
						}
					}
				});
			}
		});

	});

	</script>
</div>
<?php 
	}
}
?>