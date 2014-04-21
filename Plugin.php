<?php
/**
 * 评论回复邮件提醒插件
 *
 * @package CommentToMail
 * @author Update By Byends
 * @version 1.2.6
 * @link http://www.byends.com
 * @oriAuthor DEFE (http://defe.me)
 * 
 * 原作者是  DEFE (http://defe.me),请尊重版权
 * 
 * update at 2012-07-11
 * 1、修复接收邮箱为空时 无法获取 博主邮箱 导致无法发送邮件的BUG
 * 2、修复已经设置邮件标题 却无效的BUG
 * 3、修复评论者对博主的回复进行回复时，发送邮件的格式依然选择博主格式时的逻辑BUG
 * 4、修复由于没有设置字符类型 和 编码类型 可能导致邮件乱码的BUG，字符类型设置为 UTF-8，编码类型为 base64
 * 5、修复评论时间  时错误的BUG
 * 5、邮件标题增加不能为空的校验
 * 6、整理代码格式，提高可读性
 */
class CommentToMail_Plugin implements Typecho_Plugin_Interface {
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate() {
        if (!ini_get('allow_url_fopen')) {
            throw new Typecho_Plugin_Exception(_t('对不起, 您的主机没有打开 allow_url_fopen 功能, 无法正常使用此插件'));
        }

        Helper::addAction('comment-to-mail', 'CommentToMail_Action');

        Typecho_Plugin::factory('Widget_Feedback')->finishComment = array('CommentToMail_Plugin', 'toMail');
        return _t('请对插件进行正确设置，以使插件顺利工作！');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate() {
        Helper::removeAction('comment-to-mail');
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form) {
        $mode= new Typecho_Widget_Helper_Form_Element_Radio('mode',
                array( 'smtp' => 'smtp',
                       'mail' => 'mail()',
                       'sendmail' => 'sendmail()'),
                'smtp', '发信方式');
        $form->addInput($mode);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, 'smtp.',
                _t('SMTP地址'), _t('请填写 SMTP 服务器地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个SMTP服务器地址')));

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '25',
                _t('SMTP端口'), _t('SMTP服务端口,一般为25。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写SMTP服务端口'))
                ->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, NULL,
                _t('SMTP用户'),_t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user->addRule('required', _t('SMTP服务验证用户名')));

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL,
                _t('SMTP密码'));
        $form->addInput($pass->addRule('required', _t('SMTP服务验证密码')));

        $validate = new Typecho_Widget_Helper_Form_Element_Checkbox('validate',
                array('validate'=>'服务器需要验证',
                    'ssl'=>'ssl加密'),
                array('validate'),'SMTP验证');
        $form->addInput($validate);

        $mail = new Typecho_Widget_Helper_Form_Element_Text('mail', NULL, NULL,
                _t('接收邮箱'),_t('接收邮件用的信箱,如为空则使用文章作者个人设置中的邮箱！'));
        $form->addInput($mail->addRule('email', _t('请填写正确的邮箱！')));

        $status = new Typecho_Widget_Helper_Form_Element_Checkbox('status',
                array('approved' => '提醒已通过评论',
                        'waiting' => '提醒待审核评论',
                        'spam' => '提醒垃圾评论'),
                array('approved', 'waiting'), '提醒设置',_t('该选项仅针对博主，访客只发送已通过的评论。'));
        $form->addInput($status);

        $other = new Typecho_Widget_Helper_Form_Element_Checkbox('other',
                array('to_owner' => '有评论及回复时，发邮件通知博主。',
                    'to_guest' => '评论被回复时，发邮件通知评论者。',
                    'to_me'=>'自己回复自己的评论时，发邮件通知。(同时针对博主和访客)',
                    'to_log' => '记录邮件发送日志。'),
                array('to_owner','to_guest','to_log'), '其他设置',_t('如果选上"记录邮件发送日志"选项，则会在./CommentToMail/log/mail_log.txt 文件中记录发送信息。<br />
                    关键性错误日志将自动记录到./CommentToMail/log/error_log.txt文件中。<br />
                    '));
        $form->addInput($other->multiMode());

        $titleForOwner = new Typecho_Widget_Helper_Form_Element_Text('titleForOwner',null,"[{title}] 一文有新的评论",
                _t('博主接收邮件标题'));
        $form->addInput($titleForOwner->addRule('required', _t('博主接收邮件标题 不能为空')));

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest',null,"您在 [{title}] 的评论有了回复",
                _t('访客接收邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('访客接收邮件标题 不能为空')));
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {

    }

    /**
     * 组合邮件内容
     *
     * @access public
     * @param $comment 调用参数
     * @return void
     */
    public static function toMail($comment) {  
        $smtp = array();        
        $options = Typecho_Widget::widget('Widget_Options');
        $smtp['site'] = $options->title;    
        $smtp['timezone'] = $options->timezone;
        $smtp['cid'] = $comment->cid;
        $smtp['coid'] = $comment->coid;
        $smtp['created'] = $comment->created;
        $smtp['author'] = $comment->author;
        $smtp['authorId'] = $comment->authorId;
        $smtp['ownerId'] = $comment->ownerId;
        $smtp['mail'] = $comment->mail;
        $smtp['ip'] = $comment->ip;
        $smtp['title'] = $comment->title;
        $smtp['text'] = $comment->text;
        $smtp['permalink'] = $comment->permalink;
        $smtp['status'] = $comment->status;
        $smtp['parent'] = $comment->parent;         
        $smtp['manage'] = $options->siteUrl."admin/manage-comments.php";
        
        //获取是否接收邮件的选项 
        if(isset ($_POST['banmail']) && 'stop' == $_POST['banmail']){
            $smtp['banMail'] = 1;
        }  else{
            $smtp['banMail'] = 0;
        }
        
        $filename = Typecho_Common::randString(7);
        $smtp = (object)$smtp;
        file_put_contents('.'.__TYPECHO_PLUGIN_DIR__.'/CommentToMail/cache/'.$filename, serialize($smtp));
        $url = ($options->rewrite) ? $options->siteUrl : $options->siteUrl.'index.php';        
        self::asyncRequest($filename, $url);
    }    
     /**
     * 发送异步请求
     *
     * @access public
     * @param string $filename 存放邮件的临时文件名
     * @param string $siteUrl 网站连接
     * @return void
     */
    public static function asyncRequest($filename, $siteUrl) {

        $dmpt = parse_url($siteUrl);

        $host = $dmpt['host'];
        $port = $dmpt['port'] ? $dmpt['port'] : 80;

        if(substr($dmpt['path'], -1) != '/') $dmpt['path'] .= '/';        
        $url = $dmpt['path'].'action/comment-to-mail';     

        $get = 'send='.$filename;

        $head = "GET ". $url . "?" . $get . " HTTP/1.0\r\n";
        $head .= "Host: " . $host . "\r\n";
        $head .= "\r\n";
        
        if(function_exists('fsockopen')){
            $fp = @fsockopen ($host, $port, $errno, $errstr, 30);
        }
        elseif(function_exists('pfsockopen')){
            $fp = @pfsockopen ($host, $port, $errno, $errstr, 30);
        }  else {
            $fp = stream_socket_client($host.":$port", $errno, $errstr, 30);
        }
        
        if($fp){                
            fputs ($fp, $head);                
            fclose($fp);
        }else{
             file_put_contents('.'.__TYPECHO_PLUGIN_DIR__.'/CommentToMail/log/error_log.txt', "SOCKET错误,".$errno.':'.$errstr);
        }
      
    }



}
