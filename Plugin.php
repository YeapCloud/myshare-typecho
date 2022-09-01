<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 编辑器内粘贴图片上传至Myshare.cc图床
 *
 * @package myshare-typecho
 * @author Ricky
 * @version 1.0.0
 * @link https://github.com/YeapCloud/myshare-typecho-plugin
 */
class Myshare_Plugin implements Typecho_Plugin_Interface
{
	const PLUGIN_NAME = 'Myshare'; //插件名称
    const UPLOAD_DIR = '/usr/uploads'; //上传文件目录路径
	public static $api = 'https://www.myshare.cc/api/v1/';
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 在编辑文章和编辑页面的底部注入代码
		Typecho_Plugin::factory('Widget_Upload')->uploadHandle = array(__CLASS__, 'uploadHandle');
		Typecho_Plugin::factory('Widget_Upload')->modifyHandle = array(__CLASS__, 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle = array(__CLASS__, 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array(__CLASS__, 'attachmentHandle');
        
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array(__CLASS__, 'render');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array(__CLASS__, 'render');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){}
    
	public static function uploadHandle($file) {
		
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
		if(empty($file['name'])) {
			return false;
		}
		//获取扩展名
		$ext = self::getSafeName($file['name']);
		//判定是否是允许的文件类型
		if (!Widget_Upload::checkFileType($ext) || Typecho_Common::isAppEngine()) {
			return false;
		}
		// 获取保存路径
		$date = new Typecho_Date($options->gmtTime);
		$fileDir = self::getUploadDir($ext) . '/' . $date->year . '/' . $date->month;
		
		// 判断是否是图片
		$del = '';
		if(self::isImage($ext)){
			$fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
			$path = $fileDir . '/' . $fileName;
			$uploadfile = self::getUploadFile($file);
			//如果没有临时文件，则退出
			if (!isset($uploadfile)) {
				return false;
			}
			try {
				$result = self::upload($uploadfile, $fileName);
			} catch (Exception $e) {
				return false;
			}
			if (!isset($file['size'])){
				$fileInfo = $result;
				$file['size'] = $fileInfo['size'];
			}
			if(!$result){
			    exit(json_encode(['code'=>500, 'message'=>'图片上传错误']));
			};
			$file['name'] = $result['origin_name'];
			$path = $result["links"]['url'];
			$del = $result['key'];
		}else{
			//创建上传目录
			if (!is_dir($fileDir)) {
				if (!self::makeUploadDir($fileDir)) {
					return false;
				}
			}
			//获取文件名
			$fileName = sprintf('%u', crc32(uniqid())) . '.' . $ext;
			$path = $fileDir . '/' . $fileName;
			if (isset($file['tmp_name'])) {
				//移动上传文件
				if (!@move_uploaded_file($file['tmp_name'], $path)) {
					return false;
				}
			} elseif (isset($file['bytes'])) {
				//直接写入文件
				if (!file_put_contents($path, $file['bytes'])) {
					return false;
				}
			} else {
				return false;
			}
			if (!isset($file['size'])) {
				$file['size'] = filesize($path);
			}
		}
		//返回相对存储路径
			$arr = array(
				'name' => $file['name'],
				'path' => $path,
				'size' => $file['size'],
				'type' => $ext,
				'key'=>$del,
				'mime' => @Typecho_Common::mimeContentType($path)
			);
			return $arr;//var_dump($arr);exit;
	}
	
	public static function deleteHandle(array $content) {
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
		$ext = self::getSafeName($content['title']);
		// 判断是否为图片
		if(self::isImage($ext)){
			try {
			    $id = explode('.', $content['attachment']->name);
			    $id = $id[0];
                $token = $options->token;
                $url = self::$api . 'images/';
			    $res = self::http_del($token, $url.$content['attachment']->key);
			    $res = json_decode($res, true);
			    if($res['status']){
			        return true;
			    }
			    return false;
			} catch (Exception $e) {
				return false;
			}
		}else{ //本地删除
			@unlink($content['attachment']->path);
		}
        return true;
    }
    public static function modifyHandle($content, $file){
		self::deleteHandle($content);
        return self::uploadHandle($file);
    }
    public static function http_del($token, $url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        $header = [];
        $header[] = 'Accept: application/json';
        $header[] = 'Authorization: Bearer '.$token;
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        return $result;
    }
	public static function http($url, $data=null, $header=[]){
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $header[] = 'Accept: application/json';
        if ( !empty($data) ) {
            @curl_setopt($ch, CURLOPT_POST, true);
            @curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        if (!empty($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }
        //var_dump($header);
        $str = curl_exec($ch);
        curl_close($ch);
        return $str;
    }
    public static function del($token, $id){
    
        $url = self::$api . 'delete';
        $data = self::http($url, ['id'=>$id, 'token'=>$token], []);
        $data = json_decode($data, true);//var_dump($data);
        if($data["code"] == 200){
            return $data["data"];
        }else{
            return [];
        }
    }
    public static function upload($image, $filename){
        $options = Helper::options()->plugin(self::PLUGIN_NAME);
        $token = $options->token;
        $url = self::$api . 'upload';
        $data = self::http($url, ['file'=>new \CURLFile(realpath($image),'image/jpeg',$filename), 'strategy_id'=>($options->strategy_id?$options->strategy_id:'0'), 'album_id'=>($options->album_id?$options->album_id:'0')], ['Authorization: Bearer '.$token, 'Content-Type: multipart/form-data']);
        $data = json_decode($data, true);
        if($data["status"] && $data['data'] && $data['data']['links']){
            return $data["data"];
        }else{
            return [];
        }
    }
    
    public static function attachmentHandle(array $content){
		$plug_url = 'https://www.myshare.cc/';
		$path = $content['attachment']->path;
		$part = explode('.', $content['attachment']->name);
        $ext = (($length = count($part)) > 1) ? strtolower($part[$length-1]) : '';
		if(in_array($ext,array('gif','jpg','jpeg','png','bmp'))){
			
			return $content['attachment']->path;// do nothing
			
		}else{
			return $content['attachment']->path;// same nothing
		}
    }
    private static function getUploadDir($ext='') {
		if(self::isImage($ext)){
			$url = parse_url(Helper::options()->siteUrl);
			$DIR = str_replace('.','_',$url['host']);
			return '/'.$DIR.self::UPLOAD_DIR;
		}elseif(defined('__TYPECHO_UPLOAD_DIR__')){
            return __TYPECHO_UPLOAD_DIR__;
        }else{
			$path = Typecho_Common::url(self::UPLOAD_DIR,__TYPECHO_ROOT_DIR__);
            return $path;
        }
    }

    private static function getUploadFile($file) {
        return isset($file['tmp_name']) ? $file['tmp_name'] : (isset($file['bytes']) ? $file['bytes'] : (isset($file['bits']) ? $file['bits'] : ''));
    }
    
    private static function getSafeName(&$name) {
        $name = str_replace(array('"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }
	
    private static function makeUploadDir($path){
        $path = preg_replace("/\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::makeUploadDir($path);
    }
	
	private static function isImage($ext){
		$img_ext_arr = array('gif','jpg','jpeg','png','tiff','bmp');
		return in_array($ext,$img_ext_arr);
	}
    public static function personalConfig(Typecho_Widget_Helper_Form $form){
    }
	public static function config(Typecho_Widget_Helper_Form $form) {
    
        $desc = new Typecho_Widget_Helper_Form_Element_Text('desc', NULL, '', _t('插件使用说明：'), 
            _t('<ol>
                    <li>请登录<a href="https://www.myshare.cc/user/tokens" target="_blank">获取Token</a><br></li>
                    <li>您可<a href="https://www.dwt.life/apk/myshare.apk" target="_blank">下载APP</a>管理您的图片和相册<br></li>
                    <li>相册ID或策略ID请阅读<a href="https://www.myshare.cc/page/help" target="_blank">『常见问题解答』</a><br></li>
                </ol>'));
		$form->addInput($desc); 
		$acid = new Typecho_Widget_Helper_Form_Element_Text('token',NULL, '',_t('Token'));
		$form->addInput($acid->addRule('required', _t('Token不能为空！')));
		$albid = new Typecho_Widget_Helper_Form_Element_Text('album_id',NULL, '',_t('相册ID，可为空'));
		$form->addInput($albid);
		$strid = new Typecho_Widget_Helper_Form_Element_Text('strategy_id',NULL, '',_t('策略ID，可为空'));
		$form->addInput($strid);

		echo '<script>window.onload = function(){document.getElementsByName("desc")[0].type = "hidden";}</script>';
    }
    public static function render()
    {

        Typecho_Widget::widget('Widget_Options')->to($options);
        $version = $options->Version;
        $version = floatval(substr($version, 0, 3));
        if($version >= 1.2) return '';
        ?>
<script>
// 粘贴文件上传
$(document).ready(function () {
    // 上传URL
    var uploadUrl = '<?php Helper::security()->index('/action/upload'); ?>';
    // 处理有特定的 CID 的情况
    var cid = $('input[name="cid"]').val();
    if (cid) {
        uploadUrl += '&cid=' + cid;
    }

    // 上传文件函数
    function uploadFile(file) {
        // 生成一段随机的字符串作为 key
        var index = Math.random().toString(10).substr(2, 5) + '-' + Math.random().toString(36).substr(2);
        // 默认文件后缀是 png，在Chrome浏览器中剪贴板粘贴的图片都是png格式，其他浏览器暂未测试
        var fileName = index + '.png';

        // 上传时候提示的文字
        var uploadingText = '[图片上传中...(' + index + ')]';

        // 先把这段文字插入
        var textarea = $('#text'), sel = textarea.getSelection(),
        offset = (sel ? sel.start : 0) + uploadingText.length;
        textarea.replaceSelection(uploadingText);
        // 设置光标位置
        textarea.setSelection(offset, offset);

        // 设置附件栏信息
        // 先切到附件栏
        $('#tab-files-btn').click();

        // 更新附件的上传提示
        var fileInfo = {
            id: index,
            name: fileName
        }
        fileUploadStart(fileInfo);

        // 是时候展示真正的上传了
        var formData = new FormData();
        formData.append('name', fileName);
        formData.append('file', file, fileName);

        $.ajax({
            method: 'post',
            url: uploadUrl,
            data: formData,
            contentType: false,
            processData: false,
            success: function (data) {
                var url = data[0], title = data[1].title;
                textarea.val(textarea.val().replace(uploadingText, '![' + title + '](' + url + ')'));
                // 触发输入框更新事件，把状态压人栈中，解决预览不更新的问题
                textarea.trigger('paste');
                // 附件上传的UI更新
                fileUploadComplete(index, url, data[1]);
            },
            error: function (error) {
                textarea.val(textarea.val().replace(uploadingText, '[图片上传错误...]\n'));
                // 触发输入框更新事件，把状态压人栈中，解决预览不更新的问题
                textarea.trigger('paste');
                // 附件上传的 UI 更新
                fileUploadError(fileInfo);
            }
        });
    }

    // 监听输入框粘贴事件
    document.getElementById('text').addEventListener('paste', function (e) {
      var clipboardData = e.clipboardData;
      var items = clipboardData.items;
      for (var i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && items[i].type.match(/^image/)) {
          // 取消默认的粘贴操作
          e.preventDefault();
          // 上传文件
          uploadFile(items[i].getAsFile());
          break;
        }
      }
    });



    //
    // 以下代码均来自 /admin/file-upload-js.php，无奈只好复制粘贴过来实现功能
    //

    // 更新附件数量显示
    function updateAttacmentNumber () {
        var btn = $('#tab-files-btn'),
            balloon = $('.balloon', btn),
            count = $('#file-list li .insert').length;

        if (count > 0) {
            if (!balloon.length) {
                btn.html($.trim(btn.html()) + ' ');
                balloon = $('<span class="balloon"></span>').appendTo(btn);
            }

            balloon.html(count);
        } else if (0 == count && balloon.length > 0) {
            balloon.remove();
        }
    }

    // 开始上传文件的提示
    function fileUploadStart (file) {
        $('<li id="' + file.id + '" class="loading">'
            + file.name + '</li>').appendTo('#file-list');
    }

    // 上传完毕的操作
    var completeFile = null;
    function fileUploadComplete (id, url, data) {
        var li = $('#' + id).removeClass('loading').data('cid', data.cid)
            .data('url', data.url)
            .data('image', data.isImage)
            .html('<input type="hidden" name="attachment[]" value="' + data.cid + '" />'
                + '<a class="insert" target="_blank" href="###" title="<?php _e('点击插入文件'); ?>">' + data.title + '</a><div class="info">' + data.bytes
                + ' <a class="file" target="_blank" href="<?php $options->adminUrl('media.php'); ?>?cid='
                + data.cid + '" title="<?php _e('编辑'); ?>"><i class="i-edit"></i></a>'
                + ' <a class="delete" href="###" title="<?php _e('删除'); ?>"><i class="i-delete"></i></a></div>')
            .effect('highlight', 1000);

        attachInsertEvent(li);
        attachDeleteEvent(li);
        updateAttacmentNumber();

        if (!completeFile) {
            completeFile = data;
        }
    }

    // 增加插入事件
    function attachInsertEvent (el) {
        $('.insert', el).click(function () {
            var t = $(this), p = t.parents('li');
            Typecho.insertFileToEditor(t.text(), p.data('url'), p.data('image'));
            return false;
        });
    }

    // 增加删除事件
    function attachDeleteEvent (el) {
        var file = $('a.insert', el).text();
        $('.delete', el).click(function () {
            if (confirm('<?php _e('确认要删除文件 %s 吗?'); ?>'.replace('%s', file))) {
                var cid = $(this).parents('li').data('cid');
                $.post('<?php Helper::security()->index('/action/contents-attachment-edit'); ?>',
                    {'do' : 'delete', 'cid' : cid},
                    function () {
                        $(el).fadeOut(function () {
                            $(this).remove();
                            updateAttacmentNumber();
                        });
                    });
            }

            return false;
        });
    }

    // 错误处理，相比原来的函数，做了一些微小的改造
    function fileUploadError (file) {
        var word;

        word = '<?php _e('上传出现错误'); ?>';

        var fileError = '<?php _e('%s 上传失败'); ?>'.replace('%s', file.name),
            li, exist = $('#' + file.id);

        if (exist.length > 0) {
            li = exist.removeClass('loading').html(fileError);
        } else {
            li = $('<li>' + fileError + '<br />' + word + '</li>').appendTo('#file-list');
        }

        li.effect('highlight', {color : '#FBC2C4'}, 2000, function () {
            $(this).remove();
        });
    }
})
</script>
<?php
    }
}
