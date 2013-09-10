<?php 
/*
Plugin Name: 又拍云插件
Version: 1.0.0
Plugin URI: http://cuelog.com/?p=43
Description: 又拍云图片储存插件，集成又拍云SDK， 在后台中上传图片时，自动上传到又拍云空间； 本地服务器与又拍云空间所有图片一键上传/下载; 一键转换本地图片url和又拍云图片url,简单易用;
Author: Cuelog
Author URI: http://cuelog.com
*/

if(is_admin()){
	define('UPYUN_IS_WIN',strstr(PHP_OS, 'WIN') ? 1 : 0 );
	register_uninstall_hook( __FILE__, 'remove_upyun' );
	add_filter ( 'plugin_action_links', 'upyun_setting_link', 10, 2 );
	include_once ('upyun.class.php');
	include ('UpyunClond.class.php');
	new UpYunCloud();
}
//删除插件
function remove_upyun(){
	$exist_option = get_option('upyun_option');
	if(isset($exist_option)){
		delete_option('upyun_option');
	}
}
//设置按钮
function upyun_setting_link($links, $file){
	$plugin = plugin_basename(__FILE__);
	if ( $file == $plugin ) {
		$setting_link = sprintf( '<a href="%s">%s</a>', admin_url('options-general.php').'?page=set_upyun_option', '设置' );
		array_unshift( $links, $setting_link );
	}
	return $links;
}
?>